#!/usr/bin/env bash
# Compile-check the NinjaScript sources against NT8's assemblies.
set -euo pipefail
export DOTNET_ROOT="${DOTNET_ROOT:-$HOME/.dotnet}"
export PATH="$DOTNET_ROOT:$PATH"
export DOTNET_CLI_TELEMETRY_OPTOUT=1
cd "$(dirname "$0")"
dotnet build NinjaScriptCheck.csproj -nologo -v q -clp:NoSummary "$@"

# NT8's older compiler misreads `x is T ? a : b` as the nullable type `T?`
# ("unexpected ("). Roslyn accepts it, so check for it explicitly.
if grep -nE '\bis [A-Za-z_][A-Za-z0-9_.]* \?' ../spike/*.cs; then
	echo "error: 'is Type ?' ternary above will not compile in NinjaTrader; use 'as' plus a null check" >&2
	exit 1
fi
# NinjaScript cannot reference Newtonsoft.Json on this install (its HintPath points
# at a missing file). Reflection by type-name string is fine.
if grep -nE 'Newtonsoft' ../spike/*.cs | grep -v '"Newtonsoft' | grep -v '^\S*:\s*//'; then
	echo "error: Newtonsoft.Json is not referenceable from NinjaScript; use System.Web.Script.Serialization.JavaScriptSerializer" >&2
	exit 1
fi
echo "OK: compiles, and no NinjaTrader-incompatible patterns found"
