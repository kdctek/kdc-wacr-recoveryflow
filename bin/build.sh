#!/usr/bin/env bash
#
# Build the distributable zip.
#
#   bin/build.sh          write dist/kdc-wacr-recoveryflow-<version>.zip
#   bin/build.sh --check  build into a temporary directory and throw it away
#   bin/build.sh v0.1.2   zip that ref's tree instead of HEAD's
#
# A ref other than HEAD is how an already-tagged release gets the zip that
# belongs to it -- the one a GitHub release attaches. The version then comes
# out of the ref's own plugin header, never the working tree's, so zipping an
# old tag cannot produce a file named for today's version.
#
# The zip contains ONE top-level directory, kdc-wacr-recoveryflow/, because that
# is what WordPress unpacks into wp-content/plugins and it must match the text
# domain and the WordPress.org slug. A zip whose folder is named anything else
# installs a plugin whose translations never load, and nothing says so.
#
# What goes in is decided by .distignore and by nothing else. In particular this
# script does NOT keep its own list of excluded paths: two lists disagree
# eventually, and the one nobody checks is the one that ships the tests
# directory or drops the assets directory. tests/dist-manifest.php asserts the
# result against what the plugin actually needs at runtime, so a new top-level
# directory cannot be forgotten silently -- which has nearly happened here once
# already, when assets/ was added.
#
# It builds from `git archive` rather than from the working tree, so an
# uncommitted experiment, a stray .orig from a merge, or a debugging file cannot
# reach a customer. That also means the zip is exactly reproducible from a
# commit.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="kdc-wacr-recoveryflow"

CHECK_ONLY=0
if [[ "${1:-}" == "--check" ]]; then
	CHECK_ONLY=1
	shift
fi

REF="${1:-HEAD}"

git -C "${ROOT}" rev-parse --verify --quiet "${REF}^{commit}" >/dev/null || {
	echo "build: ${REF} is not a commit in this repository." >&2
	exit 1
}

command -v zip >/dev/null 2>&1 || {
	echo "build: the 'zip' command is required and was not found." >&2
	exit 1
}

STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

# git archive takes the committed tree. A dirty working tree is a warning
# rather than a refusal: building a release from one is nearly always a
# mistake, but so is a build script that will not run until you commit. It is
# only worth saying when the tree is what is being built.
if [[ "${REF}" == "HEAD" && -n "$(git -C "${ROOT}" status --porcelain)" ]]; then
	echo "build: WARNING -- the working tree has uncommitted changes, which will NOT be in the zip." >&2
fi

git -C "${ROOT}" archive --format=tar "${REF}" | ( cd "${STAGE}" && tar -xf - )

# The version comes from the plugin header of the tree that was just extracted,
# which is the one WordPress reads. Everything else that carries a version is
# checked against it by tests/dist-manifest.php rather than being read here, so
# this script cannot paper over a disagreement by preferring one source.
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*\(.*\)$/\1/p' "${STAGE}/${SLUG}.php" | head -n1 | tr -d '[:space:]')"

if [[ -z "${VERSION}" ]]; then
	echo "build: no Version found in ${SLUG}.php at ${REF}." >&2
	exit 1
fi

# Now apply .distignore to what came out. Every pattern is matched against the
# path relative to the plugin root, exactly as the file lists them.
while IFS= read -r pattern; do
	# Blank lines and comments.
	[[ -z "${pattern}" || "${pattern}" == \#* ]] && continue

	# A trailing slash means the directory itself.
	pattern="${pattern%/}"

	if [[ "${pattern}" == *"*"* ]]; then
		find "${STAGE}" -name "${pattern}" -print0 | xargs -0 rm -rf --
	else
		rm -rf -- "${STAGE:?}/${pattern}"
	fi
done < "${ROOT}/.distignore"

# One top-level directory, named for the slug.
BUILD="$(mktemp -d)"
trap 'rm -rf "${STAGE}" "${BUILD}"' EXIT

mkdir -p "${BUILD}/${SLUG}"
( cd "${STAGE}" && tar -cf - . ) | ( cd "${BUILD}/${SLUG}" && tar -xf - )

if [[ "${CHECK_ONLY}" -eq 1 ]]; then
	( cd "${BUILD}" && find "${SLUG}" -type f | sort )
	exit 0
fi

mkdir -p "${ROOT}/dist"
ZIP="${ROOT}/dist/${SLUG}-${VERSION}.zip"
rm -f "${ZIP}"

( cd "${BUILD}" && zip -qr "${ZIP}" "${SLUG}" -x '.*' )

echo "Wrote ${ZIP}"
echo "  version ${VERSION}, $( cd "${BUILD}" && find "${SLUG}" -type f | wc -l | tr -d ' ' ) files, $( du -h "${ZIP}" | cut -f1 ) on disk"
