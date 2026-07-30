<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Clipboard;

use Grafida\Secret\ProcessRunner;

/**
 * Reads the OS clipboard as plain text, for the editor's "paste as plain text"
 * shortcut (Cmd/Ctrl+Shift+V).
 *
 * ⚠️ **Why this is not done in JavaScript.** The obvious implementation is
 * `navigator.clipboard.readText()`, and it is the wrong one on the webviews we
 * ship. WKWebView treats an unprivileged clipboard *read* as something the user
 * must confirm: it beeps and puts a one-item "Paste" callout on screen that has
 * to be clicked, which turns a keystroke into a mouse trip and makes the
 * feature slower than the menu it replaces. The clipboard is not a web resource
 * on a desktop app — it belongs to the user's session, which the backend is
 * already part of — so we read it the same way we read the OS appearance and
 * the secret store: a short-lived subprocess, no prompt, no callout.
 *
 * Returns **null** when the clipboard could not be read at all and the **empty
 * string** when it is genuinely empty; the caller must not conflate them (an
 * empty read is a no-op paste, an unreadable one is worth reporting).
 */
final class ClipboardService
{
    public function __construct(
        private readonly ProcessRunner $runner = new ProcessRunner(),
        private readonly WindowsClipboard $windowsClipboard = new WindowsClipboard(),
    ) {}

    /** The clipboard's plain-text flavour, or null when it cannot be read. */
    public function readText(): ?string
    {
        $text = match (\PHP_OS_FAMILY) {
            'Darwin'  => $this->readMac(),
            'Windows' => $this->readWindows(),
            default   => $this->readLinux(),
        };

        if ($text === null) {
            return null;
        }

        // Normalise the line endings the platforms disagree about: TinyMCE's
        // plain-text-to-HTML conversion splits on \n, and a stray \r survives
        // it as a control character inside the pasted paragraph.
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * macOS ships `pbpaste`, which writes the clipboard's text flavour to
     * stdout verbatim — including nothing at all for an empty clipboard, which
     * is still exit code 0 and therefore still a successful read.
     */
    private function readMac(): ?string
    {
        [$code, $stdout] = $this->runner->run(['/usr/bin/pbpaste']);

        return $code === 0 ? $stdout : null;
    }

    /**
     * Linux has no one clipboard tool: Wayland uses `wl-paste` and X11 either
     * `xclip` or `xsel`, and a session may have any subset installed. Try each
     * in turn and take the first that answers.
     *
     * An empty clipboard makes some of these exit non-zero, which is
     * indistinguishable from "not installed" — so an all-round failure is
     * reported as unreadable rather than as an empty clipboard.
     */
    private function readLinux(): ?string
    {
        $candidates = [
            ['wl-paste', '--no-newline', '--type', 'text/plain'],
            ['xclip', '-selection', 'clipboard', '-out'],
            ['xsel', '--clipboard', '--output'],
        ];

        foreach ($candidates as $command) {
            if (!$this->runner->exists($command[0])) {
                continue;
            }

            [$code, $stdout] = $this->runner->run($command);

            if ($code === 0) {
                return $stdout;
            }
        }

        return null;
    }

    /**
     * Windows ships no clipboard executable, so this reads the clipboard
     * natively through FFI ({@see WindowsClipboard}) and keeps PowerShell only
     * as the fallback for a host without FFI.
     *
     * ⚠️ **The order matters and is not a micro-optimisation.** A
     * `powershell.exe` spawn costs the best part of a second, the `boson://`
     * kernel is single-threaded, and this runs under a *keystroke* — so on the
     * PowerShell path every plain-text paste would freeze the UI for about a
     * second. That is the same stall that pushed the DPAPI secret store and the
     * theme probe onto FFI. `-Raw` is required in the fallback: without it
     * Get-Clipboard returns an array of lines and the text comes back reflowed.
     */
    private function readWindows(): ?string
    {
        if ($this->windowsClipboard->available()) {
            $text = $this->windowsClipboard->readText();

            if ($text !== null) {
                return $text;
            }
        }

        [$code, $stdout] = $this->runner->run([
            'powershell', '-NoProfile', '-NonInteractive', '-Command', 'Get-Clipboard -Raw',
        ]);

        return $code === 0 ? $stdout : null;
    }
}
