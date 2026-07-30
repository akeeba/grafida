<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Clipboard\WindowsClipboard;

/**
 * Covers the UTF-16LE decoding half of the Windows native clipboard read.
 *
 * The FFI calls themselves cannot run here, but this — the part that actually
 * has edge cases — is pure and runs anywhere, which matters because Windows is
 * not the development platform and would otherwise get no coverage at all.
 */
final class WindowsClipboardDecodeTest extends TestCase
{
    /** UTF-16LE encode, then append the NUL terminator the clipboard carries. */
    private function clipboardBytes(string $utf8, int $allocationSlack = 0): string
    {
        $encoded = mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');
        self::assertIsString($encoded);

        // GlobalSize reports the allocation, which is typically larger than the
        // string — the slack is whatever happened to be in the heap block.
        return $encoded . "\0\0" . str_repeat("\0", $allocationSlack * 2);
    }

    public function testDecodesAsciiAndStopsAtTheTerminator(): void
    {
        $bytes = $this->clipboardBytes('Hello, clipboard', 8);

        self::assertSame('Hello, clipboard', WindowsClipboard::decodeUtf16LE($bytes));
    }

    /**
     * ⚠️ The regression this guards: `U+0100` encodes as `00 01` in UTF-16LE, so
     * a naive scan for the first zero *byte* would truncate right before it.
     */
    public function testACharacterWhoseLowByteIsZeroDoesNotTruncate(): void
    {
        $bytes = $this->clipboardBytes("A\u{0100}B", 4);

        self::assertSame("A\u{0100}B", WindowsClipboard::decodeUtf16LE($bytes));
    }

    /**
     * ⚠️ The other half of the same trap: an astral character is a surrogate
     * pair (U+1F600 → D8 3D DE 00 → LE bytes 3D D8 00 DE), whose third byte is
     * zero. Byte-scanning would cut the pair in half and yield mojibake.
     */
    public function testAnEmojiSurvivesIntact(): void
    {
        $bytes = $this->clipboardBytes("hi \u{1F600} there", 6);

        self::assertSame("hi \u{1F600} there", WindowsClipboard::decodeUtf16LE($bytes));
    }

    public function testMultilineTextKeepsItsCrlf(): void
    {
        // Normalising CRLF is ClipboardService's job, not the decoder's.
        $bytes = $this->clipboardBytes("one\r\ntwo", 2);

        self::assertSame("one\r\ntwo", WindowsClipboard::decodeUtf16LE($bytes));
    }

    public function testAnEmptyClipboardStringDecodesToEmpty(): void
    {
        self::assertSame('', WindowsClipboard::decodeUtf16LE("\0\0"));
        self::assertSame('', WindowsClipboard::decodeUtf16LE(''));
    }

    /** A malformed odd-length tail must not poison the whole conversion. */
    public function testAnOddTrailingByteIsDropped(): void
    {
        $bytes = mb_convert_encoding('abc', 'UTF-16LE', 'UTF-8');
        self::assertIsString($bytes);

        self::assertSame('abc', WindowsClipboard::decodeUtf16LE($bytes . "\x41"));
    }

    /** No terminator at all (a full buffer) still decodes everything. */
    public function testTextWithNoTerminatorDecodesWhole(): void
    {
        $bytes = mb_convert_encoding('no terminator', 'UTF-16LE', 'UTF-8');
        self::assertIsString($bytes);

        self::assertSame('no terminator', WindowsClipboard::decodeUtf16LE($bytes));
    }
}
