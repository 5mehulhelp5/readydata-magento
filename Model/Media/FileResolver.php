<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Media;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\File\Uploader;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\HTTP\ClientFactory;
use Magento\MediaStorage\Helper\File\Storage\Database as StorageDatabase;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Exception\MediaReferenceException;

/**
 * Turns payload media references into stored pub/media/catalog/product paths:
 * downloads http(s) URLs and validates paths of files pushed out of band.
 *
 * Called from MediaProcessor::prepare(), i.e. BEFORE the batch transaction is
 * opened. Nothing here may throw and nothing here touches the database: every
 * failure comes back as a per-reference message the processor turns into a
 * per-product warning.
 *
 * A URL maps to a DETERMINISTIC target path — sanitized basename under
 * Magento's standard two-character dispersion — so a re-import resolves to the
 * same path, the gallery diff matches the existing row and, unless
 * "Re-Download Existing Files" is on, no request is made at all. Two different
 * URLs whose basenames collide are disambiguated by a suffix derived from the URL
 * itself rather than a counter, so the mapping stays stable across runs (a
 * counter would hand the same image a different name on every import).
 *
 * Downloaded bytes are verified against the file signature of the extension they
 * claim BEFORE anything is written, so a payload disguised as an image never
 * lands under pub/media and a rejected download leaves no partial file behind.
 * All I/O goes through the media directory's driver, never PHP's filesystem
 * primitives, so remote-storage set-ups are honoured.
 */
class FileResolver
{
    /**
     * Leading file signatures per extension family. An extension without one
     * cannot be downloaded — that is what keeps executables and SVG (which can
     * carry script) out, whatever the allow-list says.
     *
     * @var array<string, string[]> extension => list of magic byte prefixes
     */
    private const SIGNATURES = [
        'jpg' => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'png' => ["\x89PNG\r\n\x1A\n"],
        'gif' => ['GIF87a', 'GIF89a'],
        // RIFF....WEBP — the four size bytes in between are skipped below.
        'webp' => ['RIFF'],
        'avif' => ["\x00\x00\x00 ftypavif", "\x00\x00\x00\x1Cftypavif"],
    ];

