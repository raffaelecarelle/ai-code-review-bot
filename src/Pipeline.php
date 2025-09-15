<?php

declare(strict_types=1);

namespace AICR;

use AICR\Providers\AIProvider;

final class Pipeline
{
    public const OUTPUT_FORMAT_JSON     = 'json';
    public const OUTPUT_FORMAT_SUMMARY  = 'summary';
    public const OUTPUT_FORMAT_MARKDOWN = 'markdown';

    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_GEMINI    = 'gemini';
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OLLAMA    = 'ollama';

    public const MSG_NO_FINDINGS = "No findings.\n";

    private Config $config;
    private AIProvider $provider;

    public function __construct(Config $config, AIProvider $provider)
    {
        $this->config   = $config;
        $this->provider = $provider;
    }

    public function run(string $diffPath, string $outputFormat = self::OUTPUT_FORMAT_JSON): string
    {
        if (!is_file($diffPath)) {
            throw new \InvalidArgumentException("Diff file not found: {$diffPath}");
        }
        $diff = file_get_contents($diffPath);
        if (false === $diff) {
            throw new \RuntimeException("Failed to read diff file: {$diffPath}");
        }

        $chunks = $this->buildChunks($this->config->context($this->provider->getName()), $diff);

        $aiFindings = $this->provider->reviewChunks($chunks);

        $policy      = new Policy($this->config->policy());
        $allFindings = $policy->apply($aiFindings);

        if (self::OUTPUT_FORMAT_SUMMARY === $outputFormat) {
            return self::formatSummary($allFindings);
        }

        if (self::OUTPUT_FORMAT_MARKDOWN === $outputFormat) {
            return self::formatMarkdown($allFindings);
        }

        return (string) json_encode($allFindings, JSON_PRETTY_PRINT);
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     */
    public static function formatSummary(array $findings): string
    {
        return (new Output\SummaryFormatter())->format($findings);
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     */
    public static function formatMarkdown(array $findings): string
    {
        return (new Output\MarkdownFormatter())->format($findings);
    }

    /**
     * Build AI review chunks from the full unified diff, including + (added/modified) and - (deleted) lines.
     * Keeps rules engine input (addedByFile) separate, but ensures AI context has full per-file diffs.
     *
     * @param array<string, mixed> $context
     *
     * @return array<int, array{file_path:string, start_line:int, unified_diff:string}>
     */
    private function buildChunks(array $context, string $fullDiff): array
    {
        $chunks = [];
        $budget = Support\TokenBudget::fromContext($context);

        // Build map of file_path => unified diff block for that file (including headers and hunks)
        $diffByFile = $this->extractFileDiffs($fullDiff);

        $used = 0;
        foreach ($diffByFile as $file => $fileDiff) {
            $startLine = $this->getStartLineFromUnifiedDiff($fileDiff);
            $est       = $budget->estimateTokens($fileDiff);

            // Respect token budgets
            if ($budget->shouldStop($used, $est)) {
                break;
            }

            $fileDiff = $budget->enforcePerFileCap($fileDiff);
            $est      = $budget->estimateTokens($fileDiff);

            $chunks[] = [
                'file_path'    => $file,
                'start_line'   => $startLine,
                'unified_diff' => $fileDiff,
            ];

            $used += $est;
        }

        return $chunks;
    }

    /**
     * Split a full unified diff into per-file unified diff strings.
     *
     * @return array<string, string>
     */
    private function extractFileDiffs(string $fullDiff): array
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
    private function filterExcludedFiles(array $fileDiffs): array
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
     * Automatically determines if the pattern is for a file or directory.
     */
    private function matchesExcludePattern(string $filePath, string $pattern): bool
    {
        // If pattern contains wildcards or file extensions, treat as file pattern
        if (str_contains($pattern, '*') || str_contains($pattern, '?') || str_contains($pattern, '.')) {
            return $this->matchesFilePattern($filePath, $pattern);
        }

        // Otherwise, treat as directory pattern
        return $this->matchesDirectoryPattern($filePath, $pattern);
    }

    /**
     * Check if a file path matches a file pattern (supports glob-style wildcards).
     */
    private function matchesFilePattern(string $filePath, string $pattern): bool
    {
        // Use fnmatch for proper glob pattern matching
        return fnmatch($pattern, $filePath);
    }

    /**
     * Check if a file path is within an excluded directory pattern.
     */
    private function matchesDirectoryPattern(string $filePath, string $dirPattern): bool
    {
        // Remove trailing slash if present
        $dirPattern = rtrim($dirPattern, '/');

        // Check if file is directly in the directory or in a subdirectory
        return str_starts_with($filePath, $dirPattern.'/') || $filePath === $dirPattern;
    }

    private function getStartLineFromUnifiedDiff(string $fileDiff): int
    {
        // Be permissive: capture the first target start line (after '+') from any hunk header
        // Examples matched:
        //   @@ -5,3 +10,4 @@
        //   @@ -5 +10 @@ optional text
        //   ...@@ -5,3 +10,4 @@...
        if (1 === preg_match('/@@\s+-\d+(?:,\d+)?\s+\+(\d+)/m', $fileDiff, $m)) {
            $n = (int) $m[1];

            return $n > 0 ? $n : 1;
        }

        return 1;
    }
}
