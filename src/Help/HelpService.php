<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Help;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Query;

/**
 * Serves the documentation bundled in `docs/` to the in-app Help screen.
 *
 * The same Markdown files are published verbatim as the project's GitHub wiki
 * (see `scripts/sync-wiki.sh`), so the *wiki* — the dumber of the two consumers
 * — dictates the source format and this class adapts to it rather than the
 * other way round:
 *
 * - One flat directory, one file per page, the file name being the wiki page
 *   name (`Custom-API-Access.md` → the wiki's "Custom API Access" page).
 * - **No YAML front matter**, which a GitHub wiki renders as visible junk. Page
 *   metadata lives in `docs/_manifest.json` instead — which also spares us
 *   `glob()`, a function the phar stream wrapper cannot serve, so the docs can
 *   be read straight out of the compiled binary with no extraction step (see
 *   {@see \Grafida\Support\Resources::docsDir()}).
 * - Links between pages are written the way the wiki resolves them, as a bare
 *   relative page name (`[Custom API access](Custom-API-Access)`). Nothing in
 *   this app can follow such a link, so {@see self::rewriteReferences()}
 *   annotates every anchor with the attributes the SPA's click handler reads.
 *
 * Rendering is GitHub-Flavoured Markdown with raw HTML **allowed**, which is
 * what makes the two consumers agree: the GFM extension bundles
 * `DisallowedRawHtml`, so exactly the tags GitHub neutralises (`<script>`,
 * `<iframe>`, `<style>`…) are escaped here too, while the ones a manual
 * legitimately needs (`<kbd>`, `<sub>`…) survive in both places. The content is
 * ours and ships inside the binary; it is not user input.
 */
final class HelpService
{
    private const MANIFEST = '_manifest.json';

    private const IMAGE_DIR = 'images';

    /** A page slug is also a file name and a URL path segment. */
    private const SLUG_PATTERN = '/^[A-Za-z0-9_\-]+$/';

    /**
     * How deep the table of contents may nest. A cap costs nothing and stops a
     * runaway manifest from producing a tree no sidebar can usefully render;
     * four is well past the three levels any of this documentation needs.
     */
    private const MAX_DEPTH = 4;

    /** @var array{home: string, tree: list<array<string, mixed>>}|null */
    private ?array $manifest = null;

    /**
     * Every page in the tree, flattened to slug => title. Built while the tree
     * is parsed, so slug lookups do not walk it again.
     *
     * @var array<string, string>|null
     */
    private ?array $index = null;

    public function __construct(private readonly string $docsDir) {}

    /**
     * The table of contents as a tree, plus the slug of the page the Help
     * screen opens on.
     *
     * A node is `{slug, title, children}`. `slug` is null for a node that only
     * groups others and has no page of its own — a heading — which is how a
     * section can exist without inventing a landing page for it. The *files*
     * stay in one flat directory whatever the nesting says: the wiki has a flat
     * page namespace and cannot represent a folder, so the hierarchy lives in
     * the manifest and only there.
     *
     * @return array{home: string, tree: list<array<string, mixed>>}
     */
    public function contents(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $raw     = $this->read(self::MANIFEST);
        $decoded = $raw === null ? null : json_decode($raw, true);

        if (!is_array($decoded)) {
            $this->index = [];

            return $this->manifest = ['home' => '', 'tree' => []];
        }

        $rawPages = $decoded['pages'] ?? [];
        $tree     = is_array($rawPages) ? self::parseNodes($rawPages, 1) : [];

        // The flat slug => title index is derived from the finished tree rather
        // than accumulated while parsing it: the parser drops a node *after*
        // recursing into its children (a heading with no surviving children is
        // not a heading), so anything recorded on the way down could belong to a
        // subtree that is no longer there.
        $this->index = self::indexOf($tree);

        $home = $decoded['home'] ?? null;
        $home = is_string($home) ? $home : '';

        // A manifest naming a "home" that is no page of its own would leave the
        // Help screen opening on nothing; fall back to the first real page in
        // the tree — which, with heading-only nodes possible, is not necessarily
        // the first node.
        if (!\array_key_exists($home, $this->index)) {
            $home = self::firstSlug($tree) ?? '';
        }

        return $this->manifest = ['home' => $home, 'tree' => $tree];
    }

