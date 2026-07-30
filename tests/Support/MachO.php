<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Support;

/**
 * The little of the Mach-O format needed to read a library's macOS deployment target.
 *
 * Exists so `MacosDeploymentFloorTest` can check the deployment target of the Boson library we
 * vendor **on every platform**. Shelling out to `vtool` would work, but only on a Mac with the
 * Xcode command line tools — and the library is a production `require`, so it is on disk when
 * the suite runs on Linux or Windows too. A guard that is dormant everywhere but the
 * maintainer's laptop is not much of a guard.
 *
 * Test-only: it has no production caller, `phpstan.neon` analyses `src` alone, and
 * `phpunit.xml`'s coverage source is `src` alone.
 */
final class MachO
{
    private const FAT_MAGIC    = 0xCAFEBABE;
    private const FAT_MAGIC_64 = 0xCAFEBABF;

    /**
     * 64-bit Mach-O magic, as the four header bytes read **little-endian**.
     *
     * ⚠️ Read this way round, `MH_MAGIC_64` is the value a *little*-endian slice yields — which
     * is every macOS slice we ship — and `MH_CIGAM_64` (the same number byte-swapped) is what a
     * big-endian one yields. Getting the two the wrong way about picks the wrong byte order for
     * every field afterwards and the walk lands in the middle of nowhere.
     */
    private const MH_MAGIC_64 = 0xFEEDFACF;
    private const MH_CIGAM_64 = 0xCFFAEDFE;

    private const LC_BUILD_VERSION      = 0x32;
    private const LC_VERSION_MIN_MACOSX = 0x24;

    /** CPU types we care to name; anything else is reported by its hex type. */
    private const CPU_NAMES = [
        0x01000007 => 'x86_64',
        0x0100000C => 'arm64',
    ];

    /**
     * The minimum macOS version each architecture slice of $path was built for.
     *
     * @return array<string, string> e.g. `['x86_64' => '15.0.0', 'arm64' => '15.0.0']`
     */
    public static function minimumMacosVersions(string $path): array
    {
        $data = @file_get_contents($path);

        if (!\is_string($data) || \strlen($data) < 8) {
            return [];
        }

        $versions = [];

        foreach (self::slices($data) as $arch => $offset) {
            $version = self::sliceMinimumVersion($data, $offset);

            if ($version !== null) {
                $versions[$arch] = $version;
            }
        }

        return $versions;
    }

    /**
     * Architecture name => byte offset of its Mach-O header.
     *
     * A universal binary starts with a **big-endian** fat header whatever the byte order of the
     * slices inside it; a thin file is simply its own single slice at offset 0.
     *
     * @return array<string, int>
     */
    private static function slices(string $data): array
    {
        /** @var array{magic: int, count: int}|false $fat */
        $fat = unpack('Nmagic/Ncount', $data);

        if ($fat === false) {
            return [];
        }

        if ($fat['magic'] !== self::FAT_MAGIC && $fat['magic'] !== self::FAT_MAGIC_64) {
            return ['thin' => 0];
        }

        // FAT_MAGIC_64 differs only in using 64-bit offsets and sizes, so its entries are 32
        // bytes rather than 20.
        $wide      = $fat['magic'] === self::FAT_MAGIC_64;
        $entrySize = $wide ? 32 : 20;
        $slices    = [];

        for ($i = 0; $i < $fat['count']; $i++) {
            $entry = substr($data, 8 + $i * $entrySize, $entrySize);

            if (\strlen($entry) < $entrySize) {
                break;
            }

            /** @var array{cpu: int, sub: int, offset: int}|false $arch */
            $arch = unpack($wide ? 'Ncpu/Nsub/Joffset' : 'Ncpu/Nsub/Noffset', $entry);

            if ($arch === false) {
                continue;
            }

            $slices[self::CPU_NAMES[$arch['cpu']] ?? \sprintf('cpu-0x%08x', $arch['cpu'])]
                = $arch['offset'];
        }

        return $slices;
    }

    /**
     * The `minos` of the slice whose header starts at $offset, or null when it carries no
     * version load command at all.
     */
    private static function sliceMinimumVersion(string $data, int $offset): ?string
    {
        /** @var array{magic: int}|false $header */
        $header = unpack('Vmagic', substr($data, $offset, 4));

        if ($header === false) {
            return null;
        }

        // A 32-bit slice (MH_MAGIC/MH_CIGAM) predates LC_BUILD_VERSION and cannot be a macOS 11+
        // library, so there is nothing here worth reading.
        if ($header['magic'] !== self::MH_MAGIC_64 && $header['magic'] !== self::MH_CIGAM_64) {
            return null;
        }

        // The magic came out right when read little-endian, so the rest of the slice is
        // little-endian too; the byte-swapped value means the reverse.
        $format = $header['magic'] === self::MH_MAGIC_64 ? 'V' : 'N';

        /** @var array{ncmds: int}|false $counts */
        $counts = unpack($format . 'ncmds', substr($data, $offset + 16, 4));

        if ($counts === false) {
            return null;
        }

        // The 64-bit Mach-O header is 32 bytes; the load commands follow it.
        $cursor = $offset + 32;

        for ($i = 0; $i < $counts['ncmds']; $i++) {
            /** @var array{cmd: int, size: int}|false $command */
            $command = unpack($format . 'cmd/' . $format . 'size', substr($data, $cursor, 8));

            if ($command === false || $command['size'] < 8) {
                return null;
            }

            // Both commands put a packed version where we need it: LC_BUILD_VERSION's `minos`
            // comes after platform (offset 12), LC_VERSION_MIN_MACOSX's `version` right after the
            // header (offset 8).
            $versionAt = match ($command['cmd']) {
                self::LC_BUILD_VERSION      => $cursor + 12,
                self::LC_VERSION_MIN_MACOSX => $cursor + 8,
                default                     => null,
            };

            if ($versionAt !== null) {
                /** @var array{packed: int}|false $packed */
                $packed = unpack($format . 'packed', substr($data, $versionAt, 4));

                if ($packed !== false) {
                    return self::unpackVersion($packed['packed']);
                }
            }

            $cursor += $command['size'];
        }

        return null;
    }

    /** `xxxx.yy.zz` nibble-packed into a uint32 — 15.0.0 is `0x000F0000`. */
    private static function unpackVersion(int $packed): string
    {
        return \sprintf('%d.%d.%d', $packed >> 16, ($packed >> 8) & 0xFF, $packed & 0xFF);
    }
}
