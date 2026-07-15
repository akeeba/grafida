<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

/*
 * Compute the phpmicro SFX size — i.e. the byte offset at which Boson's appended
 * payload begins — for a Windows PE produced by `boson compile`.
 *
 * This is the Windows analogue of the `otool` segment-end probe in
 * scripts/make-macos-app.sh: splitting the combined grafida.exe there yields a
 * clean, Authenticode-signable stub plus a sibling grafida.phar the patched SFX
 * loads at run time (see build/readme/02-signing-architecture.md).
 *
 * It replicates phpmicro's own _micro_init_sfxsize() for PE
 * (nikosdion/phpmicro, php_micro_fileinfo.c):
 *
 *     sfxsize = max over sections of (PointerToRawData + SizeOfRawData)
 *
 * The PHP_MICRO_SFXSIZE_ID RC_DATA-resource shortcut phpmicro also supports is
 * NOT emitted by Boson's AssemblyTargetTask — it concatenates the SFX file bytes
 * verbatim, then the extra-ini block, then the phar — so the section-end value
 * is authoritative. (The currently-shipping unsplit Windows build proves this:
 * its PHAR loads fine until Authenticode corrupts the trailer, which only works
 * if the runtime's sfxsize equals Boson's append offset = the section end.)
 *
 * As a safety check the computed offset is verified to point exactly at Boson's
 * extra-ini magic (fd f6 69 e6, AssemblyTargetTask::appendPhpConfig), so a wrong
 * offset can never be split and shipped.
 *
 * Usage:  php build/tasks/pe-sfxsize.php <path-to-exe>
 * Output: the split offset (decimal, one line) on stdout; non-zero exit + a
 *         message on stderr on any failure.
 */

// Boson's extra-ini marker, written immediately after the SFX prefix
// (see AssemblyTargetTask::appendPhpConfig()).
const EXT_INI_MAGIC = "\xfd\xf6\x69\xe6";

function fail(string $message): never
{
    \fwrite(\STDERR, 'pe-sfxsize: ' . $message . "\n");
    exit(1);
}

/**
 * @param resource $fh
 */
function readAt($fh, int $offset, int $length): string
{
    if (\fseek($fh, $offset) !== 0)
    {
        fail("cannot seek to offset {$offset}");
    }

    $data = \fread($fh, $length);

    if ($data === false || \strlen($data) !== $length)
    {
        fail("short read at offset {$offset} (wanted {$length} bytes)");
    }

    return $data;
}

$path = $argv[1] ?? '';

if ($path === '' || !\is_file($path))
{
    fail('usage: pe-sfxsize.php <path-to-exe>');
}

$fileSize = (int) \filesize($path);
$fh       = \fopen($path, 'rb');

if ($fh === false)
{
    fail("cannot open {$path}");
}

// --- DOS header ------------------------------------------------------------
// e_magic == "MZ" at 0; e_lfanew (uint32 LE) at 0x3C points at the PE header.
$dos = readAt($fh, 0, 64);

if (\substr($dos, 0, 2) !== 'MZ')
{
    fail('not a PE file (missing MZ signature)');
}

$eLfanew = \unpack('V', \substr($dos, 0x3C, 4))[1];

// --- PE signature + IMAGE_FILE_HEADER --------------------------------------
// "PE\0\0" (4) then the 20-byte COFF file header. We need NumberOfSections
// (uint16 @ +2 of the file header) and SizeOfOptionalHeader (uint16 @ +16).
$peSig = readAt($fh, $eLfanew, 4);

if ($peSig !== "PE\x00\x00")
{
    fail('not a PE file (missing PE signature)');
}

$fileHeader          = readAt($fh, $eLfanew + 4, 20);
$numberOfSections    = \unpack('v', \substr($fileHeader, 2, 2))[1];
$sizeOfOptionalHeader = \unpack('v', \substr($fileHeader, 16, 2))[1];

if ($numberOfSections < 1 || $numberOfSections > 96)
{
    fail("implausible NumberOfSections ({$numberOfSections}) — corrupt PE");
}

// --- Section table ---------------------------------------------------------
// Section headers follow the optional header. Each IMAGE_SECTION_HEADER is 40
// bytes; SizeOfRawData is at +16, PointerToRawData at +20 (both uint32 LE).
$sectionTableOffset = $eLfanew + 4 + 20 + $sizeOfOptionalHeader;
$sectionTable       = readAt($fh, $sectionTableOffset, 40 * $numberOfSections);

$sfxSize = 0;

for ($i = 0; $i < $numberOfSections; $i++)
{
    $section        = \substr($sectionTable, $i * 40, 40);
    $sizeOfRawData  = \unpack('V', \substr($section, 16, 4))[1];
    $pointerToRaw   = \unpack('V', \substr($section, 20, 4))[1];
    $end            = $pointerToRaw + $sizeOfRawData;

    if ($end > $sfxSize)
    {
        $sfxSize = $end;
    }
}

if ($sfxSize <= 0)
{
    fail('computed a zero SFX size — corrupt PE');
}

if ($sfxSize >= $fileSize)
{
    fail(\sprintf(
        'no appended payload: section end (%d) is at or past EOF (%d). Was this binary '
        . 'compiled against the patched SFX and does it actually carry a phar?',
        $sfxSize,
        $fileSize
    ));
}

// --- Safety check: Boson's extra-ini magic must sit exactly at the offset ---
$marker = readAt($fh, $sfxSize, 4);

if ($marker !== EXT_INI_MAGIC)
{
    fail(\sprintf(
        "computed split offset %d does not point at Boson's extra-ini magic "
        . '(got %s, expected fd f6 69 e6). Refusing to split — the stub/phar '
        . 'boundary is not where phpmicro will look for it.',
        $sfxSize,
        \bin2hex($marker)
    ));
}

\fclose($fh);

echo $sfxSize, "\n";
exit(0);
