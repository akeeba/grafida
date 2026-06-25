<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Field;

/**
 * Knows which core Joomla custom-field types Grafida can edit, and detects the
 * situation where a required field uses an unsupported type — which makes
 * publishing through the API impossible.
 */
final class FieldSupport
{
    /**
     * The subset of core field types Grafida renders and edits. Any other type
     * (editor, media, sql, subform, user, usergrouplist, imagelist, ...) is
     * considered unsupported.
     */
    public const SUPPORTED = [
        'calendar',
        'checkboxes',
        'color',
        'integer',
        'list',
        'radio',
        'text',
        'textarea',
        'url',
    ];

    public function isSupported(string $type): bool
    {
        return in_array(strtolower($type), self::SUPPORTED, true);
    }

    /**
     * Splits API field definitions into supported and unsupported, annotating
     * each with `supported` and normalised `required` flags.
     *
     * @param list<array<string, mixed>> $definitions
     *
     * @return array{supported: list<array<string, mixed>>, unsupported: list<array<string, mixed>>}
     */
    public function partition(array $definitions): array
    {
        $supported   = [];
        $unsupported = [];

        foreach ($definitions as $field) {
            $rawType   = $field['type'] ?? null;
            $type      = is_string($rawType) ? $rawType : 'text';
            $field['supported'] = $this->isSupported($type);
            $field['required']  = $this->isRequired($field);

            if ($field['supported']) {
                $supported[] = $field;
            } else {
                $unsupported[] = $field;
            }
        }

        return ['supported' => $supported, 'unsupported' => $unsupported];
    }

    /**
     * Returns the required fields whose type is unsupported. A non-empty result
     * means the article cannot be published through the API.
     *
     * @param list<array<string, mixed>> $definitions
     *
     * @return list<array<string, mixed>>
     */
    public function blockingFields(array $definitions): array
    {
        $blocking = [];

        foreach ($definitions as $field) {
            $rawType = $field['type'] ?? null;
            if ($this->isRequired($field) && !$this->isSupported(is_string($rawType) ? $rawType : '')) {
                $blocking[] = $field;
            }
        }

        return $blocking;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isRequired(array $field): bool
    {
        $required = $field['required'] ?? false;

        return $required === true || $required === 1 || $required === '1';
    }
}
