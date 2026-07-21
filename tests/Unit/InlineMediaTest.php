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
    public function testRewritesTaggedDataImageIntoMediaFieldImg(): void
    {
        $html = '<p><img src="data:image/png;base64,AAA" data-grafida-media-id="7">'
            . '<img src="https://example.com/done.png"></p>';

        $seen = [];
        $out  = (new InlineMedia())->rewriteOfflineImages($html, function (?int $id, ?string $uri) use (&$seen): array {
            $seen[] = [$id, $uri];

            return [
                'src'      => 'images/grafida/x.png',
                'dataPath' => 'local-images:/grafida/x.png',
                'width'    => 640,
                'height'   => 480,
            ];
        });

        // Only the data: image is offered to the callback, tagged with its blob id.
        self::assertSame([[7, 'data:image/png;base64,AAA']], $seen);
        self::assertStringContainsString('src="images/grafida/x.png"', $out);
        self::assertStringContainsString('data-path="local-images:/grafida/x.png"', $out);
        self::assertStringContainsString('width="640"', $out);
        self::assertStringContainsString('height="480"', $out);
        self::assertStringContainsString('loading="lazy"', $out);
        self::assertStringNotContainsString('data:image/png', $out);
        self::assertStringNotContainsString('data-grafida-media-id', $out);
    }

    public function testRewritesUntaggedDataImageWithNullId(): void
    {
        // A pasted/dropped image lands without the data-grafida-media-id tag; it
        // must still be offered to the callback (id null) so it gets uploaded.
        $html = '<p><img src="data:image/png;base64,BBB"></p>';

        $ids = [];
        $out = (new InlineMedia())->rewriteOfflineImages($html, function (?int $id, ?string $uri) use (&$ids): array {
            $ids[] = $id;

            return ['src' => 'images/grafida/y.png', 'dataPath' => 'local-images:/grafida/y.png'];
        });

        self::assertSame([null], $ids);
        self::assertStringContainsString('src="images/grafida/y.png"', $out);
        self::assertStringContainsString('data-path="local-images:/grafida/y.png"', $out);
        self::assertStringNotContainsString('data:image/png', $out);
    }

    public function testRewritesLocalMediaUrlImage(): void
    {
        // The gh-36 common case: an <img> pointing at the Boson-served local
        // blob, with no data: URI at all — only the id, parsed from the URL.
        $html = '<p><img src="boson://app/api/media/42/raw?rev=abc12345"></p>';

        $seen = [];
        $out  = (new InlineMedia())->rewriteOfflineImages($html, function (?int $id, ?string $uri) use (&$seen): array {
            $seen[] = [$id, $uri];

            return [
                'src'      => 'images/grafida/z.png',
                'dataPath' => 'local-images:/grafida/z.png',
                'width'    => 100,
                'height'   => 200,
            ];
        });

        self::assertSame([[42, null]], $seen);
        self::assertStringContainsString('src="images/grafida/z.png"', $out);
        self::assertStringNotContainsString('boson://app/api/media/42/raw', $out);
    }

    public function testRewritesLocalMediaUrlImageWithoutRevQuery(): void
    {
        $html = '<p><img src="boson://app/api/media/9/raw"></p>';

        $ids = [];
        $out = (new InlineMedia())->rewriteOfflineImages($html, function (?int $id, ?string $uri) use (&$ids): array {
            $ids[] = $id;

            return ['src' => 'images/grafida/w.png'];
        });

        self::assertSame([9], $ids);
        self::assertStringContainsString('src="images/grafida/w.png"', $out);
    }

    public function testLocalMediaUrlIdWinsOverStaleAttribute(): void
    {
        // The id in the src is what actually rendered; a stale/mismatched
        // data-grafida-media-id attribute must never override it.
        $html = '<p><img src="boson://app/api/media/5/raw?rev=zzz" data-grafida-media-id="999"></p>';

        $ids = [];
        $out = (new InlineMedia())->rewriteOfflineImages($html, function (?int $id, ?string $uri) use (&$ids): array {
            $ids[] = $id;

            return ['src' => 'images/grafida/v.png'];
        });

        self::assertSame([5], $ids);
        self::assertStringNotContainsString('data-grafida-media-id', $out);
    }

    public function testBothFormsInOneDocument(): void
    {
        $html = '<p><img src="boson://app/api/media/1/raw?rev=aaa">'
            . '<img src="data:image/png;base64,CCC" data-grafida-media-id="2">'
            . '<img src="https://example.com/done.png"></p>';

        $seen = [];
        $out  = (new InlineMedia())->rewriteOfflineImages($html, function (?int $id, ?string $uri) use (&$seen): array {
            $seen[] = [$id, $uri];

            return ['src' => 'images/grafida/' . $id . '.png'];
        });

        self::assertSame([
            [1, null],
            [2, 'data:image/png;base64,CCC'],
        ], $seen);
        self::assertStringContainsString('src="images/grafida/1.png"', $out);
        self::assertStringContainsString('src="images/grafida/2.png"', $out);
        self::assertStringContainsString('src="https://example.com/done.png"', $out);
    }

    public function testExistingWidthAndHeightAttributesAreNotOverwritten(): void
    {
        // A hand-set width/height on the <img> (e.g. from a prior publish, or
        // hand-edited HTML) must win over whatever the upload result reports —
        // applyResult() only fills in the attribute when it is absent.
        $html = '<p><img src="data:image/png;base64,AAA" width="10" height="20"></p>';

        $out = (new InlineMedia())->rewriteOfflineImages(
            $html,
            static fn (?int $id, ?string $uri): array => ['src' => 'images/grafida/x.png', 'width' => 999, 'height' => 888],
        );

        self::assertStringContainsString('width="10"', $out);
        self::assertStringContainsString('height="20"', $out);
        self::assertStringNotContainsString('999', $out);
        self::assertStringNotContainsString('888', $out);
    }

    public function testExternalUrlOutputIsByteIdenticalToInput(): void
    {
        $html = '<p>Text <img src="https://example.com/photo.png" width="5" height="5"> more.</p>';

        $out = (new InlineMedia())->rewriteOfflineImages(
            $html,
            static fn (?int $id, ?string $uri): array => throw new \LogicException('must not be called'),
        );

        self::assertSame($html, $out);
    }

    public function testLeavesHtmlUntouchedWhenNoOfflineImages(): void
    {
        $html = '<p><img src="https://example.com/done.png"></p>';

        $out = (new InlineMedia())->rewriteOfflineImages($html, static fn (?int $id, ?string $uri): array => ['src' => 'unused']);

        self::assertSame($html, $out);
    }
}
