<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Capability;

use Mcp\Capability\Attribute\McpTool;
use Rad\SymfonyDocsMateExtension\Service\DocsRepository;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

final class SymfonyDocsPageTool
{
    public function __construct(
        private readonly DocsRepository $repository,
    ) {
    }

    /**
     * @param string $locator Page path, title, or exact label used to resolve a documentation page
     * @param bool   $includeHeadings Include section headings in the response
     * @param bool   $includeLabels Include page labels in the response
     * @param bool   $includeChildren Include toctree child pages in the response
     * @param int    $summaryLines Maximum number of summary lines to return
     */
    #[McpTool('symfony-docs-page', 'Inspect a Symfony docs page without dumping the full file. Use this to see page metadata, headings, labels, and child pages before reading a section excerpt.')]
    public function page(
        string $locator,
        bool $includeHeadings = true,
        bool $includeLabels = true,
        bool $includeChildren = true,
        int $summaryLines = 12,
    ): string {
        return ResponseEncoder::encode(
            $this->repository->getPage($locator, $includeHeadings, $includeLabels, $includeChildren, $summaryLines),
        );
    }
}
