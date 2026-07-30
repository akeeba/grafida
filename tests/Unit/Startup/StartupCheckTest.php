<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Startup;

use Grafida\Secret\ProcessRunner;
use Grafida\Startup\StartupCheck;
use Grafida\Support\App;
use Grafida\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class StartupCheckTest extends TestCase
{
    /** @var list<string> Temporary plists to remove afterwards. */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            @unlink($path);
        }

        $this->fixtures = [];
        putenv(StartupCheck::LIBRARY_ENV);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function versions(): array
    {
        return [
            // The boundary, and the trap behind it: version_compare('15', '15.0', '>=') is FALSE,
            // because a missing component sorts below '0'. Both sides are padded first.
            'exactly the minimum'      => ['15.0', true],
            'the minimum, unpadded'    => ['15', true],
            'a later patch release'    => ['15.7.2', true],
            // The Darwin-offset trap: macOS 26 is newer than 15, and any "kernel major minus 9"
            // arithmetic would get this wrong (macOS 26.5.2 runs Darwin 25).
            'a much later major'       => ['26.5.2', true],
            'the Mac that reported it' => ['14.7.6', false],
            'the release before'       => ['14.0', false],
            // What a version-capped process sees for Big Sur; it must not read as "above 15".
            'the Big Sur compat value' => ['10.16', false],
            // Fail-open: an unparseable version must NOT be reported as too old. Pinned so
            // nobody "hardens" it into refusing to start on a machine we could not identify.
            'empty'                    => ['', true],
            'not a version at all'     => ['banana', true],
            'half a version'           => ['15.x', true],
        ];
    }

    #[DataProvider('versions')]
    public function testIsAtLeastComparesMacOsVersions(string $running, bool $expected): void
    {
        $this->assertSame($expected, StartupCheck::isAtLeast($running, '15.0'));
    }

    public function testReadsTheProductVersionOutOfThePlist(): void
    {
        $plist = <<<'PLIST'
            <dict>
                <key>ProductName</key>
                <string>macOS</string>
                <key>ProductUserVisibleVersion</key>
                <string>14.7</string>
                <key>ProductVersion</key>
                <string>14.7.6</string>
                <key>iOSSupportVersion</key>
                <string>17.0</string>
            </dict>
            PLIST;

        $this->assertSame('14.7.6', StartupCheck::productVersion($plist));
    }

    public function testProductVersionIsNullWhenTheKeyIsAbsent(): void
    {
        $this->assertNull(StartupCheck::productVersion('<dict><key>ProductName</key><string>macOS</string></dict>'));
        $this->assertNull(StartupCheck::productVersion(''));
    }

    public function testMacOsVersionReadsThePlistWithoutASubprocess(): void
    {
        // The runner would answer 13.0, so a plist answer of 15.4 proves the file won.
        $check = new StartupCheck($this->swVersRunner(0, "13.0\n"), $this->plistFixture('15.4'));

        $this->assertSame('15.4', $check->macOsVersion());
    }

    public function testMacOsVersionFallsBackToSwVers(): void
    {
        $check = new StartupCheck($this->swVersRunner(0, "14.7.6\n"), '/nonexistent/SystemVersion.plist');

        $this->assertSame('14.7.6', $check->macOsVersion());
    }

    public function testMacOsVersionIsNullWhenNothingAnswers(): void
    {
        $check = new StartupCheck($this->swVersRunner(1, ''), '/nonexistent/SystemVersion.plist');

        $this->assertNull($check->macOsVersion());
    }

    public function testAnOldMacIsReportedWithBothVersionsInTheMessage(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('The macOS gate only applies on Darwin.');
        }

        $check   = new StartupCheck($this->swVersRunner(1, ''), $this->plistFixture('14.7.6'));
        $message = $check->unsupportedMacOs();

        $this->assertNotNull($message);
        $this->assertStringContainsString(App::MIN_MACOS_NAME, $message);
        $this->assertStringContainsString('14.7.6', $message);
    }

    public function testASupportedMacIsNotReported(): void
    {
        $check = new StartupCheck($this->swVersRunner(1, ''), $this->plistFixture('26.5.2'));

        $this->assertNull($check->unsupportedMacOs());
    }

    public function testAnUnreadableVersionDoesNotBlockTheLaunch(): void
    {
        // Neither source answers: the check must fail open rather than refuse to start on a
        // machine it merely could not identify.
        $check = new StartupCheck($this->swVersRunner(1, ''), '/nonexistent/SystemVersion.plist');

        $this->assertNull($check->unsupportedMacOs());
    }

    public function testAnUnparseableVersionDoesNotBlockTheLaunch(): void
    {
        $check = new StartupCheck($this->swVersRunner(1, ''), $this->plistFixture('macOS X'));

        $this->assertNull($check->unsupportedMacOs());
    }

    public function testNothingIsReportedOffDarwin(): void
    {
        if (\PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('Only meaningful on a non-macOS host.');
        }

        $check = new StartupCheck($this->swVersRunner(0, '14.0'), $this->plistFixture('14.0'));

        $this->assertNull($check->unsupportedMacOs());
    }

    public function testLibraryOverrideIsNullWithoutTheEnvironmentVariable(): void
    {
        putenv(StartupCheck::LIBRARY_ENV);

        $this->assertNull((new StartupCheck())->libraryOverride());
    }

    public function testLibraryOverrideIsTheTrimmedPathOfAnExistingFile(): void
    {
        $library = $this->plistFixture('irrelevant');
        putenv(StartupCheck::LIBRARY_ENV . '=  ' . $library . '  ');

        $this->assertSame($library, (new StartupCheck())->libraryOverride());
    }

    public function testLibraryOverrideIgnoresAPathThatDoesNotExist(): void
    {
        // A typo must degrade to Boson's own detection, not to a confusing dlopen error.
        putenv(StartupCheck::LIBRARY_ENV . '=/nonexistent/libboson.dylib');

        $this->assertNull((new StartupCheck())->libraryOverride());
    }

    public function testSupplyingYourOwnLibrarySwitchesTheVersionGateOff(): void
    {
        // The whole point of the escape hatch: you told us you brought your own library, so we
        // stop second-guessing the OS it was built for (gh-58).
        putenv(StartupCheck::LIBRARY_ENV . '=' . $this->plistFixture('irrelevant'));

        $check = new StartupCheck($this->swVersRunner(1, ''), $this->plistFixture('14.7.6'));

        $this->assertNull($check->unsupportedMacOs());
    }

    /** A runner answering `sw_vers` with a fixed exit code and stdout. */
    private function swVersRunner(int $code, string $stdout): ProcessRunner
    {
        return new class ($code, $stdout) extends ProcessRunner {
            public function __construct(
                private readonly int $code,
                private readonly string $stdout,
            ) {}

            public function run(array $command, ?string $stdin = null): array
            {
                return str_contains($command[0], 'sw_vers')
                    ? [$this->code, $this->stdout, '']
                    : [127, '', 'not found'];
            }
        };
    }

    /** A throwaway SystemVersion.plist reporting $version; returns its pathname. */
    private function plistFixture(string $version): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grafida-sysver-');

        if ($path === false) {
            self::fail('Could not create a temporary plist.');
        }

        $this->fixtures[] = $path;

        file_put_contents(
            $path,
            '<dict><key>ProductVersion</key><string>' . $version . '</string></dict>'
        );

        return $path;
    }
}
