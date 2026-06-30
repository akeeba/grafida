<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace tasks;

use Phing\Exception\BuildException;
use Phing\Task;
use Phing\Type\FileSet;

/**
 * Create a GitHub release and upload one or more files to it as assets.
 *
 * The release is created as a draft, every asset is uploaded, and then (unless
 * the draft attribute is true) the release is published in a final step — so a
 * partial release is never visible to users or the update stream.
 *
 * Attributes:
 *  - organization  GitHub organisation or user (e.g. "akeeba")
 *  - repository    GitHub repository name      (e.g. "grafida")
 *  - token         GitHub personal access token with release permission
 *  - tagName       The tag for the release (created from targetCommitish if absent on GitHub)
 *  - releaseName   Human-readable release title (defaults to tagName)
 *  - bodyFile      Path to a file whose contents become the release description (release notes)
 *  - body          Inline release description (used when bodyFile is not set)
 *  - targetCommitish  Commit-ish the tag is created from when it does not exist (e.g. "main")
 *  - draft         Leave the release as a draft (default false → publish after upload)
 *  - prerelease    Mark the release as a pre-release (default false)
 *  - propName      Optional property name to receive the published release's html_url
 *
 * Nested <fileset> elements select the asset files to upload.
 */
class GitHubRelease extends Task
{
    private string $organization = '';

    private string $repository = '';

    private string $token = '';

    private string $tagName = '';

    private string $releaseName = '';

    private string $bodyFile = '';

    private string $body = '';

    private string $targetCommitish = '';

    private bool $draft = false;

    private bool $prerelease = false;

    private string $propName = '';

    /** @var FileSet[] */
    private array $filesets = [];

    public function setOrganization(string $organization): void
    {
        $this->organization = $organization;
    }

