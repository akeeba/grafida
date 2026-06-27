<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Ai;

/**
 * A configured AI provider connection.
 */
final class AiService
{
    /**
     * @param array<string, mixed> $params Extra model parameters (temperature, top_p, max_completion_tokens, etc.)
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public string $provider,
        public string $endpoint,
        public string $model,
        public array $params,
        public ?string $secretRef,
        public ?string $insecureKey,
        public bool $isDefault,
    ) {}

    /**
     * @param array{id?: int|string|null, name: string, provider: string, endpoint: string,
     *             model: string, params_json: string, secret_ref: string|null,
     *             insecure_key: string|null, is_default: int|string} $row
     */
    public static function fromRow(array $row): self
    {
        $paramsRaw = json_decode($row['params_json'], true);

        /** @var array<string, mixed> $params */
        $params = is_array($paramsRaw) ? $paramsRaw : [];

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            name: $row['name'],
            provider: $row['provider'],
            endpoint: $row['endpoint'],
            model: $row['model'],
            params: $params,
            secretRef: $row['secret_ref'],
            insecureKey: $row['insecure_key'],
            isDefault: (bool) $row['is_default'],
        );
    }

    /**
     * Returns a safe representation of this service.
     *
     * Note: API key fields (secretRef, insecureKey) are deliberately excluded
     * so that serialised services can be sent to the SPA without leaking credentials.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'provider'  => $this->provider,
            'endpoint'  => $this->endpoint,
            'model'     => $this->model,
            'params'    => $this->params,
            'isDefault' => $this->isDefault,
        ];
    }
}
