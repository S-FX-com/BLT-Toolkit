#!/usr/bin/env bash
# Build the distribution zip: one stable top-level blt-toolkit/ folder,
# trimmed by .distignore. Usage: build-zip.sh <version>
# Prints the zip path on the last line.
#
# CI runners have rsync. Where it is missing (some dev containers), a small
# fallback copies the tree and applies the two pattern shapes .distignore
# uses: "/path" anchored at the repo root, and a bare name or glob that
# matches at any depth. Any other shape fails the build rather than ship
# something .distignore meant to keep out.
set -euo pipefail

VERSION="${1:?usage: build-zip.sh <version>}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

DEST="build/blt-toolkit"

copy_without_rsync() {
	local anchored=( '/build' ) floating=( '.git' '.github' ) line entry p skip

	while IFS= read -r line || [ -n "$line" ]; do
		line="${line%$'\r'}"
		case "$line" in
			'' | '#'* | ';'*) continue ;;
		esac
		line="${line%/}"
		case "$line" in
			/*) anchored+=( "$line" ) ;;
			*/*)
				echo "build-zip.sh: install rsync, or anchor this .distignore pattern with a leading /: ${line}" >&2
				exit 1
				;;
			*) floating+=( "$line" ) ;;
		esac
	done < .distignore

	# Copy only the top-level entries no pattern names, so large excluded
	# folders (vendor, node_modules) are never copied in the first place.
	shopt -s dotglob nullglob
	for entry in *; do
		skip=0
		for p in "${anchored[@]}" "${floating[@]}"; do
			# Glob match is intended: the pattern is unquoted on purpose.
			if [[ "/${entry}" == ${p} || "${entry}" == ${p} ]]; then
				skip=1
				break
			fi
		done
		if [ "$skip" = 0 ]; then
			cp -R -p "$entry" "$DEST/"
		fi
	done
	shopt -u dotglob nullglob

	# Anchored patterns below the top level, then bare names at any depth.
	for p in "${anchored[@]}"; do
		case "${p#/}" in
			*/*)
				# Word splitting and globbing are intended here.
				# shellcheck disable=SC2086
				rm -rf "$DEST"/${p#/}
				;;
		esac
	done
	for p in "${floating[@]}"; do
		find "$DEST" -depth -name "$p" -exec rm -rf {} +
	done
}

rm -rf build
mkdir -p "$DEST"
if command -v rsync > /dev/null 2>&1; then
	rsync -a --exclude-from=.distignore --exclude=.git --exclude=.github --exclude=/build ./ "$DEST/"
else
	echo "rsync not found; using the built-in copy." >&2
	copy_without_rsync
fi
( cd build && zip -rq "blt-toolkit-${VERSION}.zip" blt-toolkit )
echo "build/blt-toolkit-${VERSION}.zip"
