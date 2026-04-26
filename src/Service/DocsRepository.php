<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Service;

use Rad\SymfonyDocsMateExtension\Model\DocsConfiguration;
use Symfony\Component\Process\Process;

final class DocsRepository
{
    private ?array $manifest = null;
    private ?bool $ripgrepAvailable = null;

    public function __construct(
        private readonly DocsConfiguration $configuration,
        private readonly DocsSourceResolver $sourceResolver,
        private readonly RstParser $parser,
    ) {
    }

    /**
     * @return array{repo_root: string, generated_at: string, topic: array<string, mixed>|null, items: list<array<string, mixed>>}
     */
    public function getCatalog(?string $topic = null, int $depth = 1, int $limit = 50): array
    {
        $manifest = $this->getManifest();
        $pagePath = null === $topic || '' === trim($topic)
            ? 'index.rst'
            : $this->resolvePagePath($topic);
        $page = $manifest['pages'][$pagePath] ?? null;
        $items = [];

        if (null !== $page) {
            $queue = [];

            foreach ($page['children'] as $childPath) {
                $queue[] = ['path' => $childPath, 'depth' => 1, 'parent' => $page['path']];
            }

            while ([] !== $queue && count($items) < $limit) {
                $current = array_shift($queue);
                $child = $manifest['pages'][$current['path']] ?? null;

                if (null === $child) {
                    continue;
                }

                $items[] = [
                    'type' => [] === $child['children'] ? 'page' : 'topic',
                    'title' => $child['title'],
                    'path' => $child['path'],
                    'id' => $child['path'],
                    'depth' => $current['depth'],
                    'parent_path' => $current['parent'],
                    'children_count' => count($child['children']),
                    'summary' => $child['summary'],
                ];

                if ($current['depth'] >= $depth) {
                    continue;
                }

                foreach ($child['children'] as $grandChildPath) {
                    $queue[] = [
                        'path' => $grandChildPath,
                        'depth' => $current['depth'] + 1,
                        'parent' => $child['path'],
                    ];
                }
            }
        }

        return [
            'repo_root' => $this->getDocsRoot(),
            'generated_at' => $manifest['generated_at'],
            'topic' => null !== $page ? [
                'title' => $page['title'],
                'path' => $page['path'],
                'children_count' => count($page['children']),
            ] : null,
            'items' => $items,
        ];
    }

