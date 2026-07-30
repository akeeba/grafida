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
use Grafida\Media\InlineImageExtractor;
use Grafida\Media\MediaRepository;
use Grafida\Media\MediaUploadTarget;
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
 * Publishing a `media` custom field. Its value is the `accessiblemedia` record
 * (see {@see \Grafida\Field\MediaFieldValue}), and a picture picked from
 * Grafida's own media browser while offline is held as the same
 * `grafida-media://N` sentinel the intro/full-text images use — so it has to be
 * uploaded here, and described the way Joomla's own media field describes an
 * uploaded picture.
 */
final class PublishServiceMediaFieldTest extends TestCase
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

        // One article-wide `media` field, pre-seeded so mapFields() never needs
        // a network round trip to learn the field's type.
        $references = new ReferenceRepository($this->db);
        $references->put(1, ReferenceService::KIND_FIELDS, [
            ['id' => 5, 'name' => 'photo', 'label' => 'Photo', 'type' => 'media', 'required' => 0],
        ]);
        $references->put(1, ReferenceService::KIND_CATEGORIES, []);

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

        $this->transport->on(
            'https://example.com/index.php/api/v1/content/articles',
            new HttpResponse(
                201,
                json_encode(['data' => ['type' => 'articles', 'id' => '42', 'attributes' => [
                    'title' => 'Media field publish',
                ]]]),
                ['Content-Type' => 'application/vnd.api+json'],
            ),
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
            new MediaUploadTarget($apiClient),
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function draftWithFields(array $fields): Draft
    {
        $draft = new Draft(
            id: null,
            siteId: 1,
            remoteId: null,
            title: 'Media field publish',
            alias: 'media-field-publish',
            catid: null,
            access: 1,
            language: '*',
            state: 1,
            html: '<p>Body</p>',
            fields: $fields,
        );
        $draft->id = $this->drafts->insert($draft);

        return $draft;
    }

    /**
     * The `com_fields` block of the article write, decoded.
     *
     * @return array<string, mixed>
     */
    private function publishedFields(): array
    {
        foreach ($this->transport->requests as $request) {
            if ($request['url'] !== 'https://example.com/index.php/api/v1/content/articles') {
                continue;
            }

            $body = json_decode((string) $request['body'], true);

            return is_array($body) && is_array($body['com_fields'] ?? null) ? $body['com_fields'] : [];
        }

        return [];
    }

    /**
     * The offline sentinel is uploaded and replaced by the value Joomla's own
     * media field would hold, `#joomlaImage://` fragment and all — the fragment
     * is what gives the rendered <img> its width/height and lazy loading.
     */
    public function testOfflinePictureIsUploadedAndDescribedLikeJoomlasOwnMediaField(): void
    {
        $mediaId = $this->media->store(1, null, 'cat.png', 'image/png', 'raw-bytes', 640, 480);

        $this->transport->on(
            'https://example.com/index.php/api/v1/media/files',
            new HttpResponse(
                201,
                json_encode(['data' => ['type' => 'media', 'id' => '1', 'attributes' => [
                    'path'   => 'local-images:/grafida/' . $mediaId . '-cat.png',
                    'url'    => 'https://example.com/images/grafida/' . $mediaId . '-cat.png',
                    'width'  => 640,
                    'height' => 480,
                ]]]),
                ['Content-Type' => 'application/vnd.api+json'],
            ),
        );

        $draft = $this->draftWithFields([
            'photo' => json_encode([
                'imagefile' => 'grafida-media://' . $mediaId,
                'alt_text'  => 'A cat',
                'alt_empty' => '',
            ]),
        ]);

        $this->publishService()->publish($draft, $this->site);

        $expected = sprintf(
            'images/grafida/%1$d-cat.png#joomlaImage://local-images/grafida/%1$d-cat.png?width=640&height=480',
            $mediaId
        );

        self::assertSame(
            ['photo' => json_encode(
                ['imagefile' => $expected, 'alt_text' => 'A cat', 'alt_empty' => ''],
                \JSON_UNESCAPED_SLASHES
            )],
            $this->publishedFields()
        );
    }

    /** A picture already on the site is sent as it stands, only re-canonicalised. */
    public function testSitePathIsPublishedUnchanged(): void
    {
        $draft = $this->draftWithFields(['photo' => '{"imagefile":"images/existing.jpg","alt_text":"Alt"}']);

        $this->publishService()->publish($draft, $this->site);

        self::assertSame(
            ['photo' => '{"imagefile":"images/existing.jpg","alt_text":"Alt","alt_empty":""}'],
            $this->publishedFields()
        );
        self::assertSame(
            [],
            array_values(array_filter(
                $this->transport->requests,
                static fn (array $r): bool => str_contains($r['url'], '/media/files'),
            )),
            'A picture already on the site must not be re-uploaded.'
        );
    }

    /** A Joomla 3 bare path is understood rather than silently blanked. */
    public function testLegacyBarePathIsUpgradedToTheRecordShape(): void
    {
        $draft = $this->draftWithFields(['photo' => 'images/legacy.jpg']);

        $this->publishService()->publish($draft, $this->site);

        self::assertSame(
            ['photo' => '{"imagefile":"images/legacy.jpg","alt_text":"","alt_empty":""}'],
            $this->publishedFields()
        );
    }

    /**
     * A sentinel whose blob has been deleted from the Local Media tab is
     * dropped, exactly as resolveImages() drops an intro image's: publishing it
     * verbatim would put `grafida-media://999999` on the live site.
     */
    public function testSentinelForADeletedBlobIsDroppedRatherThanPublished(): void
    {
        $draft = $this->draftWithFields([
            'photo' => '{"imagefile":"grafida-media://999999","alt_text":"Gone","alt_empty":""}',
        ]);

        $this->publishService()->publish($draft, $this->site);

        // An empty value is how plg_system_fields is told to clear the field.
        self::assertSame(['photo' => ''], $this->publishedFields());
    }

    /** An unsupported field type is still never sent. */
    public function testValueForAFieldTheSiteDoesNotHaveIsNotSent(): void
    {
        $draft = $this->draftWithFields(['nosuchfield' => 'x']);

        $this->publishService()->publish($draft, $this->site);

        self::assertSame([], $this->publishedFields());
    }
}
