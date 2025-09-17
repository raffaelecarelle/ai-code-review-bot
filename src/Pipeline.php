<?php

declare(strict_types=1);

namespace AICR;

use AICR\Providers\AIProvider;
use AICR\Support\ChunkBuilder;
use AICR\Support\DiffProcessor;
use AICR\Support\StreamingFileReader;

final class Pipeline
{
    public const OUTPUT_FORMAT_JSON     = 'json';
    public const OUTPUT_FORMAT_SUMMARY  = 'summary';
    public const OUTPUT_FORMAT_MARKDOWN = 'markdown';

    public const MSG_NO_FINDINGS = "No findings.\n";

    private Config $config;
    private AIProvider $provider;
    private ChunkBuilder $chunkBuilder;
    private StreamingFileReader $fileReader;

    public function __construct(Config $config, AIProvider $provider, ?DiffProcessor $diffProcessor = null, ?ChunkBuilder $chunkBuilder = null, ?StreamingFileReader $fileReader = null)
    {
        $this->config       = $config;
        $this->provider     = $provider;
        $this->chunkBuilder = $chunkBuilder ?? new ChunkBuilder($diffProcessor ?? new DiffProcessor($config));
        $this->fileReader   = $fileReader ?? new StreamingFileReader();
    }

    public function run(string $diffPath, string $outputFormat = self::OUTPUT_FORMAT_JSON): string
    {
        if (!$this->fileReader->validatePath($diffPath)) {
            throw new \InvalidArgumentException("Invalid file path: {$diffPath}");
        }

        $diff = $this->fileReader->readFile($diffPath);

        $chunks = $this->chunkBuilder->buildChunks($this->config->context($this->provider->getName()), $diff);

        $policyConfig = $this->config->policy();
        $findings     = $this->provider->reviewChunks($chunks, $policyConfig);

        if (self::OUTPUT_FORMAT_SUMMARY === $outputFormat) {
            return self::formatSummary($findings);
        }

        if (self::OUTPUT_FORMAT_MARKDOWN === $outputFormat) {
            return self::formatMarkdown($findings);
        }

        return (string) json_encode($findings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
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