    /**
     * Turns the manifest's nested arrays into nodes.
     *
     * A node with an unusable slug is dropped **whole**, children included: it
     * cannot be linked to, so keeping its subtree would orphan the pages under
     * it in a section with no way to reach it. A node with no slug at all is a
     * different thing entirely — a heading — and is kept, unless nothing
     * survived underneath it.
     *
     * @param array<int|string, mixed> $entries
     *
     * @return list<array<string, mixed>>
     */
    private static function parseNodes(array $entries, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $nodes = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $slug = $entry['slug'] ?? null;

            if ($slug !== null && (!is_string($slug) || preg_match(self::SLUG_PATTERN, $slug) !== 1)) {
                continue;
            }

            $title = $entry['title'] ?? null;
            $title = is_string($title) && $title !== '' ? $title : ($slug ?? '');

            // A node with neither a page nor a label is nothing at all.
            if ($title === '') {
                continue;
            }

            $rawChildren = $entry['children'] ?? [];
            $children    = is_array($rawChildren) ? self::parseNodes($rawChildren, $depth + 1) : [];

            // A heading whose children all fell away has nothing left to head.
            if ($slug === null && $children === []) {
                continue;
            }

            $nodes[] = [
                'slug'     => $slug,
                'title'    => $title,
                'children' => $children,
            ];
        }

