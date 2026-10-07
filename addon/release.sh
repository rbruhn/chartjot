#!/usr/bin/env bash
# Attaches the NT8 AddOn package to a GitHub Release (#88), and keeps the fixed "addon" release current (#94).
#
# The deploy workflow creates the release when development is merged to main, but GitHub's runners can't build
# ChartJot.dll: it compiles against NinjaTrader's own DLLs, which only a PC with NinjaTrader 8 has. So this runs
# here, from WSL, after the release exists:
#
#   addon/release.sh 0.0.18           build and attach ChartJot-AddOn-<AddonVersion>.zip
#   addon/release.sh 0.0.18 --force   attach it even if the AddOn didn't change since the previous release
#
# It refuses unless the checkout is exactly the release's commit with no local changes, so the zip always matches
# the release.
#
# Besides the app release, the zip goes to the fixed release tagged "addon"
# (https://github.com/rbruhn/chartjot/releases/tag/addon). That page always holds the current AddOn, so app-only
# releases never hide it, and it is never marked "Latest". Its tag moves to the release's commit, older zips are
# removed, and its notes are rewritten.
set -euo pipefail

TAG="${1:-}"
FORCE="${2:-}"
if [ -z "$TAG" ]; then
    echo "usage: addon/release.sh <release-tag> [--force]" >&2
    exit 2
fi

ADDON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$ADDON_DIR/.." && pwd)"
GH="${GH:-gh}"
ADDON_TAG="addon"
cd "$REPO_DIR"

git -c core.fileMode=false fetch --quiet --tags --force origin
if ! git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "release.sh: no tag $TAG (has the deploy workflow created the release yet?)" >&2
    exit 1
fi
COMMIT="$(git rev-parse "$TAG^{commit}")"
if [ "$(git rev-parse HEAD)" != "$COMMIT" ]; then
    echo "release.sh: HEAD is not $TAG. Check it out first: git checkout $TAG" >&2
    exit 1
fi
if [ -n "$(git -c core.fileMode=false status --porcelain)" ]; then
    echo "release.sh: the checkout has local changes; the zip must match the release exactly" >&2
    exit 1
fi

# The previous app release: version tags only, so the fixed "addon" tag never counts.
PREVIOUS="$(git describe --tags --abbrev=0 --match '[0-9]*.[0-9]*.[0-9]*' "$TAG^" 2>/dev/null || true)"
if [ -n "$PREVIOUS" ] && [ "$FORCE" != "--force" ] \
    && git diff --quiet "$PREVIOUS" "$TAG" -- addon/core addon/nt8; then
    echo "release.sh: the AddOn didn't change since $PREVIOUS; nothing to attach (use --force to attach anyway)"
    exit 0
fi

ZIP="$(bash "$ADDON_DIR/package.sh" | tail -n 1)"
ZIP_NAME="$(basename "$ZIP")"
"$GH" release upload "$TAG" "$ZIP" --clobber
echo "Attached $ZIP_NAME to release $TAG"

# ---- the fixed "addon" release ------------------------------------------------------------------------------------
VERSION="${ZIP_NAME#ChartJot-AddOn-}"
VERSION="${VERSION%.zip}"
NOTES="$(mktemp)"
trap 'rm -f "$NOTES"' EXIT
cat > "$NOTES" <<EOF
The current Chart Jot AddOn for NinjaTrader 8: **version $VERSION**, from release [$TAG](https://github.com/rbruhn/chartjot/releases/tag/$TAG). This page always holds the latest AddOn. It's replaced whenever a new version is released.

**Install or update:** download \`$ZIP_NAME\` below. In NinjaTrader's Control Center, go to **Tools → Import → NinjaScript…**, pick the zip and click **Import**. Then reopen your charts, so they get the Chart Jot button.

**Uninstall:** **Tools → Remove NinjaScript Assembly → ChartJot**, then restart NinjaTrader.

Setup, settings and the full guide: [README, "NT8 AddOn"](https://github.com/rbruhn/chartjot#nt8-addon).
EOF

git tag -f "$ADDON_TAG" "$COMMIT" >/dev/null
git push --quiet --force origin "refs/tags/$ADDON_TAG"

if "$GH" release view "$ADDON_TAG" >/dev/null 2>&1; then
    "$GH" release edit "$ADDON_TAG" --title "Chart Jot NT8 AddOn" --notes-file "$NOTES" --latest=false
    # Upload the new zip before removing the old ones, so the page is never empty.
    "$GH" release upload "$ADDON_TAG" "$ZIP" --clobber
    "$GH" release view "$ADDON_TAG" --json assets -q '.assets[].name' | while read -r asset; do
        if [ -n "$asset" ] && [ "$asset" != "$ZIP_NAME" ]; then
            "$GH" release delete-asset "$ADDON_TAG" "$asset" --yes
        fi
    done
else
    "$GH" release create "$ADDON_TAG" "$ZIP" --verify-tag --title "Chart Jot NT8 AddOn" --notes-file "$NOTES" --latest=false
fi
echo "Updated the fixed AddOn release: https://github.com/rbruhn/chartjot/releases/tag/$ADDON_TAG"
