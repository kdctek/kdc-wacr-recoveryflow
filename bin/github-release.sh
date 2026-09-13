#!/usr/bin/env bash
#
# Publish a GitHub release for a plugin version.
#
#   bin/github-release.sh              the version in the plugin header
#   bin/github-release.sh 0.1.1 0.1.2  those versions, in the order given
#
# Every version of this plugin gets a GitHub release, because the CHANGELOG
# links each version heading at a release page and because the zip a merchant
# installs has to be downloadable from somewhere that is not a laptop. The
# notes are the CHANGELOG section for that version, verbatim -- there is no
# second description of a release to keep in step with the first -- and the
# asset is built with bin/build.sh from the tagged tree, so the file attached
# to v0.1.1 is the one v0.1.1 actually was.
#
# It is safe to re-run. A release that already exists is updated in place
# rather than duplicated, and the tag is only ever created at HEAD, never
# moved: a tag that already points somewhere is the record of what shipped.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="kdc-wacr-recoveryflow"
REPO="kdctek/kdc-wacr-recoveryflow"

command -v gh >/dev/null 2>&1 || {
	echo "release: the 'gh' command is required and was not found." >&2
	exit 1
}

# The plugin header is the one version WordPress reads, so it decides which of
# the versions asked for is the current one. Only that one is marked latest on
# GitHub; the rest are backfill, and a backfill that claims to be latest sends
# every updater and every reader at an old zip.
CURRENT="$(sed -n 's/^ \* Version:[[:space:]]*\(.*\)$/\1/p' "${ROOT}/${SLUG}.php" | head -n1 | tr -d '[:space:]')"

VERSIONS=( "$@" )
if [[ "${#VERSIONS[@]}" -eq 0 ]]; then
	VERSIONS=( "${CURRENT}" )
fi

NOTES="$(mktemp -d)"
trap 'rm -rf "${NOTES}"' EXIT

for VERSION in "${VERSIONS[@]}"; do
	VERSION="${VERSION#v}"
	TAG="v${VERSION}"

	# The notes are the CHANGELOG section, and nothing else is written by hand.
	# A version with no section is refused rather than released empty: the
	# entry is the only account anybody gets of what changed.
	BODY="${NOTES}/${TAG}.md"
	awk -v heading="## [${VERSION}]" '
		substr($0, 1, length(heading)) == heading { inside = 1; next }
		inside && substr($0, 1, 4) == "## [" { exit }
		inside && substr($0, 1, 1) == "[" && index($0, "]: ") { exit }
		inside { print }
	' "${ROOT}/CHANGELOG.md" | awk '
		{ line[NR] = $0; if (NF) { if (!first) first = NR; last = NR } }
		END { for (i = first; i <= last; i++) print line[i] }
	' > "${BODY}"

	if [[ ! -s "${BODY}" ]]; then
		echo "release: CHANGELOG.md has no '## [${VERSION}]' section, so there is nothing to publish." >&2
		exit 1
	fi

	DATE="$(sed -n "s/^## \[${VERSION}\] - \(.*\)$/\1/p" "${ROOT}/CHANGELOG.md" | head -n1)"

	cat >> "${BODY}" <<NOTE

---

\`${SLUG}-${VERSION}.zip\` below is the plugin exactly as WordPress unpacks it: download it and
install it under **Plugins > Add New > Upload Plugin**. The whole history is in
[CHANGELOG.md](https://github.com/${REPO}/blob/${TAG}/CHANGELOG.md).
NOTE

	# A tag that exists is the record of what shipped and is never moved. One
	# that does not is created at HEAD, and only if HEAD is really that
	# version and the tree is clean -- otherwise the tag would name a version
	# the files it points at do not carry.
	if git -C "${ROOT}" rev-parse --verify --quiet "refs/tags/${TAG}" >/dev/null; then
		echo "release: ${TAG} already exists at $(git -C "${ROOT}" rev-parse --short "${TAG}^{commit}")."
	else
		if [[ "${VERSION}" != "${CURRENT}" ]]; then
			echo "release: ${TAG} does not exist and ${VERSION} is not the version in the plugin header (${CURRENT}), so there is no commit to tag." >&2
			exit 1
		fi
		if [[ -n "$(git -C "${ROOT}" status --porcelain)" ]]; then
			echo "release: the working tree has uncommitted changes, so ${TAG} would not name what you tested." >&2
			exit 1
		fi
		git -C "${ROOT}" tag -a "${TAG}" -m "RecoveryFlow ${VERSION}${DATE:+ - ${DATE}}" HEAD
		echo "release: tagged ${TAG} at $(git -C "${ROOT}" rev-parse --short HEAD)."
	fi

	git -C "${ROOT}" push --quiet origin "refs/tags/${TAG}"

	# Built from the tag, not from the tree, so the asset is reproducible from
	# the commit it is attached to.
	"${ROOT}/bin/build.sh" "${TAG}" >/dev/null
	ZIP="${ROOT}/dist/${SLUG}-${VERSION}.zip"
	[[ -f "${ZIP}" ]] || {
		echo "release: ${ZIP} was not built." >&2
		exit 1
	}

	LATEST="false"
	[[ "${VERSION}" == "${CURRENT}" ]] && LATEST="true"

	if gh release view "${TAG}" --repo "${REPO}" >/dev/null 2>&1; then
		gh release edit "${TAG}" --repo "${REPO}" \
			--title "RecoveryFlow ${VERSION}" \
			--notes-file "${BODY}" \
			--latest="${LATEST}" >/dev/null
		gh release upload "${TAG}" "${ZIP}" --repo "${REPO}" --clobber >/dev/null
		echo "release: updated https://github.com/${REPO}/releases/tag/${TAG}"
	else
		gh release create "${TAG}" "${ZIP}" --repo "${REPO}" \
			--title "RecoveryFlow ${VERSION}" \
			--notes-file "${BODY}" \
			--verify-tag \
			--latest="${LATEST}" >/dev/null
		echo "release: published https://github.com/${REPO}/releases/tag/${TAG}"
	fi
done
