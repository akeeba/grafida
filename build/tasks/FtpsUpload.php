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
 * Upload one or more files to an FTP server over explicit TLS (FTPS).
 *
 * Used to publish the update information to the CDN's FTP storage. The transfer
 * always negotiates TLS (CURLUSESSL_ALL → AUTH TLS on an ftp:// URL), so the
 * credentials and payload are never sent in clear text.
 *
 * Attributes:
 *  - host        FTP hostname (e.g. "storage.example.com")
 *  - port        FTP port (default 21)
 *  - username    FTP username
 *  - password    FTP password
 *  - remoteDir   Remote directory the files are uploaded into (e.g. "/grafida/updates")
 *  - verifyPeer  Verify the server's TLS certificate (default true)
 *  - passive     Use passive mode (default true)
 *
 * Nested <fileset> elements select the local files to upload.
 */
class FtpsUpload extends Task
{
    private string $host = '';

    private int $port = 21;

    private string $username = '';

    private string $password = '';

    private string $remoteDir = '';

    private bool $verifyPeer = true;

    private bool $passive = true;

    /** @var FileSet[] */
    private array $filesets = [];

    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function setRemoteDir(string $remoteDir): void
    {
        $this->remoteDir = $remoteDir;
    }

    public function setVerifyPeer(bool $verifyPeer): void
    {
        $this->verifyPeer = $verifyPeer;
    }

    public function setPassive(bool $passive): void
    {
        $this->passive = $passive;
    }

    public function createFileSet(): FileSet
    {
        $fileset          = new FileSet();
        $this->filesets[] = $fileset;

        return $fileset;
    }

    public function main(): void
    {
        if ($this->host === '' || $this->username === '' || $this->password === '')
        {
            throw new BuildException('FtpsUpload: host, username and password are all required.');
        }

        $files = $this->collectFiles();
        $base  = rtrim($this->remoteDir, '/');

        foreach ($files as $file)
        {
            $remote = $base . '/' . basename($file);
            $this->uploadFile($file, $remote);
        }
    }

    /**
     * Resolve every file matched by the nested <fileset> elements.
     *
     * @return string[]  Absolute local paths.
     */
    private function collectFiles(): array
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
            throw new BuildException('FtpsUpload: no files matched the nested <fileset>(s).');
        }

        return $files;
    }

    /**
     * Upload a single file over FTPS, creating any missing remote directories.
     */
    private function uploadFile(string $file, string $remotePath): void
    {
        if (!is_file($file))
        {
            throw new BuildException(sprintf('FtpsUpload: file "%s" not found.', $file));
        }

        $handle = fopen($file, 'rb');

        if ($handle === false)
        {
            throw new BuildException(sprintf('FtpsUpload: could not open "%s" for reading.', $file));
        }

        // The remote path is absolute (leading slash), so the URL is ftp://host:port/<remotePath>.
        $url = sprintf('ftp://%s:%d/%s', $this->host, $this->port, ltrim($remotePath, '/'));

        $this->log(sprintf('Uploading %s → ftps://%s:%d/%s', basename($file), $this->host, $this->port, ltrim($remotePath, '/')));

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $handle);
        curl_setopt($ch, CURLOPT_INFILESIZE, (int) filesize($file));
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_FTP_CREATE_MISSING_DIRS, CURLFTP_CREATE_DIR);
        curl_setopt($ch, CURLOPT_FTP_USE_EPSV, $this->passive);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Explicit FTPS: require TLS for both the control and data channels (AUTH TLS).
        curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyPeer ? 2 : 0);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);
        fclose($handle);

        if ($response === false || $errno !== 0)
        {
            throw new BuildException(sprintf('FtpsUpload: failed to upload "%s": [%d] %s', basename($file), $errno, $error));
        }
    }
}
