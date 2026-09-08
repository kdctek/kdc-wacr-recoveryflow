#!/usr/bin/env bash
#
# Run a PHPUnit suite, and refuse when it turns out there was nothing in it.
#
# WHAT THIS SCRIPT IS FOR
#
# `phpunit --testsuite unit` over an empty directory prints "No tests executed!"
# and EXITS 0. A CI job named "Unit tests" ran exactly that on every release of
# this plugin and put a green tick on coverage that did not exist, for five
# slices. The job was removed rather than left lying.
#
# The job can only come back if the command behind it cannot lie, and PHPUnit 9
# cannot be told to fail on an empty suite: there is no such option and no such
# XML attribute, and setting one is ignored without complaint. PHPUnit 10 has it
# and requires PHP 8.1; this plugin supports 8.0. So the refusal is here.
#
#   bin/phpunit.sh unit          # one suite
#   bin/phpunit.sh               # every suite in phpunit.xml.dist
#
# Anything after the suite name is passed through to phpunit.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHPUNIT="${ROOT}/vendor/bin/phpunit"

if [[ ! -x "${PHPUNIT}" ]]; then
	echo "bin/phpunit.sh: ${PHPUNIT} is not there. Run composer install." >&2
	exit 1
fi

# The suites to run, read from the config rather than listed here, so removing
# one from phpunit.xml.dist cannot leave this script asking for a suite that no
# longer exists -- or, worse, silently not asking for one that does.
if [[ $# -gt 0 && "$1" != -* ]]; then
	SUITES=( "$1" )
	shift
else
	mapfile -t SUITES < <(
		php -r '
			$config = simplexml_load_file( $argv[1] );

			if ( false === $config ) {
				fwrite( STDERR, "bin/phpunit.sh: phpunit.xml.dist is not valid XML.\n" );
				exit( 1 );
			}

			foreach ( $config->testsuites->testsuite as $suite ) {
				echo (string) $suite["name"], "\n";
			}
		' "${ROOT}/phpunit.xml.dist"
	)
fi

if [[ ${#SUITES[@]} -eq 0 ]]; then
	echo "bin/phpunit.sh: phpunit.xml.dist declares no test suites." >&2
	exit 1
fi

status=0

for suite in "${SUITES[@]}"; do
	echo
	echo "bin/phpunit.sh: ${suite}"
	echo

	set +e
	output="$( "${PHPUNIT}" --testsuite "${suite}" "$@" 2>&1 )"
	code=$?
	set -e

	echo "${output}"

	# The refusal. "No tests executed!" is PHPUnit's own wording for the state
	# this script exists to catch, and it arrives with a zero exit code.
	if [[ "${output}" == *"No tests executed!"* ]]; then
		echo >&2
		echo "bin/phpunit.sh: the '${suite}' suite contains no tests, so it proved nothing." >&2
		echo "Refusing rather than reporting success: a green tick over an empty suite is" >&2
		echo "read as coverage, and this repository has already shipped five releases with" >&2
		echo "one. Write a test in tests/${suite}/, or remove the suite from" >&2
		echo "phpunit.xml.dist so nothing claims it exists." >&2
		status=1

		continue
	fi

	if [[ ${code} -ne 0 ]]; then
		status="${code}"
	fi
done

exit "${status}"
