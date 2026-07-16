<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Http\HttpResponse;
use Grafida\Joomla\ApiClient;
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\TemplateDiscovery;
use Grafida\Site\Site;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\FakeTransport;
use Joomla\Database\DatabaseInterface;

final class TemplateDiscoveryTest extends TestCase
{
    /** The URL ApiClient builds for the template-styles collection. */
    private const STYLES_URL = 'https://example.com/index.php/api/v1/templates/styles/site?page%5Blimit%5D=0';

    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::memory();

        // An insecure_token keeps SiteService::tokenFor() away from the keychain.
        TestDatabase::connection($this->db)->exec(
            "INSERT INTO sites (id, title, base_url, api_base, insecure_token, created_at, updated_at) "
            . "VALUES (7, 'Example', 'https://example.com', 'https://example.com/index.php/api', 'TOK', '2026-01-01', '2026-01-01')"
        );
    }

    private function site(): Site
    {
        return new Site(7, 'Example', 'https://example.com', 'https://example.com/index.php/api', null, false);
    }

    private function discovery(FakeTransport $transport): TemplateDiscovery
    {
        $api = new ApiClient($transport);

        return new TemplateDiscovery(
            new ReferenceRepository($this->db),
            new SiteService(new SiteRepository($this->db), $api, null),
            $api,
            $transport,
        );
    }

    private function homePage(string $html): FakeTransport
    {
        return (new FakeTransport())->on('https://example.com/', new HttpResponse(200, $html));
    }

    /**
     * @param list<array{template: string, home: string}> $styles
     */
    private function withStyles(FakeTransport $transport, array $styles): FakeTransport
    {
        $data = [];

        foreach ($styles as $i => $style) {
            $data[] = [
                'type'       => 'styles',
                'id'         => (string) ($i + 1),
                'attributes' => ['template' => $style['template'], 'home' => $style['home']],
            ];
        }

        $body = json_encode(['data' => $data], \JSON_THROW_ON_ERROR);

        return $transport->on(self::STYLES_URL, new HttpResponse(200, $body, ['content-type' => 'application/vnd.api+json']));
    }

    public function testDiscoversTheTemplateFromAModernMediaPath(): void
    {
        $transport = $this->homePage(
            '<link rel="stylesheet" href="/media/templates/site/cassiopeia_brianv5/css/template.css">'
        );

        self::assertSame(['cassiopeia_brianv5'], $this->discovery($transport)->templates($this->site()));
    }

    public function testDiscoversALegacyTemplatePath(): void
    {
        $transport = $this->homePage('<link rel="stylesheet" href="/templates/protostar/css/template.css">');

        self::assertSame(['protostar'], $this->discovery($transport)->templates($this->site()));
    }

    /** /media/templates/site/x/ must not also register as a legacy "x" match. */
    public function testDoesNotDoubleCountAMediaPathAsALegacyPath(): void
    {
        $transport = $this->homePage('<link rel="stylesheet" href="/media/templates/site/mytpl/css/template.css">');

        self::assertSame(['mytpl'], $this->discovery($transport)->templates($this->site()));
    }

    public function testIgnoresJoomlasSharedSystemAssets(): void
    {
        $transport = $this->homePage(
            '<link rel="stylesheet" href="/templates/system/css/system.css">'
            . '<link rel="stylesheet" href="/media/templates/site/mytpl/css/template.css">'
        );

        self::assertSame(['mytpl'], $this->discovery($transport)->templates($this->site()));
    }

    public function testPrefersTheMediaPathAndDeduplicatesNames(): void
    {
        $transport = $this->homePage(
            '<link rel="stylesheet" href="/templates/legacytpl/css/template.css">'
            . '<link rel="stylesheet" href="/media/templates/site/mytpl/css/template.css">'
            . '<script src="/media/templates/site/mytpl/js/template.js"></script>'
        );

        self::assertSame(['mytpl', 'legacytpl'], $this->discovery($transport)->templates($this->site()));
    }

    public function testFindsANameInsideAnInlineStyleBlock(): void
    {
        $transport = $this->homePage('<style>@import url("/media/templates/site/mytpl/css/extra.css");</style>');

        self::assertSame(['mytpl'], $this->discovery($transport)->templates($this->site()));
    }

    public function testCachesTheDiscoveredNamesAndServesThemWhenTheSiteIsUnreachable(): void
    {
        $html = '<link rel="stylesheet" href="/media/templates/site/mytpl/css/template.css">';
        $this->discovery($this->homePage($html))->templates($this->site());

        $offline = new FakeTransport(new HttpResponse(500, ''));

        self::assertSame(['mytpl'], $this->discovery($offline)->templates($this->site()));
    }

    public function testReturnsNothingWhenThePageNamesNoTemplate(): void
    {
        $transport = $this->homePage('<html><head></head><body>Hello</body></html>');

        self::assertSame([], $this->discovery($transport)->templates($this->site()));
    }

    public function testATransportFailureIsNotAnError(): void
    {
        $transport = (new FakeTransport())->throwFor('https://example.com/');

        self::assertSame([], $this->discovery($transport)->templates($this->site()));
    }

    // -----------------------------------------------------------------------
    //  The template styles API (gh-3)
    // -----------------------------------------------------------------------

    /**
     * The bug behind gh-3: a child template overriding nothing but editor.css
     * renders no asset URL of its own, so the home page only ever names its
     * parent. Only the styles API can see it.
     */
    public function testFindsAChildTemplateThePageOnlyNamesTheParentOf(): void
    {
        $transport = $this->withStyles(
            $this->homePage('<link rel="stylesheet" href="/media/templates/site/cassiopeia/css/template.css">'),
            [['template' => 'fool', 'home' => '1']]
        );

        self::assertSame(['fool', 'cassiopeia'], $this->discovery($transport)->templates($this->site()));
    }

    public function testPrefersTheDefaultStyleOverALanguageHome(): void
    {
        $transport = $this->withStyles($this->homePage(''), [
            ['template' => 'greek_child', 'home' => 'el-GR'],
            ['template' => 'fool', 'home' => '1'],
        ]);

        self::assertSame(['fool', 'greek_child'], $this->discovery($transport)->templates($this->site()));
    }

    /**
     * A style assigned to no menu item names a merely-installed template; its
     * editor.css must never be tried ahead of the honest fallbacks.
     */
    public function testIgnoresStylesThatAreNotAHome(): void
    {
        $transport = $this->withStyles($this->homePage(''), [
            ['template' => 'fool', 'home' => '1'],
            ['template' => 'unused_template', 'home' => '0'],
        ]);

        self::assertSame(['fool'], $this->discovery($transport)->templates($this->site()));
    }

    public function testAnApiFailureFallsBackToScanningThePage(): void
    {
        $transport = $this->homePage('<link rel="stylesheet" href="/media/templates/site/cassiopeia/css/template.css">')
            ->on(self::STYLES_URL, new HttpResponse(403, '{"errors":[{"code":403}]}'));

        self::assertSame(['cassiopeia'], $this->discovery($transport)->templates($this->site()));
    }

    public function testDoesNotRepeatATemplateBothWitnessesName(): void
    {
        $transport = $this->withStyles(
            $this->homePage('<link rel="stylesheet" href="/media/templates/site/cassiopeia/css/template.css">'),
            [['template' => 'cassiopeia', 'home' => '1']]
        );

        self::assertSame(['cassiopeia'], $this->discovery($transport)->templates($this->site()));
    }

    public function testASiteWithNoTokenStillScansThePage(): void
    {
        $transport = $this->homePage('<link rel="stylesheet" href="/media/templates/site/mytpl/css/template.css">');

        TestDatabase::connection($this->db)->exec('UPDATE sites SET insecure_token = NULL WHERE id = 7');

        self::assertSame(['mytpl'], $this->discovery($transport)->templates($this->site()));
    }
}
