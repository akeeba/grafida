<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Html\InlineMedia;

final class InlineMediaTest extends TestCase
{
    public function testFindsPendingMediaIds(): void
    {
        $html = '<p><img src="data:image/png;base64,AAA" data-grafida-media-id="7">'
            . '<img src="https://example.com/done.png" data-grafida-media-id="8"></p>';

        $ids = (new InlineMedia())->pendingMediaIds($html);

        self::assertSame([7], $ids);
    }

    public function testReplacesDataUriWithPublicUrl(): void
    {
        $html = '<p><img src="data:image/png;base64,AAA" data-grafida-media-id="7"></p>';

        $out = (new InlineMedia())->applyUploadedUrls($html, [7 => 'https://example.com/images/grafida/x.png']);

        self::assertStringContainsString('src="https://example.com/images/grafida/x.png"', $out);
        self::assertStringNotContainsString('data:image/png', $out);
        self::assertStringNotContainsString('data-grafida-media-id', $out);
    }
}