    /**
     * @param list<string>|null $within
     *
     * @return array{query: string, hits: list<array<string, mixed>>}
     */
    public function search(string $query, ?array $within = null, string $scope = 'auto', ?int $limit = null): array
    {
        $manifest = $this->getManifest();
        $limit ??= $this->configuration->maxSearchHits;
        $normalizedQuery = trim($query);

        if ('' === $normalizedQuery) {
            return ['query' => $query, 'hits' => []];
        }

        $queryTokens = $this->tokenize($normalizedQuery);
        $within = $this->normalizeWithin($within);
        $hits = [];

        foreach ($manifest['pages'] as $page) {
            if (!$this->matchesWithin($page['path'], $within)) {
                continue;
            }

            $lowerTitle = mb_strtolower($page['title']);
            $lowerPath = mb_strtolower($page['path']);
            $score = 0;
            $reasons = [];
            $sectionTitle = null;
            $id = $page['path'];
            $matchedExactLabel = false;
            $matchedExactHeading = false;

            foreach ($page['labels'] as $label) {
                if (mb_strtolower($label['label']) === mb_strtolower($normalizedQuery)) {
                    $score += 100;
                    $reasons[] = 'label_exact';
                    $id = $page['path'].'#'.$label['label'];
                    $matchedExactLabel = true;
                    break;
                }
            }

            if ($lowerPath === mb_strtolower($normalizedQuery) || basename($lowerPath) === mb_strtolower($normalizedQuery)) {
                $score += 95;
                $reasons[] = 'path_exact';
            }

            if ($lowerTitle === mb_strtolower($normalizedQuery)) {
                $score += 90;
                $reasons[] = 'title_exact';
            }

            foreach ($page['headings'] as $heading) {
                if (mb_strtolower($heading['title']) === mb_strtolower($normalizedQuery)) {
                    $score += 75;
                    $reasons[] = 'heading_exact';
                    if (!$matchedExactLabel) {
                        $sectionTitle = $heading['title'];
                        $id = $page['path'].'#'.$heading['slug'];
                        $matchedExactHeading = true;
                    }
                    break;
                }
            }

            $titleCoverage = $this->coverageScore($queryTokens, $this->tokenize($page['title']));
            if ($titleCoverage > 0) {
                $score += 40 + $titleCoverage;
                $reasons[] = 'title_tokens';
            }

            $pathCoverage = $this->coverageScore($queryTokens, $this->tokenize($page['path']));
            if ($pathCoverage > 0) {
                $score += 20 + $pathCoverage;
                $reasons[] = 'path_tokens';
            }

            $labelCoverage = $this->coverageScore($queryTokens, $this->tokenize(implode(' ', array_column($page['labels'], 'label'))));
            if ($labelCoverage > 0) {
                $score += 15 + $labelCoverage;
                $reasons[] = 'label_tokens';
            }

            foreach ($page['headings'] as $heading) {
                $coverage = $this->coverageScore($queryTokens, $this->tokenize($heading['title']));

                if ($coverage <= 0) {
                    continue;
                }

                $score += 10 + $coverage;
                if (!$matchedExactLabel && !$matchedExactHeading) {
                    $sectionTitle ??= $heading['title'];
                    $id = $page['path'].'#'.$heading['slug'];
                }
                $reasons[] = 'heading_tokens';
                break;
            }

            if ([] !== $within) {
                $score += 15;
                $reasons[] = 'within_scope';
            }

            if ($score <= 0) {
                continue;
            }

            $hits[$page['path']] = [
                'id' => $id,
                'type' => null !== $sectionTitle || str_contains($id, '#') ? 'section' : 'page',
                'path' => $page['path'],
                'title' => $page['title'],
                'section_title' => $sectionTitle,
                'label' => str_contains($id, '#') && !str_contains($id, '#'.($sectionTitle ? $this->slugify($sectionTitle) : ''))
                    ? substr($id, strpos($id, '#') + 1)
                    : null,
                'score' => $score,
                'match_reasons' => array_values(array_unique($reasons)),
                'snippet' => $page['summary'],
            ];
        }

        uasort($hits, static function (array $left, array $right): int {
            $scoreCompare = $right['score'] <=> $left['score'];

            return 0 !== $scoreCompare ? $scoreCompare : strcmp($left['path'], $right['path']);
        });

        $seenPaths = array_fill_keys(array_keys($hits), true);
        $hits = array_values($hits);

        if (
            ('auto' === $scope || 'content' === $scope)
            && (0 === count($hits) || (($hits[0]['score'] ?? 0) < 60))
        ) {
            $fallbackHits = $this->searchInContent($normalizedQuery, $within, $limit * 2);

            foreach ($fallbackHits as $fallbackHit) {
                if (isset($seenPaths[$fallbackHit['path']])) {
                    continue;
                }

                $seenPaths[$fallbackHit['path']] = true;
                $hits[] = $fallbackHit;
            }

            usort($hits, static function (array $left, array $right): int {
                $scoreCompare = $right['score'] <=> $left['score'];

                return 0 !== $scoreCompare ? $scoreCompare : strcmp($left['path'], $right['path']);
            });
        }

        return [
            'query' => $query,
            'hits' => array_slice($hits, 0, $limit),
        ];
    }

