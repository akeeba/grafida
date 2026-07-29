# Secrets security

Grafida holds two kinds of secret on your behalf: the **Joomla API token** for each connected site,
and the **API key** for each configured [AI service](AI-Services). Neither of them is kept in
Grafida's own database. This page explains where they go instead, and what that buys you.

## Why it matters

A Joomla API token is not a minor credential. It is your username and your password rolled into
one, it bypasses Multi-factor Authentication, and it does not expire on its own. Anyone holding it
can act on your site as you, from anywhere, until you go and revoke it.

An AI provider key is a billing instrument: whoever holds it can spend your money.

## Where secrets are kept

Grafida's database is an ordinary, unencrypted SQLite file — the path is shown on the
[Settings](Settings#local-storage) screen. Everything else about your work lives in it. The tokens
and keys do not.

Instead, each secret is handed to the **operating system's own secret store**, and the database
keeps nothing but an opaque **reference**: a short label such as `grafida-site-9f3a1c07b28d4e15` or
`grafida.ai_service.4`. The reference is a lookup key. It is not derived from the secret, it
contains no part of it, and it is worthless without the store it points into.

| Platform | Where the secret actually goes |
|---|---|
| macOS | The **login Keychain**, as a generic password under the service name `Grafida` |
| Linux | The **Secret Service** (GNOME Keyring, KWallet, …) through `libsecret` |
| Windows | Encrypted with **DPAPI** under your user account, the ciphertext written to a file under Grafida's application data folder |

On Windows there is no system-wide credential vault of the right shape, so Grafida encrypts the
secret with the Data Protection API using the **CurrentUser** scope and stores the resulting blob
itself. The encryption key is derived from your Windows account by the operating system; Grafida
never sees it and cannot write it down anywhere.

## What an attacker gets from a stolen database

Suppose the SQLite file leaves your machine — a backup that ended up somewhere public, a synced
folder, a stolen laptop drive, a support bundle sent to the wrong person.

**They do not get your API tokens or your AI keys.** Those are not in the file. What is in the file
is a reference string that resolves to nothing outside your user account on your machine.

Be clear about what they *do* get, because it is not nothing:

* the URL and title of every site you have connected, and the API base Grafida discovered for it;
* every local article — text, alias, category, tags, metadata — including anything you have not
  published;
* every picture you have pasted into an article and not yet published;
* the cached categories, tags, access levels, languages and custom fields of each site;
* every saved AI chat, which contains the article text it was about;
* each site's cached favicon.

So the file is worth protecting in its own right. What the OS secret store guarantees is that
losing it does not hand over the keys to your site.

> [!NOTE]
> The same reasoning applies to a `.grafida`
> [export](Editing-Articles#moving-an-article-between-computers). It carries the article, its
> pictures and its saved AI chats — but deliberately not the site it belonged to, and never a
> token or a key.

## What this does not protect you from

This is the honest part, and it matters more than the reassuring part.

> [!IMPORTANT]
> The OS secret store protects secrets **at rest, against a copy of your data leaving the
> machine**. It does not protect them against something running **as you, on your machine**.

Your keychain or keyring is unlocked for as long as you are logged in — that is what lets Grafida
read a token without asking you for a password every time you open an article. The same is true of
DPAPI on Windows: anything running under your account can ask the OS to decrypt what your account
encrypted.

The practical consequences:

* **Malware running under your user account can read the secrets.** No application-level storage
  scheme changes this; the program is, as far as the operating system is concerned, you.
* **A full-disk image plus your login password is enough** on any of the three platforms. Full-disk
  encryption (FileVault, LUKS, BitLocker) is what defends against a stolen drive; the secret store
  defends against a stray copy of one file.
* **Another admin on a shared machine** with the ability to run code as you is in the same position
  you are.

If any of that is in your threat model, the answer is at the operating-system level — full-disk
encryption, a locked screen, and not sharing an account — not inside Grafida.

## When no secret store is available

On some systems there is no store to use: a Linux box with no Secret Service running, typically a
minimal or headless desktop.

Grafida does **not** silently fall back. It refuses to save, and asks you to confirm explicitly
that you want to store the secret **as plain text in the SQLite database**. Only if you say yes
does it write the token to `sites.insecure_token`, or the AI key to `ai_services.insecure_key`.

> [!CAUTION]
> A token stored this way is in the database in the clear. Everything in the "stolen database"
> section above no longer applies to it: whoever has the file has your site. Say yes only on a
> machine whose disk you trust, and prefer installing a Secret Service provider — on GNOME
> `gnome-keyring`, on KDE `kwalletmanager` with its libsecret bridge — so the question stops being
> asked.

## Housekeeping

**Deleting a site** deletes its token from the OS secret store as well as its row from the
database. The same goes for deleting an AI service.

**Reset local storage** ([Settings](Settings#reset-local-storage)) deletes every site's stored
token from the OS secret store before wiping the database.

> [!NOTE]
> AI service keys are a known exception: **Reset local storage** clears the `ai_services` table but
> leaves those entries behind in your keychain, where they become orphans nothing refers to. They
> are harmless, but if you want them gone, delete each AI service from
> [Settings](Settings) *before* resetting, or remove the `grafida.ai_service.*` entries by hand with
> your platform's keychain tool.

**Backups.** Backing up the database backs up your work, not your credentials. Restoring it on
another machine — or on the same machine after a reinstall — means re-entering each site's API
token and each AI key. That is the storage design working as intended, not a fault.

## Secrets in diagnostics

Grafida's troubleshooting tools handle the same tokens, and they redact them.

The [Request Log](Request-Log) and **Diagnose Connection** (see
[Connection Troubleshooting](Connection-Troubleshooting)) both mask the token wherever it appears —
in the `Authorization` and `X-Joomla-Token` headers, and anywhere it turns up in a URL or a body —
down to its first four and last four characters. The masking happens on the single path every
record takes, so an exported file contains exactly what the screen showed; there is no separate,
laxer export route.

Requests to an AI provider are never recorded at all, so an AI key never reaches either tool.

## If a token is exposed

Revocation happens on your Joomla site, not in Grafida.

1. Log into the site and open your user profile's **Joomla API Token** tab (see
   [Connect a Site](Connect-a-Site)).
2. Set **Active** to No, save, then set it back to Yes and save again. This mints a **new** token
   and the old one stops working immediately.
3. Copy the new token and put it into Grafida from **Sites ▸ Edit**.

For an AI provider, revoke the key in that provider's own dashboard and paste a new one into the
service in [Settings](Settings).
