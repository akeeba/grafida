<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Feature;

use Boson\Component\Http\Request;
use Grafida\Application\Kernel;
use Grafida\Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;

/**
 * The Help routes, driven through the kernel against the **real** `docs/`
 * directory (the container's `docs.dir` parameter defaults to it), so this is
 * also the thing that notices a manifest listing a page nobody ever wrote.
 *
 * Nothing here seeds a site or a database row on purpose: the Help screen must
 * work with no site configured at all, which is exactly when someone is most
 * likely to open it.
 */
final class HelpRoutingTest extends TestCase
{
    private function kernel(): Kernel
    {
        return TestContainer::create()->get(Kernel::class);
    }

    /** @return array{0: int, 1: mixed} */
    private function call(Kernel $kernel, string $method, string $path): array
    {
        $response = $kernel->handle(new Request($method, 'boson://app' . $path, [], ''));

        return [(int) (string) $response->status, json_decode((string) $response->body, true)];
    }

    /**
     * Flattens the table-of-contents tree to the nodes that are actual pages;
     * heading nodes (no slug) are skipped but recursed into.
     *
     * @param  list<array<string, mixed>>          $nodes
     * @return list<array{slug: string, title: string}>
     */
    private function flatten(array $nodes): array
    {
        $pages = [];

        foreach ($nodes as $node) {
            if (is_string($node['slug'])) {
                $pages[] = ['slug' => $node['slug'], 'title' => $node['title']];
            }

            $pages = array_merge($pages, $this->flatten($node['children']));
        }

        return $pages;
    }

    public function testContentsReturnsTheTableOfContentsTree(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/help');

        $this->assertSame(200, $status);
        $this->assertTrue($json['ok']);
        $this->assertNotSame('', $json['data']['home']);
        $this->assertNotEmpty($json['data']['tree']);

        foreach ($json['data']['tree'] as $node) {
            $this->assertArrayHasKey('slug', $node);
            $this->assertArrayHasKey('title', $node);
            $this->assertArrayHasKey('children', $node);
        }
    }

    /**
     * Every page the manifest advertises must actually render, at any depth.
     * This is the check that keeps `docs/_manifest.json` honest — nothing scans
     * the directory, so a renamed file would otherwise only be noticed by a user
     * clicking the dead entry.
     */
    public function testEveryAdvertisedPageRenders(): void
    {
        $kernel = $this->kernel();

        [, $contents] = $this->call($kernel, 'GET', '/api/help');

        $pages = $this->flatten($contents['data']['tree']);

        $this->assertNotEmpty($pages);

        foreach ($pages as $page) {
            [$status, $json] = $this->call($kernel, 'GET', '/api/help/page/' . $page['slug']);

            $this->assertSame(200, $status, 'Page ' . $page['slug'] . ' did not render');
            $this->assertSame($page['slug'], $json['data']['slug']);
            $this->assertSame($page['title'], $json['data']['title']);
            $this->assertNotSame('', $json['data']['html']);
        }
    }

    /** The home page must be one of the pages, or the screen opens on nothing. */
    public function testTheHomePageIsOneOfTheAdvertisedPages(): void
    {
        [, $contents] = $this->call($this->kernel(), 'GET', '/api/help');

        $slugs = array_column($this->flatten($contents['data']['tree']), 'slug');

        $this->assertContains($contents['data']['home'], $slugs);
    }

    public function testUnknownPageIs404(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/help/page/No-Such-Page');

        $this->assertSame(404, $status);
        $this->assertFalse($json['ok']);
        $this->assertSame('help_page_not_found', $json['code']);
    }

    public function testUnknownImageIs404(): void
    {
        [$status, $json] = $this->call($this->kernel(), 'GET', '/api/help/image/absent.png');

        $this->assertSame(404, $status);
        $this->assertSame('help_image_not_found', $json['code']);
    }

    /**
     * The `{file}` placeholder admits neither a slash nor a bare `..`, so a
     * traversal attempt never reaches a handler at all — it fails to match any
     * registered pattern and falls through to the router's own 404.
     */
    public function testImageRouteCannotBeTraversed(): void
    {
        [$status] = $this->call($this->kernel(), 'GET', '/api/help/image/../_manifest.json');

        $this->assertSame(404, $status);
    }

    public function testWritingToTheHelpRoutesIsNotAllowed(): void
    {
        [$status] = $this->call($this->kernel(), 'POST', '/api/help');

        $this->assertSame(405, $status);
    }
}
