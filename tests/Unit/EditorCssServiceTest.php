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
use Grafida\Reference\EditorCssService;
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\TemplateDiscovery;
use Grafida\Site\Site;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\FakeTransport;
use Joomla\Database\DatabaseInterface;

final class EditorCssServiceTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::memory();

        // A site row must exist for the FK-constrained caches.
        TestDatabase::connection($this->db)->exec(
            "INSERT INTO sites (id, title, base_url, api_base, created_at, updated_at) "
            . "VALUES (7, 'Example', 'https://example.com', 'https://example.com/index.php/api', '2026-01-01', '2026-01-01')"
        );
    }

    private function site(?string $editorCssUrl = null): Site
    {
        return new Site(7, 'Example', 'https://example.com', 'https://example.com/index.php/api', null, false, '*', $editorCssUrl);
    }

    private function service(FakeTransport $transport): EditorCssService
    {
        $repo = new ReferenceRepository($this->db);
        $api  = new ApiClient($transport);

        // The site row carries no token, so discovery here rests on the home-page
        // scan alone; the styles API has its own coverage in TemplateDiscoveryTest.
        $discovery = new TemplateDiscovery(
            $repo,
            new SiteService(new SiteRepository($this->db), $api, null),
            $api,
            $transport,
        );

        return new EditorCssService($repo, $discovery, new \Grafida\Html\CssRebaser(), $transport);
    }

    /** The bug this fixes: a template that is not stock Cassiopeia was never found. */
    public function testFindsEditorCssOfADiscoveredChildTemplate(): void
    {
        $html = '<html><head>'
            . '<link rel="stylesheet" href="/media/templates/site/cassiopeia_brianv5/css/template.css">'
            . '</head><body></body></html>';

        $transport = (new FakeTransport())
            ->on('https://example.com/', new HttpResponse(200, $html))
            ->on(
                'https://example.com/media/templates/site/cassiopeia_brianv5/css/editor.css',
                new HttpResponse(200, 'p { color: red }')
            );

        self::assertSame('p { color: red }', $this->service($transport)->load($this->site()));
    }

    public function testManualOverrideWinsOverDiscovery(): void
    {
        $html = '<html><head>'
            . '<link rel="stylesheet" href="/media/templates/site/cassiopeia_brianv5/css/template.css">'
            . '</head><body></body></html>';

        $transport = (new FakeTransport())
            ->on('https://example.com/', new HttpResponse(200, $html))
            ->on(
                'https://example.com/media/templates/site/cassiopeia_brianv5/css/editor.css',
                new HttpResponse(200, 'p { color: red }')
            )
            ->on('https://example.com/custom/editor.css', new HttpResponse(200, 'p { color: blue }'));

        $css = $this->service($transport)->load($this->site('/custom/editor.css'));

        self::assertSame('p { color: blue }', $css);
    }

    public function testAbsoluteOverrideUrlIsUsedAsGiven(): void
    {
        $transport = (new FakeTransport())
            ->on('https://cdn.example.com/editor.css', new HttpResponse(200, 'p { color: green }'));

        $css = $this->service($transport)->load($this->site('https://cdn.example.com/editor.css'));

        self::assertSame('p { color: green }', $css);
    }

    public function testFallsBackToStockCassiopeiaWhenNothingIsDiscovered(): void
    {
        $transport = (new FakeTransport())
            ->on('https://example.com/', new HttpResponse(200, '<html><head></head><body></body></html>'))
            ->on(
                'https://example.com/media/templates/site/cassiopeia/css/editor.css',
                new HttpResponse(200, 'p { color: black }')
            );

        self::assertSame('p { color: black }', $this->service($transport)->load($this->site()));
    }

    public function testServesTheCachedCopyWhenTheSiteIsUnreachable(): void
    {
        (new ReferenceRepository($this->db))->putEditorCss(7, 'p { color: cached }');

        $transport = new FakeTransport(new HttpResponse(500, ''));

        self::assertSame('p { color: cached }', $this->service($transport)->load($this->site(), true));
    }

    public function testReturnsNullWhenNothingIsFoundAndNothingIsCached(): void
    {
        self::assertNull($this->service(new FakeTransport())->load($this->site()));
    }

    /**
     * The bug this fixes: finding the stylesheet costs template discovery plus a
     * walk over every candidate URL, and it was paid on every editor open — in
     * front of the user, before TinyMCE was created.
     */
    public function testACachedStylesheetIsServedWithoutTouchingTheNetwork(): void
    {
        (new ReferenceRepository($this->db))->putEditorCss(7, 'p { color: cached }');

        $transport = new FakeTransport();

        self::assertSame('p { color: cached }', $this->service($transport)->load($this->site()));
        self::assertSame([], $transport->requests);
    }

    public function testRefreshLooksAgainEvenWithACachedCopy(): void
    {
        (new ReferenceRepository($this->db))->putEditorCss(7, 'p { color: cached }');

        $transport = (new FakeTransport())
            ->on('https://example.com/', new HttpResponse(200, '<html><head></head><body></body></html>'))
            ->on(
                'https://example.com/media/templates/site/cassiopeia/css/editor.css',
                new HttpResponse(200, 'p { color: fresh }')
            );

        self::assertSame('p { color: fresh }', $this->service($transport)->load($this->site(), true));
    }

    /**
     * A site whose template ships no editor.css must not pay the candidate walk
     * on every single open, so the miss is cached too.
     */
    public function testAMissIsCachedWhenTheSiteAnswered(): void
    {
        $transport = new FakeTransport(new HttpResponse(404, ''));

        self::assertNull($this->service($transport)->load($this->site()));

        $second = new FakeTransport();

        self::assertNull($this->service($second)->load($this->site()));
        self::assertSame([], $second->requests);
    }

    /**
     * ...but an unreachable site taught us nothing, so the next open must be
     * free to look again — otherwise adding a site while offline would leave the
     * editor unstyled until the next metadata refresh.
     */
    public function testAMissIsNotCachedWhenNoCandidateAnswered(): void
    {
        self::assertNull($this->service((new FakeTransport())->throwForAll(6))->load($this->site()));

        $second = (new FakeTransport())
            ->on('https://example.com/', new HttpResponse(200, '<html><head></head><body></body></html>'))
            ->on(
                'https://example.com/media/templates/site/cassiopeia/css/editor.css',
                new HttpResponse(200, 'p { color: black }')
            );

        self::assertSame('p { color: black }', $this->service($second)->load($this->site()));
    }

    /** A refresh that finds nothing must not throw away the stylesheet we already had. */
    public function testAFailedRefreshKeepsTheCachedStylesheet(): void
    {
        (new ReferenceRepository($this->db))->putEditorCss(7, 'p { color: cached }');

        self::assertSame(
            'p { color: cached }',
            $this->service(new FakeTransport(new HttpResponse(404, '')))->load($this->site(), true)
        );
        self::assertSame(
            'p { color: cached }',
            $this->service(new FakeTransport())->load($this->site())
        );
    }

    /** A url() in the fetched CSS is rebased against the stylesheet it came from. */
    public function testRebasesRelativeUrlsAgainstTheStylesheet(): void
    {
        $html = '<html><head>'
            . '<link rel="stylesheet" href="/media/templates/site/mytpl/css/template.css">'
            . '</head><body></body></html>';

        $transport = (new FakeTransport())
            ->on('https://example.com/', new HttpResponse(200, $html))
            ->on(
                'https://example.com/media/templates/site/mytpl/css/editor.css',
                new HttpResponse(200, 'body { background: url(../images/bg.png) }')
            );

        $css = $this->service($transport)->load($this->site());

        self::assertNotNull($css);
        self::assertStringContainsString('https://example.com/media/templates/site/mytpl/images/bg.png', $css);
    }
}
