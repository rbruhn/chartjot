#!/usr/bin/env bash
# Builds the NT8 AddOn as a NinjaScript archive that NinjaTrader imports with
# Tools > Import > NinjaScript (#88):
#
#   AddOns\ChartJot.cs          the AddOn source, compiled by NinjaTrader on import
#   ChartJot.Core.dll           addon/core built for .NET Framework 4.6.2: no netstandard reference, System.Net.Http 4.0.0.0
#   AdditionalReferences.txt    tells NinjaTrader to reference ChartJot.Core.dll
#   Info.xml                    NinjaTrader's export header
#
# NinjaTrader's own source export records the ChartJot.Core reference but leaves the DLL
# out, so this script builds the archive itself, in the same layout and encoding.
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

"$DOTNET" build "$ADDON_DIR/core" -c Release -f net462 --nologo -v:q -o "$ADDON_DIR/core/bin/package"
DLL="$ADDON_DIR/core/bin/package/ChartJot.Core.dll"

mkdir -p "$OUT_DIR"
ZIP="$OUT_DIR/ChartJot-AddOn-$VERSION.zip"
rm -f "$ZIP"

python3 - "$ZIP" "$ADDON_DIR/nt8/ChartJot.cs" "$DLL" <<'PY'
import sys, time, zipfile

zip_path, source, dll = sys.argv[1:4]

# Byte for byte what NinjaTrader 8.1.8.3 writes in an export: Info.xml has a BOM and CRLF line endings,
# AdditionalReferences.txt has CRLF and no BOM, and entry names use backslashes.
info = ('﻿<?xml version="1.0" encoding="utf-8"?>\r\n'
        '<NinjaTrader>\r\n'
        '  <Export>\r\n'
        '    <Version>8.1.8.3</Version>\r\n'
        '    <Agile>None</Agile>\r\n'
        '  </Export>\r\n'
        '</NinjaTrader>').encode('utf-8')
references = '*MyDocuments*\\NinjaTrader 8\\bin\\Custom\\ChartJot.Core.dll\r\n'.encode('utf-8')

def entry(name):
    zi = zipfile.ZipInfo(name, time.localtime()[:6])
    zi.compress_type = zipfile.ZIP_DEFLATED
    zi.create_system = 0  # MS-DOS/NT, as NinjaTrader writes it
    return zi

with zipfile.ZipFile(zip_path, 'w') as z:
    with open(source, 'rb') as f:
        z.writestr(entry('AddOns\\ChartJot.cs'), f.read())
    with open(dll, 'rb') as f:
        z.writestr(entry('ChartJot.Core.dll'), f.read())
    z.writestr(entry('AdditionalReferences.txt'), references)
    z.writestr(entry('Info.xml'), info)

# Check the result: exactly these entries, and the DLL is the .NET Framework 4.6.2 build.
with zipfile.ZipFile(zip_path) as z:
    names = sorted(z.namelist())
    expected = sorted(['AddOns\\ChartJot.cs', 'ChartJot.Core.dll', 'AdditionalReferences.txt', 'Info.xml'])
    if names != expected:
        sys.exit('package.sh: unexpected entries %r' % names)
    if b'.NETFramework,Version=v4.6.2' not in z.read('ChartJot.Core.dll'):
        sys.exit('package.sh: ChartJot.Core.dll is not the net462 build')
PY

echo "$ZIP"
