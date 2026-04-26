<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Service;

final class RstParser
{
    /**
     * @return array{
     *     path: string,
     *     title: string,
     *     title_line: int,
     *     headings: list<array{title: string, slug: string, start_line: int, level: string}>,
     *     labels: list<array{label: string, line: int}>,
     *     children: list<string>,
     *     summary: string,
     *     search_blob: string
     * }
     */
    public function parse(string $absolutePath, string $relativePath, string $docsRoot): array
    {
        $lines = file($absolutePath, \FILE_IGNORE_NEW_LINES);

        if (false === $lines) {
            throw new \RuntimeException(\sprintf('Unable to read file "%s".', $absolutePath));
        }

        $headings = $this->parseHeadings($lines);
        $labels = $this->parseLabels($lines);
        $children = $this->parseToctreeChildren($lines, $relativePath, $docsRoot);
        $title = $headings[0]['title'] ?? pathinfo($relativePath, \PATHINFO_FILENAME);
        $titleLine = $headings[0]['start_line'] ?? 1;
        $summary = $this->extractSummary($lines, $titleLine);
        $tokens = array_merge(
            [$title, $relativePath],
            array_column($headings, 'title'),
            array_column($labels, 'label'),
        );

        return [
            'path' => $relativePath,
            'title' => $title,
            'title_line' => $titleLine,
            'headings' => $headings,
            'labels' => $labels,
            'children' => array_values(array_unique($children)),
            'summary' => $summary,
            'search_blob' => mb_strtolower(implode(' ', $tokens)),
        ];
    }

    /**
     * @param list<string> $lines
     *
     * @return list<array{title: string, slug: string, start_line: int, level: string}>
     */
    private function parseHeadings(array $lines): array
    {
        $headings = [];

        for ($index = 0, $count = count($lines) - 1; $index < $count; ++$index) {
            $title = trim($lines[$index]);
            $underline = trim($lines[$index + 1]);

            if ('' === $title || !$this->isHeadingUnderline($underline)) {
                continue;
            }

            if (mb_strlen($underline) < mb_strlen($title)) {
                continue;
            }

            $headings[] = [
                'title' => $title,
                'slug' => $this->slugify($title),
                'start_line' => $index + 1,
                'level' => $underline[0],
            ];
        }

        return $headings;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<array{label: string, line: int}>
     */
    private function parseLabels(array $lines): array
    {
        $labels = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^\.\. _([A-Za-z0-9_:\-]+):$/', trim($line), $matches)) {
                $labels[] = [
                    'label' => $matches[1],
                    'line' => $index + 1,
                ];
            }
        }

        return $labels;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function parseToctreeChildren(array $lines, string $relativePath, string $docsRoot): array
    {
        $children = [];
        $directory = dirname($relativePath);
        $directory = '.' === $directory ? '' : $directory;

        for ($index = 0, $count = count($lines); $index < $count; ++$index) {
            if ('.. toctree::' !== trim($lines[$index])) {
                continue;
            }

            for ($cursor = $index + 1; $cursor < $count; ++$cursor) {
                $raw = $lines[$cursor];

                if ('' === trim($raw)) {
                    continue;
                }

                if (!preg_match('/^\s+/', $raw)) {
                    break;
                }

                $entry = trim($raw);

                if (str_starts_with($entry, ':')) {
                    continue;
                }

                $resolved = $this->resolveToctreeEntry($entry, $directory, $docsRoot);

                if (null !== $resolved) {
                    $children[] = $resolved;
                }
            }
        }

        return $children;
    }

    private function resolveToctreeEntry(string $entry, string $directory, string $docsRoot): ?string
    {
        if (preg_match('/<([^>]+)>/', $entry, $matches)) {
            $entry = $matches[1];
        }

        $entry = trim($entry);

        if ('' === $entry || str_contains($entry, '://')) {
            return null;
        }

        $target = str_starts_with($entry, '/')
            ? ltrim($entry, '/')
            : ltrim(($directory !== '' ? $directory.'/' : '').$entry, '/');
        $target = preg_replace('#/+#', '/', $target) ?? $target;

        $candidates = str_ends_with($target, '.rst') || str_ends_with($target, '.rst.inc')
            ? [$target]
            : [$target.'.rst', $target.'/index.rst', $target.'.rst.inc'];

        foreach ($candidates as $candidate) {
            $absolutePath = $docsRoot.'/'.$candidate;

            if (is_file($absolutePath)) {
                return str_replace('\\', '/', $candidate);
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     */
    private function extractSummary(array $lines, int $titleLine): string
    {
        $paragraph = [];
        $skipDirectiveContent = false;

        for ($index = max(0, $titleLine + 1), $count = count($lines); $index < $count; ++$index) {
            $rawLine = $lines[$index];
            $line = trim($rawLine);

            if ($skipDirectiveContent) {
                if ('' === $line || preg_match('/^\s+/', $rawLine)) {
                    continue;
                }

                $skipDirectiveContent = false;
            }

            if ('' === $line) {
                if ([] !== $paragraph) {
                    break;
                }

                continue;
            }

            if (str_starts_with($line, '.. ') || str_starts_with($line, ':')) {
                if ([] !== $paragraph) {
                    break;
                }

                $skipDirectiveContent = str_starts_with($line, '.. ');

                continue;
            }

            if ($this->isHeadingUnderline($line)) {
                continue;
            }

            if ($index + 1 < $count && $this->isHeadingUnderline(trim($lines[$index + 1]))) {
                continue;
            }

            $paragraph[] = $line;

            if (mb_strlen(implode(' ', $paragraph)) >= 300) {
                break;
            }
        }

        return implode(' ', $paragraph);
    }

    private function isHeadingUnderline(string $line): bool
    {
        return 1 === preg_match('/^([=\-~."])\1{2,}$/', $line);
    }

    private function slugify(string $value): string
    {
        $slug = mb_strtolower($value);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}
