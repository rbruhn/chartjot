#!/usr/bin/env bash
# Builds the NT8 AddOn as a NinjaScript archive, the way vendors ship add-ons (#88):
#
#   ChartJot.dll   the AddOn (addon/nt8/ChartJot.cs) and addon/core compiled into one assembly
#   Info.xml       NinjaTrader's export header
#
# Traders install it with Tools > Import > NinjaScript and remove it with
# Tools > Remove NinjaScript Assembly > ChartJot.
#
# ChartJot.dll compiles against NinjaTrader's own DLLs and the real .NET Framework runtime assemblies, so this
# runs on a Windows PC with NinjaTrader 8 installed, from WSL (see addon/nt8/ChartJot.NT8.csproj). It then checks
# the DLL with Windows' own .NET: every framework reference must be version 4.0.0.0 (what NinjaScript compiles
# against; 4.2.0.0 breaks every script's compile with CS1705) and nothing may reference netstandard.
#
# Usage: addon/package.sh [output-dir]   (default: addon/dist)
set -euo pipefail

ADDON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="${1:-$ADDON_DIR/dist}"
DOTNET="${DOTNET:-dotnet}"

VERSION="$(sed -nE 's/.*public const string AddonVersion = "([^"]+)";.*/\1/p' "$ADDON_DIR/nt8/ChartJot.cs")"
if [ -z "$VERSION" ]; then
    echo "package.sh: AddonVersion not found in addon/nt8/ChartJot.cs" >&2
    exit 1
fi

BUILD_DIR="$ADDON_DIR/nt8/bin/package"
rm -rf "$BUILD_DIR"
"$DOTNET" build "$ADDON_DIR/nt8/ChartJot.NT8.csproj" -c Release --nologo -v:q -clp:ErrorsOnly \
    -p:AddonVersion="$VERSION" -o "$BUILD_DIR" >&2
DLL="$BUILD_DIR/ChartJot.dll"

# Check the DLL with Windows' .NET Framework, the runtime NinjaTrader uses.
WIN_DLL="$(wslpath -w "$DLL")"
powershell.exe -NoProfile -NonInteractive -Command "
    \$a = [Reflection.Assembly]::ReflectionOnlyLoad([IO.File]::ReadAllBytes('$WIN_DLL'))
    \$bad = \$a.GetReferencedAssemblies() | Where-Object {
        \$_.Name -eq 'netstandard' -or (\$_.Name -notlike 'NinjaTrader.*' -and \$_.Version -gt [Version]'4.0.0.0') }
    if (\$bad) { \$bad | ForEach-Object { [Console]::Error.WriteLine('package.sh: ChartJot.dll references ' + \$_.Name + ' ' + \$_.Version) }; exit 1 }
    if (\$a.GetName().Version -ne [Version]'$VERSION.0') { [Console]::Error.WriteLine('package.sh: ChartJot.dll version is ' + \$a.GetName().Version); exit 1 }
" | tr -d '\r' >&2

mkdir -p "$OUT_DIR"
ZIP="$OUT_DIR/ChartJot-AddOn-$VERSION.zip"
rm -f "$ZIP"

python3 - "$ZIP" "$DLL" <<'PY'
import sys, time, zipfile

zip_path, dll = sys.argv[1:3]

# As NinjaTrader 8.1.8.3 writes Info.xml in an export: a BOM and CRLF line endings. Agile is the
# copy-protection version; Chart Jot isn't protected.
info = ('﻿<?xml version="1.0" encoding="utf-8"?>\r\n'
        '<NinjaTrader>\r\n'
        '  <Export>\r\n'
        '    <Version>8.1.8.3</Version>\r\n'
        '    <Agile>None</Agile>\r\n'
        '  </Export>\r\n'
        '</NinjaTrader>').encode('utf-8')

def entry(name):
    zi = zipfile.ZipInfo(name, time.localtime()[:6])
    zi.compress_type = zipfile.ZIP_DEFLATED
    zi.create_system = 0  # MS-DOS/NT, as NinjaTrader writes it
    return zi

with zipfile.ZipFile(zip_path, 'w') as z:
    z.writestr(entry('Info.xml'), info)
    with open(dll, 'rb') as f:
        z.writestr(entry('ChartJot.dll'), f.read())

with zipfile.ZipFile(zip_path) as z:
    if sorted(z.namelist()) != ['ChartJot.dll', 'Info.xml']:
        sys.exit('package.sh: unexpected entries %r' % z.namelist())
PY

echo "$ZIP"
