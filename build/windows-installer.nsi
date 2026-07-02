; ============================================================================
; Grafida — Windows installer (NSIS)
; Edit Joomla! articles on your desktop.
;
; Copyright (c) 2026 Nicholas K. Dionysopoulos
; License: GNU General Public License version 3, or later
;
; The NSIS compiler, `makensis`, runs NATIVELY on macOS and Linux
; (brew install makensis), so this installer is produced from the same machine
; as the rest of the build — no Wine, Docker, or Windows host required.
;
;   makensis -DSRCDIR=build/windows/amd64 \
;            -DOUTFILE=build/dist/Grafida-<version>-windows-amd64-Setup.exe \
;            build/windows-installer.nsi
;
; scripts/build-all.sh invokes it automatically when `makensis` is on PATH.
;
; Source layout produced by `boson compile` (SRCDIR):
;   grafida.exe                    — main application binary
;   libboson-windows-x86_64.dll    — Boson WebView runtime DLL (must sit beside the .exe)
;   assets/                        — UI assets mounted at runtime (must sit beside the .exe)
;
; The installer is per-user (no admin needed): it lands in
; %LOCALAPPDATA%\Programs\Grafida.
; ============================================================================

Unicode true

; ---- Overridable defines (passed with -D… by build-all.sh) -----------------
; makensis changes its working directory to this script's folder (build/), so
; relative paths must be anchored with ${__FILEDIR__}. build-all.sh passes
; absolute SRCDIR/OUTFILE; these are sensible defaults for a direct invocation.
!ifndef SRCDIR
  !define SRCDIR "${__FILEDIR__}/windows/amd64"
!endif
!ifndef OUTFILE
  !define OUTFILE "${__FILEDIR__}/dist/Grafida-windows-amd64-Setup.exe"
!endif
!ifndef APPVERSION
  !define APPVERSION "0.1.0"
!endif
; VIProductVersion requires exactly four numeric components (X.X.X.X); build-all.sh
; computes this from APPVERSION via build/tasks/vi-version.php (see that script for
; the alpha/beta/rc/stable encoding of the fourth component).
!ifndef VIVERSION
  !define VIVERSION "${APPVERSION}.0"
!endif
!ifndef LICENSEFILE
  !define LICENSEFILE "${__FILEDIR__}/../LICENSE.txt"
!endif
; Application .ico. build-all.sh passes an absolute path via -D.
!ifndef ICONFILE
  !define ICONFILE "icon/Grafida.ico"
!endif

!define APPNAME    "Grafida"
!define PUBLISHER  "Nicholas K. Dionysopoulos / Akeeba Ltd"
!define APPURL     "https://github.com/akeeba/grafida"
!define APPEXE     "grafida.exe"
!define APPDLL     "libboson-windows-x86_64.dll"
!define APPICON    "Grafida.ico"
!define REGUNINST  "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APPNAME}"

; Bundle the .ico beside the .exe and point shortcuts / Add-Remove Programs at
; it so Grafida shows its real icon (the compiled .exe carries a generic one).
!define MUI_ICON   "${ICONFILE}"
!define MUI_UNICON "${ICONFILE}"

; ---- Installer attributes --------------------------------------------------
Name "${APPNAME}"
OutFile "${OUTFILE}"
RequestExecutionLevel user
InstallDir "$LOCALAPPDATA\Programs\${APPNAME}"
InstallDirRegKey HKCU "Software\${APPNAME}" "InstallDir"
SetCompressor /SOLID lzma
VIProductVersion "${VIVERSION}"
VIAddVersionKey "ProductName"     "${APPNAME}"
VIAddVersionKey "FileDescription" "${APPNAME} installer"
VIAddVersionKey "LegalCopyright"  "(c) 2026 ${PUBLISHER}"
VIAddVersionKey "FileVersion"     "${APPVERSION}"
VIAddVersionKey "ProductVersion"  "${APPVERSION}"

; ---- Modern UI -------------------------------------------------------------
!include "MUI2.nsh"
!define MUI_ABORTWARNING

!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_LICENSE "${LICENSEFILE}"
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!define MUI_FINISHPAGE_RUN "$INSTDIR\${APPEXE}"
!define MUI_FINISHPAGE_RUN_TEXT "Launch ${APPNAME}"
!insertmacro MUI_PAGE_FINISH

!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

!insertmacro MUI_LANGUAGE "English"

; ---- Install ---------------------------------------------------------------
Section "Install"
    SetOutPath "$INSTDIR"
    File "${SRCDIR}/${APPEXE}"
    File "${SRCDIR}/${APPDLL}"
    File "/oname=${APPICON}" "${ICONFILE}"

    ; UI assets must sit next to the executable (the runtime mounts them
    ; relative to the binary; without them the app shows a 404).
    SetOutPath "$INSTDIR\assets"
    File /r "${SRCDIR}/assets/*.*"

    ; Shortcuts
    SetOutPath "$INSTDIR"
    CreateDirectory "$SMPROGRAMS\${APPNAME}"
    CreateShortCut "$SMPROGRAMS\${APPNAME}\${APPNAME}.lnk" "$INSTDIR\${APPEXE}" "" "$INSTDIR\${APPICON}" 0
    CreateShortCut "$SMPROGRAMS\${APPNAME}\Uninstall ${APPNAME}.lnk" "$INSTDIR\uninstall.exe"

    ; Remember install dir + register uninstaller in Add/Remove Programs
    WriteRegStr HKCU "Software\${APPNAME}" "InstallDir" "$INSTDIR"
    WriteRegStr HKCU "${REGUNINST}" "DisplayName"     "${APPNAME}"
    WriteRegStr HKCU "${REGUNINST}" "DisplayVersion"  "${APPVERSION}"
    WriteRegStr HKCU "${REGUNINST}" "Publisher"       "${PUBLISHER}"
    WriteRegStr HKCU "${REGUNINST}" "URLInfoAbout"    "${APPURL}"
    WriteRegStr HKCU "${REGUNINST}" "DisplayIcon"     "$INSTDIR\${APPICON}"
    WriteRegStr HKCU "${REGUNINST}" "UninstallString" "$INSTDIR\uninstall.exe"
    WriteRegDWORD HKCU "${REGUNINST}" "NoModify" 1
    WriteRegDWORD HKCU "${REGUNINST}" "NoRepair" 1

    WriteUninstaller "$INSTDIR\uninstall.exe"
SectionEnd

; ---- Uninstall -------------------------------------------------------------
Section "Uninstall"
    Delete "$INSTDIR\${APPEXE}"
    Delete "$INSTDIR\${APPDLL}"
    Delete "$INSTDIR\${APPICON}"
    RMDir /r "$INSTDIR\assets"
    Delete "$INSTDIR\uninstall.exe"
    RMDir "$INSTDIR"

    Delete "$SMPROGRAMS\${APPNAME}\${APPNAME}.lnk"
    Delete "$SMPROGRAMS\${APPNAME}\Uninstall ${APPNAME}.lnk"
    RMDir "$SMPROGRAMS\${APPNAME}"

    DeleteRegKey HKCU "Software\${APPNAME}"
    DeleteRegKey HKCU "${REGUNINST}"
SectionEnd
