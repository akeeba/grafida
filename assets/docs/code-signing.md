# Code signing

- **macOS** — releases are **signed with a Developer ID and notarised by Apple** (builds made
  with the patched SFX runtime; see
  [`build/readme/01-macos-signing.md`](build/readme/01-macos-signing.md)). The app opens like
  any other downloaded application. If you run an *older, unsigned* release, right-click
  **Grafida.app** in Finder and choose **Open**, then confirm — or clear the quarantine flag
  with `xattr -dr com.apple.quarantine /Applications/Grafida.app` if macOS reports it as
  “damaged”.
- **Windows** — releases are **Authenticode-signed** with Azure Artifact Signing (via
  [Jsign](https://ebourg.github.io/jsign/); see
  [`build/readme/04-exe-signing-on-macos.md`](build/readme/04-exe-signing-on-macos.md)). Signing
  does not eliminate SmartScreen's "Windows protected your PC" warning for a small install base —
  reputation accumulates with download volume over time — but it shows our publisher name instead
  of "Unknown Publisher" and improves behaviour with enterprise AV/EDR and AppLocker. Click **More
  info → Run anyway** if you still see the warning.
- **Linux** — no signing is involved; nothing extra is required.

The code signing certificates belong to my company, Akeeba Ltd. The software is developed and maintained by me personally; the company signs the build artefacts on my behalf. The simple reality is that code signing appears to be unavailable for natural persons. It requires a company or sole proprietorship. Instead of releasing unsigned binaries, I chose to have my company sign them on my behalf – essentially having the company I own vouch for my identity and good intentions, which I think is more than fair.
