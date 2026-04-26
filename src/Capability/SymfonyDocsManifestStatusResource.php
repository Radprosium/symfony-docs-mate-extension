<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Capability;

use Mcp\Capability\Attribute\McpResource;
use Rad\SymfonyDocsMateExtension\Service\DocsRepository;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

final class SymfonyDocsManifestStatusResource
{
    public function __construct(
        private readonly DocsRepository $repository,
    ) {
    }

    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    #[McpResource(uri: 'symfony-docs://manifest-status', name: 'symfony_docs_manifest_status', mimeType: 'text/plain')]
    public function status(): array
    {
        return [
            'uri' => 'symfony-docs://manifest-status',
            'mimeType' => 'text/plain',
            'text' => ResponseEncoder::encode($this->repository->getManifestStatus()),
        ];
    }
}
