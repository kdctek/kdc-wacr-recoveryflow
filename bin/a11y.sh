#!/usr/bin/env bash
#
# Run pa11y-ci over every screen listed in .pa11yci.json.
#
#   npm run env:start
#   npm run a11y:seed        # demo data, and the ids the URL list needs
#   npm run a11y
#
# WHAT THIS SCRIPT IS FOR
#
# pa11y-ci given an empty `urls` array has nothing to check and says so
# cheerfully, so `npm run a11y` reported success while testing not one screen --
# for every slice that shipped a screen. WCAG 2.2 AA/AAA is a stated requirement
# of this plugin's admin UI, and a green accessibility command is exactly the
# thing somebody points at instead of checking. The empty-list refusal below
# stays for that reason, even now that the list is written: a future edit that
# empties it must fail, not pass.
#
# Two things stop .pa11yci.json being runnable on its own, and this script
# supplies both.
#
# 1. A SESSION. Every admin screen is behind a capability check, so an
#    unauthenticated run would check twenty copies of the login form and report
#    them clean. The script logs in once with curl and passes the cookies to
#    Chrome as a header. The alternative -- a pa11y `actions` block driving the
#    login form -- would log in once per URL, which is twenty logins and twenty
#    more things to go wrong on a check nobody watches.
#
# 2. THREE IDS THAT DO NOT EXIST UNTIL SOMETHING DOES. One recovery, one
#    workflow's editor and the public opt-out page cannot be addressed without a
#    row to address, so .pa11yci.json holds {{PLACEHOLDERS}} and the seeder
#    writes what they stand for. The list of SCREENS stays in .pa11yci.json,
#    where it can be read and reviewed; only the values are generated.
#
# The generated config goes in a temp file and is deleted on the way out,
# because it holds a live session cookie for whoever ran it.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="${ROOT}/.pa11yci.json"
VALUES="${ROOT}/tests/a11y/urls.generated.json"

SITE="${RECOVERYFLOW_A11Y_URL:-http://localhost:8901}"
USER="${RECOVERYFLOW_A11Y_USER:-admin}"
PASS="${RECOVERYFLOW_A11Y_PASS:-password}"

# The command that puts demo data on the site, run before EACH pass. It has to
# run before each one and not merely once, because checking the opt-out page
# properly means pressing its button, and pressing it spends the token and
# closes the recovery -- so a second pass over the same seed finds no form and
# fails the action. That is the right failure to have (it is loud, and it is
# how a stale seed announces itself) but it is not a state to leave the person
# running the suite in.
#
# Set it to an empty string to seed by hand, which is what running against
# anything other than wp-env will need.
SEED="${RECOVERYFLOW_A11Y_SEED-npm run --silent a11y:seed}"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

if [[ ! -f "${CONFIG}" ]]; then
	echo "bin/a11y.sh: ${CONFIG} does not exist." >&2
	exit 1
fi

count="$(php -r '
	$config = json_decode( (string) file_get_contents( $argv[1] ), true );
	if ( ! is_array( $config ) ) {
		fwrite( STDERR, "bin/a11y.sh: .pa11yci.json is not valid JSON.\n" );
		exit( 1 );
	}
	echo count( (array) ( $config["urls"] ?? array() ) );
' "${CONFIG}")"

if [[ "${count}" -eq 0 ]]; then
	cat >&2 <<'MSG'
bin/a11y.sh: .pa11yci.json lists no URLs, so there is nothing to check.

Refusing rather than reporting success, because a green run against zero screens
is read as coverage and there is none. Add each screen to the "urls" array; see
docs/accessibility.md.
MSG
	exit 1
fi

# --- demo data --------------------------------------------------------------

seed() {
	if [[ -z "${SEED}" ]]; then
		return 0
	fi

	echo "bin/a11y.sh: seeding (${SEED})"

	if ! ${SEED} >/dev/null 2>&1; then
		echo "bin/a11y.sh: the seed command failed: ${SEED}" >&2
		echo "Start wp-env (npm run env:start), or set RECOVERYFLOW_A11Y_SEED='' and seed by hand." >&2
		exit 1
	fi
}

