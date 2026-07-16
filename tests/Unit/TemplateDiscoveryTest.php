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
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\TemplateDiscovery;
use Grafida\Site\Site;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\FakeTransport;
use Joomla\Database\DatabaseInterface;

final class TemplateDiscoveryTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::memory();

        TestDatabase::connection($this->db)->exec(
            "INSERT INTO sites (id, title, base_url, api_base, created_at, updated_at) "
            . "VALUES (7, 'Example', 'https://example.com', 'https://example.com/index.php/api', '2026-01-01', '2026-01-01')"
        );
    }

    private function site(): Site
    {
        return new Site(7, 'Example', 'https://example.com', 'https://example.com/index.php/api', null, false);
    }

    private function discovery(FakeTransport $transport): TemplateDiscovery
    {
        return new TemplateDiscovery(new ReferenceRepository($this->db), $transport);
    }

    private function homePage(string $html): FakeTransport
    {
        return (new FakeTransport())->on('https://example.com/', new HttpResponse(200, $html));
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
}
