<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Service;

use Rad\SymfonyDocsMateExtension\Model\DocsConfiguration;
use Symfony\Component\Process\Process;

final class DocsSourceResolver
{
    private ?string $resolvedDocsRoot = null;

    /**
     * @var array{
     *     mode: string,
     *     docs_root: string,
     *     docs_ref: string|null,
     *     archive_url: string|null,
     *     snapshot_dir: string|null,
     *     updated_at: string|null
     * }|null
     */
    private ?array $sourceInfo = null;

    public function __construct(
        private readonly DocsConfiguration $configuration,
    ) {
    }

    public function getDocsRoot(): string
    {
        if (null !== $this->resolvedDocsRoot) {
            return $this->resolvedDocsRoot;
        }

        $explicitDocsRoot = $this->normalizeOptionalPath($this->configuration->docsRoot);

        if (null !== $explicitDocsRoot) {
            $this->validateDocsRoot($explicitDocsRoot, true);
            $this->sourceInfo = [
                'mode' => 'explicit',
                'docs_root' => $explicitDocsRoot,
                'docs_ref' => null,
                'archive_url' => null,
                'snapshot_dir' => null,
                'updated_at' => null,
            ];

            return $this->resolvedDocsRoot = $explicitDocsRoot;
        }

        return $this->resolvedDocsRoot = $this->ensureManagedSnapshot();
    }

    /**
     * @return array{
     *     mode: string,
     *     docs_root: string,
     *     docs_ref: string|null,
     *     archive_url: string|null,
     *     snapshot_dir: string|null,
     *     updated_at: string|null
     * }
     */
    public function syncManagedSnapshot(bool $force = false, ?string $overrideDocsRef = null): array
    {
        $explicitDocsRoot = $this->normalizeOptionalPath($this->configuration->docsRoot);

        if (null !== $explicitDocsRoot) {
            $this->validateDocsRoot($explicitDocsRoot, true);
            $this->resolvedDocsRoot = $explicitDocsRoot;
            $this->sourceInfo = [
                'mode' => 'explicit',
                'docs_root' => $explicitDocsRoot,
                'docs_ref' => null,
                'archive_url' => null,
                'snapshot_dir' => null,
                'updated_at' => null,
            ];

            return $this->sourceInfo;
        }

        $this->resolvedDocsRoot = $this->ensureManagedSnapshot($force, $overrideDocsRef);

        return $this->getSourceInfo();
    }

    /**
     * @return array{
     *     mode: string,
     *     docs_root: string,
     *     docs_ref: string|null,
     *     archive_url: string|null,
     *     snapshot_dir: string|null,
     *     updated_at: string|null
     * }
     */
    public function getSourceInfo(): array
    {
        $this->getDocsRoot();

        return $this->sourceInfo ?? [
            'mode' => 'unknown',
            'docs_root' => '',
            'docs_ref' => null,
            'archive_url' => null,
            'snapshot_dir' => null,
            'updated_at' => null,
        ];
    }

