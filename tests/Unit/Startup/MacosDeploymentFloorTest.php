<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Startup;

use Grafida\Support\App;
use Grafida\Tests\Support\MachO;
use Grafida\Tests\Unit\TestCase;

/**
 * Guards the coupling that produced gh-58: the macOS version Grafida can run on is decided by a
 * third-party binary we vendor, and nothing used to notice when it changed.
 *
 * Upstream builds `libboson-darwin-universal.dylib` on `macos-latest` with no
 * `CMAKE_OSX_DEPLOYMENT_TARGET`, so its floor silently follows whatever SDK the GitHub runner
 * had. A bump of `boson-php/*` can therefore lock out every user of the current macOS with no
 * other symptom than a crash on someone else's Mac. This test is the symptom.
 */
final class MacosDeploymentFloorTest extends TestCase
{
    private const DYLIB = 'vendor/boson-php/saucer/bin/libboson-darwin-universal.dylib';

    public function testTheVendoredBosonLibraryStillRunsOnOurDeclaredMinimum(): void
    {
        $floors = MachO::minimumMacosVersions($this->dylib());

        // A reader that finds nothing must fail rather than pass vacuously: a silent blind spot
        // here is the exact failure mode this test exists to remove.
        self::assertNotSame(
            [],
            $floors,
            'No deployment target could be read from the Boson library — the Mach-O reader has gone blind.'
        );

        // Mach-O always yields three components, and version_compare('15.0.0', '15.0', '<=') is
        // FALSE because a missing component sorts below '0' — so pad before comparing. Done here
        // by hand rather than through StartupCheck::isAtLeast(): a guard must not depend on the
        // code it guards, and isAtLeast() fails open by design, which would let an unreadable
        // version pass silently.
        $minimum = implode('.', array_pad(explode('.', App::MIN_MACOS), 3, '0'));

        foreach ($floors as $arch => $floor) {
            self::assertTrue(
                version_compare($floor, $minimum, '<='),
                \sprintf(
                    "The vendored Boson library's %s slice now needs macOS %s, above our declared "
                    . "minimum of %s.\nRaise App::MIN_MACOS (with App::MIN_MACOS_NAME, "
                    . "docs/Requirements.md and README.md) or vendor an older library. See gh-58.",
                    $arch,
                    $floor,
                    App::MIN_MACOS
                )
            );
        }
    }

    public function testTheVendoredBosonLibraryStillShipsBothArchitectures(): void
    {
        // A different upstream regression with no other warning: dropping the Intel slice would
        // break build/macos/amd64 silently, since the compiler copies whatever it is given.
        self::assertSame(
            ['arm64', 'x86_64'],
            $this->sortedArchitectures(),
            'The Boson library no longer ships both an Intel and an Apple Silicon slice.'
        );
    }

    public function testTheBuildScriptCanStillReadTheMinimumFromTheConstant(): void
    {
        // The exact expression scripts/make-macos-app.sh uses. If a reformat of App.php breaks
        // it the script now aborts — but this says so in a second rather than at release time.
        $source = (string) file_get_contents($this->path('src/Support/App.php'));

        self::assertSame(1, preg_match("/MIN_MACOS = '([^']+)'/", $source, $matches));
        self::assertSame(App::MIN_MACOS, $matches[1]);
    }

    public function testTheInfoPlistInterpolatesTheConstantInsteadOfHardCodingIt(): void
    {
        $script = (string) file_get_contents($this->path('scripts/make-macos-app.sh'));

        self::assertStringContainsString(
            '<key>LSMinimumSystemVersion</key><string>${MIN_MACOS}</string>',
            $script,
            'Info.plist must interpolate App::MIN_MACOS, never hard-code a macOS version (gh-58).'
        );
    }

    /** @return list<string> */
    private function sortedArchitectures(): array
    {
        $architectures = array_keys(MachO::minimumMacosVersions($this->dylib()));
        sort($architectures);

        return $architectures;
    }

    /** The vendored library, or a skip when dependencies are not installed. */
    private function dylib(): string
    {
        $path = $this->path(self::DYLIB);

        if (!is_file($path)) {
            self::markTestSkipped('The vendored Boson library is not present.');
        }

        return $path;
    }

    private function path(string $relative): string
    {
        return \dirname(__DIR__, 3) . '/' . $relative;
    }
}
