<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Capability;

use Symfony\AI\Mate\Attribute\MateResource;
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
    #[MateResource(uri: 'symfony-docs://manifest-status', name: 'symfony_docs_manifest_status', title: 'Symfony Docs Manifest Status', description: 'Inspection of the local Symfony documentation snapshot status and metadata', mimeType: 'text/plain')]
    public function status(): array
    {
        return [
            'uri' => 'symfony-docs://manifest-status',
            'mimeType' => 'text/plain',
            'text' => ResponseEncoder::encode($this->repository->getManifestStatus()),
        ];
    }
}
