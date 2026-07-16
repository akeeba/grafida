<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Site;

/**
 * A connected Joomla site. The API token is never held on this object; it is
 * resolved on demand through {@see SiteService::tokenFor()}.
 */
final readonly class Site
{
    public function __construct(
        public ?int $id,
        public string $title,
        public string $baseUrl,
        public ?string $apiBase,
        public ?string $secretRef,
        public bool $hasInsecureToken,
        public string $defaultLanguage = '*',
        public ?string $editorCssUrl = null,
    ) {}

    /**
     * @param array{id?: int|string|null, title: string, base_url: string, api_base: string|null,
     *             secret_ref: string|null, insecure_token: string|int|null, default_language?: string,
     *             editor_css_url?: string|null} $row
     */
    public static function fromRow(array $row): self
    {
        $editorCssUrl = $row['editor_css_url'] ?? null;

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            title: $row['title'],
            baseUrl: $row['base_url'],
            apiBase: $row['api_base'] !== null ? $row['api_base'] : null,
            secretRef: $row['secret_ref'] !== null ? $row['secret_ref'] : null,
            hasInsecureToken: $row['insecure_token'] !== null && $row['insecure_token'] !== '' && $row['insecure_token'] !== 0,
            defaultLanguage: $row['default_language'] ?? '*',
            editorCssUrl: $editorCssUrl !== '' ? $editorCssUrl : null,
        );
    }

    /** Whether the API token for this site is kept in plaintext (insecure). */
    public function isInsecure(): bool
    {
        return $this->secretRef === null && $this->hasInsecureToken;
    }

    /** @return array<string, mixed> Public representation for the front-end (never includes the token). */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'baseUrl'         => $this->baseUrl,
            'apiBase'         => $this->apiBase,
            'defaultLanguage' => $this->defaultLanguage,
            'editorCssUrl'    => $this->editorCssUrl,
            'insecure'        => $this->isInsecure(),
        ];
    }
}
