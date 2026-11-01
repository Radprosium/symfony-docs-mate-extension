<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Capability;

use Symfony\AI\Mate\Attribute\MateResource;
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
    #[MateResource(uri: 'symfony-docs://catalog', name: 'symfony_docs_catalog', title: 'Symfony Docs Catalog', description: 'Browse the top-level Symfony documentation catalog structure', mimeType: 'text/plain')]
    public function catalog(): array
    {
        return [
            'uri' => 'symfony-docs://catalog',
            'mimeType' => 'text/plain',
            'text' => ResponseEncoder::encode($this->repository->getCatalog()),
        ];
    }
}