    private const CONNECT_TIMEOUT_SEC = 5;
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly MediaConfig $mediaConfig,
        private readonly ClientFactory $httpClientFactory,
        private readonly StorageDatabase $storageDatabase,
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * Resolve payload references to stored paths.
     *
     * @param string[] $references distinct payload values
     * @return array<string, array{file: string|null, message: string|null}>
     *         reference => stored path ("/a/b/file.jpg") or null plus the reason
     */
    public function resolve(array $references): array
    {
        $results = [];

        if ($this->storageDatabase->checkDbUsage()) {
            // Files written to the local media directory would be invisible to
            // the storefront while the gallery rows looked perfectly consistent.
            $message = 'Media import is not supported while database media storage is enabled.';
            foreach ($references as $reference) {
                $results[$reference] = ['file' => null, 'message' => $message];
            }
            $this->logger->error($message);

            return $results;
        }

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $base = $this->mediaConfig->getBaseMediaPath();

        foreach ($references as $reference) {
            $reference = (string)$reference;
            try {
                $file = preg_match('#^https?://#i', $reference) === 1
                    ? $this->resolveUrl($directory, $base, $reference)
                    : $this->resolveLocal($directory, $base, $reference);
                $results[$reference] = ['file' => $file, 'message' => null];
            } catch (MediaReferenceException $e) {
                // Expected, per-reference problems: reported, not logged as errors.
                $results[$reference] = ['file' => null, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                $message = sprintf(
                    'Media reference "%s" could not be resolved: %s',
                    $reference,
                    $e->getMessage()
                );
                $results[$reference] = ['file' => null, 'message' => $message];
                $this->logger->warning($message, ['exception' => $e]);
            }
        }

        return $results;
    }

    /**
     * Validate a reference to a file already present under
     * pub/media/catalog/product and return its stored path.
     *
     * @throws MediaReferenceException
     */
    private function resolveLocal(WriteInterface $directory, string $base, string $reference): string
    {
        $path = str_replace('\\', '/', trim($reference));
        if ($path === '' || str_contains($path, "\0")) {
            throw new MediaReferenceException('Media entry with an empty file skipped.');
        }
        // Accept both "/s/h/x.jpg" and "catalog/product/s/h/x.jpg".
        $path = '/' . ltrim($path, '/');
        if (str_starts_with($path, '/' . $base . '/')) {
            $path = substr($path, strlen($base) + 1);
        }

        // One whitelist instead of traversal blacklisting: every segment must
        // start alphanumeric and contain nothing but word characters, dots and
        // dashes, which leaves no way to express "..", "//" or an absolute path.
        if (preg_match('#^(?:/[A-Za-z0-9][A-Za-z0-9._\-]*)+$#', $path) !== 1) {
            throw new MediaReferenceException(
                sprintf('Media file "%s" is not a valid path under pub/media/%s; skipped.', $reference, $base)
            );
        }
        $this->assertAllowedExtension($reference, $path);

        // Existence first: getRealPath() below cannot resolve a path that is not
        // there, and reporting a plain typo as a containment violation would send
        // whoever reads the message hunting for a security problem.
        $mediaPath = $base . $path;
        if (!$directory->isExist($mediaPath) || !$directory->isFile($mediaPath)) {
            throw new MediaReferenceException(
                sprintf('Media file "%s" was not found under pub/media/%s; skipped.', $reference, $base)
            );
        }

        // Independent containment check: whatever the normalisation above did,
        // the resolved path must still sit inside the product media directory.
        // This is also what catches a symlink pointing out of the tree.
        $driver = $directory->getDriver();
        $realPath = $driver->getRealPath($directory->getAbsolutePath($mediaPath));
        $realBase = $driver->getRealPath($directory->getAbsolutePath($base));
        if (!is_string($realPath)
            || !is_string($realBase)
            || !str_starts_with($realPath, rtrim($realBase, '/') . '/')
        ) {
            throw new MediaReferenceException(
                sprintf('Media file "%s" resolves outside pub/media/%s; skipped.', $reference, $base)
            );
        }

        return $path;
    }

    /**
     * Download a URL into pub/media/catalog/product and return its stored path.
     *
     * @throws MediaReferenceException
     */
    private function resolveUrl(WriteInterface $directory, string $base, string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            throw new MediaReferenceException(sprintf('Media URL "%s" could not be parsed; skipped.', $url));
        }
        if (!in_array(mb_strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new MediaReferenceException(sprintf('Media URL "%s" is not http(s); skipped.', $url));
        }

        $allowedHosts = $this->config->getMediaAllowedHosts();
        if ($allowedHosts && !in_array(mb_strtolower($parts['host']), $allowedHosts, true)) {
            throw new MediaReferenceException(
                sprintf('Media URL host "%s" is not in the allowed download hosts; skipped.', $parts['host'])
            );
        }

        $name = mb_strtolower(Uploader::getCorrectFileName(basename($parts['path'])));
        $extension = mb_strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (pathinfo($name, PATHINFO_FILENAME) === '' || $extension === '') {
            throw new MediaReferenceException(
                sprintf('Media URL "%s" has no usable file name; skipped.', $url)
            );
        }
        $this->assertAllowedExtension($url, $name);
        if (!isset(self::SIGNATURES[$extension])) {
            throw new MediaReferenceException(sprintf(
                'Media URL "%s" has extension "%s", for which there is no known image signature;'
                . ' downloads are limited to %s.',
                $url,
                $extension,
                implode(', ', array_keys(self::SIGNATURES))
            ));
        }

        $target = Uploader::getDispersionPath($name) . '/' . $name;
        if ($directory->isExist($base . $target) && !$this->config->isMediaRedownloadExisting()) {
            // Already published: no request, no write. This is what makes a
            // re-import free and a retry after a rolled-back batch converge.
            return $target;
        }

        $body = $this->download($url);
        $this->assertSignature($url, $extension, $body);

        return $this->store($directory, $base, $target, $name, $extension, $url, $body);
    }

    /**
     * @throws MediaReferenceException
     */
    private function download(string $url): string
    {
        // A fresh client per reference: Curl is stateful, so options, headers and
        // cookies must not leak from one download into the next.
        $client = $this->httpClientFactory->create();
        $client->setTimeout($this->config->getMediaDownloadTimeout());
        $client->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SEC);
        $client->setOption(CURLOPT_FOLLOWLOCATION, true);
        $client->setOption(CURLOPT_MAXREDIRS, self::MAX_REDIRECTS);
        $client->get($url);

        $status = (int)$client->getStatus();
        if ($status !== 200) {
            throw new MediaReferenceException(
                sprintf('Download of "%s" failed with HTTP %d; skipped.', $url, $status)
            );
        }

        $maxBytes = $this->config->getMediaMaxFileSizeKb() * 1024;
        $headers = $client->getHeaders();
        $declared = (int)($headers['content-length'] ?? $headers['Content-Length'] ?? 0);
        if ($declared > $maxBytes) {
            throw new MediaReferenceException(sprintf(
                'Download of "%s" announced %d bytes, above the %d byte limit; skipped.',
                $url,
                $declared,
                $maxBytes
            ));
        }

        $body = (string)$client->getBody();
        if ($body === '') {
            throw new MediaReferenceException(sprintf('Download of "%s" returned no content; skipped.', $url));
        }
        if (strlen($body) > $maxBytes) {
            throw new MediaReferenceException(sprintf(
                'Download of "%s" is %d bytes, above the %d byte limit; skipped.',
                $url,
                strlen($body),
                $maxBytes
            ));
        }

        return $body;
    }

