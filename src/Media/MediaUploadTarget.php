<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Media;

use Grafida\Http\HttpException;
use Grafida\Joomla\ApiClient;
use Grafida\Joomla\ApiException;
use Grafida\Site\Site;

/**
 * Works out where a published article's images are uploaded to: which Media
 * Manager filesystem (adapter) and which folder inside it (gh-57).
 *
 * ⚠️ **An upload must name its adapter, because Joomla's idea of the default one
 * is not "the images folder".** `ProviderManagerHelperTrait::getDefaultAdapterName()`
 * resolves a colon-less path against `local-` + com_media's **`file_path`**
 * parameter, which a stock Joomla install stores as `files` (see `base.sql`), and
 * `plg_filesystem_local` ships adapters for both `images` and `files` — so on a
 * default site an unqualified path lands in `files/`, not `images/`, and only a
 * site that has *removed* the `files` adapter falls through to the "first
 * available local adapter" everyone assumes is the rule. Joomla's own image
 * fields (`MediaField`, TinyMCE's upload dir) use the separate `image_path`
 * parameter, i.e. `images` — which is what an article's pictures belong in, so
 * that is what we resolve to when the user has expressed no preference.
 *
 * Both halves are per-site settings (`sites.media_adapter` / `sites.media_folder`);
 * an empty adapter means "resolve automatically" and an empty folder means
 * {@see DEFAULT_FOLDER}.
 */
final class MediaUploadTarget
{
    /** The folder created inside the target filesystem when the site names none. */
    public const DEFAULT_FOLDER = 'grafida';

    /** The adapter automatic resolution prefers: Joomla's own images filesystem. */
    public const PREFERRED_ADAPTER = 'local-images';

    /**
     * Automatically resolved adapters, by site id.
     *
     * Only *automatic* resolution is memoised — an adapter the user picked is
     * read from the site row every time — and it is memoised because the
     * alternative is one `media/adapters` request per uploaded image. A site
     * whose filesystems change while Grafida is running therefore needs a
     * restart to be seen, which is the same deal as every other per-process
     * cache here and far cheaper than the request-per-image it replaces.
     *
     * @var array<int, string>
     */
    private array $resolved = [];

    public function __construct(
        private readonly ApiClient $api,
    ) {}

    /**
     * The full upload path for a file, e.g. `local-images:/grafida/photo.png`.
     *
     * @param string $filename Bare (already sanitised) file name.
     */
    public function pathFor(Site $site, string $base, string $token, string $filename): string
    {
        return $this->adapterPrefix($site, $base, $token) . $this->relativePathFor($site, $filename);
    }

    /**
     * The upload path **without** the adapter, e.g. `grafida/photo.png` — what a
     * caller needs when it has to guess a public URL from the path it sent.
     */
    public function relativePathFor(Site $site, string $filename): string
    {
        $folder = self::folderFor($site);

        return $folder !== '' ? $folder . '/' . $filename : $filename;
    }

    /** The site's target folder, normalised ('' only when it was explicitly a slash or two). */
    public static function folderFor(Site $site): string
    {
        return self::normaliseFolder($site->mediaFolder);
    }

    /**
     * Cleans up a folder as the user typed it: no leading/trailing slashes, no
     * adapter prefix (a value pasted from the Media Manager might carry one),
     * and an empty value means the built-in default rather than "the root" —
     * dropping a site's images loose into `images/` is nobody's intent.
     */
    public static function normaliseFolder(?string $folder): string
    {
        $value = trim($folder ?? '');

        if (($pos = strpos($value, ':')) !== false) {
            $value = substr($value, $pos + 1);
        }

        $value = trim(str_replace('\\', '/', $value), '/');

        return $value !== '' ? $value : self::DEFAULT_FOLDER;
    }

    /**
     * Cleans up an adapter as the site stored it, into the `local-images:/` form
     * an upload path is built from. An empty value stays empty, meaning
     * "resolve it automatically".
     */
    public static function normaliseAdapter(?string $adapter): string
    {
        $value = rtrim(trim($adapter ?? ''), '/');

        if ($value === '') {
            return '';
        }

        return rtrim($value, ':') . ':/';
    }

    /**
     * The adapter prefix to build the upload path from — the site's own setting,
     * or automatic resolution.
     *
     * Automatic resolution degrades to the **empty** prefix, i.e. exactly what
     * Grafida sent before this existed: a site whose adapters we cannot list
     * (an offline moment, a token without the rights) must still be able to
     * publish, and letting Joomla pick is a worse target, not a broken one.
     */
    private function adapterPrefix(Site $site, string $base, string $token): string
    {
        $configured = self::normaliseAdapter($site->mediaAdapter);

        if ($configured !== '') {
            return $configured;
        }

        $key = $site->id ?? 0;

        if (!isset($this->resolved[$key])) {
            $this->resolved[$key] = self::normaliseAdapter($this->discoverAdapter($base, $token));
        }

        return $this->resolved[$key];
    }

    /**
     * Asks the site which filesystems it has and picks the one an image belongs
     * in: `local-images` when it exists, else the first one reported.
     */
    private function discoverAdapter(string $base, string $token): string
    {
        try {
            $adapters = $this->api->listMediaAdapters($base, $token);
        } catch (ApiException | HttpException) {
            return '';
        }

        $first = '';

        foreach ($adapters as $adapter) {
            $name = self::adapterName($adapter);

            if ($name === '') {
                continue;
            }

            if ($name === self::PREFERRED_ADAPTER) {
                return $name;
            }

            $first = $first !== '' ? $first : $name;
        }

        return $first;
    }

    /**
     * The `local-images` style name of an adapter resource, from whichever of
     * its fields is present: the flattened resource's `id`, else its `path`
     * (`local-images:/`), else provider + name.
     *
     * @param array<string, mixed> $adapter
     */
    private static function adapterName(array $adapter): string
    {
        $id = $adapter['id'] ?? null;

        if (is_string($id) && $id !== '') {
            return rtrim($id, ':/');
        }

        $path = $adapter['path'] ?? null;

        if (is_string($path) && $path !== '') {
            return rtrim($path, ':/');
        }

        $provider = $adapter['provider_id'] ?? null;
        $name     = $adapter['name'] ?? null;

        if (is_string($provider) && $provider !== '' && is_string($name) && $name !== '') {
            return $provider . '-' . $name;
        }

        return '';
    }
}
