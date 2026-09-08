#!/usr/bin/env bash
#
# Run pa11y-ci over the admin screens listed in .pa11yci.json.
#
#   npm run a11y
#
# The wrapper exists for one reason: pa11y-ci given an empty `urls` array has
# nothing to check and says so cheerfully, so `npm run a11y` reported success
# while testing not one screen -- for every slice that shipped a screen. WCAG
# 2.2 AA/AAA is a stated requirement of this plugin's admin UI, and a green
# accessibility command is exactly the thing somebody points at instead of
# checking. Refuse rather than pass; an empty list is an unwritten suite, not a
# clean result.
#
# Populating it needs a running wp-env and a logged-in session -- see
# docs/accessibility.md. Add the URLs there, not here.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="${ROOT}/.pa11yci.json"

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

The accessibility suite is NOT built. Refusing rather than reporting success,
because a green run against zero screens is read as coverage and there is none.

To build it: start wp-env (`npm run env:start`), then add each admin screen's
URL to the "urls" array in .pa11yci.json along with the login action described
in docs/accessibility.md. Every screen has shipped; none is listed.
MSG
	exit 1
fi

echo "bin/a11y.sh: checking ${count} URL(s) at WCAG2AAA."
exec npx pa11y-ci --config "${CONFIG}"
