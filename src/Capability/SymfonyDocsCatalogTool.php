<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Capability;

use Mcp\Capability\Attribute\McpTool;
use Rad\SymfonyDocsMateExtension\Service\DocsRepository;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

final class SymfonyDocsCatalogTool
{
    public function __construct(
        private readonly DocsRepository $repository,
    ) {
    }

    /**
     * @param string|null $topic Optional page or section locator to scope the catalog to
     * @param int         $depth How many child levels to expand from the selected topic
     * @param int         $limit Maximum number of catalog items to return
     */
    #[McpTool('symfony-docs-catalog', 'Browse the Symfony docs structure by topic or page path. Use this before reading content when you need to orient yourself in the documentation tree.')]
    public function catalog(?string $topic = null, int $depth = 1, int $limit = 50): string
    {
        return ResponseEncoder::encode($this->repository->getCatalog($topic, $depth, $limit));
    }
}
