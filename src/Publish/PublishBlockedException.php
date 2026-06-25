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
 * Raised when an article cannot be published because the site defines required
 * custom fields of a type Grafida does not support. The UI offers the user the
 * article HTML to copy into Joomla's backend editor instead.
 */
final class PublishBlockedException extends \RuntimeException
{
    /**
     * @param list<string> $fieldLabels Labels of the blocking required fields.
     */
    public function __construct(
        public readonly array $fieldLabels,
        public readonly string $articleHtml,
    ) {
        parent::__construct(
            'This article cannot be published through Grafida because the site requires custom fields of a '
            . 'type that only Joomla\'s backend can edit: ' . implode(', ', $fieldLabels) . '.'
        );
    }
}