        return $nodes;
    }

    /**
     * Flattens a parsed tree to slug => title, for the two things that need to
     * answer a question about a slug without walking the tree again: "is this a
     * page?" and "what is it called?".
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return array<string, string>
     */
    private static function indexOf(array $nodes): array
    {
        $index = [];

        foreach ($nodes as $node) {
            $slug  = $node['slug'] ?? null;
            $title = $node['title'] ?? null;

            if (is_string($slug) && is_string($title)) {
                $index[$slug] = $title;
            }

            $children = $node['children'] ?? [];

            if (is_array($children)) {
                /** @var list<array<string, mixed>> $children */
                $index += self::indexOf($children);
            }
        }

        return $index;
    }

    /**
     * The first node in the tree that is an actual page, depth-first — the
     * fallback home page. Depth-first rather than "first top-level page" because
     * a manifest may perfectly well open with a heading.
     *
     * @param list<array<string, mixed>> $nodes
     */
    private static function firstSlug(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if (is_string($node['slug'] ?? null)) {
                return $node['slug'];
            }

            $children = $node['children'] ?? [];

            if (is_array($children)) {
                /** @var list<array<string, mixed>> $children */
                $found = self::firstSlug($children);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Renders one documentation page, or returns null when the slug is not in
     * the manifest or its file is missing.
     *
     * @return array{slug: string, title: string, html: string}|null
     */
    public function page(string $slug): ?array
    {
        $this->contents();

        \assert($this->index !== null);

        if (!isset($this->index[$slug])) {
            return null;
        }

        $markdown = $this->read($slug . '.md');

        if ($markdown === null) {
            return null;
        }

        try {
            $html = (string) $this->converter()->convert($markdown);
        } catch (CommonMarkException) {
            return null;
        }

        return [
            'slug'  => $slug,
            'title' => $this->index[$slug],
            'html'  => trim($html),
        ];
    }

    /**
     * Reads an illustration from `docs/images/`, or returns null when there is
     * no such file.
     *
     * @return array{filename: string, mime: string, data: string}|null
     */
    public function image(string $filename): ?array
    {
        // The route's {file} placeholder already excludes slashes and `..`, so
        // this is belt-and-braces rather than the only guard.
        if (preg_match('/^[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,5}$/', $filename) !== 1) {
            return null;
        }

        $data = $this->read(self::IMAGE_DIR . '/' . $filename);

        if ($data === null) {
            return null;
        }

        return [
            'filename' => $filename,
            'mime'     => self::mimeOf($filename),
            'data'     => $data,
        ];
    }

    /**
     * Reads a file from the docs directory. Uses `file_get_contents()`
     * throughout because it, unlike `glob()`, works on a `phar://` path — which
     * is what the whole no-extraction arrangement rests on.
     */
    private function read(string $relative): ?string
    {
        $path = $this->docsDir . '/' . $relative;

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input'         => 'allow',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addEventListener(
            DocumentParsedEvent::class,
            fn (DocumentParsedEvent $event) => $this->rewriteReferences($event)
        );

        return new MarkdownConverter($environment);
    }

    /**
     * Retargets links and images while they are still an AST, which is the only
     * point at which a URL is unambiguously a URL — doing this with a regex over
     * the rendered HTML would have to guess at quoting and at what is inside a
     * code block.
     *
     * Anchors are not rewritten so much as *annotated*: nothing in a Boson
     * webview can follow either kind of link on its own (there is no page to
     * navigate to, and an external URL has to go through `api.openUrl()`), so
     * each one is tagged with a `data-help-page` or `data-help-external`
     * attribute and the SPA's delegated click handler does the rest. An in-page
     * `#fragment` link is left completely alone — that one the browser can
     * handle by itself.
     */
    private function rewriteReferences(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();

        foreach ((new Query())->where(Query::type(Link::class))->findAll($document) as $link) {
            \assert($link instanceof Link);

            $url = $link->getUrl();

            if ($url === '' || str_starts_with($url, '#')) {
                continue;
            }

            if (preg_match('#^https?://#i', $url) === 1) {
                // Off-app. The SPA hands these to Support\UrlOpener via
                // api.openUrl(), which is the only way out of a Boson webview —
                // following the link normally would replace the whole SPA with
                // the remote page (and in practice does nothing at all, since
                // the webview opens no new window). The fragment rides along
                // untouched, so `…/grafida#readme` lands where it should.
                $link->data->set('attributes/data-help-external', '1');

                continue;
            }

            if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) === 1) {
                // Some other scheme — mailto:, ftp:, tel:. Deliberately NOT
                // tagged: UrlOpener accepts http(s) only and would answer a
                // click with an error toast, which is worse than nothing. The
                // href is left intact so the link still reads correctly on the
                // wiki; in the app the SPA's handler swallows the click (see
                // initHelpLinks()) so it cannot navigate the webview away.
                // CommonMark's `allow_unsafe_links => false` has already removed
                // the dangerous schemes (javascript:, data:, vbscript:, file:).
                continue;
            }

            // A relative target is a wiki page name. Strip the `.md` a writer may
            // have added out of habit and any fragment; an unknown slug is left
            // pointing at itself so the Help screen can say the page is missing,
            // which is far easier to notice than a link that quietly does nothing.
            $slug = preg_replace('/#.*$/', '', $url) ?? $url;
            $slug = preg_replace('/\.md$/i', '', $slug) ?? $slug;
            $slug = rawurldecode(ltrim($slug, './'));

            $link->data->set('attributes/data-help-page', $slug);
        }

        foreach ((new Query())->where(Query::type(Image::class))->findAll($document) as $image) {
            \assert($image instanceof Image);

            $url = $image->getUrl();

            if ($url === '' || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) === 1) {
                // An absolute URL would need the network; leave it, and let the
                // broken image be the visible consequence of writing one.
                continue;
            }

            // `docs/images/` is deliberately flat: the wiki repository has no
            // meaningful directory structure either, and a basename is the only
            // thing both consumers can resolve identically.
            $image->setUrl('/api/help/image/' . rawurlencode(basename($url)));
        }
    }

    private static function mimeOf(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));

        return match ($extension) {
            'png'          => 'image/png',
            'jpg', 'jpeg'  => 'image/jpeg',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
            'svg'          => 'image/svg+xml',
            default        => 'application/octet-stream',
        };
    }
}
