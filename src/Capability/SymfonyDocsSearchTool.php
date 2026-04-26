<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Capability;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Rad\SymfonyDocsMateExtension\Service\DocsRepository;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

final class SymfonyDocsSearchTool
{
    public function __construct(
        private readonly DocsRepository $repository,
    ) {
    }

    /**
     * @param string      $query  Search phrase, page title, label, or section title to find in the docs
     * @param string[]|null $within Optional page paths or top-level scopes used to narrow the search
     * @param string      $scope  Search mode: auto, metadata, or content
     * @param int         $limit  Maximum number of hits to return
     */
    #[McpTool('symfony-docs-search', 'Search the Symfony docs by path, title, label, heading, and optionally body content. Use this as the default entry point for targeted documentation lookup.')]
    public function search(
        string $query,
        #[Schema(items: ['type' => 'string'])]
        ?array $within = null,
        string $scope = 'auto',
        int $limit = 8,
    ): string
    {
        return ResponseEncoder::encode($this->repository->search($query, $within, $scope, $limit));
    }
}
