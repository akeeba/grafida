<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Display;

/**
 * Reads Windows' apps light/dark preference straight from the registry via a
 * direct FFI call into advapi32.dll's RegGetValueA.
 *
 * {@see DisplayModeService::windowsPrefersDark()} used to shell out to `reg.exe`
 * for this. Even though the app hides its console at startup (so child processes
 * inherit a hidden console), PHP's proc_open does not pass CREATE_NO_WINDOW, so
 * the spawn could still briefly flash a console window — and this probe runs on
 * every window focus. Reading the DWORD directly spawns nothing, so there is
 * never a window to flash. `reg.exe` remains a fallback for when FFI is
 * unavailable (see the service).
 */
final class WindowsThemeReader
{
    /**
     * HKEY_CURRENT_USER. Win32 defines it as (HKEY)(ULONG_PTR)(LONG)0x80000001,
     * i.e. 0x80000001 sign-extended to 64-bit = 0xFFFFFFFF80000001, whose signed
     * value is -2147483647. PHP cannot hold 0xFFFFFFFF80000001 as an int (it
     * overflows to float), so pass the signed form; FFI marshals it into the
     * 64-bit argument register with the identical bit pattern.
     */
    private const HKEY_CURRENT_USER = -2147483647;

    /** RRF_RT_REG_DWORD — restrict RegGetValue to a REG_DWORD value. */
    private const RRF_RT_REG_DWORD = 0x00000010;

    private const SUBKEY = 'Software\\Microsoft\\Windows\\CurrentVersion\\Themes\\Personalize';
    private const VALUE  = 'AppsUseLightTheme';

    private static ?\FFI $advapi = null;
    private static ?bool $available = null;

    /** Whether the native registry read can be used on this host right now. */
    public function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        if (\PHP_OS_FAMILY !== 'Windows' || !\extension_loaded('ffi')) {
            return self::$available = false;
        }

        try {
            self::$advapi = \FFI::cdef(
                'int RegGetValueA(int64_t hKey, const char *lpSubKey, const char *lpValue,'
                . ' uint32_t dwFlags, uint32_t *pdwType, uint32_t *pvData, uint32_t *pcbData);',
                'advapi32.dll'
            );
        } catch (\Throwable) {
            self::$advapi = null;

            return self::$available = false;
        }

        return self::$available = true;
    }

    /**
     * True for dark, false for light, or null when the preference cannot be read
     * (missing key, or FFI/registry error). Spawns no subprocess.
     */
    public function prefersDark(): ?bool
    {
        if (!$this->available() || self::$advapi === null) {
            return null;
        }

        $advapi = self::$advapi;

        try {
            $data = $advapi->new('uint32_t');
            $size = $advapi->new('uint32_t');
            $size->cdata = \FFI::sizeof($data);

            $ret = $advapi->RegGetValueA(
                self::HKEY_CURRENT_USER,
                self::SUBKEY,
                self::VALUE,
                self::RRF_RT_REG_DWORD,
                null,
                \FFI::addr($data),
                \FFI::addr($size)
            );

            if ($ret !== 0) {
                return null;
            }

            // AppsUseLightTheme: 1 = light, 0 = dark.
            return $data->cdata === 0;
        } catch (\Throwable) {
            return null;
        }
    }
}
