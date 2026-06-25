<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Html\CssRebaser;

final class CssRebaserTest extends TestCase
{
    private const CSS_URL = 'https://example.com/templates/cassiopeia/css/editor.css';

    public function testRebasesDocumentRelativeUrl(): void
    {
        $out = (new CssRebaser())->rebase('body{background:url(../images/bg.png)}', self::CSS_URL);

        self::assertStringContainsString('url(https://example.com/templates/cassiopeia/images/bg.png)', $out);
    }

    public function testRebasesRootRelativeUrl(): void
    {
        $out = (new CssRebaser())->rebase('body{background:url(/media/logo.png)}', self::CSS_URL);

        self::assertStringContainsString('url(https://example.com/media/logo.png)', $out);
    }

    public function testLeavesAbsoluteAndDataUrlsAlone(): void
    {
        $css = 'a{background:url(https://cdn.test/x.png)} b{background:url(data:image/png;base64,AAAA)}';

        $out = (new CssRebaser())->rebase($css, self::CSS_URL);

        self::assertStringContainsString('url(https://cdn.test/x.png)', $out);
        self::assertStringContainsString('url(data:image/png;base64,AAAA)', $out);
    }

    public function testPreservesQuotes(): void
    {
        $out = (new CssRebaser())->rebase("i{background:url('fonts/a.woff')}", self::CSS_URL);

        self::assertStringContainsString("url('https://example.com/templates/cassiopeia/css/fonts/a.woff')", $out);
    }
}
