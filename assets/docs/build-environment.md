# Build environment

You need a Linux or macOS system. WSL2 on Windows also works; it's just a Linux virtual machine.

> [!IMPORTANT]
> Building the macOS DMG distributable requires macOS. You cannot do that from Linux.

You need the following directory structure:

- `buildfiles` [Akeeba Build Tools — Public Packager](https://github.com/akeeba/buildfiles-public). Follow its README instructions to initialise it!
- `grafida` [Grafida desktop](https://github.com/grafida/grafida)
- `grafida-ipad` [Grafida for iPad and iPhone](https://github.com/grafida/grafida-ipad)
- `grafida-tauri` [Grafida for Android](https://github.com/grafida/grafida-tauri)

> [!NOTE]
> While each Grafida implementation can compile on its own, there are cross-repository references you might need for development purposes.

The following software must be present and available:
- **Bash**. Used for build scripts.
- **PHP** CLI. See the `composer.json` file for the acceptable version range.
- **Composer**. Dependency management.
- **Phing**. Follow the Build Tools repo's instructions to install it account-wide.
- **Node.js 16+ and npm 8.5.5+** — composer install automatically runs npm install to vendor and minify the frontend assets
- **curl**. Downloads the patched Boson SFX runtimes.
- **unzip** — extracts the Visual C++ runtime bundled with Windows packages.
- **Standard Unix utilities** — notably tar, gzip, shasum, awk, sed, grep, head, tail, find, and BSD stat (the latter on macOS; built-in).

The following software is required on macOS to build DMGs:
- **Apple Command Line Tools** providing codesign, otool, iconutil, tiffutil, xcrun notarytool, and stapler. macOS itself provides hdiutil and osascript.

The following software is required to build Windows installers:
- **NSIS** (makensis). Without it, the build falls back to a portable ZIP, in which case zip is required instead.

The following software is required to build Linux .tar.gz packages:
- **tar**

Conditional tools, only relevant to the maintainers / release managers:

- **librsvg** (rsvg-convert) and **ImageMagick** (magick/convert). Only needed to regenerate icons, or the DMG background.
- **Azure CLI** (az), **Jsign**, and **1Password CLI** (op). Required only when Windows Authenticode signing is enabled. Jsign also needs a Java runtime, normally installed as its package dependency. osslsigncode is optional, used only for informational verification.
- **OpenSSH** (ssh and sftp), required only for the separate documentation-publishing command, not for a software release. 

## Setting up the build environment on a Mac

```bash
xcode-select --install
brew install node librsvg imagemagick makensis azure-cli jsign 1password-cli

mkdir -p ~/Projects/grafida || exit 1
cd ~/Projects/grafida || exit 1
git checkout https://github.com/grafida/grafida.git
git checkout https://github.com/grafida/grafida-ipad.git
git checkout https://github.com/grafida/grafida-tauri.git
```