seed

if [[ ! -f "${VALUES}" ]]; then
	cat >&2 <<MSG
bin/a11y.sh: ${VALUES} does not exist.

The URL list addresses one recovery, one workflow and a live recovery link, none
of which have an address until something has been created. Seed them first:

    npm run env:start
    npm run a11y:seed

Running without it would leave those placeholders unsubstituted and check three
URLs that do not resolve -- which pa11y reports as a clean page.
MSG
	exit 1
fi

# --- a session -------------------------------------------------------------

JAR="${WORK}/cookies.txt"

status="$(curl -sS -c "${JAR}" -o /dev/null -w '%{http_code}' \
	-X POST "${SITE}/wp-login.php" \
	--data-urlencode "log=${USER}" \
	--data-urlencode "pwd=${PASS}" \
	--data-urlencode 'wp-submit=Log In' \
	--data-urlencode 'testcookie=1' || echo 000)"

if ! grep -q 'wordpress_logged_in' "${JAR}" 2>/dev/null; then
	echo "bin/a11y.sh: could not log in to ${SITE} as ${USER} (HTTP ${status})." >&2
	echo "Every admin screen is behind a capability check, so the run would check the" >&2
	echo "login form twenty times and report it clean. Start wp-env, or set" >&2
	echo "RECOVERYFLOW_A11Y_URL / _USER / _PASS." >&2
	exit 1
fi

COOKIE="$(awk -F'\t' 'NF==7 {printf "%s=%s; ", $6, $7}' "${JAR}")"

# Prove the session actually reaches an admin screen before checking twenty of
# them. A cookie that parses but does not authenticate looks exactly like one
# that does, right up until every result is a login form.
probe="$(curl -sS -o /dev/null -w '%{http_code}' -H "Cookie: ${COOKIE}" \
	"${SITE}/wp-admin/admin.php?page=recoveryflow" || echo 000)"

if [[ "${probe}" != "200" ]]; then
	echo "bin/a11y.sh: the session did not reach wp-admin (HTTP ${probe})." >&2
	exit 1
fi

# --- the ids ---------------------------------------------------------------

# --- the runnable config ----------------------------------------------------

