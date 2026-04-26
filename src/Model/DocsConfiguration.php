<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Model;

final readonly class DocsConfiguration
{
    public function __construct(
        public ?string $docsRoot,
        public string $projectRoot,
        public string $cacheDir,
        public ?string $docsRef = null,
        public ?string $archiveUrl = null,
        public int $maxSearchHits = 8,
        public int $maxExcerptLines = 80,
        public int $maxExcerptChars = 4000,
    ) {
    }
}
