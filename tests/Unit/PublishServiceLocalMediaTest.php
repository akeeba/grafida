<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\Http\HttpResponse;
use Grafida\I18n\LanguageService;
use Grafida\Joomla\ApiClient;
use Grafida\Joomla\ApiException;
use Grafida\Media\InlineImageExtractor;
use Grafida\Media\LocalMediaUrl;
use Grafida\Media\MediaRepository;
use Grafida\Publish\PublishService;
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\ReferenceService;
use Grafida\Site\Site;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Storage\SettingsRepository;
use Grafida\Tests\Unit\Support\FakeTransport;
use Grafida\Tests\Support\TestDatabase;
use Joomla\Database\DatabaseInterface;

/**
 * gh-36: a draft whose body references an offline blob by its local URL
 * (`boson://app/api/media/{id}/raw`) must publish exactly like the legacy
 * `data:`-tagged form — uploaded to the site's Media Manager and rewritten
 * into the same `<img src="images/…" data-path="…">` Joomla's own media
 * field emits — and a reference to a since-deleted blob must abort the
 * publish with the dedicated error rather than leak a `boson://` src into
 * the article.
 */
final class PublishServiceLocalMediaTest extends TestCase
{
    private DatabaseInterface $db;
    private MediaRepository $media;
    private DraftRepository $drafts;
    private FakeTransport $transport;
    private Site $site;

    protected function setUp(): void
    {
        $this->db   = TestDatabase::memory();
        $connection = TestDatabase::connection($this->db);
        $connection->exec(
            'INSERT INTO sites (id, title, base_url, api_base, insecure_token, created_at, updated_at) '
            . "VALUES (1, 'Site', 'https://example.com', 'https://example.com/index.php/api', 'tok', "
            . "'2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );

        // Pre-seed the "fields" reference cache empty so guardRequiredUnsupportedFields()
        // and mapFields() never need a network round trip for this test.
        (new ReferenceRepository($this->db))->put(1, ReferenceService::KIND_FIELDS, []);

        $this->media     = new MediaRepository($this->db);
        $this->drafts    = new DraftRepository($this->db);
        $this->transport = new FakeTransport();
        $this->site      = new Site(
            id: 1,
            title: 'Site',
            baseUrl: 'https://example.com',
            apiBase: 'https://example.com/index.php/api',
            secretRef: null,
            hasInsecureToken: true,
        );
    }

    private function publishService(): PublishService
    {
        $apiClient = new ApiClient($this->transport);
        $siteRepo  = new SiteRepository($this->db);
        $sites     = new SiteService($siteRepo, $apiClient, null);
        $refs      = new ReferenceService(new ReferenceRepository($this->db), $sites, $apiClient);
        $language  = new LanguageService(new SettingsRepository($this->db), \dirname(__DIR__, 2));

        return new PublishService(
            $sites,
            $apiClient,
            $refs,
            $this->drafts,
            $this->media,
            $language,
            new InlineImageExtractor($this->media),
        );
    }

    private function draftWithHtml(string $html): Draft
    {
        $draft = new Draft(
            id: null,
            siteId: 1,
            remoteId: null,
            title: 'Local media publish',
            alias: 'local-media-publish',
            catid: null,
            access: 1,
            language: '*',
            state: 1,
            html: $html,
        );
        $draft->id = $this->drafts->insert($draft);

        return $draft;
    }

    public function testDraftWithLocalUrlImagePublishesAsMediaFieldImg(): void
    {
        $mediaId = $this->media->store(1, null, 'photo.png', 'image/png', 'raw-bytes', 640, 480);
        $meta    = $this->media->findMeta($mediaId);
        self::assertNotNull($meta);
        $localUrl = LocalMediaUrl::build($mediaId, $meta['updated_at'] ?? $meta['created_at']);

        $draft = $this->draftWithHtml('<p><img src="' . $localUrl . '"></p>');

        $this->transport->on(
            'https://example.com/index.php/api/v1/media/files',
            new HttpResponse(
                201,
                json_encode(['data' => ['type' => 'media', 'id' => '1', 'attributes' => [
                    'path'   => 'grafida/photo.png',
                    'url'    => 'images/grafida/photo.png',
                    'width'  => 640,
                    'height' => 480,
                ]]]),
                ['Content-Type' => 'application/vnd.api+json'],
            ),
        );
        $this->transport->on(
            'https://example.com/index.php/api/v1/content/articles',
            new HttpResponse(
                201,
                json_encode(['data' => ['type' => 'articles', 'id' => '42', 'attributes' => [
                    'title' => 'Local media publish',
                ]]]),
                ['Content-Type' => 'application/vnd.api+json'],
            ),
        );

        $result = $this->publishService()->publish($draft, $this->site);

        self::assertSame(42, $result['remoteId']);
        self::assertTrue($result['created']);

        // The publish rewrites and persists the draft's html back to the DB.
        $stored = $this->drafts->find($draft->id ?? 0);
        self::assertNotNull($stored);
        self::assertStringContainsString('src="images/grafida/photo.png"', $stored->html);
        self::assertStringContainsString('data-path="grafida/photo.png"', $stored->html);
        self::assertStringContainsString('width="640"', $stored->html);
        self::assertStringNotContainsString('boson://', $stored->html);
    }

    public function testDraftReferencingDeletedBlobFailsPublishInsteadOfLeakingLocalUrl(): void
    {
        // A local URL naming a blob id that was never stored (i.e. deleted from
        // the Local Media tab) — nothing for uploadInlineImage() to fall back on.
        $draft = $this->draftWithHtml('<p><img src="boson://app/api/media/999999/raw?rev=deadbeef"></p>');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('no longer exists');

        $this->publishService()->publish($draft, $this->site);
    }
}
