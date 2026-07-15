# Windows Code Signing with Azure Artifact Signing on macOS

## Overview

Azure Artifact Signing (formerly "Azure Trusted Signing") is Microsoft's cloud-based code signing service. Private keys are stored in Azure's cloud HSM — no physical USB token required. Signing is done via [Jsign](https://ebourg.github.io/jsign/), a cross-platform Java tool that handles Windows Authenticode signing on macOS and Linux.

**Cost**: Basic tier is ~€9.99/month, covering 5,000 signatures — more than enough for small-volume releases.

**Important**: Azure Artifact Signing issues certificates with a 3-day validity window. This is by design. As long as you always include a timestamp when signing (covered below), the signature on the file remains valid indefinitely. Never skip the timestamp.

**SmartScreen caveat**: Signing does not eliminate SmartScreen warnings for new files. SmartScreen reputation accumulates based on download volume over time. For tools with a small install base, users will still need to click "More info → Run anyway". What signing does provide is that the warning shows your publisher name instead of "Unknown Publisher", and it improves behaviour with enterprise AV/EDR tools and Windows AppLocker policies.

---

## Local Prerequisites

Install via Homebrew:

```sh
brew install azure-cli jsign osslsigncode 1password-cli
```

Verify:

```sh
az --version
jsign --version
op --version
```

The 1Password CLI (`op`) must be signed in (`op signin`) and the service-principal secret from
Step 8 below must be saved as a 1Password item — the build reads `appId`/`password`/`tenant` from
it via `op item get`, rather than keeping them in plaintext in `build/build.properties`.

That's all you need locally.

---

## Azure Setup

### Step 1: Paid Azure Subscription

Azure Artifact Signing does not work on free, trial, or sponsored subscriptions. You need a pay-as-you-go or Enterprise Agreement subscription. Set one up at [portal.azure.com](https://portal.azure.com) if you don't already have one.

### Step 2: Register the CodeSigning Resource Provider

```sh
az login
az provider register --namespace Microsoft.CodeSigning

# Poll until it says "Registered" (takes 2–3 minutes)
az provider show --namespace Microsoft.CodeSigning --query registrationState -o tsv
```

### Step 3: Create a Resource Group

```sh
az group create --name AkeebaCodeSigning --location westeurope
```

Western Europe is the appropriate region for Cyprus/EU.

### Step 4: Create an Artifact Signing Account

```sh
az trustedsigning create \
  --name akeeba-signing \
  --resource-group AkeebaCodeSigning \
  --location westeurope \
  --sku Basic
```

### Step 5: Assign the Identity Verifier Role to Your Own Account

Before you can submit an identity validation request in the portal, your Azure user account needs the **Artifact Signing Identity Verifier** role. Run:

```sh
RESOURCE_ID=$(az trustedsigning show \
  --name akeeba-signing \
  --resource-group AkeebaCodeSigning \
  --query id -o tsv)

MY_OBJECT_ID=$(az ad signed-in-user show --query id -o tsv)

az role assignment create \
  --assignee $MY_OBJECT_ID \
  --role "Artifact Signing Identity Verifier" \
  --scope $RESOURCE_ID
```

Wait a minute or two for the role assignment to propagate before proceeding.

### Step 6: Submit Identity Validation

This is the only manual step and the slowest part. Microsoft needs to verify that Akeeba Ltd is a legitimate legal entity before issuing publicly trusted certificates. Expect a few days to two weeks.

In the Azure Portal ([portal.azure.com](https://portal.azure.com)):

1. Use the search bar at the top and search for **"Trusted Signing"**.
2. In the results, click **Artifact Signing Accounts** (under Services). Note: the portal uses the old name "Trusted Signing" in search but lists the resource as "Artifact Signing Accounts".
3. Click on **akeeba-signing**.
4. In the left menu, find **Identity validation** and click **New identity validation**.
5. Select **Organization**.
6. Fill in Akeeba Ltd's details: legal name, registration number, country (Cyprus), registered address, primary contact email, and website.
7. Submit and wait for the approval email.

Nothing in the steps below is possible until identity validation is approved.

### Step 7: Create a Certificate Profile

Once identity validation is approved, return to the **akeeba-signing** account in the portal:

1. In the left menu, click **Certificate profiles** → **New**.
2. Set the profile type to **Public Trust**. This is mandatory — "Private Trust" profiles are for internal enterprise apps only and will not produce publicly trusted signatures.
3. Name it `AkeebaPublic` (or similar — note the exact name).

### Step 8: Create a Service Principal for Non-Interactive Signing

```sh
az ad sp create-for-rbac --name "akeeba-codesigning" --output json
```

The output looks like:

```json
{
  "appId": "<client-id>",
  "password": "<client-secret>",
  "tenant": "<tenant-id>"
}
```

**Save all three values to 1Password immediately.** The `password` (client secret) is only shown once and cannot be retrieved again.

### Step 9: Assign Signing Role to the Service Principal

```sh
RESOURCE_ID=$(az trustedsigning show \
  --name akeeba-signing \
  --resource-group AkeebaCodeSigning \
  --query id -o tsv)

SP_OBJECT_ID=$(az ad sp show --id <appId> --query id -o tsv)

az role assignment create \
  --assignee $SP_OBJECT_ID \
  --role "Artifact Signing Certificate Profile Signer" \
  --scope $RESOURCE_ID
```

---

## Signing a Binary

### Authenticate

```sh
az login --service-principal \
  -u <appid> \
  -p <password> \
  --tenant <tenant-id>
```

Or, using 1Password, something like this:

```sh
az login --service-principal \
  -u "$(op item get 'Code signing secrets' --fields label=appId)" \
  -p "$(op item get 'Code signing secrets' --fields label=password --reveal)" \
  --tenant "$(op item get 'Code signing secrets' --fields label=tenant)"
```

### Sign

```sh
jsign \
  --storetype TRUSTEDSIGNING \
  --keystore weu.codesigning.azure.net \
  --storepass "$(az account get-access-token \
    --resource https://codesigning.azure.net \
    --query accessToken -o tsv)" \
  --alias akeeba-signing/AkeebaPublic \
  --tsaurl http://timestamp.acs.microsoft.com \
  --tsmode RFC3161 \
  grafida.exe
```

The `--tsaurl` argument is not optional. Without it, the signature expires when the 3-day certificate expires, making the signed file untrusted after that window.

### Verify the Signature

To confirm the signature was applied correctly:

```sh
osslsigncode verify grafida.exe
```

**This will report the signature as untrusted even when it is correct**, because the Microsoft
root certificate isn't in this machine's Keychain. Read the rest of `osslsigncode`'s output (the
signer, timestamp, and certificate chain details) to confirm the signature is actually there and
correct — don't trust the exit status. Because of this, **`osslsigncode verify`'s exit status must
never gate the build**; `scripts/sign-windows-exe.sh` runs it for information only and ignores its
result.

---

## Wiring into the build

`scripts/sign-windows-exe.sh <path-to-exe>` wraps the `az login` + `jsign` recipe above. It reads
its configuration from environment variables, which the Phing `package-win-x86` target forwards
from `build/build.properties` (gitignored — copy `build/build.sample.properties` first):

```properties
windows.sign.op-item=Code signing secrets
windows.sign.op-field-appid=appId
windows.sign.op-field-password=password
windows.sign.op-field-tenant=tenant
```

(`windows.sign.keystore` / `windows.sign.alias` / `windows.sign.tsaurl` also exist with sensible
defaults matching Steps 4/7/the timestamp URL above — override only if you named things
differently.) Leaving `windows.sign.op-item` blank (the default) leaves `grafida.exe` and the
installer unsigned — the same "blank disables it" convention as the macOS
`macos.sign.identity`/`macos.notary.profile` properties (see
[`01-macos-signing.md`](01-macos-signing.md)).

### You cannot sign the compiled `grafida.exe` directly — it must be split first

`boson compile` produces `grafida.exe` as a **phpmicro self-executable**: a PE stub with the
application PHAR appended after it. Authenticode writes its certificate into the PE's Attribute
Certificate Table, which the PE spec requires to be the **last** thing in the file — so Jsign
appends it *after* the PHAR. A PHAR stores its own signature as the last bytes of the file, and PHP
verifies it by seeking to EOF; once the certificate sits past the PHAR, `Phar::mapPhar('grafida.phar')`
reads the certificate where the PHAR trailer should be and the app **dies at startup with
"grafida.exe has a broken signature"**. (The Authenticode signature itself is valid — it hashes the
overlay too — but that does not help; nothing appendable can survive after a PHAR.)

This is the same trailing-data problem macOS has, and it has the same cure (see
[`02-signing-architecture.md`](02-signing-architecture.md)). When `grafida.exe` was compiled against
the patched sibling-payload SFX (`build/sfx/windows-x86_64.standard.sfx`, from the
`nikosdion/phpmicro` `sibling-phar` fork — fetched by `scripts/fetch-sfx.sh`),
`scripts/make-windows-installer.sh` **splits** it into a clean PE stub (`grafida.exe`) plus a sibling
`grafida.phar` — using the split offset from `build/tasks/pe-sfxsize.php` — exactly as
`make-macos-app.sh` splits the `.app`. The patched stub loads `grafida.phar` from its own directory
at run time.

`make-windows-installer.sh` therefore calls `sign-windows-exe.sh` on the **split stub** (never the
combined binary), then again on the finished `Setup.exe`. NSIS ships `grafida.phar` beside the stub
(guarded by the `HAVE_PHAR` define). As a tripwire, `sign-windows-exe.sh` runs `pe-sfxsize.php` and
**refuses** to sign any PE that still carries a PHAR overlay, so a combined binary can never be
signed by accident. If the patched SFX is absent, the build ships the **unsigned** combined
`grafida.exe` (which works); if signing is configured but the patched SFX is missing,
`make-windows-installer.sh` aborts with an explanation rather than producing a broken signed binary.

**Critical: signing must only run from packaging, never from a local compile.** Azure Artifact
Signing's Basic tier is capped at **5,000 signatures/month**; burning that quota on every local dev
build would be wasteful. `sign-windows-exe.sh` is only ever invoked from
`scripts/make-windows-installer.sh`, which is itself only reachable from the `package-win-x86` /
`package` Phing targets (and `scripts/build-all.sh`) — **never** from `git-win-x86` (the plain
compile used by `phing run`/`phing git`). Do not add a call to it anywhere in the `git-*` path.

For a one-off `scripts/build-all.sh` run outside Phing, export the same variables instead of using
`build.properties`:

```bash
export WINDOWS_SIGN_OP_ITEM="Code signing secrets"
```

---

## AV False Positives

Signing reduces but does not eliminate AV false positives. PHAR-based tooling in particular is routinely flagged by antivirus software regardless of signing status. Document this in your release notes and provide users with instructions for adding an exclusion if needed.