    private function ensureManagedSnapshot(bool $force = false, ?string $overrideDocsRef = null): string
    {
        $snapshotBaseDir = rtrim($this->configuration->cacheDir, '/').'/snapshot';
        $docsRef = $this->determineDocsRef($overrideDocsRef);
        $archiveUrl = $this->determineArchiveUrl($docsRef);
        $snapshotDir = $snapshotBaseDir.'/symfony-docs-'.$this->sanitizeRef($docsRef);
        $metadataFile = $snapshotBaseDir.'/metadata.php';
        $lockFile = $snapshotBaseDir.'/snapshot.lock';

        if (!is_dir($snapshotBaseDir) && !mkdir($snapshotBaseDir, 0777, true) && !is_dir($snapshotBaseDir)) {
            throw new \RuntimeException(\sprintf('Unable to create snapshot cache directory "%s".', $snapshotBaseDir));
        }

        $lockHandle = fopen($lockFile, 'c+');
        if (false === $lockHandle) {
            throw new \RuntimeException(\sprintf('Unable to open snapshot lock file "%s".', $lockFile));
        }

        try {
            if (!flock($lockHandle, \LOCK_EX)) {
                throw new \RuntimeException(\sprintf('Unable to lock snapshot cache file "%s".', $lockFile));
            }

            $metadata = $this->readMetadata($metadataFile);

            if (
                !$force
                &&
                is_dir($snapshotDir)
                && is_file($snapshotDir.'/index.rst')
                && ($metadata['docs_ref'] ?? null) === $docsRef
                && ($metadata['archive_url'] ?? null) === $archiveUrl
                && ($metadata['snapshot_dir'] ?? null) === $snapshotDir
            ) {
                $this->sourceInfo = [
                    'mode' => 'managed_snapshot',
                    'docs_root' => $snapshotDir,
                    'docs_ref' => $docsRef,
                    'archive_url' => $archiveUrl,
                    'snapshot_dir' => $snapshotDir,
                    'updated_at' => $metadata['updated_at'] ?? null,
                ];

                return $snapshotDir;
            }

            $this->downloadSnapshot($archiveUrl, $snapshotDir, $snapshotBaseDir, $docsRef, $metadataFile);

            $metadata = $this->readMetadata($metadataFile);
            $this->sourceInfo = [
                'mode' => 'managed_snapshot',
                'docs_root' => $snapshotDir,
                'docs_ref' => $docsRef,
                'archive_url' => $archiveUrl,
                'snapshot_dir' => $snapshotDir,
                'updated_at' => $metadata['updated_at'] ?? null,
            ];

            return $snapshotDir;
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function determineDocsRef(?string $overrideDocsRef = null): string
    {
        $normalizedOverride = $this->normalizeOptionalPath($overrideDocsRef);
        if (null !== $normalizedOverride) {
            return $normalizedOverride;
        }

        $configuredRef = $this->normalizeOptionalPath($this->configuration->docsRef);

        if (null !== $configuredRef) {
            return $configuredRef;
        }

        $composerFile = rtrim($this->configuration->projectRoot, '/').'/composer.json';
        if (is_file($composerFile)) {
            $decoded = json_decode((string) file_get_contents($composerFile), true);

            if (is_array($decoded)) {
                $extraRequire = $decoded['extra']['symfony']['require'] ?? null;
                if (is_string($extraRequire) && preg_match('/(\d+\.\d+)/', $extraRequire, $matches)) {
                    return $matches[1];
                }

                foreach (['symfony/framework-bundle', 'symfony/console'] as $package) {
                    $constraint = $decoded['require'][$package] ?? null;
                    if (is_string($constraint) && preg_match('/(\d+\.\d+)/', $constraint, $matches)) {
                        return $matches[1];
                    }
                }
            }
        }

        return '8.0';
    }

    private function determineArchiveUrl(string $docsRef): string
    {
        $configuredArchiveUrl = $this->normalizeOptionalPath($this->configuration->archiveUrl);

        if (null !== $configuredArchiveUrl) {
            return str_replace('{ref}', $docsRef, $configuredArchiveUrl);
        }

        return \sprintf('https://codeload.github.com/symfony/symfony-docs/tar.gz/refs/heads/%s', $docsRef);
    }

    private function downloadSnapshot(string $archiveUrl, string $snapshotDir, string $snapshotBaseDir, string $docsRef, string $metadataFile): void
    {
        $tempDir = $snapshotBaseDir.'/.tmp-'.bin2hex(random_bytes(8));
        $archivePath = $tempDir.'/symfony-docs.tar.gz';
        $extractDir = $tempDir.'/extract';

        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new \RuntimeException(\sprintf('Unable to create temporary directory "%s".', $tempDir));
        }

        try {
            if (!mkdir($extractDir, 0777, true) && !is_dir($extractDir)) {
                throw new \RuntimeException(\sprintf('Unable to create extract directory "%s".', $extractDir));
            }

            $this->downloadArchive($archiveUrl, $archivePath);
            $this->extractArchive($archivePath, $extractDir);
            $extractedDocsRoot = $this->findExtractedDocsRoot($extractDir);

            $this->removeDirectory($snapshotDir);

            if (!@rename($extractedDocsRoot, $snapshotDir)) {
                $this->copyDirectory($extractedDocsRoot, $snapshotDir);
            }

            $this->validateDocsRoot($snapshotDir, false);

            file_put_contents($metadataFile, "<?php\n\nreturn ".var_export([
                'docs_ref' => $docsRef,
                'archive_url' => $archiveUrl,
                'snapshot_dir' => $snapshotDir,
                'updated_at' => date(\DateTimeInterface::ATOM),
            ], true).";\n");
        } catch (\Throwable $exception) {
            $this->removeDirectory($snapshotDir);

            throw $exception;
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    private function downloadArchive(string $archiveUrl, string $archivePath): void
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'user_agent' => 'SymfonyDocsMateExtension/1.0',
            ],
            'https' => [
                'timeout' => 60,
                'user_agent' => 'SymfonyDocsMateExtension/1.0',
            ],
        ]);

