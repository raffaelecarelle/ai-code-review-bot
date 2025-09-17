<?php

declare(strict_types=1);

namespace AICR;

use AICR\Policy;
use AICR\Providers\AIProvider;
use AICR\Support\ChunkBuilder;
use AICR\Support\DiffProcessor;

final class Pipeline
{
    public const OUTPUT_FORMAT_JSON     = 'json';
    public const OUTPUT_FORMAT_SUMMARY  = 'summary';
    public const OUTPUT_FORMAT_MARKDOWN = 'markdown';

    public const MSG_NO_FINDINGS = "No findings.\n";

    private Config $config;
    private AIProvider $provider;
    private ChunkBuilder $chunkBuilder;

    public function __construct(Config $config, AIProvider $provider, ?DiffProcessor $diffProcessor = null, ?ChunkBuilder $chunkBuilder = null)
    {
        $this->config       = $config;
        $this->provider     = $provider;
        $this->chunkBuilder = $chunkBuilder ?? new ChunkBuilder($diffProcessor ?? new DiffProcessor($config));
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

        $chunks = $this->chunkBuilder->buildChunks($this->config->context($this->provider->getName()), $diff);

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
}
