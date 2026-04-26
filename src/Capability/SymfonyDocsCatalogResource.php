<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Capability;

use Mcp\Capability\Attribute\McpResource;
use Rad\SymfonyDocsMateExtension\Service\DocsRepository;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

final class SymfonyDocsCatalogResource
{
    public function __construct(
        private readonly DocsRepository $repository,
    ) {
    }

    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    #[McpResource(uri: 'symfony-docs://catalog', name: 'symfony_docs_catalog', mimeType: 'text/plain')]
    public function catalog(): array
    {
        return [
            'uri' => 'symfony-docs://catalog',
            'mimeType' => 'text/plain',
            'text' => ResponseEncoder::encode($this->repository->getCatalog()),
        ];
    }
}