        $source = @fopen($archiveUrl, 'rb', false, $context);
        if (false === $source) {
            throw new \RuntimeException(\sprintf(
                'Unable to download Symfony docs snapshot from "%s". Configure "symfony_docs_mate.archive_url" or set an explicit "symfony_docs_mate.docs_root" if network access is unavailable.',
                $archiveUrl,
            ));
        }

        $target = fopen($archivePath, 'wb');
        if (false === $target) {
            fclose($source);
            throw new \RuntimeException(\sprintf('Unable to write snapshot archive "%s".', $archivePath));
        }

        try {
            if (false === stream_copy_to_stream($source, $target)) {
                throw new \RuntimeException(\sprintf('Unable to persist snapshot archive "%s".', $archivePath));
            }
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private function extractArchive(string $archivePath, string $extractDir): void
    {
        try {
            $process = new Process(['tar', '-xzf', $archivePath, '-C', $extractDir]);
            $process->run();

            if ($process->isSuccessful()) {
                return;
            }
        } catch (\Throwable) {
        }

        try {
            $phar = new \PharData($archivePath);
            $tarPath = preg_replace('/\.gz$/', '', $archivePath) ?? $archivePath.'.tar';

            if (!is_file($tarPath)) {
                $phar->decompress();
            }

            $tar = new \PharData($tarPath);
            $tar->extractTo($extractDir, null, true);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                \sprintf('Unable to extract Symfony docs snapshot archive "%s".', $archivePath),
                previous: $exception,
            );
        }
    }

    private function findExtractedDocsRoot(string $extractDir): string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isDir()) {
                continue;
            }

            $candidate = $file->getPathname();
            if (is_file($candidate.'/index.rst')) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Downloaded Symfony docs snapshot does not contain a valid docs root.');
    }

    private function validateDocsRoot(string $docsRoot, bool $explicitlyConfigured): void
    {
        if (!is_dir($docsRoot)) {
            $message = $explicitlyConfigured
                ? 'Docs root "%s" does not exist. If Symfony AI Mate runs inside a container, set "symfony_docs_mate.docs_root" to the docs checkout path inside the container and make sure that path is mounted there.'
                : 'Managed docs snapshot directory "%s" does not exist after download.';

            throw new \InvalidArgumentException(\sprintf($message, $docsRoot));
        }

        if (!is_readable($docsRoot)) {
            $message = $explicitlyConfigured
                ? 'Docs root "%s" is not readable. Make sure the docs checkout is mounted with read access for the container user.'
                : 'Managed docs snapshot directory "%s" is not readable.';

            throw new \InvalidArgumentException(\sprintf($message, $docsRoot));
        }

        if (!is_file($docsRoot.'/index.rst')) {
            $message = $explicitlyConfigured
                ? 'Docs root "%s" does not look like a Symfony docs checkout because "index.rst" is missing. Point "symfony_docs_mate.docs_root" at the root of a symfony-docs repository inside the current runtime.'
                : 'Managed docs snapshot directory "%s" does not contain "index.rst".';

            throw new \InvalidArgumentException(\sprintf($message, $docsRoot));
        }
    }

    private function readMetadata(string $metadataFile): array
    {
        if (!is_file($metadataFile)) {
            return [];
        }

        $metadata = require $metadataFile;

        return is_array($metadata) ? $metadata : [];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($path);
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            $targetPath = $destination.'/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($source))), '/');

            if ($file->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0777, true) && !is_dir($targetPath)) {
                    throw new \RuntimeException(\sprintf('Unable to create snapshot directory "%s".', $targetPath));
                }

                continue;
            }

            $targetDirectory = dirname($targetPath);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
                throw new \RuntimeException(\sprintf('Unable to create snapshot directory "%s".', $targetDirectory));
            }

            if (!copy($file->getPathname(), $targetPath)) {
                throw new \RuntimeException(\sprintf('Unable to copy snapshot file "%s".', $targetPath));
            }
        }
    }

    private function normalizeOptionalPath(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    private function sanitizeRef(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? $value;

        return trim($sanitized, '-');
    }
}
