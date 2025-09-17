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
        /** @var array<int, array{file: string, start_line?: int, unified_diff: string}> $chunks */
        $chunks = [];
        $budget = TokenBudget::fromContext($context);

        $fullDiff = $budget->filterTrivialChanges($fullDiff);

        $diffByFile = $this->diffProcessor->extractFileDiffs($fullDiff);

        $used = 0;

        /** @var array<int, array{file: string, start_line?: int, unified_diff: string}> $rawChunks */
        $rawChunks = [];

        // Process files in parallel-friendly batches for better performance
        $fileBatch = $this->processFileBatch($diffByFile, $budget);

        foreach ($fileBatch as $fileData) {
            if ($budget->shouldStop($used, $fileData['estimated_tokens'])) {
                break; // Stop processing if budget exceeded
            }

            $rawChunks[] = $fileData['chunk'];
            $used += $fileData['estimated_tokens'];
        }

        if ($context['enable_semantic_chunking'] ?? false) {
            // Cast rawChunks to the expected type for SemanticChunker
            /** @var array<int, array{file: string, unified_diff: string}> $chunksForSemantic */
            $chunksForSemantic = array_map(function (array $chunk): array {
                return [
                    'file'         => $chunk['file'],
                    'unified_diff' => $chunk['unified_diff'],
                ];
            }, $rawChunks);

            $semanticChunks = SemanticChunker::chunkByContext($chunksForSemantic);

            // Flatten semantic chunks back to original format
            foreach ($semanticChunks as $semanticChunk) {
                foreach ($semanticChunk as $chunk) {
                    // Restore the original chunk format with start_line if present
                    $originalChunk = null;
                    foreach ($rawChunks as $rawChunk) {
                        if ($rawChunk['file'] === $chunk['file'] && $rawChunk['unified_diff'] === $chunk['unified_diff']) {
                            $originalChunk = $rawChunk;

                            break;
                        }
                    }
                    $chunks[] = $originalChunk ?? $chunk;
                }
            }
        } else {
            $chunks = $rawChunks;
        }

        return $chunks;
    }

    /**
     * Processes file diffs in optimized batches with lazy evaluation.
     * This method prepares file processing for potential parallel execution.
     *
     * @param array<string, string> $diffByFile File diffs indexed by filename
     * @param TokenBudget           $budget     Token budget manager
     *
     * @return array<int, array{chunk: array{file: string, start_line?: int, unified_diff: string}, estimated_tokens: int}>
     */
    private function processFileBatch(array $diffByFile, TokenBudget $budget): array
    {
        $batchSize   = $this->calculateOptimalBatchSize(count($diffByFile));
        $fileBatches = array_chunk($diffByFile, max(1, $batchSize), true);

        /** @var array<int, array{chunk: array{file: string, start_line?: int, unified_diff: string}, estimated_tokens: int}> $processedFiles */
        $processedFiles = [];

        foreach ($fileBatches as $batch) {
            $batchResults = $this->processSingleBatch($batch, $budget);
            foreach ($batchResults as $result) {
                $processedFiles[] = $result;
            }
        }

        // Sort by estimated tokens (largest first) for better budget utilization
        usort($processedFiles, fn ($a, $b) => $b['estimated_tokens'] <=> $a['estimated_tokens']);

        return $processedFiles;
    }

    /**
     * Processes a single batch of file diffs.
     *
     * @param array<string, string> $batch  File diffs in this batch
     * @param TokenBudget           $budget Token budget manager
     *
     * @return array<int, array{chunk: array{file: string, start_line?: int, unified_diff: string}, estimated_tokens: int}>
     */
    private function processSingleBatch(array $batch, TokenBudget $budget): array
    {
        /** @var array<int, array{chunk: array{file: string, start_line?: int, unified_diff: string}, estimated_tokens: int}> $batchResults */
        $batchResults = [];

        foreach ($batch as $file => $fileDiff) {
            $fileData = $this->processIndividualFile($file, $fileDiff, $budget);
            if (null !== $fileData) {
                $batchResults[] = $fileData;
            }
        }

        return $batchResults;
    }

    /**
     * Processes an individual file diff with compression and optimization.
     *
     * @param string      $file     File path
     * @param string      $fileDiff File diff content
     * @param TokenBudget $budget   Token budget manager
     *
     * @return null|array{chunk: array{file: string, start_line?: int, unified_diff: string}, estimated_tokens: int}
     */
    private function processIndividualFile(string $file, string $fileDiff, TokenBudget $budget): ?array
    {
        $startLine = $this->diffProcessor->getStartLineFromUnifiedDiff($fileDiff);
        $est       = $budget->estimateTokens($fileDiff);

        // Apply compression if file is too large
        $processedDiff = $this->optimizeFileDiff($fileDiff, $budget, $est);
        if (null === $processedDiff) {
            return null; // File too large even after optimization
        }

        $finalEst = $budget->estimateTokens($processedDiff);

        /** @var array{file: string, start_line?: int, unified_diff: string} $chunk */
        $chunk = [
            'file'         => $file,
            'unified_diff' => $processedDiff,
        ];

        if ($startLine > 0) {
            $chunk['start_line'] = $startLine;
        }

        return [
            'chunk'            => $chunk,
            'estimated_tokens' => $finalEst,
        ];
    }

    /**
     * Optimizes file diff content through compression and per-file caps.
     *
     * @param string      $fileDiff         Original file diff
     * @param TokenBudget $budget           Token budget manager
     * @param int         $originalEstimate Original token estimate
     *
     * @return null|string Optimized diff or null if unusable
     */
    private function optimizeFileDiff(string $fileDiff, TokenBudget $budget, int $originalEstimate): ?string
    {
        // Try compression if file is large
        if ($originalEstimate > 1000) { // Configurable threshold
            $compressed    = $budget->compressDiff($fileDiff, $originalEstimate);
            $compressedEst = $budget->estimateTokens($compressed);

            // Use compressed version if it's significantly smaller
            if ($compressedEst < $originalEstimate * 0.7) {
                $fileDiff = $compressed;
            }
        }

        // Apply per-file cap
        $cappedDiff = $budget->enforcePerFileCap($fileDiff);
        $cappedEst  = $budget->estimateTokens($cappedDiff);

        // Reject if still too large after all optimizations
        if ($cappedEst > $budget->getMaxTokensPerFile()) {
            return null;
        }

        return $cappedDiff;
    }

    /**
     * Calculates optimal batch size based on total file count.
     * Larger file sets get smaller batches for better memory management.
     */
    private function calculateOptimalBatchSize(int $totalFiles): int
    {
        return match (true) {
            $totalFiles <= 10  => $totalFiles, // Process all at once
            $totalFiles <= 50  => 10,          // Small batches
            $totalFiles <= 200 => 20,         // Medium batches
            default            => 25                      // Large batches for many files
        };
    }
}
