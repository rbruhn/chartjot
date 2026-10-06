#!/usr/bin/env bash
# Attaches the NT8 AddOn package to a GitHub Release (#88).
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
cd "$REPO_DIR"

git -c core.fileMode=false fetch --quiet --tags origin
if ! git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "release.sh: no tag $TAG (has the deploy workflow created the release yet?)" >&2
    exit 1
fi
if [ "$(git rev-parse HEAD)" != "$(git rev-parse "$TAG^{commit}")" ]; then
    echo "release.sh: HEAD is not $TAG. Check it out first: git checkout $TAG" >&2
    exit 1
fi
if [ -n "$(git -c core.fileMode=false status --porcelain)" ]; then
    echo "release.sh: the checkout has local changes; the zip must match the release exactly" >&2
    exit 1
fi

PREVIOUS="$(git describe --tags --abbrev=0 "$TAG^" 2>/dev/null || true)"
if [ -n "$PREVIOUS" ] && [ "$FORCE" != "--force" ] \
    && git diff --quiet "$PREVIOUS" "$TAG" -- addon/core addon/nt8; then
    echo "release.sh: the AddOn didn't change since $PREVIOUS; nothing to attach (use --force to attach anyway)"
    exit 0
fi

ZIP="$(bash "$ADDON_DIR/package.sh" | tail -n 1)"
"$GH" release upload "$TAG" "$ZIP" --clobber
echo "Attached $(basename "$ZIP") to release $TAG"