    /**
     * @return array{
     *     path: string,
     *     title: string,
     *     summary: string,
     *     headings: list<array{title: string, slug: string, start_line: int}>,
     *     labels: list<array{label: string, line: int}>,
     *     children: list<array{path: string, title: string}>,
     *     resolved_from: string
     * }
     */
    public function getPage(
        string $locator,
        bool $includeHeadings = true,
        bool $includeLabels = true,
        bool $includeChildren = true,
        int $summaryLines = 12,
    ): array {
        $manifest = $this->getManifest();
        $pagePath = $this->resolvePagePath($locator);
        $page = $manifest['pages'][$pagePath] ?? null;

        if (null === $page) {
            throw new \InvalidArgumentException(\sprintf('Unknown documentation page "%s".', $locator));
        }

        $summary = $page['summary'];
        $summary = implode("\n", array_slice(preg_split('/\R/', $summary) ?: [], 0, max(1, $summaryLines)));

        return [
            'path' => $page['path'],
            'title' => $page['title'],
            'summary' => $summary,
            'headings' => $includeHeadings
                ? array_values(array_map(
                    static fn (array $heading): array => [
                        'title' => $heading['title'],
                        'slug' => $heading['slug'],
                        'start_line' => $heading['start_line'],
                    ],
                    array_slice($page['headings'], 1),
                ))
                : [],
            'labels' => $includeLabels ? $page['labels'] : [],
            'children' => $includeChildren
                ? array_values(array_filter(array_map(
                    static function (string $childPath) use ($manifest): ?array {
                        $child = $manifest['pages'][$childPath] ?? null;

                        if (null === $child) {
                            return null;
                        }

                        return [
                            'path' => $child['path'],
                            'title' => $child['title'],
                        ];
                    },
                    $page['children'],
                )))
                : [],
            'resolved_from' => $locator,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     path: string,
     *     title: string,
     *     section_title: string|null,
     *     start_line: int,
     *     end_line: int,
     *     truncated: bool,
     *     excerpt: string
     * }
     */
    public function getSection(
        string $locator,
        ?int $maxLines = null,
        ?int $maxChars = null,
        bool $includeHeading = true,
    ): array {
        $manifest = $this->getManifest();
        $maxLines ??= $this->configuration->maxExcerptLines;
        $maxChars ??= $this->configuration->maxExcerptChars;
        [$pagePath, $fragment] = $this->splitLocator($locator);
        if (null === $fragment && isset($manifest['labels'][$locator])) {
            $fragment = $locator;
        }
        $pagePath = $this->resolvePagePath($pagePath ?: $locator);
        $page = $manifest['pages'][$pagePath] ?? null;

        if (null === $page) {
            throw new \InvalidArgumentException(\sprintf('Unknown documentation page "%s".', $locator));
        }

        $absolutePath = $this->getDocsRoot().'/'.$page['path'];
        $lines = file($absolutePath, \FILE_IGNORE_NEW_LINES);

        if (false === $lines) {
            throw new \RuntimeException(\sprintf('Unable to read "%s".', $page['path']));
        }

        $section = $this->resolveSectionBounds($page, $fragment);
        $startLine = $section['start_line'];

        if (!$includeHeading && $section['include_heading_lines'] > 0) {
            $startLine += $section['include_heading_lines'];
        }

        $slice = array_slice($lines, $startLine - 1, $section['end_line'] - $startLine + 1);
        $truncated = false;

        if (count($slice) > $maxLines) {
            $slice = array_slice($slice, 0, $maxLines);
            $truncated = true;
        }

        $excerpt = implode("\n", $slice);

        if (mb_strlen($excerpt) > $maxChars) {
            $excerpt = rtrim(mb_substr($excerpt, 0, $maxChars))."\n...";
            $truncated = true;
        }

        return [
            'id' => null === $section['fragment'] ? $page['path'] : $page['path'].'#'.$section['fragment'],
            'path' => $page['path'],
            'title' => $page['title'],
            'section_title' => $section['section_title'],
            'start_line' => $startLine,
            'end_line' => min($section['end_line'], $startLine + count($slice) - 1),
            'truncated' => $truncated,
            'excerpt' => $excerpt,
        ];
    }

    /**
     * @return array{
     *     docs_root: string,
     *     cache_file: string,
     *     generated_at: string,
     *     file_count: int,
     *     checksum: string,
     *     has_ripgrep: bool
     * }
     */
    public function getManifestStatus(): array
    {
        $manifest = $this->getManifest();

        return [
            'docs_root' => $this->getDocsRoot(),
            'cache_file' => $this->getCacheFile(),
            'generated_at' => $manifest['generated_at'],
            'file_count' => $manifest['signature']['file_count'],
            'checksum' => $manifest['signature']['checksum'],
            'has_ripgrep' => $this->hasRipgrep(),
        ] + $this->sourceResolver->getSourceInfo();
    }

    private function getManifest(): array
    {
        if (null !== $this->manifest) {
            return $this->manifest;
        }

        $signature = $this->computeSignature();
        $cacheFile = $this->getCacheFile();

        if (is_file($cacheFile)) {
            $cachedManifest = require $cacheFile;

            if (
                is_array($cachedManifest)
                && ($cachedManifest['signature']['checksum'] ?? null) === $signature['checksum']
            ) {
                return $this->manifest = $cachedManifest;
            }
        }

        $manifest = $this->buildManifest($signature);
        $cacheDirectory = dirname($cacheFile);

        if (!is_dir($cacheDirectory) && !mkdir($cacheDirectory, 0777, true) && !is_dir($cacheDirectory)) {
            throw new \RuntimeException(\sprintf('Unable to create cache directory "%s".', $cacheDirectory));
        }

        file_put_contents($cacheFile, "<?php\n\nreturn ".var_export($manifest, true).";\n");

        return $this->manifest = $manifest;
    }

    /**
     * @return array{generated_at: string, signature: array<string, mixed>, pages: array<string, array<string, mixed>>, labels: array<string, array<string, mixed>>}
     */
    private function buildManifest(array $signature): array
    {
        $pages = [];
        $labels = [];

        foreach ($this->iterateDocsFiles() as $relativePath => $absolutePath) {
            $page = $this->parser->parse($absolutePath, $relativePath, $this->getDocsRoot());
            $pages[$relativePath] = $page;

            foreach ($page['labels'] as $label) {
                $labels[$label['label']] = [
                    'path' => $relativePath,
                    'line' => $label['line'],
                ];
            }
        }

        ksort($pages);
        ksort($labels);

        return [
            'generated_at' => date(\DateTimeInterface::ATOM),
            'signature' => $signature,
            'pages' => $pages,
            'labels' => $labels,
        ];
    }

    /**
     * @return array{file_count: int, latest_mtime: int, checksum: string}
     */
    private function computeSignature(): array
    {
        $entries = [];
        $latestMtime = 0;

        foreach ($this->iterateDocsFiles() as $relativePath => $absolutePath) {
            $mtime = filemtime($absolutePath) ?: 0;
            $latestMtime = max($latestMtime, $mtime);
            $entries[] = $relativePath.'@'.$mtime;
        }

        sort($entries);

        return [
            'file_count' => count($entries),
            'latest_mtime' => $latestMtime,
            'checksum' => sha1(implode('|', $entries)),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function iterateDocsFiles(): array
    {
        $root = $this->getDocsRoot();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current, string $key, \RecursiveIterator $iterator): bool {
                    if ($iterator->hasChildren()) {
                        return !in_array($current->getFilename(), ['.git', '_build', 'vendor'], true);
                    }

                    return str_ends_with($current->getFilename(), '.rst')
                        || str_ends_with($current->getFilename(), '.rst.inc');
                },
            ),
        );
        $files = [];

        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $absolutePath = $file->getPathname();
            $relativePath = ltrim(str_replace('\\', '/', substr($absolutePath, strlen($root))), '/');
            $files[$relativePath] = $absolutePath;
        }

