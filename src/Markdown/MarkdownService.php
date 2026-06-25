<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Markdown;

use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Converts Markdown to HTML for the "import Markdown file" feature, using the
 * GitHub-Flavored Markdown dialect.
 */
final class MarkdownService
{
    private readonly GithubFlavoredMarkdownConverter $converter;

    public function __construct()
    {
        $this->converter = new GithubFlavoredMarkdownConverter([
            'html_input'         => 'allow',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * @throws CommonMarkException
     */
    public function toHtml(string $markdown): string
    {
        return trim((string) $this->converter->convert($markdown));
    }

    /**
     * Reads a Markdown file from disk and converts it.
     *
     * @throws \RuntimeException If the file cannot be read.
     */
    public function fromFile(string $path): string
    {
        $markdown = @file_get_contents($path);

        if ($markdown === false) {
            throw new \RuntimeException('Could not read Markdown file: ' . $path);
        }

        return $this->toHtml($markdown);
    }
}
