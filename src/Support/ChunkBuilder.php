<?php

declare(strict_types=1);

namespace AICR\Support;

/**
 * Handles the construction of AI review chunks with intelligent optimization.
 * Extracted from Pipeline to follow SRP (Single Responsibility Principle).
 */
final class ChunkBuilder
{
    private DiffProcessor $diffProcessor;

    public function __construct(DiffProcessor $diffProcessor)
    {
        $this->diffProcessor = $diffProcessor;
    }

    /**
     * Build AI review chunks from the full unified diff with intelligent optimization.
     * Applies prioritization, compression, filtering and semantic chunking.
     *
     * @param array<string, mixed> $context
     *
     * @return array<int, array{file: string, start_line?: int, unified_diff: string}>
     */
    public function buildChunks(array $context, string $fullDiff): array
    {
        $chunks = [];
        $budget = TokenBudget::fromContext($context);

        $fullDiff = $budget->filterTrivialChanges($fullDiff);

        $diffByFile = $this->diffProcessor->extractFileDiffs($fullDiff);

        $used      = 0;
        $rawChunks = [];

        foreach ($diffByFile as $file => $fileDiff) {
            $startLine = $this->diffProcessor->getStartLineFromUnifiedDiff($fileDiff);
            $est       = $budget->estimateTokens($fileDiff);

            // Check if we should compress or stop
            if ($budget->shouldStop($used, $est)) {
                // Try compression instead of stopping
                $remainingBudget = $budget->getRemainingBudget($used);
                if ($remainingBudget > 100) { // Only compress if meaningful budget remains
                    $fileDiff = $budget->compressDiff($fileDiff, $remainingBudget);
                    $est      = $budget->estimateTokens($fileDiff);

                    if ($budget->shouldStop($used, $est)) {
                        break; // Still too big after compression
                    }
                } else {
                    break; // Not enough budget for compression
                }
            }

            // Enforce per-file cap
            $fileDiff = $budget->enforcePerFileCap($fileDiff);
            $est      = $budget->estimateTokens($fileDiff);

            $chunk = [
                'file'         => $file,
                'unified_diff' => $fileDiff,
            ];
            if ($startLine > 0) {
                $chunk['start_line'] = $startLine;
            }
            $rawChunks[] = $chunk;

            $used += $est;
        }

        if ($context['enable_semantic_chunking'] ?? false) {
            $semanticChunks = SemanticChunker::chunkByContext($rawChunks);

            // Flatten semantic chunks back to original format
            foreach ($semanticChunks as $semanticChunk) {
                foreach ($semanticChunk as $chunk) {
                    $chunks[] = $chunk;
                }
            }
        } else {
            $chunks = $rawChunks;
        }

        return $chunks;
    }
}