# $1 the standard to write in, $2 where to write it.
build_config() {
	php -r '
	$config = json_decode( (string) file_get_contents( $argv[1] ), true );
	$values = json_decode( (string) file_get_contents( $argv[2] ), true );

	if ( ! is_array( $config ) || ! is_array( $values ) ) {
		fwrite( STDERR, "bin/a11y.sh: could not read the config or the seeded values.\n" );
		exit( 1 );
	}

	unset( $config["_note"] );

	$config["defaults"]["headers"]["Cookie"] = $argv[3];
	$config["defaults"]["standard"]          = $argv[5];

	// pa11y require()s a named runner from its own directory, so a runner of
	// ours has to be given an absolute path or it is looked for inside
	// node_modules and reported missing.
	$config["defaults"]["runners"] = array_map(
		static function ( $runner ) use ( $argv ) {
			return false === strpos( $runner, "/" ) ? $runner : $argv[6] . "/" . $runner;
		},
		(array) ( $config["defaults"]["runners"] ?? array( "htmlcs" ) )
	);

	$search  = array();
	$replace = array();

	foreach ( $values as $name => $value ) {
		$search[]  = "{{" . $name . "}}";
		$replace[] = (string) $value;
	}

	$urls = array();

	foreach ( (array) ( $config["urls"] ?? array() ) as $entry ) {
		if ( is_string( $entry ) ) {
			$entry = array( "url" => $entry );
		}

		// Substituted across the whole entry, not only the url: an action that
		// waits for a seeded path needs the same treatment, and an action left
		// holding a placeholder waits for an address that never arrives.
		$entry["url"] = str_replace( $search, $replace, (string) ( $entry["url"] ?? "" ) );

		foreach ( (array) ( $entry["actions"] ?? array() ) as $i => $action ) {
			$entry["actions"][ $i ] = str_replace( $search, $replace, (string) $action );
		}

		// A placeholder nothing filled in leaves a URL that cannot resolve, and
		// pa11y reports a 404 body as a clean page. Refuse the whole run rather
		// than quietly check one screen fewer than the list says.
		$unfilled = $entry["url"] . " " . implode( " ", (array) ( $entry["actions"] ?? array() ) );

		if ( "" === $entry["url"] || false !== strpos( $unfilled, "{{" ) ) {
			fwrite( STDERR, sprintf( "bin/a11y.sh: %s was never filled in. Re-run the seeder.\n", $unfilled ) );
			exit( 1 );
		}

		unset( $entry["comment"] );

		$urls[] = $entry;
	}

	$config["urls"] = $urls;

	file_put_contents( $argv[4], json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
' "${CONFIG}" "${VALUES}" "${COOKIE}" "$2" "$1" "${ROOT}"
}

# --- the gate ---------------------------------------------------------------
#
# AA is what the plugin commits to, so an AA failure is a failure. AAA is what
# it reaches for, so AAA findings are printed and read rather than enforced --
# most of them are core WordPress's own colours, which this plugin does not get
# to change and must not pretend to have fixed.

GATE="${WORK}/pa11yci-aa.json"
build_config WCAG2AA "${GATE}"

echo
echo "bin/a11y.sh: WCAG 2.2 AA over ${count} screen(s), as ${USER} on ${SITE}. This is the gate."
echo

set +e
npx pa11y-ci --config "${GATE}" "$@"
gate=$?
set -e

if [[ "${SKIP_AAA:-}" == "1" ]]; then
	exit "${gate}"
fi

seed

REPORT="${WORK}/pa11yci-aaa.json"
build_config WCAG2AAA "${REPORT}"

echo
echo "bin/a11y.sh: WCAG 2.2 AAA over the same screens. Findings are REVIEWED, not enforced."
echo

set +e
npx pa11y-ci --config "${REPORT}" --reporter json > "${WORK}/aaa.json" 2>/dev/null
set -e

# The AAA findings are meant to be read, and reading 400 of them means sorting
# them by something. Keep the raw results when asked, so a change in this list
# can be diffed against the last one rather than argued about from memory.
if [[ -n "${RECOVERYFLOW_A11Y_AAA_JSON:-}" ]]; then
	cp "${WORK}/aaa.json" "${RECOVERYFLOW_A11Y_AAA_JSON}"
	echo "bin/a11y.sh: AAA results written to ${RECOVERYFLOW_A11Y_AAA_JSON}"
fi

php -r '
	$raw = (string) file_get_contents( $argv[1] );
	$at  = strpos( $raw, "{" );
	$out = false === $at ? null : json_decode( substr( $raw, $at ), true );

	if ( ! is_array( $out ) ) {
		echo "bin/a11y.sh: the AAA pass produced nothing readable.\n";
		exit( 0 );
	}

	$counts = array();

	foreach ( (array) ( $out["results"] ?? array() ) as $url => $issues ) {
		foreach ( (array) $issues as $issue ) {
			if ( "error" !== ( $issue["type"] ?? "" ) ) {
				continue;
			}

			$code = (string) ( $issue["code"] ?? "" );

			$counts[ $code ] = ( $counts[ $code ] ?? 0 ) + 1;
		}
	}

	arsort( $counts );

	if ( array() === $counts ) {
		echo "AAA: nothing found.\n";
		exit( 0 );
	}

	printf( "AAA findings by rule, over %d screens:\n\n", (int) ( $out["total"] ?? 0 ) );

	foreach ( $counts as $code => $n ) {
		printf( "  %5d  %s\n", $n, $code );
	}

	echo "\nRead these; do not gate on them. Run with --reporter json for the detail.\n";
' "${WORK}/aaa.json"

exit "${gate}"
