<?php

declare(strict_types=1);

namespace AICR;

use AICR\Providers\AIProvider;
use AICR\Providers\AnthropicProvider;
use AICR\Providers\GeminiProvider;
use AICR\Providers\OllamaProvider;
use AICR\Providers\OpenAIProvider;

final class Pipeline
{
    public const OUTPUT_FORMAT_JSON    = 'json';
    public const OUTPUT_FORMAT_SUMMARY = 'summary';

    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_GEMINI    = 'gemini';
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OLLAMA    = 'ollama';

    public const DEFAULT_DIFF_TOKEN_LIMIT   = 8000;
    public const DEFAULT_PER_FILE_TOKEN_CAP = 2000;
    public const DEFAULT_OVERFLOW_STRATEGY  = 'trim';

    public const MSG_NO_FINDINGS = "No findings.\n";

    private Config $config;
    private ?AIProvider $providerOverride = null;

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

        $addedByFile = DiffParser::parse($diff);
        $provider    = $this->providerOverride ?? $this->buildProvider($this->config->providers());
        $chunks      = $this->buildChunks($addedByFile, $this->config->context(), $diff);

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
        if ([] === $findings) {
            return self::MSG_NO_FINDINGS;
        }
        $out = 'Findings ('.count($findings)."):\n";
        foreach ($findings as $f) {
            $out .= sprintf(
                "- [%s] %s (%s:%d-%d) %s\n  Suggestion: %s\n",
                strtoupper((string) $f['severity']),
                (string) $f['rule_id'],
                (string) $f['file_path'],
                (int) $f['start_line'],
                (int) $f['end_line'],
                (string) $f['rationale'],
                (string) $f['suggestion']
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $providers
     */
    private function buildProvider(array $providers): AIProvider
    {
        $default = (string) ($providers['default'] ?? null);

        switch ($default) {
            case self::PROVIDER_OPENAI:
                $opts = isset($providers['openai']) && is_array($providers['openai']) ? $providers['openai'] : [];
                // Inject prompts config if present and append guidelines file content if configured (base64-encoded)
                $prompts = $this->config->getAll()['prompts'] ?? [];
                if (!is_array($prompts)) {
                    $prompts = [];
                }
                $guidelinesPath = $this->config->getAll()['guidelines_file'] ?? null;
                if (is_string($guidelinesPath) && '' !== trim($guidelinesPath) && is_file($guidelinesPath) && is_readable($guidelinesPath)) {
                    $gl = file_get_contents($guidelinesPath);
                    if (false !== $gl && '' !== trim($gl)) {
                        if (!isset($prompts['extra']) || !is_array($prompts['extra'])) {
                            $prompts['extra'] = [];
                        }
                        $b64                = base64_encode($gl);
                        $prompts['extra'][] = "Coding guidelines file content is provided below in base64 (decode and follow strictly):\n".$b64;
                    }
                }
                $opts['prompts'] = $prompts;

                return new OpenAIProvider($opts);

            case self::PROVIDER_GEMINI:
                $opts    = isset($providers['gemini']) && is_array($providers['gemini']) ? $providers['gemini'] : [];
                $prompts = $this->config->getAll()['prompts'] ?? [];
                if (!is_array($prompts)) {
                    $prompts = [];
                }
                $guidelinesPath = $this->config->getAll()['guidelines_file'] ?? null;
                if (is_string($guidelinesPath) && '' !== trim($guidelinesPath) && is_file($guidelinesPath) && is_readable($guidelinesPath)) {
                    $gl = file_get_contents($guidelinesPath);
                    if (false !== $gl && '' !== trim($gl)) {
                        if (!isset($prompts['extra']) || !is_array($prompts['extra'])) {
                            $prompts['extra'] = [];
                        }
                        $b64                = base64_encode($gl);
                        $prompts['extra'][] = "Coding guidelines file content is provided below in base64 (decode and follow strictly):\n".$b64;
                    }
                }
                $opts['prompts'] = $prompts;

                return new GeminiProvider($opts);

            case self::PROVIDER_ANTHROPIC:
                $opts    = isset($providers['anthropic']) && is_array($providers['anthropic']) ? $providers['anthropic'] : [];
                $prompts = $this->config->getAll()['prompts'] ?? [];
                if (!is_array($prompts)) {
                    $prompts = [];
                }
                $guidelinesPath = $this->config->getAll()['guidelines_file'] ?? null;
                if (is_string($guidelinesPath) && '' !== trim($guidelinesPath) && is_file($guidelinesPath) && is_readable($guidelinesPath)) {
                    $gl = file_get_contents($guidelinesPath);
                    if (false !== $gl && '' !== trim($gl)) {
                        if (!isset($prompts['extra']) || !is_array($prompts['extra'])) {
                            $prompts['extra'] = [];
                        }
                        $b64                = base64_encode($gl);
                        $prompts['extra'][] = "Coding guidelines file content is provided below in base64 (decode and follow strictly):\n".$b64;
                    }
                }
                $opts['prompts'] = $prompts;

                return new AnthropicProvider($opts);

            case self::PROVIDER_OLLAMA:
                $opts    = isset($providers['ollama']) && is_array($providers['ollama']) ? $providers['ollama'] : [];
                $prompts = $this->config->getAll()['prompts'] ?? [];
                if (!is_array($prompts)) {
                    $prompts = [];
                }
                $guidelinesPath = $this->config->getAll()['guidelines_file'] ?? null;
                if (is_string($guidelinesPath) && '' !== trim($guidelinesPath) && is_file($guidelinesPath) && is_readable($guidelinesPath)) {
                    $gl = file_get_contents($guidelinesPath);
                    if (false !== $gl && '' !== trim($gl)) {
                        if (!isset($prompts['extra']) || !is_array($prompts['extra'])) {
                            $prompts['extra'] = [];
                        }
                        $b64                = base64_encode($gl);
                        $prompts['extra'][] = "Coding guidelines file content is provided below in base64 (decode and follow strictly):\n".$b64;
                    }
                }
                $opts['prompts'] = $prompts;

                return new OllamaProvider($opts);

            case 'mock':
                // Lightweight mock provider for tests/E2E using CommandTester
                return new Providers\MockProvider();
        }

        throw new \InvalidArgumentException("Unknown provider: {$default}");
    }

    /**
     * Build AI review chunks from the full unified diff, including + (added/modified) and - (deleted) lines.
     * Keeps rules engine input (addedByFile) separate, but ensures AI context has full per-file diffs.
     *
     * @param array<string, array<int, array{line:int, content:string}>> $addedByFile
     * @param array<string, mixed>                                       $context
     *
     * @return array<int, array{file_path:string, start_line:int, unified_diff:string}>
     */
    private function buildChunks(array $addedByFile, array $context, string $fullDiff): array
    {
        $chunks     = [];
        $globalCap  = (int) ($context['diff_token_limit'] ?? self::DEFAULT_DIFF_TOKEN_LIMIT);
        $perFileCap = (int) ($context['per_file_token_cap'] ?? self::DEFAULT_PER_FILE_TOKEN_CAP);
        $overflow   = (string) ($context['overflow_strategy'] ?? self::DEFAULT_OVERFLOW_STRATEGY);

        // Build map of file_path => unified diff block for that file (including headers and hunks)
        $diffByFile = $this->extractFileDiffs($fullDiff);

        $used = 0;
        foreach ($diffByFile as $file => $fileDiff) {
            $startLine = $this->getStartLineFromUnifiedDiff($fileDiff);
            $est       = (int) ceil(strlen($fileDiff) / 4);

            // Respect token budgets
            if ($used + $est > $globalCap) {
                if (self::DEFAULT_OVERFLOW_STRATEGY === $overflow) {
                    break;
                }
            }
            if ($est > $perFileCap) {
                // Trim the file diff to approximately fit per-file cap by cutting tail
                $ratio    = max(1, (int) floor((strlen($fileDiff) / 4) / $perFileCap));
                $maxBytes = (int) floor(strlen($fileDiff) / $ratio);
                $fileDiff = substr($fileDiff, 0, $maxBytes);
                $est      = (int) ceil(strlen($fileDiff) / 4);
            }

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
                $cur = (string) $m[2]; // use b/ path as current file
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
        if (preg_match('#^@@\s+-\d+(?:,\d+)?\s+\+(\d+)(?:,\d+)?\s+@@#m', $fileDiff, $m)) {
            return (int) $m[1];
        }

        return 1;
    }
}
