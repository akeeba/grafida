<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Startup;

use Grafida\Secret\ProcessRunner;
use Grafida\Support\App;

/**
 * Tells the user, in words, why Grafida could not start.
 *
 * The failure this exists for is the native webview library refusing to load: the wrong macOS
 * (gh-58), a Linux box with no WebKitGTK, a Windows 10 box with no Edge WebView2 Runtime. All
 * three used to surface as an uncaught `FFI\Exception` with a PHP stack trace — and a
 * Finder-launched app bundle discards even that, so the app simply vanished.
 *
 * ⚠️ **stderr is not a user-facing channel here.** A macOS `.app` sends it to unified logging,
 * and on Windows `index.php` has already hidden the console by the time this runs. It is
 * written first regardless (it is what a developer or a terminal launch wants), then a native
 * alert is attempted, best-effort, on whatever the platform provides.
 *
 * ⚠️ The text is **hard-coded English** — see the note on {@see StartupCheck}.
 */
final class FailureReporter
{
    /** MB_ICONERROR | MB_SETFOREGROUND | MB_TOPMOST — our own window never opened. */
    private const MB_FLAGS = 0x00050010;

    public function __construct(
        private readonly ProcessRunner $runner = new ProcessRunner(),
    ) {}

    /**
     * Write $message to stderr, then show it natively if this platform lets us.
     *
     * $detail is appended to the stderr copy only — a stack trace belongs in a terminal or a bug
     * report, not in an alert box the user is expected to read.
     */
    public function report(string $message, string $detail = ''): void
    {
        $this->toStandardError($detail === '' ? $message : $message . "\n\n" . $detail);

        if (\PHP_OS_FAMILY === 'Darwin') {
            $this->alertMacOs($message);

            return;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $this->alertWindows($message);

            return;
        }

        $this->alertLinux($message);
    }

    /**
     * Turn a start-up failure into something a person can act on.
     *
     * Pure, and `$osFamily` is a parameter rather than `PHP_OS_FAMILY` read inline so all three
     * platforms' advice is assertable from one machine. The original exception message is always
     * kept: it names the library and the missing symbol, which is what a bug report needs.
     */
    public static function describe(\Throwable $e, string $osFamily = \PHP_OS_FAMILY): string
    {
        $advice = match ($osFamily) {
            'Darwin'  => \sprintf(
                'Grafida needs %s or later. The webview library it is built on cannot be loaded by '
                . 'an earlier macOS.',
                App::MIN_MACOS_NAME
            ),
            'Windows' => 'Grafida needs the Microsoft Edge WebView2 Runtime. It is preinstalled on '
                . 'Windows 11 and on current Windows 10, but may be missing otherwise. You can get '
                . 'it from https://developer.microsoft.com/microsoft-edge/webview2/',
            'Linux'   => 'Grafida needs GTK 4 and WebKitGTK 6.0. Install them with '
                . '"apt install libgtk-4-1 libwebkitgtk-6.0-4" (Debian, Ubuntu), '
                . '"dnf install gtk4 webkitgtk6.0" (Fedora) or '
                . '"pacman -S gtk4 webkitgtk-6.0" (Arch).',
            default   => 'Grafida could not load the webview library it needs on this system.',
        };

        return "Grafida could not start: its webview library could not be loaded.\n\n"
            . $advice . "\n\n"
            . 'Technical detail: ' . $e::class . ': ' . $e->getMessage();
    }

    /** Always attempted, and the only channel a developer or a terminal launch needs. */
    private function toStandardError(string $message): void
    {
        $stream = \defined('STDERR') ? \STDERR : @fopen('php://stderr', 'wb');

        if (!\is_resource($stream)) {
            return;
        }

        @fwrite($stream, rtrim($message) . \PHP_EOL);
    }

    /**
     * `display alert` runs inside osascript's own process rather than sending an Apple Event to
     * another application, so it needs no automation permission and prompts for nothing. It
     * blocks until dismissed, which is what we want — the app is about to die.
     *
     * ⚠️ The text is passed as **argv**, never interpolated into the script source. An
     * AppleScript string literal knows only `\\` and `\"` and **cannot contain a raw newline**,
     * so interpolating a two-paragraph message would produce a syntax error and no alert at all.
     */
    private function alertMacOs(string $message): void
    {
        $this->runner->run([
            '/usr/bin/osascript',
            '-e', 'on run argv',
            '-e', 'display alert (item 1 of argv) message (item 2 of argv) as critical'
                . ' buttons {"Quit"} default button "Quit"',
            '-e', 'end run',
            'Grafida cannot start',
            $message,
        ]);
    }

    /**
     * MessageBoxA through FFI, for the same reason the secret store, the theme probe and the
     * clipboard read use FFI ({@see \Grafida\Secret\WindowsDpapi}): a PowerShell spawn costs
     * about a second. Here it is also the *only* channel — `index.php` hides the console before
     * this can run, so stderr goes nowhere a user will look.
     *
     * The `A` variant, unlike the `W` the rest of our user32 code prefers: this text is
     * hard-coded ASCII English by construction (it exists before `UiStrings` does), so PHP FFI's
     * own `string` → `const char *` conversion is enough — the same idiom as
     * {@see \Grafida\Display\WindowsThemeReader}'s `RegGetValueA` call. If it ever becomes
     * translatable this has to become `MessageBoxW` with a UTF-16LE buffer.
     */
    private function alertWindows(string $message): void
    {
        if (!\extension_loaded('ffi')) {
            return;
        }

        try {
            $user32 = \FFI::cdef(
                'int MessageBoxA(void *hWnd, const char *lpText, const char *lpCaption,'
                . ' unsigned int uType);',
                'user32.dll'
            );

            $user32->MessageBoxA(null, $message, 'Grafida cannot start', self::MB_FLAGS);
        } catch (\Throwable) {
            // No user32, no FFI, or a session with no desktop: stderr already has the message.
        }
    }

    /** Whatever dialog helper the desktop happens to ship; neither is a dependency we take. */
    private function alertLinux(string $message): void
    {
        if ($this->runner->exists('zenity')) {
            $this->runner->run(['zenity', '--error', '--title', 'Grafida', '--text', $message]);

            return;
        }

        if ($this->runner->exists('kdialog')) {
            $this->runner->run(['kdialog', '--title', 'Grafida', '--error', $message]);
        }
    }
}
