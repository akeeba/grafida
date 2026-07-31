<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Publish;

/**
 * Raised when an article's category carries **required** custom fields of a type
 * Grafida does not support. Joomla's API validates a write against the article
 * edit form, so a required field with no value is a hard 400 — Grafida stops
 * first, with a message the user can act on.
 *
 * It is raised in two distinguishable situations (gh-59), because only one of
 * them is a dead end:
 *
 * - **Blocking** (`fieldLabels` non-empty): there is at least one required
 *   unsupported field Grafida holds no value for — typically a brand-new
 *   article. Nothing could be sent for it, so the only way out is Joomla's own
 *   back-end; the UI offers the article HTML to copy across.
 * - **Overridable** (`fieldLabels` empty, `overridableLabels` non-empty): every
 *   required unsupported field carries the value read off the site when the
 *   article was imported, so re-sending those values verbatim satisfies the
 *   form. `canForce()` is true and the publish can be retried with `$force`.
 *   It is not done silently: a value Grafida cannot display is also one it
 *   cannot show to be stale, so the user confirms it.
 */
final class PublishBlockedException extends \RuntimeException
{
    /**
     * @param list<string> $fieldLabels       Required unsupported fields with no value to send.
     * @param list<string> $overridableLabels Required unsupported fields whose imported value can be re-sent.
     */
    public function __construct(
        public readonly array $fieldLabels,
        public readonly string $articleHtml,
        public readonly array $overridableLabels = [],
    ) {
        parent::__construct(
            $fieldLabels === []
                ? 'This article uses custom fields of a type only Joomla\'s backend can edit, and the site '
                    . 'requires them: ' . implode(', ', $overridableLabels) . '. Grafida can publish it by '
                    . 'sending back the values it read from the site, unchanged.'
                : 'This article cannot be published through Grafida because the site requires custom fields of a '
                    . 'type that only Joomla\'s backend can edit: ' . implode(', ', $fieldLabels) . '.'
        );
    }

    /**
     * Whether a "publish anyway" retry could succeed: every required unsupported
     * field has a value we can send, and there is at least one of them.
     */
    public function canForce(): bool
    {
        return $this->fieldLabels === [] && $this->overridableLabels !== [];
    }
}
