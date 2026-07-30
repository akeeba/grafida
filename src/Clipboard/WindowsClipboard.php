<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Clipboard;

/**
 * Reads the Windows clipboard's text through direct FFI calls into user32.dll
 * and kernel32.dll.
 *
 * Windows ships no `pbpaste` equivalent, so the alternative is
 * `powershell.exe Get-Clipboard`, and PowerShell cold-starts in roughly a
 * second. Because the `boson://` kernel is single-threaded that would freeze the
 * whole UI — under a *keystroke*, on every paste. It is the same stall that
 * pushed the DPAPI secret store onto FFI ({@see \Grafida\Secret\WindowsDpapi})
 * and the theme probe onto FFI ({@see \Grafida\Display\WindowsThemeReader}); this
 * is the third instance of that pattern, and PowerShell survives as the fallback
 * for a host without FFI.
 *
 * Returns **null** when the clipboard could not be read (so the caller can fall
 * back) and the **empty string** when it holds no text at all — an image-only
 * clipboard reads as empty, exactly as `pbpaste` does on macOS.
 *
 * Deliberately not `final`, like {@see \Grafida\Secret\ProcessRunner}: the
 * whole class is unreachable off Windows, so {@see ClipboardService}'s
 * fallback-ordering test can only exist with a fake standing in for it.
 */
class WindowsClipboard
{
    /** CF_UNICODETEXT — UTF-16LE, NUL-terminated. */
    private const CF_UNICODETEXT = 13;

    /**
     * The clipboard is a single global lock, so OpenClipboard genuinely fails
     * when another process holds it — routinely, and for microseconds. A couple
     * of quick retries turn that into a non-event rather than a spurious
     * fallback to a one-second PowerShell spawn.
     */
    private const OPEN_ATTEMPTS   = 5;
    private const OPEN_RETRY_USEC = 10_000;

    private static ?\FFI $user32 = null;
    private static ?\FFI $kernel32 = null;
    private static ?bool $available = null;

    /** Whether the native clipboard read can be used on this host right now. */
    public function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        if (\PHP_OS_FAMILY !== 'Windows' || !\extension_loaded('ffi')) {
            return self::$available = false;
        }

        try {
            self::$user32 = \FFI::cdef(
                'int OpenClipboard(void *hWndNewOwner);'
                . 'int CloseClipboard(void);'
                . 'void *GetClipboardData(uint32_t uFormat);'
                . 'int IsClipboardFormatAvailable(uint32_t format);',
                'user32.dll'
            );
            // The clipboard hands back a moveable HGLOBAL, which must be locked
            // to be read and unlocked afterwards. It must NOT be freed: the
            // clipboard owns it, and freeing it corrupts another app's data.
            self::$kernel32 = \FFI::cdef(
                'void *GlobalLock(void *hMem);'
                . 'int GlobalUnlock(void *hMem);'
                . 'size_t GlobalSize(void *hMem);',
                'kernel32.dll'
            );
        } catch (\Throwable) {
            self::$user32   = null;
            self::$kernel32 = null;

            return self::$available = false;
        }

        return self::$available = true;
    }

    /**
     * The clipboard's Unicode text as UTF-8, '' when it holds no text, or null
     * when it could not be read at all. Spawns no subprocess.
     */
    public function readText(): ?string
    {
        if (!$this->available() || self::$user32 === null || self::$kernel32 === null) {
            return null;
        }

        $user32   = self::$user32;
        $kernel32 = self::$kernel32;

        try {
            if (!$this->open($user32)) {
                return null;
            }

            try {
                if ($user32->IsClipboardFormatAvailable(self::CF_UNICODETEXT) === 0) {
                    // Nothing pasteable as text (an image, or an empty clipboard).
                    return '';
                }

                $handle = $user32->GetClipboardData(self::CF_UNICODETEXT);

                if ($handle === null || \FFI::isNull($handle)) {
                    return null;
                }

                $size = $kernel32->GlobalSize($handle);

                // GlobalSize returns a size_t, so anything else means the
                // clipboard handed back something that is not a memory block.
                if (!\is_int($size)) {
                    return null;
                }

                // Too small to hold even a NUL terminator: nothing to paste.
                if ($size < 2) {
                    return '';
                }

                $ptr = $kernel32->GlobalLock($handle);

                if ($ptr === null || \FFI::isNull($ptr)) {
                    return null;
                }

                try {
                    // GlobalSize reports the *allocation*, which may be larger
                    // than the string, so the bytes are taken wholesale and the
                    // NUL terminator located afterwards — FFI::string() with no
                    // length would stop at the first zero BYTE, which for
                    // UTF-16LE is the high half of the very first ASCII
                    // character ("A" is 41 00), truncating everything to one
                    // letter.
                    $bytes = \FFI::string($kernel32->cast('char *', $ptr), $size);
                } finally {
                    $kernel32->GlobalUnlock($handle);
                }
            } finally {
                $user32->CloseClipboard();
            }
        } catch (\Throwable) {
            return null;
        }

        return self::decodeUtf16LE($bytes);
    }

    /** Acquire the global clipboard lock, retrying briefly; see OPEN_ATTEMPTS. */
    private function open(\FFI $user32): bool
    {
        for ($attempt = 0; $attempt < self::OPEN_ATTEMPTS; $attempt++) {
            if ($user32->OpenClipboard(null) !== 0) {
                return true;
            }

            if ($attempt < self::OPEN_ATTEMPTS - 1) {
                usleep(self::OPEN_RETRY_USEC);
            }
        }

        return false;
    }

    /**
     * UTF-16LE bytes (up to and beyond a NUL terminator) to UTF-8.
     *
     * ⚠️ Truncating at the terminator MUST happen on a **code-unit** boundary.
     * Scanning for a single zero byte — the obvious way, and what `FFI::string()`
     * does by itself — cuts the string at the first character whose *low* byte is
     * zero: `U+0100`, `U+0200`, and the low half of every surrogate pair, so an
     * emoji would truncate the paste mid-character.
     *
     * `public static` and free of FFI on purpose: it is the one genuinely fiddly
     * piece of this Windows-only class, and this way it is unit-testable on any
     * platform ({@see \Grafida\Tests\Unit\WindowsClipboardDecodeTest}).
     */
    public static function decodeUtf16LE(string $bytes): string
    {
        // Drop a trailing odd byte; a UTF-16 stream is always even-length, and a
        // malformed tail would otherwise poison the whole conversion.
        if (\strlen($bytes) % 2 === 1) {
            $bytes = substr($bytes, 0, -1);
        }

        for ($i = 0; $i + 1 < \strlen($bytes); $i += 2) {
            if ($bytes[$i] === "\0" && $bytes[$i + 1] === "\0") {
                $bytes = substr($bytes, 0, $i);
                break;
            }
        }

        if ($bytes === '') {
            return '';
        }

        $utf8 = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');

        return \is_string($utf8) ? $utf8 : '';
    }
}
