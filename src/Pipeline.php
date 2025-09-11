<?php

declare(strict_types=1);

namespace AICR;

use AICR\Providers\AIProvider;

final class Pipeline
{
    public const OUTPUT_FORMAT_JSON    = 'json';
    public const OUTPUT_FORMAT_SUMMARY = 'summary';

    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_GEMINI    = 'gemini';
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OLLAMA    = 'ollama';

    public const MSG_NO_FINDINGS = "No findings.\n";

    private Config $config;
    private ?AIProvider $providerOverride;

    public function __construct(Config $config, ?AIProvider $providerOverride = null)
    {
        $this->config           = $config;
        $this->providerOverride = $providerOverride;
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

        $provider = $this->providerOverride ?? $this->buildProvider();
        $chunks   = $this->buildChunks($this->config->context(), $diff);

        $aiFindings = $provider->reviewChunks($chunks);

        $policy      = new Policy($this->config->policy());
        $allFindings = $policy->apply($aiFindings);

        if (self::OUTPUT_FORMAT_SUMMARY === $outputFormat) {
            return self::formatSummary($allFindings);
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

    private function buildProvider(): AIProvider
    {
        return (new Providers\AIProviderFactory($this->config))->build($this->providerOverride);
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

        return $map;
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
