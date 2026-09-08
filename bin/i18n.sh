#!/usr/bin/env bash
#
# Regenerate languages/kdc-wacr-recoveryflow.pot, or check that it is current.
#
#   bin/i18n.sh          rewrite the .pot from the source
#   bin/i18n.sh --check  fail if the .pot does not match the source
#
# The check is what CI runs. A .pot that has drifted from the source is not a
# harmless staleness: translators work from it, so every string added since the
# last regeneration is a string no locale can translate, and nothing in the
# plugin misbehaves to tell anyone.
#
# WP-CLI is fetched on demand rather than declared as a Composer dependency:
# the i18n command pulls the whole WP-CLI framework, which is a heavy thing to
# put in the dependency tree of a plugin that ships no vendor directory.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
POT="${ROOT}/languages/kdc-wacr-recoveryflow.pot"
CACHE="${ROOT}/.cache"
PHAR="${CACHE}/wp-cli.phar"
WP_CLI_VERSION="2.12.0"

mode="write"
if [[ "${1:-}" == "--check" ]]; then
	mode="check"
elif [[ $# -gt 0 ]]; then
	echo "usage: bin/i18n.sh [--check]" >&2
	exit 64
fi

if command -v wp >/dev/null 2>&1; then
	wp_cli=( wp )
else
	if [[ ! -f "${PHAR}" ]]; then
		mkdir -p "${CACHE}"
		echo "Fetching WP-CLI ${WP_CLI_VERSION}..."
		curl -fsSL -o "${PHAR}" \
			"https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
	fi
	# WP-CLI 2.12 still trips deprecation notices of its own on PHP 8.4+.
	wp_cli=( php -d error_reporting="E_ALL & ~E_DEPRECATED" "${PHAR}" )
fi

target="${POT}"
if [[ "${mode}" == "check" ]]; then
	# A full path template ending in X's is the one mktemp form that means the
	# same thing to BSD and GNU. BSD reads -t as a bare prefix and appends its
	# own randomness; GNU demands the X's and dies with "too few X's in
	# template". So "-t recoveryflow-pot" passes on a developer's macOS and
	# fails on CI's Linux -- the worst way round for a check whose entire
	# purpose is to run in CI.
	target="$(mktemp "${TMPDIR:-/tmp}/recoveryflow-pot.XXXXXX")"
	trap 'rm -f "${target}"' EXIT
fi

"${wp_cli[@]}" i18n make-pot "${ROOT}" "${target}" \
	--slug=kdc-wacr-recoveryflow \
	--domain=kdc-wacr-recoveryflow \
	--exclude=tests,docs,bin,.cache,node_modules,vendor \
	--headers='{"Report-Msgid-Bugs-To":"https://wa.cr/support"}' \
	--quiet

if [[ "${mode}" == "write" ]]; then
	echo "Wrote ${POT#"${ROOT}"/}"
	exit 0
fi

# POT-Creation-Date changes on every run and says nothing about the strings.
if diff -u \
	<(grep -v '^"POT-Creation-Date:' "${POT}") \
	<(grep -v '^"POT-Creation-Date:' "${target}") >/dev/null; then
	echo "languages/kdc-wacr-recoveryflow.pot is up to date."
	exit 0
fi

echo "languages/kdc-wacr-recoveryflow.pot is out of date. Run bin/i18n.sh and commit the result." >&2
diff -u \
	<(grep -v '^"POT-Creation-Date:' "${POT}") \
	<(grep -v '^"POT-Creation-Date:' "${target}") >&2 || true
exit 1
