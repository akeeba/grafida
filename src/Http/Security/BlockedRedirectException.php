<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Security;

/**
 * Raised when a server answers with a redirect that would take the request —
 * and the credential it carries — somewhere the original URL did not authorise.
 *
 * Distinct from {@see \Grafida\Http\InsecureUrlException}, which is about a URL
 * *we* were given: this one is about a URL the *remote end* chose, so it is a
 * remote failure (HTTP 502) rather than bad input (HTTP 400).
 */
final class BlockedRedirectException extends \RuntimeException
{
}