        ksort($files);

        return $files;
    }

    private function resolvePagePath(string $locator): string
    {
        $manifest = $this->getManifest();
        [$pathPart, $fragment] = $this->splitLocator(trim($locator));
        $pathPart = '' === $pathPart ? trim($locator) : $pathPart;

        if (isset($manifest['pages'][$pathPart])) {
            return $pathPart;
        }

        $trimmed = ltrim($pathPart, '/');
        $trimmed = preg_replace('/\.html$/', '.rst', $trimmed) ?? $trimmed;

        foreach ([$trimmed, $trimmed.'.rst', $trimmed.'/index.rst', $trimmed.'.rst.inc'] as $candidate) {
            if (isset($manifest['pages'][$candidate])) {
                return $candidate;
            }
        }

        if (isset($manifest['labels'][$pathPart])) {
            return $manifest['labels'][$pathPart]['path'];
        }

        if (isset($manifest['labels'][$fragment])) {
            return $manifest['labels'][$fragment]['path'];
        }

        $matches = array_values(array_filter(
            $manifest['pages'],
            static fn (array $page): bool => mb_strtolower($page['title']) === mb_strtolower($trimmed),
        ));

        if (1 === count($matches)) {
            return $matches[0]['path'];
        }

        throw new \InvalidArgumentException(\sprintf('Unable to resolve documentation locator "%s".', $locator));
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitLocator(string $locator): array
    {
        if (!str_contains($locator, '#')) {
            return [$locator, null];
        }

        [$path, $fragment] = explode('#', $locator, 2);

        return [$path, $fragment];
    }

    /**
     * @param list<string>|null $within
     *
     * @return list<string>
     */
    private function normalizeWithin(?array $within): array
    {
        if (null === $within) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (string $value): string {
                $value = trim($value);
                $value = ltrim($value, '/');

                return rtrim($value, '/');
            },
            $within,
        )));
    }

    /**
     * @param list<string> $within
     */
    private function matchesWithin(string $path, array $within): bool
    {
        if ([] === $within) {
            return true;
        }

        foreach ($within as $scope) {
            if ('' === $scope) {
                continue;
            }

            if ($path === $scope || str_starts_with($path, $scope.'/')) {
                return true;
            }

            if (str_ends_with($scope, '.rst') && $path === $scope) {
                return true;
            }

            if ($path === $scope.'.rst' || $path === $scope.'/index.rst') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $queryTokens
     * @param list<string> $candidateTokens
     */
    private function coverageScore(array $queryTokens, array $candidateTokens): int
    {
        if ([] === $queryTokens || [] === $candidateTokens) {
            return 0;
        }

        $matches = 0;

        foreach ($queryTokens as $token) {
            if (in_array($token, $candidateTokens, true)) {
                ++$matches;
            }
        }

        if (0 === $matches) {
            return 0;
        }

        return $matches === count($queryTokens) ? 20 + $matches * 5 : $matches * 4;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $value): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', mb_strtolower($value)) ?: [];

        return array_values(array_filter($tokens, static fn (string $token): bool => '' !== $token));
    }

    /**
     * @param list<string> $within
     *
     * @return list<array<string, mixed>>
     */
    private function searchInContent(string $query, array $within, int $limit): array
    {
        return $this->hasRipgrep()
            ? $this->searchInContentWithRipgrep($query, $within, $limit)
            : $this->searchInContentWithPhp($query, $within, $limit);
    }

    /**
     * @param list<string> $within
     *
     * @return list<array<string, mixed>>
     */
    private function searchInContentWithPhp(string $query, array $within, int $limit): array
    {
        $manifest = $this->getManifest();
        $hits = [];
        $lowerQuery = mb_strtolower($query);

        foreach ($manifest['pages'] as $page) {
            if (!$this->matchesWithin($page['path'], $within)) {
                continue;
            }

            $lines = file($this->getDocsRoot().'/'.$page['path'], \FILE_IGNORE_NEW_LINES);

            if (false === $lines) {
                continue;
            }

            foreach ($lines as $index => $line) {
                if (!str_contains(mb_strtolower($line), $lowerQuery)) {
                    continue;
                }

                $hits[] = [
                    'id' => $page['path'],
                    'type' => 'page',
                    'path' => $page['path'],
                    'title' => $page['title'],
                    'section_title' => null,
                    'label' => null,
                    'score' => 35,
                    'match_reasons' => ['content_match'],
                    'snippet' => trim($line),
                    'line' => $index + 1,
                ];

                break;
            }

            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $within
     *
     * @return list<array<string, mixed>>
     */
    private function searchInContentWithRipgrep(string $query, array $within, int $limit): array
    {
        $searchTargets = $this->buildRipgrepTargets($within);
        $command = ['rg', '-n', '-i', '-m', '2', '--color', 'never', '--glob', '*.rst', '--glob', '*.rst.inc', '-F', $query];
        array_push($command, ...$searchTargets);
        $process = new Process($command, $this->getDocsRoot());
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $manifest = $this->getManifest();
        $hits = [];

        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            if ('' === $line) {
                continue;
            }

            [$path, $lineNumber, $snippet] = array_pad(explode(':', $line, 3), 3, '');
            $relativePath = ltrim(str_replace('\\', '/', $path), './');
            $page = $manifest['pages'][$relativePath] ?? null;

            if (null === $page) {
                continue;
            }

            $hits[] = [
                'id' => $relativePath,
                'type' => 'page',
                'path' => $relativePath,
                'title' => $page['title'],
                'section_title' => null,
                'label' => null,
                'score' => 35,
                'match_reasons' => ['content_match'],
                'snippet' => trim($snippet),
                'line' => (int) $lineNumber,
            ];

            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $within
     *
     * @return list<string>
     */
    private function buildRipgrepTargets(array $within): array
    {
        if ([] === $within) {
            return ['.'];
        }

        $targets = [];

        foreach ($within as $scope) {
            foreach ([$scope, $scope.'.rst', $scope.'/index.rst', $scope.'.rst.inc'] as $candidate) {
                if (is_file($this->getDocsRoot().'/'.$candidate) || is_dir($this->getDocsRoot().'/'.$candidate)) {
                    $targets[] = $candidate;
                    break;
                }
            }
        }

        return [] !== $targets ? $targets : ['.'];
    }

    private function hasRipgrep(): bool
    {
        if (null !== $this->ripgrepAvailable) {
            return $this->ripgrepAvailable;
        }

        try {
            $process = new Process(['rg', '--version']);
            $process->run();

            return $this->ripgrepAvailable = $process->isSuccessful();
        } catch (\Throwable) {
            return $this->ripgrepAvailable = false;
        }
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array{fragment: string|null, start_line: int, end_line: int, section_title: string|null, include_heading_lines: int}
     */
    private function resolveSectionBounds(array $page, ?string $fragment): array
    {
        $headings = $page['headings'];
        $lineCount = $this->countFileLines($page['path']);

        if (null === $fragment || '' === $fragment) {
            return [
                'fragment' => null,
                'start_line' => $page['title_line'],
                'end_line' => $lineCount,
                'section_title' => $page['title'],
                'include_heading_lines' => 2,
            ];
        }

        foreach ($page['labels'] as $label) {
            if ($label['label'] !== $fragment) {
                continue;
            }

            $nextHeading = $this->findHeadingAtOrAfter($headings, $label['line'], 3);
            $currentHeading = $nextHeading ?? $this->findCurrentHeading($headings, $label['line']);
            $startLine = $currentHeading['start_line'] ?? $label['line'];
            $endLine = $this->findNextHeadingStart($headings, $startLine, $lineCount) - 1;

            return [
                'fragment' => $fragment,
                'start_line' => $startLine,
                'end_line' => $endLine > 0 ? $endLine : $lineCount,
                'section_title' => $currentHeading['title'] ?? null,
                'include_heading_lines' => isset($currentHeading['start_line']) ? 2 : 0,
            ];
        }

        foreach ($headings as $heading) {
            if ($heading['slug'] !== $fragment && mb_strtolower($heading['title']) !== mb_strtolower($fragment)) {
                continue;
            }

            $endLine = $this->findNextHeadingStart($headings, $heading['start_line'], $lineCount) - 1;

            return [
                'fragment' => $heading['slug'],
                'start_line' => $heading['start_line'],
                'end_line' => $endLine > 0 ? $endLine : $lineCount,
                'section_title' => $heading['title'],
                'include_heading_lines' => 2,
            ];
        }

        throw new \InvalidArgumentException(\sprintf('Unable to resolve section "%s" in "%s".', $fragment, $page['path']));
    }

    /**
     * @param list<array{title: string, slug: string, start_line: int, level: string}> $headings
     *
     * @return array{title: string, slug: string, start_line: int, level: string}|null
     */
    private function findHeadingAtOrAfter(array $headings, int $line, int $distance): ?array
    {
        foreach ($headings as $heading) {
            if ($heading['start_line'] >= $line && $heading['start_line'] <= $line + $distance) {
                return $heading;
            }
        }

        return null;
    }

    /**
     * @param list<array{title: string, slug: string, start_line: int, level: string}> $headings
     *
     * @return array{title: string, slug: string, start_line: int, level: string}|null
     */
    private function findCurrentHeading(array $headings, int $line): ?array
    {
        $currentHeading = null;

        foreach ($headings as $heading) {
            if ($heading['start_line'] > $line) {
                break;
            }

            $currentHeading = $heading;
        }

        return $currentHeading;
    }

    /**
     * @param list<array{title: string, slug: string, start_line: int, level: string}> $headings
     */
    private function findNextHeadingStart(array $headings, int $line, int $lineCount): int
    {
        foreach ($headings as $heading) {
            if ($heading['start_line'] > $line) {
                return $heading['start_line'];
            }
        }

        return $lineCount + 1;
    }

    private function countFileLines(string $path): int
    {
        $lines = file($this->getDocsRoot().'/'.$path, \FILE_IGNORE_NEW_LINES);

        if (false === $lines) {
            throw new \RuntimeException(\sprintf('Unable to read "%s".', $path));
        }

        return count($lines);
    }

    private function getCacheFile(): string
    {
        return rtrim($this->configuration->cacheDir, '/').'/manifest.php';
    }

    private function getDocsRoot(): string
    {
        return $this->sourceResolver->getDocsRoot();
    }

    private function slugify(string $value): string
    {
        $slug = mb_strtolower($value);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}
