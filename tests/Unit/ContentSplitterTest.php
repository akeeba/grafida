<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Html\ContentSplitter;

final class ContentSplitterTest extends TestCase
{
    public function testSplitsOnReadMoreMarker(): void
    {
        $splitter = new ContentSplitter();

        $result = $splitter->split('<p>Intro paragraph.</p><hr class="readmore"><p>The rest.</p>');

        self::assertStringContainsString('Intro paragraph.', $result['introtext']);
        self::assertStringNotContainsString('The rest.', $result['introtext']);
        self::assertStringContainsString('The rest.', $result['fulltext']);
        self::assertStringNotContainsString('readmore', $result['introtext'] . $result['fulltext']);
    }

    public function testWholeContentIsIntrotextWithoutMarker(): void
    {
        $splitter = new ContentSplitter();

        $result = $splitter->split('<p>Only intro here.</p>');

        self::assertStringContainsString('Only intro here.', $result['introtext']);
        self::assertSame('', $result['fulltext']);
    }

    public function testCountsMarkers(): void
    {
        $splitter = new ContentSplitter();

        self::assertSame(0, $splitter->countMarkers('<p>x</p>'));
        self::assertSame(1, $splitter->countMarkers('<p>a</p><hr class="readmore"><p>b</p>'));
        self::assertSame(2, $splitter->countMarkers('<hr class="readmore"><hr class="foo readmore">'));
    }

    public function testHandlesUtf8(): void
    {
        $splitter = new ContentSplitter();

        $result = $splitter->split('<p>Καλημέρα</p><hr class="readmore"><p>Κόσμε</p>');

        self::assertStringContainsString('Καλημέρα', $result['introtext']);
        self::assertStringContainsString('Κόσμε', $result['fulltext']);
    }
}