    public function setRepository(string $repository): void
    {
        $this->repository = $repository;
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    public function setTagName(string $tagName): void
    {
        $this->tagName = $tagName;
    }

    public function setReleaseName(string $releaseName): void
    {
        $this->releaseName = $releaseName;
    }

    public function setBodyFile(string $bodyFile): void
    {
        $this->bodyFile = $bodyFile;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function setTargetCommitish(string $targetCommitish): void
    {
        $this->targetCommitish = $targetCommitish;
    }

    public function setDraft(bool $draft): void
    {
        $this->draft = $draft;
    }

    public function setPrerelease(bool $prerelease): void
    {
        $this->prerelease = $prerelease;
    }

    public function setPropName(string $propName): void
    {
        $this->propName = $propName;
    }

    public function createFileSet(): FileSet
    {
        $fileset          = new FileSet();
        $this->filesets[] = $fileset;

        return $fileset;
    }

    public function main(): void
    {
        if ($this->organization === '' || $this->repository === '' || $this->token === '')
        {
            throw new BuildException('GitHubRelease: organization, repository and token are all required.');
        }

        if ($this->tagName === '')
        {
            throw new BuildException('GitHubRelease: tagName is required.');
        }

        $body = $this->body;

        if ($this->bodyFile !== '')
        {
            if (!is_file($this->bodyFile))
            {
                throw new BuildException(sprintf('GitHubRelease: bodyFile "%s" not found.', $this->bodyFile));
            }

            $body = (string) file_get_contents($this->bodyFile);
        }

        $files = $this->collectAssetFiles();

        // 1. Create the release as a draft.
        $payload = [
            'tag_name'   => $this->tagName,
            'name'       => $this->releaseName !== '' ? $this->releaseName : $this->tagName,
            'body'       => $body,
            'draft'      => true,
            'prerelease' => $this->prerelease,
        ];

        if ($this->targetCommitish !== '')
        {
            $payload['target_commitish'] = $this->targetCommitish;
        }

        $this->log(sprintf('Creating draft GitHub release %s in %s/%s', $this->tagName, $this->organization, $this->repository));

        $release = $this->apiRequest(
            'POST',
            sprintf('https://api.github.com/repos/%s/%s/releases', $this->organization, $this->repository),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $releaseId = $release['id'] ?? null;
        $uploadUrl = (string) ($release['upload_url'] ?? '');

        if (!is_int($releaseId) || $uploadUrl === '')
        {
            throw new BuildException('GitHubRelease: the GitHub API did not return a usable release id / upload URL.');
        }

        // The upload_url is an RFC 6570 template ending in "{?name,label}". Strip the template part.
        $uploadUrl = preg_replace('/\{.*}$/', '', $uploadUrl) ?? $uploadUrl;

        // 2. Upload every asset.
        foreach ($files as $file)
        {
            $this->uploadAsset($uploadUrl, $file);
        }

        // 3. Publish the release (unless we were asked to leave it as a draft).
        if (!$this->draft)
        {
            $this->log(sprintf('Publishing GitHub release %s', $this->tagName));

            $release = $this->apiRequest(
                'PATCH',
                sprintf('https://api.github.com/repos/%s/%s/releases/%d', $this->organization, $this->repository, $releaseId),
                json_encode(['draft' => false], JSON_UNESCAPED_SLASHES)
            );
        }

        $htmlUrl = (string) ($release['html_url'] ?? '');

        $this->log(sprintf('GitHub release ready: %s', $htmlUrl !== '' ? $htmlUrl : ('id ' . $releaseId)));

        if ($this->propName !== '' && $htmlUrl !== '')
        {
            $this->project->setProperty($this->propName, $htmlUrl);
        }
    }

    /**
     * Resolve every file matched by the nested <fileset> elements.
     *
     * @return string[]  Absolute paths.
     */
    private function collectAssetFiles(): array
    {
        $files = [];

        foreach ($this->filesets as $fileset)
        {
            $scanner = $fileset->getDirectoryScanner($this->project);
            $baseDir = $scanner->getBasedir();

            foreach ($scanner->getIncludedFiles() as $relative)
            {
                $files[] = $baseDir . DIRECTORY_SEPARATOR . $relative;
            }
        }

        if ($files === [])
        {
            throw new BuildException('GitHubRelease: no asset files matched the nested <fileset>(s).');
        }

        return $files;
    }

    /**
     * Upload a single asset to the release.
     */
    private function uploadAsset(string $uploadUrl, string $file): void
    {
        if (!is_file($file))
        {
            throw new BuildException(sprintf('GitHubRelease: asset "%s" not found.', $file));
        }

        $name = rawurlencode(basename($file));
        $url  = $uploadUrl . '?name=' . $name;

        $this->log(sprintf('Uploading asset %s (%s)', basename($file), $this->humanSize((int) filesize($file))));

        $contents = file_get_contents($file);

        if ($contents === false)
        {
            throw new BuildException(sprintf('GitHubRelease: could not read asset "%s".', $file));
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $contents);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: grafida-phing-build/1.0',
            'Content-Type: application/octet-stream',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false)
        {
            throw new BuildException(sprintf('GitHubRelease: cURL error uploading "%s": %s', basename($file), $error));
        }

        if ($httpCode < 200 || $httpCode >= 300)
        {
            throw new BuildException(sprintf('GitHubRelease: uploading "%s" returned HTTP %d: %s', basename($file), $httpCode, (string) $response));
        }
    }

    /**
     * Perform a GitHub REST API request with a JSON body and return the decoded response.
     *
     * @return array<string, mixed>
     */
    private function apiRequest(string $method, string $url, string $jsonBody): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: grafida-phing-build/1.0',
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false)
        {
            throw new BuildException(sprintf('GitHubRelease: cURL error on %s %s: %s', $method, $url, $error));
        }

        if ($httpCode < 200 || $httpCode >= 300)
        {
            throw new BuildException(sprintf('GitHubRelease: %s %s returned HTTP %d: %s', $method, $url, $httpCode, (string) $response));
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded))
        {
            throw new BuildException(sprintf('GitHubRelease: could not parse the response to %s %s as JSON.', $method, $url));
        }

        return $decoded;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $i     = 0;
        $size  = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1)
        {
            $size /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
