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
use Grafida\Publish\PublishBlockedException;
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
 * The required-unsupported-field guard (gh-59).
 *
 * A required custom field of a type Grafida cannot edit is a hard 400 from
 * Joomla's API — the write is validated against com_content's edit form, and
 * `FormField::validate()` rejects a required field with an empty value. So the
 * publish is stopped locally; what changes with gh-59 is that it is no longer
 * *always* a dead end: a draft imported from the live article carries the values
 * the site reported for those fields, and sending them back verbatim satisfies
 * the form.
 */
final class PublishServiceUnsupportedFieldsTest extends TestCase
{
    private DatabaseInterface $db;
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
                    'title' => 'Guarded',
                ]]]),
                ['Content-Type' => 'application/vnd.api+json'],
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function seedFields(array $fields): void
    {
        $references = new ReferenceRepository($this->db);
        $references->put(1, ReferenceService::KIND_FIELDS, $fields);
        $references->put(1, ReferenceService::KIND_CATEGORIES, []);
    }

    private function publishService(): PublishService
    {
        $apiClient = new ApiClient($this->transport);
        $siteRepo  = new SiteRepository($this->db);
        $sites     = new SiteService($siteRepo, $apiClient, null);
        $refs      = new ReferenceService(new ReferenceRepository($this->db), $sites, $apiClient);
        $language  = new LanguageService(new SettingsRepository($this->db), \dirname(__DIR__, 2));
        $media     = new MediaRepository($this->db);

        return new PublishService(
            $sites,
            $apiClient,
            $refs,
            $this->drafts,
            $media,
            $language,
            new InlineImageExtractor($media),
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
            title: 'Guarded',
            alias: 'guarded',
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

    /** A required unsupported field with nothing to send is a dead end, forced or not. */
    public function testRequiredUnsupportedFieldWithNoValueCannotBeForced(): void
    {
        $this->seedFields([
            ['id' => 5, 'name' => 'blurb', 'label' => 'Blurb', 'type' => 'editor', 'required' => 1],
        ]);

        $draft = $this->draftWithFields([]);

        try {
            $this->publishService()->publish($draft, $this->site, true);
            self::fail('The publish should have been blocked.');
        } catch (PublishBlockedException $e) {
            self::assertSame(['Blurb'], $e->fieldLabels);
            self::assertSame([], $e->overridableLabels);
            self::assertFalse($e->canForce());
        }

        self::assertSame([], $this->transport->requests, 'Nothing may be sent to the site.');
    }

    /**
     * With the value imported from the site in hand the publish is offered
     * rather than refused — but only on a second, explicit attempt.
     */
    public function testRequiredUnsupportedFieldWithAnImportedValueIsOfferedThenPublished(): void
    {
        $this->seedFields([
            ['id' => 5, 'name' => 'blurb', 'label' => 'Blurb', 'type' => 'editor', 'required' => 1],
        ]);

        $draft = $this->draftWithFields(['blurb' => '<p>Read this first.</p>']);

        try {
            $this->publishService()->publish($draft, $this->site);
            self::fail('The first attempt should have asked for confirmation.');
        } catch (PublishBlockedException $e) {
            self::assertSame([], $e->fieldLabels);
            self::assertSame(['Blurb'], $e->overridableLabels);
            self::assertTrue($e->canForce());
        }

        $this->publishService()->publish($draft, $this->site, true);

        self::assertSame(['blurb' => '<p>Read this first.</p>'], $this->publishedFields());
    }

    /**
     * A blocking field is not rescued by a *different* unsupported field having
     * a value: forcing must still be refused, and the two are reported apart so
     * the UI can say which is which.
     */
    public function testOneFieldWithoutAValueBlocksTheWholePublish(): void
    {
        $this->seedFields([
            ['id' => 5, 'name' => 'blurb', 'label' => 'Blurb', 'type' => 'editor', 'required' => 1],
            ['id' => 6, 'name' => 'owner', 'label' => 'Owner', 'type' => 'user', 'required' => 1],
        ]);

        $draft = $this->draftWithFields(['blurb' => '<p>Kept</p>']);

        try {
            $this->publishService()->publish($draft, $this->site, true);
            self::fail('The publish should have been blocked.');
        } catch (PublishBlockedException $e) {
            self::assertSame(['Owner'], $e->fieldLabels);
            self::assertSame(['Blurb'], $e->overridableLabels);
            self::assertFalse($e->canForce());
        }
    }

    /**
     * A *non-required* unsupported field never blocks and is never sent, even
     * when forced and even when we hold its value: the API does not fire
     * `onContentNormaliseRequestData`, so `plg_system_fields` falls back to the
     * stored value for every `com_fields` key we omit. Leaving it out is strictly
     * safer than overwriting the site's value with our snapshot.
     */
    public function testOptionalUnsupportedFieldIsNeitherBlockingNorSent(): void
    {
        $this->seedFields([
            ['id' => 5, 'name' => 'blurb', 'label' => 'Blurb', 'type' => 'editor', 'required' => 0],
        ]);

        $draft = $this->draftWithFields(['blurb' => '<p>Left alone</p>']);

        $this->publishService()->publish($draft, $this->site, true);

        self::assertSame([], $this->publishedFields());
    }

    /**
     * An empty imported value is no value at all — Joomla's required check
     * rejects `''` exactly as it rejects a missing key, so offering to force it
     * would only produce a 400 from the site.
     */
    public function testEmptyImportedValueDoesNotMakeAFieldOverridable(): void
    {
        $this->seedFields([
            ['id' => 5, 'name' => 'blurb', 'label' => 'Blurb', 'type' => 'editor', 'required' => 1],
        ]);

        $draft = $this->draftWithFields(['blurb' => '']);

        try {
            $this->publishService()->publish($draft, $this->site, true);
            self::fail('The publish should have been blocked.');
        } catch (PublishBlockedException $e) {
            self::assertFalse($e->canForce());
            self::assertSame(['Blurb'], $e->fieldLabels);
        }
    }

    /** A required field Grafida *can* edit needs none of this machinery. */
    public function testRequiredSupportedFieldIsPublishedNormally(): void
    {
        $this->seedFields([
            ['id' => 5, 'name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text', 'required' => 1],
        ]);

        $draft = $this->draftWithFields(['subtitle' => 'A subtitle']);

        $this->publishService()->publish($draft, $this->site);

        self::assertSame(['subtitle' => 'A subtitle'], $this->publishedFields());
    }
}