    /**
     * Write the downloaded bytes, reusing or side-stepping an existing file.
     *
     * @throws MediaReferenceException
     */
    private function store(
        WriteInterface $directory,
        string $base,
        string $target,
        string $name,
        string $extension,
        string $url,
        string $body
    ): string {
        if ($directory->isExist($base . $target)) {
            if (sha1((string)$directory->readFile($base . $target)) === sha1($body)) {
                // Byte-identical: keep the published file untouched.
                return $target;
            }
            // A different image under the same dispersed name. Suffix from the
            // URL, so this reference always lands on the same alternative path
            // instead of accumulating a new copy per import.
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $name = sprintf('%s_%s.%s', $stem, substr(sha1($url), 0, 8), $extension);
            $target = Uploader::getDispersionPath($name) . '/' . $name;

            if ($directory->isExist($base . $target)) {
                if (sha1((string)$directory->readFile($base . $target)) === sha1($body)) {
                    return $target;
                }
                throw new MediaReferenceException(sprintf(
                    'Download of "%s" collides with a different file already stored at "%s"; skipped.',
                    $url,
                    $target
                ));
            }
        }

        $directory->create($base . dirname($target));
        $directory->writeFile($base . $target, $body);

        return $target;
    }

    /**
     * @throws MediaReferenceException
     */
    private function assertAllowedExtension(string $reference, string $path): void
    {
        $extension = mb_strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->config->getMediaAllowedExtensions(), true)) {
            throw new MediaReferenceException(sprintf(
                'Media file "%s" has a file extension that is not allowed for import; skipped.',
                $reference
            ));
        }
    }

    /**
     * @throws MediaReferenceException
     */
    private function assertSignature(string $url, string $extension, string $body): void
    {
        foreach (self::SIGNATURES[$extension] as $signature) {
            if (str_starts_with($body, $signature)) {
                // RIFF containers carry their type after the four size bytes.
                if ($extension === 'webp' && substr($body, 8, 4) !== 'WEBP') {
                    continue;
                }

                return;
            }
        }

        throw new MediaReferenceException(sprintf(
            'Downloaded file for "%s" is not a valid %s image; skipped.',
            $url,
            $extension
        ));
    }
}
