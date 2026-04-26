<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Capability;

use Mcp\Capability\Attribute\McpTool;
use Rad\SymfonyDocsMateExtension\Service\DocsRepository;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

final class SymfonyDocsSectionTool
{
    public function __construct(
        private readonly DocsRepository $repository,
    ) {
    }

    /**
     * @param string   $locator Exact label, page path, or path#fragment used to resolve the excerpt
     * @param int|null $maxLines Hard limit for excerpt lines
     * @param int|null $maxChars Hard limit for excerpt characters
     * @param bool     $includeHeading Include the section heading in the excerpt
     */
    #[McpTool('symfony-docs-section', 'Read a bounded excerpt from the Symfony docs. Use this once you already know the relevant page or section so you only fetch the lines that matter.')]
    public function section(
        string $locator,
        ?int $maxLines = null,
        ?int $maxChars = null,
        bool $includeHeading = true,
    ): string {
        return ResponseEncoder::encode(
            $this->repository->getSection($locator, $maxLines, $maxChars, $includeHeading),
        );
    }
}
