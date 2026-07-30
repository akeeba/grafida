<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Startup;

use Grafida\Startup\FailureReporter;
use Grafida\Support\App;
use Grafida\Tests\Unit\TestCase;

final class FailureReporterTest extends TestCase
{
    private const FFI_MESSAGE = "Failed loading 'libboson-darwin-universal.dylib' "
        . '(built for macOS 15.0 which is newer than running OS)';

    public function testTheMacOsAdviceNamesTheMinimumVersion(): void
    {
        $text = FailureReporter::describe(new \RuntimeException(self::FFI_MESSAGE), 'Darwin');

        $this->assertStringContainsString(App::MIN_MACOS_NAME, $text);
    }

    public function testTheLinuxAdviceNamesThePackagesToInstall(): void
    {
        $text = FailureReporter::describe(new \RuntimeException(self::FFI_MESSAGE), 'Linux');

        $this->assertStringContainsString('libgtk-4-1', $text);
        $this->assertStringContainsString('libwebkitgtk-6.0-4', $text);
    }

    public function testTheWindowsAdviceNamesTheWebView2Runtime(): void
    {
        $text = FailureReporter::describe(new \RuntimeException(self::FFI_MESSAGE), 'Windows');

        $this->assertStringContainsString('WebView2', $text);
    }

    public function testAnUnknownPlatformStillGetsAnExplanation(): void
    {
        $text = FailureReporter::describe(new \RuntimeException(self::FFI_MESSAGE), 'Haiku');

        $this->assertStringContainsString('webview library', $text);
    }

    public function testTheOriginalErrorIsAlwaysKept(): void
    {
        // The FFI message names the library and the missing symbol; that is the whole content of
        // a useful bug report, so no branch may drop it.
        foreach (['Darwin', 'Linux', 'Windows', 'Haiku'] as $family) {
            $text = FailureReporter::describe(new \RuntimeException(self::FFI_MESSAGE), $family);

            $this->assertStringContainsString(self::FFI_MESSAGE, $text, $family);
            $this->assertStringContainsString(\RuntimeException::class, $text, $family);
        }
    }
}
