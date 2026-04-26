<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Tests\Service;

use PHPUnit\Framework\TestCase;
use Rad\SymfonyDocsMateExtension\Model\DocsConfiguration;
use Rad\SymfonyDocsMateExtension\Service\DocsSourceResolver;
use Symfony\Component\Process\Process;

final class DocsSourceResolverTest extends TestCase
{
    public function testManagedSnapshotModeDownloadsArchiveIntoCache(): void
    {
        $fixtureDocsRoot = dirname(__DIR__).'/Fixture/docs';
        $workspace = sys_get_temp_dir().'/symfony-docs-mate-source-resolver-'.bin2hex(random_bytes(6));
        $projectRoot = $workspace.'/project';
        $cacheDir = $workspace.'/cache';
        $archiveRoot = $workspace.'/archive-root';
        $archiveFixtureDir = $archiveRoot.'/symfony-symfony-docs-fixture';
        $archivePath = $workspace.'/symfony-docs-fixture.tar.gz';

        mkdir($projectRoot, 0777, true);
        mkdir($archiveFixtureDir, 0777, true);
        file_put_contents($projectRoot.'/composer.json', json_encode([
            'extra' => [
                'symfony' => [
                    'require' => '8.0.*',
                ],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $this->copyDirectory($fixtureDocsRoot, $archiveFixtureDir);
        $process = new Process(['tar', '-czf', $archivePath, '-C', $archiveRoot, basename($archiveFixtureDir)]);
        $process->mustRun();

        $configuration = new DocsConfiguration(null, $projectRoot, $cacheDir, null, $archivePath);
        $resolver = new DocsSourceResolver($configuration);

        $docsRoot = $resolver->getDocsRoot();
        $sourceInfo = $resolver->getSourceInfo();

        self::assertFileExists($docsRoot.'/index.rst');
        self::assertSame('managed_snapshot', $sourceInfo['mode']);
        self::assertSame('8.0', $sourceInfo['docs_ref']);
        self::assertSame($archivePath, $sourceInfo['archive_url']);
        self::assertSame($docsRoot, $sourceInfo['snapshot_dir']);
    }

    public function testForceSyncReusesOverrideRef(): void
    {
        $fixtureDocsRoot = dirname(__DIR__).'/Fixture/docs';
        $workspace = sys_get_temp_dir().'/symfony-docs-mate-source-resolver-force-'.bin2hex(random_bytes(6));
        $projectRoot = $workspace.'/project';
        $cacheDir = $workspace.'/cache';
        $archiveRoot = $workspace.'/archive-root';
        $archiveFixtureDir = $archiveRoot.'/symfony-symfony-docs-fixture';
        $archivePath = $workspace.'/symfony-docs-fixture.tar.gz';

        mkdir($projectRoot, 0777, true);
        mkdir($archiveFixtureDir, 0777, true);
        file_put_contents($projectRoot.'/composer.json', '{"extra":{"symfony":{"require":"8.0.*"}}}');

        $this->copyDirectory($fixtureDocsRoot, $archiveFixtureDir);
        $process = new Process(['tar', '-czf', $archivePath, '-C', $archiveRoot, basename($archiveFixtureDir)]);
        $process->mustRun();

        $configuration = new DocsConfiguration(null, $projectRoot, $cacheDir, null, $archivePath);
        $resolver = new DocsSourceResolver($configuration);

        $sourceInfo = $resolver->syncManagedSnapshot(true, '8.1');

        self::assertSame('8.1', $sourceInfo['docs_ref']);
        self::assertFileExists($sourceInfo['docs_root'].'/index.rst');
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
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0777, true);
                }

                continue;
            }

            $targetDirectory = dirname($targetPath);
            if (!is_dir($targetDirectory)) {
                mkdir($targetDirectory, 0777, true);
            }

            copy($file->getPathname(), $targetPath);
        }
    }
}
