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
use Magento\MediaStorage\Helper\File\Storage\Database as StorageDatabase;
use Psr\Http\Message\StreamInterface;
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
 * Downloads run through a bounded concurrent pool (see DownloaderInterface),
 * and resolve() is organised in three passes so they can: everything that can
 * be decided without the network is decided first, the URLs that genuinely need
 * fetching go to the pool in one call, and each body is validated and written
 * inside the completion callback so no more than the configured concurrency is
 * ever held.
 *
 * A URL maps to a DETERMINISTIC target path: the sanitized basename plus a short
 * digest of the URL itself, under Magento's standard two-character dispersion.
 * The digest is not decoration — without it two suppliers' "hero.jpg" collide on
 * one path, and skip-if-present then hands the same file to both products. With
 * it, a re-import resolves to the same path, the gallery diff matches the stored
 * row, and nothing is re-downloaded.
 *
 * Downloaded bytes are verified against the file signature of the extension they
 * claim before anything is written, and are streamed to a temporary name and
 * renamed into place, so neither a disguised payload nor a half-written file can
 * ever be left where the next run's skip-if-present check would trust it. All
 * I/O goes through the media directory's driver, never PHP's filesystem
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

    /** Enough for the longest signature plus the WEBP form marker at offset 8. */
    private const SIGNATURE_PROBE_BYTES = 16;

    private const COPY_CHUNK_BYTES = 262144;
    private const TMP_SUFFIX = '.rd-part';
    private const MAX_FILE_NAME_LENGTH = 255;
    private const URL_DIGEST_LENGTH = 8;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly MediaConfig $mediaConfig,
        private readonly DownloaderInterface $downloader,
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

        // 1. Decide everything that needs no network: local paths resolve now,
        //    URLs are validated and reduced to a target path, and those already
        //    published are answered without a request.
        /** @var array<string, array{url: string, target: string, extension: string}> $pending */
        $pending = [];
        foreach ($references as $reference) {
            $reference = (string)$reference;
            $results[$reference] = $this->guard($reference, function () use (
                $directory,
                $base,
                $reference,
                &$pending
            ): ?string {
                if (preg_match('#^https?://#i', $reference) !== 1) {
                    return $this->resolveLocal($directory, $base, $reference);
                }

                $plan = $this->planDownload($reference);
                if (!$this->config->isMediaRedownloadExisting()
                    && $directory->isExist($base . $plan['target'])
                ) {
                    // Already published: no request, no write. This is what makes
                    // a re-import free and a retry after a rolled-back batch
                    // converge on the same file.
                    return $plan['target'];
                }

                $pending[$reference] = $plan;

                return null;
            });
        }

        // 2. Fetch what is left, concurrently, validating and writing each body
        //    as it lands so only the in-flight ones are ever held.
        if ($pending) {
            $this->downloader->fetchAll(
                array_map(static fn (array $plan): string => $plan['url'], $pending),
                function (
                    string $reference,
                    ?StreamInterface $body,
                    int $status,
                    ?\Throwable $error
                ) use (&$results, $pending, $directory, $base): void {
                    $results[$reference] = $this->guard(
                        $reference,
                        fn (): string => $this->completeDownload(
                            $directory,
                            $base,
                            $pending[$reference],
                            $body,
                            $status,
                            $error
                        )
                    );
                }
            );
        }

        return $results;
    }

    /**
     * Run one reference's resolution, converting anything it throws into the
     * result shape. Expected problems carry a ready-to-report sentence;
     * genuinely unexpected ones are additionally logged.
     *
     * @param callable(): (string|null) $resolve
     * @return array{file: string|null, message: string|null}
     */
    private function guard(string $reference, callable $resolve): array
    {
        try {
            return ['file' => $resolve(), 'message' => null];
        } catch (MediaReferenceException $e) {
            return ['file' => null, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            $message = sprintf(
                'Media reference "%s" could not be resolved: %s',
                $reference,
                $e->getMessage()
            );
            $this->logger->warning($message, ['exception' => $e]);

            return ['file' => null, 'message' => $message];
        }
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
     * Validate a URL and derive the path its content will be stored at.
     *
     * @return array{url: string, target: string, extension: string}
     * @throws MediaReferenceException
     */
    private function planDownload(string $url): array
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

        $name = mb_strtolower(Uploader::getCorrectFileName($this->trimToLimit(basename($parts['path']))));
        $extension = mb_strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $stem = (string)pathinfo($name, PATHINFO_FILENAME);
        if ($stem === '' || $extension === '') {
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

        $name = $this->targetName($stem, $extension, $url);

        return [
            'url' => $url,
            'target' => Uploader::getDispersionPath($name) . '/' . $name,
            'extension' => $extension,
        ];
    }

    /**
     * Keep a basename inside the filesystem limit, preserving its extension.
     *
     * Applied before core's sanitizer, which throws a LengthException on an
     * over-long name rather than trimming it — a feed with a verbose filename
     * would otherwise fail the entry outright. Sanitising can only shorten the
     * result further, so trimming first is safe.
     */
    private function trimToLimit(string $basename): string
    {
        if (strlen($basename) <= self::MAX_FILE_NAME_LENGTH) {
            return $basename;
        }

        $extension = (string)pathinfo($basename, PATHINFO_EXTENSION);
        $stem = (string)pathinfo($basename, PATHINFO_FILENAME);
        $room = self::MAX_FILE_NAME_LENGTH - ($extension === '' ? 0 : strlen($extension) + 1);
        $stem = substr($stem, 0, max(1, $room));

        return $extension === '' ? $stem : $stem . '.' . $extension;
    }

    /**
     * "hero.jpg" from https://cdn/x/hero.jpg becomes "hero_1a2b3c4d.jpg".
     *
     * The digest makes the name a pure function of the URL, so two different
     * URLs sharing a basename cannot land on one path — the collision that would
     * otherwise let skip-if-present serve one supplier's image for another's.
     */
    private function targetName(string $stem, string $extension, string $url): string
    {
        $digest = substr(sha1($url), 0, self::URL_DIGEST_LENGTH);
        $room = self::MAX_FILE_NAME_LENGTH - (strlen($digest) + strlen($extension) + 2);
        if (strlen($stem) > $room) {
            $stem = substr($stem, 0, max(1, $room));
        }

        return sprintf('%s_%s.%s', $stem, $digest, $extension);
    }

    /**
     * Validate a completed download and put it in place.
     *
     * @param array{url: string, target: string, extension: string} $plan
     * @throws MediaReferenceException
     */
    private function completeDownload(
        WriteInterface $directory,
        string $base,
        array $plan,
        ?StreamInterface $body,
        int $status,
        ?\Throwable $error
    ): string {
        $url = $plan['url'];
        if ($error !== null) {
            throw new MediaReferenceException(
                sprintf('Download of "%s" failed: %s; skipped.', $url, $error->getMessage())
            );
        }
        if ($status !== 200) {
            throw new MediaReferenceException(
                sprintf('Download of "%s" failed with HTTP %d; skipped.', $url, $status)
            );
        }
        if ($body === null) {
            throw new MediaReferenceException(sprintf('Download of "%s" returned no content; skipped.', $url));
        }

        // Sniff before a single byte is written: this is what stops a payload
        // wearing a .jpg name from reaching pub/media at all.
        $probe = $body->read(self::SIGNATURE_PROBE_BYTES);
        if ($probe === '') {
            throw new MediaReferenceException(sprintf('Download of "%s" returned no content; skipped.', $url));
        }
        $this->assertSignature($url, $plan['extension'], $probe);

        return $this->store($directory, $base, $plan['target'], $url, $probe, $body);
    }

    /**
     * Stream the body to a temporary name, then rename it into place.
     *
     * Never written directly to the target: a process killed mid-write would
     * leave a truncated file exactly where the next run's skip-if-present check
     * trusts it, and that file would then be served forever.
     *
     * @throws MediaReferenceException
     */
    private function store(
        WriteInterface $directory,
        string $base,
        string $target,
        string $url,
        string $probe,
        StreamInterface $body
    ): string {
        $maxBytes = $this->config->getMediaMaxFileSizeKb() * 1024;
        $tmpPath = $base . $target . self::TMP_SUFFIX;
        $directory->create($base . dirname($target));

        $hash = hash_init('sha1');
        $written = 0;
        $file = $directory->openFile($tmpPath, 'w');
        try {
            for ($chunk = $probe; $chunk !== ''; $chunk = $body->read(self::COPY_CHUNK_BYTES)) {
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    // The true streaming cap: an origin that lies about (or
                    // omits) Content-Length is stopped here rather than after a
                    // whole body has been accepted.
                    throw new MediaReferenceException(sprintf(
                        'Download of "%s" exceeds the %d byte limit; skipped.',
                        $url,
                        $maxBytes
                    ));
                }
                hash_update($hash, $chunk);
                $file->write($chunk);
            }
        } catch (\Throwable $e) {
            $file->close();
            $this->discard($directory, $tmpPath);
            throw $e;
        }
        $file->close();

        if ($directory->isExist($base . $target)) {
            // Only reachable with "Re-Download Existing Files" on.
            if (sha1((string)$directory->readFile($base . $target)) === hash_final($hash)) {
                $this->discard($directory, $tmpPath);

                return $target;
            }
            $this->logger->warning(sprintf(
                'Replaced the stored bytes of "%s" from "%s"; resized renditions under'
                . ' pub/media/catalog/product/cache are now stale for it.',
                $target,
                $url
            ));
            $directory->delete($base . $target);
        }

        $directory->renameFile($tmpPath, $base . $target);

        return $target;
    }

    private function discard(WriteInterface $directory, string $path): void
    {
        try {
            if ($directory->isExist($path)) {
                $directory->delete($path);
            }
        } catch (\Throwable $e) {
            // A leftover part file is untidy, not harmful: it can never be
            // mistaken for a published image because of its suffix.
            $this->logger->warning(
                sprintf('Could not remove the temporary media file "%s": %s', $path, $e->getMessage())
            );
        }
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
    private function assertSignature(string $url, string $extension, string $probe): void
    {
        foreach (self::SIGNATURES[$extension] as $signature) {
            if (str_starts_with($probe, $signature)) {
                // RIFF containers carry their type after the four size bytes.
                if ($extension === 'webp' && substr($probe, 8, 4) !== 'WEBP') {
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
