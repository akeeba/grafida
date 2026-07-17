<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Controller;

/**
 * Shared, dependency-free request-parsing helpers for every domain controller.
 *
 * Deliberately thin: it holds only the trivial body-array readers
 * (`str`/`int`/`bool`) that every controller needs and that need no collaborator
 * of their own. The site-resolution helpers (`requireSite`, `connectedSite`,
 * `siteArray`, `relationshipIds`, `firstRelationshipId`, `withCategoryTitles`)
 * that used to live here moved to the injectable `Grafida\Http\SiteContext`
 * collaborator, and the `UI_KEYS` string list moved to
 * `Grafida\I18n\UiStrings::KEYS` — both were "shared behaviour", not
 * request-parsing, and forced every controller (including ones with nothing
 * to do with sites) to carry SiteService/FaviconService/ReferenceService
 * through inheritance. Kept as an abstract base class (rather than a trait)
 * because every concrete controller already `extends Controller` and gets
 * these two methods "for free" without repeating a `use` statement in eight
 * files; there is no constructor here for subclasses to worry about calling.
 */
abstract class Controller
{
    /**
     * Safely reads a string value from a mixed-typed body map.
     *
     * @param array<string, mixed> $body
     */
    protected function str(array $body, string $key, string $default = ''): string
    {
        $value = $body[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Safely reads an int value from a mixed-typed body map.
     *
     * @param array<string, mixed> $body
     */
    protected function int(array $body, string $key, int $default = 0): int
    {
        $value = $body[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Safely reads a boolean value from a mixed-typed body map.
     *
     * A JSON body carries a real boolean, but a value that merely stands in for
     * one (0/1, "0"/"1") is accepted too; anything absent or unusable yields the
     * default, so an omitted key never reads as false by accident.
     *
     * @param array<string, mixed> $body
     */
    protected function bool(array $body, string $key, bool $default = false): bool
    {
        $value = $body[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        return is_numeric($value) || is_string($value) ? (bool) $value : $default;
    }
}
