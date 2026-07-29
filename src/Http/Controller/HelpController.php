<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Controller;

use Boson\Component\Http\Response;
use Boson\Contracts\Http\ResponseInterface;
use Grafida\Help\HelpService;
use Grafida\Http\Json;
use Grafida\Http\RouteContext;
use Grafida\Http\Router;

/**
 * Serves the documentation bundled in `docs/` to the Help screen.
 *
 * Everything here reads files that shipped inside the binary — there is no
 * site, no network and no database involved, so the Help screen works with no
 * site configured at all, which is exactly when someone is most likely to want
 * it.
 */
final class HelpController extends Controller
{
    public function __construct(private readonly HelpService $help) {}

    public function registerRoutes(Router $router): void
    {
        $router->add('GET', '/api/help', fn (RouteContext $ctx): ResponseInterface => $this->contents());
        // `{key}` compiles to `[A-Za-z0-9_\-]+` and `{file}` to `name.ext`, so
        // neither can match a path containing a slash: the two routes cannot
        // collide with each other, nor with `/api/help` above.
        $router->add('GET', '/api/help/page/{key}', fn (RouteContext $ctx): ResponseInterface => $this->page($ctx->string('key')));
        $router->add('GET', '/api/help/image/{file}', fn (RouteContext $ctx): ResponseInterface => $this->image($ctx->string('file')));
    }

    /** The table of contents, and which page to open first. */
    private function contents(): ResponseInterface
    {
        return Json::ok($this->help->contents());
    }

    /** One page, rendered to HTML. */
    private function page(string $slug): ResponseInterface
    {
        $page = $this->help->page($slug);

        if ($page === null) {
            return Json::error('No such documentation page: ' . $slug, 404, ['code' => 'help_page_not_found']);
        }

        return Json::ok($page);
    }

    /** An illustration from `docs/images/`, served as raw bytes. */
    private function image(string $filename): ResponseInterface
    {
        $image = $this->help->image($filename);

        if ($image === null) {
            return Json::error('No such documentation image: ' . $filename, 404, ['code' => 'help_image_not_found']);
        }

        return new Response($image['data'], 200, [
            'Content-Type'           => $image['mime'],
            'Content-Length'         => (string) \strlen($image['data']),
            'X-Content-Type-Options' => 'nosniff',
            // Same rationale as Http\Json::response() (gh-35): the webview caches
            // custom-scheme GETs heuristically, in a disk-backed cache that
            // survives a restart. These bytes cannot change without a new build,
            // so caching them would be harmless in itself — but "nothing the
            // internal API answers is cacheable" is a rule worth keeping without
            // exceptions to remember, and the read is a local file.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma'        => 'no-cache',
        ]);
    }
}
