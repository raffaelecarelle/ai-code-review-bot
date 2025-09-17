<?php

declare(strict_types=1);

namespace AICR\Support;

use AICR\Config;

/**
 * Handles diff parsing and file filtering operations.
 * Extracted from Pipeline to follow SRP (Single Responsibility Principle).
 */
final class DiffProcessor
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Split a full unified diff into per-file unified diff strings.
     *
     * @return array<string, string>
     */
    public function extractFileDiffs(string $fullDiff): array
    {
        $lines = preg_split('/\r?\n/', $fullDiff) ?: [];
        $map   = [];
        $buf   = [];
        $cur   = null;

        foreach ($lines as $line) {
            if (preg_match('#^diff --git a/(.+?) b/(.+)$#', $line, $m)) {
                // Flush previous
                if (null !== $cur) {
                    $map[$cur] = rtrim(implode("\n", $buf))."\n";
                }
                // Ensure the per-file key matches test expectations (include the 'b/' prefix)
                $cur = 'b/'.(string) $m[2];
                $buf = [$line];

                continue;
            }
            if (null !== $cur) {
                $buf[] = $line;
            }
        }
        if (null !== $cur) {
            $map[$cur] = rtrim(implode("\n", $buf))."\n";
        }

        // Filter out excluded files and directories
        return $this->filterExcludedFiles($map);
    }

    /**
     * Filter out files and directories that match exclude patterns.
     *
     * @param array<string, string> $fileDiffs
     *
     * @return array<string, string>
     */
    public function filterExcludedFiles(array $fileDiffs): array
    {
        $excludePaths = $this->config->excludes();

        if (empty($excludePaths)) {
            return $fileDiffs;
        }

        $filtered = [];
        foreach ($fileDiffs as $filePath => $diff) {
            // Remove 'b/' prefix for pattern matching
            $cleanPath = preg_replace('#^b/#', '', $filePath);

            $shouldExclude = false;

            // Check against excluded paths
            foreach ($excludePaths as $pattern) {
                if ($this->matchesExcludePattern($cleanPath, $pattern)) {
                    $shouldExclude = true;

                    break;
                }
            }

            if (!$shouldExclude) {
                $filtered[$filePath] = $diff;
            }
        }

        return $filtered;
    }

    /**
     * Check if a file path matches an exclude pattern.
     */
    public function matchesExcludePattern(string $filePath, string $pattern): bool
    {
        // Directory pattern (ends with / or could be a directory name)
        if (str_ends_with($pattern, '/')) {
            return $this->matchesDirectoryPattern($filePath, $pattern);
        }

        // Check if pattern could be a directory (no file extension and contains path separators or is a common directory name)
        if (!str_contains($pattern, '.') && (str_contains($pattern, '/') || $this->isLikelyDirectoryName($pattern))) {
            // Try as directory pattern first
            if ($this->matchesDirectoryPattern($filePath, $pattern.'/')) {
                return true;
            }
        }

        // File pattern
        return $this->matchesFilePattern($filePath, $pattern);
    }

    /**
     * Check if a file path matches a file pattern.
     */
    public function matchesFilePattern(string $filePath, string $pattern): bool
    {
        return fnmatch($pattern, $filePath, FNM_PATHNAME);
    }

    /**
     * Check if a file path matches a directory pattern.
     */
    public function matchesDirectoryPattern(string $filePath, string $dirPattern): bool
    {
        $dir = rtrim($dirPattern, '/');

        return str_starts_with($filePath, $dir.'/') || $filePath === $dir;
    }

    /**
     * Extract start line number from unified diff.
     */
    public function getStartLineFromUnifiedDiff(string $fileDiff): int
    {
        $lines = preg_split('/\r?\n/', $fileDiff) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^@@\s+-\d+(?:,\d+)?\s+\+(\d+)(?:,\d+)?\s+@@/', $line, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /**
     * Check if a pattern is likely a directory name (common directory names).
     */
    private function isLikelyDirectoryName(string $pattern): bool
    {
        $commonDirNames = [
            'vendor', 'node_modules', 'build', 'dist', 'target', 'bin', 'obj',
            'tmp', 'temp', 'cache', 'logs', 'var', 'public', 'assets', 'lib',
            'libs', 'deps', 'dependencies', 'modules', 'packages', 'src', 'test',
            'tests', 'spec', 'specs', 'docs', 'doc', 'documentation',
        ];

        return in_array($pattern, $commonDirNames, true);
    }
}
