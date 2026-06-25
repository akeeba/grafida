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
 * Raised when no OS secret store is available and the caller has not yet agreed
 * to fall back to storing the API token in plaintext.
 */
final class SecureStoreUnavailableException extends \RuntimeException
{
}
