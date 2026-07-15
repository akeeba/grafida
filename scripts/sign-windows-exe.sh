#!/usr/bin/env bash
#
# Grafida — edit Joomla! articles on your desktop.
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later.
#
# Signs a Windows PE file (.exe) with Azure Artifact Signing, using Jsign as
# the Authenticode signer and the Azure CLI to obtain the access token. See
# build/readme/04-exe-signing-on-macos.md for the one-time Azure setup this
# depends on.
#
# Runs from macOS/Linux — no Windows host needed (Jsign is cross-platform).
#
# Usage:  scripts/sign-windows-exe.sh <path-to-exe>
#
# Configuration is via environment variables, all forwarded from build.xml
# properties (see build/build.sample.properties, "Windows code signing"):
#
#   WINDOWS_SIGN_OP_ITEM          1Password item holding the service principal
#                                  (appId/password/tenant fields). Empty (the
#                                  default) disables signing entirely — this
#                                  script is a no-op unless explicitly enabled.
#   WINDOWS_SIGN_OP_FIELD_APPID    1Password field name for the appId    (default: appId)
#   WINDOWS_SIGN_OP_FIELD_PASSWORD 1Password field name for the password (default: password)
#   WINDOWS_SIGN_OP_FIELD_TENANT   1Password field name for the tenant   (default: tenant)
#   WINDOWS_SIGN_KEYSTORE          Jsign --keystore                      (default: weu.codesigning.azure.net)
#   WINDOWS_SIGN_ALIAS             Jsign --alias                         (default: akeeba-signing/AkeebaPublic)
#   WINDOWS_SIGN_TSAURL            Jsign --tsaurl                        (default: http://timestamp.acs.microsoft.com)
#
# IMPORTANT: this script must only be invoked from packaging (package-win-x86 /
# scripts/build-all.sh), never from a local/dev compile (git-win-x86) — Azure
# Artifact Signing's Basic tier is capped at 5,000 signatures/month.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

EXE="${1:-}"
if [ -z "$EXE" ] || [ ! -f "$EXE" ]; then
  echo "Usage: scripts/sign-windows-exe.sh <path-to-exe>" >&2
  exit 1
fi

OP_ITEM="${WINDOWS_SIGN_OP_ITEM:-}"
if [ -z "$OP_ITEM" ]; then
  echo "Windows code signing not configured (windows.sign.op-item is empty) — leaving $EXE unsigned."
  exit 0
fi

# Safety net: NEVER Authenticode-sign a phpmicro combined binary (a PE stub with
# the application PHAR appended). Signing appends the certificate table past the
# PHAR and corrupts the PHAR's own trailing signature — the app then dies at
# startup on Phar::mapPhar ("grafida.exe has a broken signature"). pe-sfxsize.php
# exits 0 ONLY for such a binary (it verifies Boson's extra-ini magic sits at the
# SFX/payload boundary); a split stub or an NSIS installer (whose overlay is not a
# PHAR) makes it exit non-zero, so those sign normally. The correct fix for a
# combined binary is to split it first — scripts/make-windows-installer.sh does
# exactly that. See build/readme/02-signing-architecture.md.
if php "$ROOT/build/tasks/pe-sfxsize.php" "$EXE" >/dev/null 2>&1; then
  echo "ERROR: refusing to sign $EXE — it is a phpmicro combined binary carrying an" >&2
  echo "       appended PHAR. Signing would corrupt the PHAR's trailing signature and the" >&2
  echo "       app would fail to start (Phar::mapPhar). Split it into a clean stub + sibling" >&2
  echo "       grafida.phar first — scripts/make-windows-installer.sh handles this." >&2
  exit 1
fi

OP_FIELD_APPID="${WINDOWS_SIGN_OP_FIELD_APPID:-appId}"
OP_FIELD_PASSWORD="${WINDOWS_SIGN_OP_FIELD_PASSWORD:-password}"
OP_FIELD_TENANT="${WINDOWS_SIGN_OP_FIELD_TENANT:-tenant}"
KEYSTORE="${WINDOWS_SIGN_KEYSTORE:-weu.codesigning.azure.net}"
ALIAS="${WINDOWS_SIGN_ALIAS:-akeeba-signing/AkeebaPublic}"
TSAURL="${WINDOWS_SIGN_TSAURL:-http://timestamp.acs.microsoft.com}"

for bin in az jsign op; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "ERROR: '$bin' is required for Windows code signing but was not found on PATH." >&2
    echo "       Install with: brew install azure-cli jsign 1password-cli" >&2
    exit 1
  fi
done

echo "Signing $EXE (Azure Artifact Signing, 1Password item: $OP_ITEM)"

APP_ID="$(op item get "$OP_ITEM" --fields "label=$OP_FIELD_APPID")"
APP_SECRET="$(op item get "$OP_ITEM" --fields "label=$OP_FIELD_PASSWORD" --reveal)"
TENANT="$(op item get "$OP_ITEM" --fields "label=$OP_FIELD_TENANT")"

az login --service-principal -u "$APP_ID" -p "$APP_SECRET" --tenant "$TENANT" --output none

ACCESS_TOKEN="$(az account get-access-token --resource https://codesigning.azure.net --query accessToken -o tsv)"

jsign \
  --storetype TRUSTEDSIGNING \
  --keystore "$KEYSTORE" \
  --storepass "$ACCESS_TOKEN" \
  --alias "$ALIAS" \
  --tsaurl "$TSAURL" \
  --tsmode RFC3161 \
  "$EXE"

# Informational only: osslsigncode's exit status is unreliable here because we
# don't have the Microsoft root cert in the local Keychain, so it reports the
# chain as untrusted even for a correctly-signed file. Never gate the build on
# this — read the printed details instead.
if command -v osslsigncode >/dev/null 2>&1; then
  echo "--- osslsigncode verify (informational only — see build/readme/04-exe-signing-on-macos.md) ---"
  osslsigncode verify "$EXE" || true
  echo "--- end osslsigncode verify ---"
fi

echo "Signed: $EXE"
