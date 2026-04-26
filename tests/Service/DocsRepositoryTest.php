<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Tests\Service;

use PHPUnit\Framework\TestCase;
use Rad\SymfonyDocsMateExtension\Model\DocsConfiguration;
use Rad\SymfonyDocsMateExtension\Service\DocsRepository;
use Rad\SymfonyDocsMateExtension\Service\DocsSourceResolver;
use Rad\SymfonyDocsMateExtension\Service\RstParser;

final class DocsRepositoryTest extends TestCase
{
    private DocsRepository $repository;

    protected function setUp(): void
    {
        $docsRoot = dirname(__DIR__).'/Fixture/docs';
        $cacheDir = sys_get_temp_dir().'/symfony-docs-mate-extension-tests-'.bin2hex(random_bytes(6));
        $configuration = new DocsConfiguration($docsRoot, $docsRoot, $cacheDir, null, null, 8, 20, 1200);

        $this->repository = new DocsRepository(
            $configuration,
            new DocsSourceResolver($configuration),
            new RstParser(),
        );
    }

    public function testCatalogUsesRootToctree(): void
    {
        $catalog = $this->repository->getCatalog();

        self::assertSame('index.rst', $catalog['topic']['path']);
        self::assertCount(2, $catalog['items']);
        self::assertSame('guide.rst', $catalog['items'][0]['path']);
        self::assertSame('reference/index.rst', $catalog['items'][1]['path']);
    }

    public function testSearchFindsExactLabel(): void
    {
        $results = $this->repository->search('guide-installation');

        self::assertNotEmpty($results['hits']);
        self::assertSame('guide.rst#guide-installation', $results['hits'][0]['id']);
        self::assertContains('label_exact', $results['hits'][0]['match_reasons']);
    }

    public function testSearchRespectsWithinScope(): void
    {
        $results = $this->repository->search('configuration', ['reference']);

        self::assertNotEmpty($results['hits']);
        self::assertSame('reference/configuration.rst', $results['hits'][0]['path']);
    }

    public function testPageReturnsHeadingsAndChildren(): void
    {
        $page = $this->repository->getPage('guide.rst');

        self::assertSame('Guide', $page['title']);
        self::assertCount(2, $page['headings']);
        self::assertSame('Installation', $page['headings'][0]['title']);
        self::assertCount(0, $page['children']);
    }

    public function testSectionResolvesLabel(): void
    {
        $section = $this->repository->getSection('guide-installation', 20, 1000);

        self::assertSame('guide.rst#guide-installation', $section['id']);
        self::assertSame('Installation', $section['section_title']);
        self::assertStringContainsString('Install the package', $section['excerpt']);
    }

    public function testManifestStatusReportsIndexedFiles(): void
    {
        $status = $this->repository->getManifestStatus();

        self::assertSame(4, $status['file_count']);
        self::assertArrayHasKey('checksum', $status);
        self::assertSame('explicit', $status['mode']);
    }

    public function testMissingDocsRootMentionsContainerPath(): void
    {
        $configuration = new DocsConfiguration(
            '/tmp/this-path-does-not-exist-for-symfony-docs-tests',
            dirname(__DIR__).'/Fixture/docs',
            sys_get_temp_dir().'/symfony-docs-mate-missing-root',
        );
        $repository = new DocsRepository(
            $configuration,
            new DocsSourceResolver($configuration),
            new RstParser(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('inside a container');

        $repository->getManifestStatus();
    }
}
