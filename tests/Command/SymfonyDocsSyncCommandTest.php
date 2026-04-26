<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Tests\Command;

use PHPUnit\Framework\TestCase;
use Rad\SymfonyDocsMateExtension\Command\SymfonyDocsSyncCommand;
use Rad\SymfonyDocsMateExtension\Model\DocsConfiguration;
use Rad\SymfonyDocsMateExtension\Service\DocsSourceResolver;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class SymfonyDocsSyncCommandTest extends TestCase
{
    public function testSyncCommandPrefetchesManagedSnapshot(): void
    {
        $fixtureDocsRoot = dirname(__DIR__).'/Fixture/docs';
        $workspace = sys_get_temp_dir().'/symfony-docs-mate-sync-command-'.bin2hex(random_bytes(6));
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
        $command = new SymfonyDocsSyncCommand(new DocsSourceResolver($configuration));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('snapshot is ready', $tester->getDisplay());
        self::assertFileExists($cacheDir.'/snapshot/metadata.php');
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
