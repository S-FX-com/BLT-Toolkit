#!/usr/bin/env bash
# Verify a built distribution zip. Usage: verify-zip.sh <version>
# Fails when the zip has more than one top-level folder, when the vendored
# update checker or the family layer is missing, when anything .distignore
# lists (or a dev file named below) ships, or when the packaged header,
# constant or readme carry another version.
set -euo pipefail

VERSION="${1:?usage: verify-zip.sh <version>}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
ZIP="$ROOT/build/blt-toolkit-${VERSION}.zip"

if [ ! -f "$ZIP" ]; then
	echo "Zip not found: ${ZIP}" >&2
	exit 1
fi

ENTRIES="$(unzip -Z1 "$ZIP")"

fail() {
	echo "$1" >&2
	exit 1
}

has() {
	printf '%s\n' "$ENTRIES" | grep -qx -- "$1"
}

# Single stable top-level folder, main file at its root.
if printf '%s\n' "$ENTRIES" | grep -v '^blt-toolkit/' | grep -q .; then
	fail "Zip has entries outside blt-toolkit/"
fi

# Files the installed plugin needs: the main file, the update checker and
# its internal vendor dir (Parsedown renders the changelog), the family
# layer, the design system and the icons the updater shows.
for required in \
	blt-toolkit/blt-toolkit.php \
	blt-toolkit/readme.txt \
	blt-toolkit/LICENSE \
	blt-toolkit/includes/lib/plugin-update-checker/plugin-update-checker.php \
	blt-toolkit/includes/lib/plugin-update-checker/Puc/v5/PucFactory.php \
	blt-toolkit/includes/lib/plugin-update-checker/vendor/Parsedown.php \
	blt-toolkit/includes/blt-family/bootstrap.php \
	blt-toolkit/includes/blt-family/loader.php \
	blt-toolkit/assets/css/blt-design-system.css \
	blt-toolkit/assets/img/blt-mark.svg \
	blt-toolkit/assets/img/icon-128x128.png \
	blt-toolkit/assets/img/icon-256x256.png \
	blt-toolkit/languages/blt-toolkit.pot; do
	has "$required" || fail "Missing from zip: ${required}"
done

# Dev-only folders and files must not ship. This list is a backstop for
# .distignore: deleting a line there must not quietly ship a dev file.
if printf '%s\n' "$ENTRIES" | grep -qE '^blt-toolkit/(tests|tasks|vendor|bin|build|worker|node_modules|\.github|\.git|\.claude)/'; then
	fail "Dev-only folder found in zip"
fi
if printf '%s\n' "$ENTRIES" | grep -qE '^blt-toolkit/(composer\.json|composer\.lock|phpcs\.xml\.dist|phpunit\.xml\.dist|\.distignore|\.gitignore|CLAUDE\.md|AGENTS\.md|SPEC\.md|DESIGN\.md|CONVENTIONS\.md|HOOKS\.md|README\.md|CHANGELOG\.md|blt-family-smoke\.php)$'; then
	fail "Dev-only file found in zip"
fi
if printf '%s\n' "$ENTRIES" | grep -qE '(^|/)(\.DS_Store|[^/]*\.(log|bak|orig|swp)|[^/]*~)$'; then
	fail "Stray .DS_Store, log or editor backup file found in zip"
fi

# Everything .distignore anchors at the root must be absent too.
while IFS= read -r line || [ -n "$line" ]; do
	line="${line%$'\r'}"
	line="${line%/}"
	case "$line" in
		/*)
			path="blt-toolkit${line}"
			# Exact entry, or anything below it (fixed-string prefix match).
			if printf '%s\n' "$ENTRIES" | awk -v p="$path" 'BEGIN { f = 0 } $0 == p || index( $0, p "/" ) == 1 { f = 1 } END { exit f ? 0 : 1 }'; then
				fail "Zip ships ${line#/}, which .distignore excludes"
			fi
			;;
	esac
done < "$ROOT/.distignore"

# The packaged header, constant and stable tag must carry the released version.
unzip -p "$ZIP" blt-toolkit/blt-toolkit.php | grep -q "^ \* Version:[[:space:]]*${VERSION}\$" \
	|| fail "Packaged header is not ${VERSION}"
unzip -p "$ZIP" blt-toolkit/blt-toolkit.php | grep -q "define( 'BLT_TOOLKIT_VERSION', '${VERSION}' );" \
	|| fail "Packaged BLT_TOOLKIT_VERSION is not ${VERSION}"
unzip -p "$ZIP" blt-toolkit/readme.txt | grep -q "^Stable tag:[[:space:]]*${VERSION}\$" \
	|| fail "Packaged readme.txt Stable tag is not ${VERSION}"

echo "Zip OK: ${ZIP} ($(printf '%s\n' "$ENTRIES" | grep -vc '/$') files)"
