<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Tests\Capability;

use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use PHPUnit\Framework\TestCase;
use Rad\SymfonyDocsMateExtension\Capability\SymfonyDocsCatalogResource;
use Rad\SymfonyDocsMateExtension\Capability\SymfonyDocsSearchTool;
use Rad\SymfonyDocsMateExtension\Model\DocsConfiguration;
use Rad\SymfonyDocsMateExtension\Service\DocsRepository;
use Rad\SymfonyDocsMateExtension\Service\DocsSourceResolver;
use Rad\SymfonyDocsMateExtension\Service\RstParser;

final class SymfonyDocsCapabilitySmokeTest extends TestCase
{
    private DocsRepository $repository;

    protected function setUp(): void
    {
        $docsRoot = dirname(__DIR__).'/Fixture/docs';
        $cacheDir = sys_get_temp_dir().'/symfony-docs-mate-extension-capability-tests-'.bin2hex(random_bytes(6));
        $configuration = new DocsConfiguration($docsRoot, $docsRoot, $cacheDir, null, null, 8, 20, 1200);

        $this->repository = new DocsRepository(
            $configuration,
            new DocsSourceResolver($configuration),
            new RstParser(),
        );
    }

    public function testSearchToolReturnsEncodedString(): void
    {
        $tool = new SymfonyDocsSearchTool($this->repository);
        $output = $tool->search('guide');

        self::assertIsString($output);
        self::assertStringContainsString('guide.rst', $output);
    }

    public function testCatalogResourceReturnsStructuredPayload(): void
    {
        $resource = new SymfonyDocsCatalogResource($this->repository);
        $payload = $resource->catalog();

        self::assertSame('symfony-docs://catalog', $payload['uri']);
        self::assertSame('text/plain', $payload['mimeType']);
        self::assertStringContainsString('guide.rst', $payload['text']);
    }

    public function testSearchToolSchemaDefinesArrayItemsForWithin(): void
    {
        $schemaGenerator = new SchemaGenerator(new DocBlockParser());
        $schema = $schemaGenerator->generate(new \ReflectionMethod(SymfonyDocsSearchTool::class, 'search'));

        self::assertSame(['array', 'null'], $schema['properties']['within']['type']);
        self::assertSame(['type' => 'string'], $schema['properties']['within']['items']);
    }
}
