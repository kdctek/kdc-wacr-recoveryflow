#!/usr/bin/env bash
#
# Walk every screen with the Tab key.
#
#   npm run a11y:keyboard
#
# The companion to bin/a11y.sh. pa11y reads the document; this drives it. They
# find different things: the run above would not notice a control that takes
# focus while invisible, and this would not notice a contrast ratio.
#
# It reuses bin/a11y.sh's session and URL list rather than keeping a second
# copy, because two lists of screens drift and the one nobody looks at is the
# one that goes stale.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="${ROOT}/.pa11yci.json"
VALUES="${ROOT}/tests/a11y/urls.generated.json"

SITE="${RECOVERYFLOW_A11Y_URL:-http://localhost:8901}"
USER="${RECOVERYFLOW_A11Y_USER:-admin}"
PASS="${RECOVERYFLOW_A11Y_PASS:-password}"
SEED="${RECOVERYFLOW_A11Y_SEED-npm run --silent a11y:seed}"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

if [[ -n "${SEED}" ]]; then
	echo "bin/keyboard.sh: seeding (${SEED})"

	if ! ${SEED} >/dev/null 2>&1; then
		echo "bin/keyboard.sh: the seed command failed: ${SEED}" >&2
		exit 1
	fi
fi

if [[ ! -f "${VALUES}" ]]; then
	echo "bin/keyboard.sh: ${VALUES} does not exist. Run npm run a11y:seed." >&2
	exit 1
fi

JAR="${WORK}/cookies.txt"

curl -sS -c "${JAR}" -o /dev/null \
	-X POST "${SITE}/wp-login.php" \
	--data-urlencode "log=${USER}" \
	--data-urlencode "pwd=${PASS}" \
	--data-urlencode 'wp-submit=Log In' \
	--data-urlencode 'testcookie=1' || true

if ! grep -q 'wordpress_logged_in' "${JAR}" 2>/dev/null; then
	echo "bin/keyboard.sh: could not log in to ${SITE} as ${USER}." >&2
	exit 1
fi

COOKIE="$(awk -F'\t' 'NF==7 {printf "%s=%s; ", $6, $7}' "${JAR}")"

# The URL list, with the seeded ids filled in. The opt-out entry that exists
# only to press a button is left out: this pass never activates anything, so
# checking it twice would walk the same page twice.
URLS="$(php -r '
	$config = json_decode( (string) file_get_contents( $argv[1] ), true );
	$values = json_decode( (string) file_get_contents( $argv[2] ), true );

	$search  = array();
	$replace = array();

	foreach ( (array) $values as $name => $value ) {
		$search[]  = "{{" . $name . "}}";
		$replace[] = (string) $value;
	}

	$urls = array();

	foreach ( (array) ( $config["urls"] ?? array() ) as $entry ) {
		if ( array() !== (array) ( $entry["actions"] ?? array() ) ) {
			continue;
		}

		$url = str_replace( $search, $replace, (string) ( $entry["url"] ?? "" ) );

		if ( "" !== $url && false === strpos( $url, "{{" ) ) {
			$urls[] = $url;
		}
	}

	echo json_encode( $urls );
' "${CONFIG}" "${VALUES}")"

echo
echo "bin/keyboard.sh: walking each screen with Tab, as ${USER} on ${SITE}."
echo

RECOVERYFLOW_A11Y_COOKIE="${COOKIE}" \
RECOVERYFLOW_A11Y_URLS="${URLS}" \
	node "${ROOT}/tests/a11y/keyboard.js"
