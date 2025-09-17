<?php

declare(strict_types=1);

namespace AICR\Support;

final class TokenBudget
{
    public const DEFAULT_DIFF_TOKEN_LIMIT   = 8000;
    public const DEFAULT_PER_FILE_TOKEN_CAP = 2000;
    public const DEFAULT_OVERFLOW_STRATEGY  = 'trim';

    private int $globalCap;
    private int $perFileCap;
    private string $overflowStrategy;
    private string $provider;

    /** @var array<string, int> Token cache to avoid repeated calculations */
    private array $tokenCache = [];

    public function __construct(int $globalCap, int $perFileCap, string $overflowStrategy, string $provider = 'openai')
    {
        $this->globalCap        = $globalCap;
        $this->perFileCap       = $perFileCap;
        $this->overflowStrategy = $overflowStrategy;
        $this->provider         = strtolower($provider);
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function fromContext(array $context): self
    {
        $provider = (string) ($context['provider'] ?? 'openai');

        return new self(
            (int) ($context['diff_token_limit'] ?? self::DEFAULT_DIFF_TOKEN_LIMIT),
            (int) ($context['per_file_token_cap'] ?? self::DEFAULT_PER_FILE_TOKEN_CAP),
            (string) ($context['overflow_strategy'] ?? self::DEFAULT_OVERFLOW_STRATEGY),
            $provider
        );
    }

    public function estimateTokens(string $text): int
    {
        // Use content hash for caching
        $hash = md5($text);
        if (isset($this->tokenCache[$hash])) {
            return $this->tokenCache[$hash];
        }

        $tokens                  = $this->calculateTokensWithProviderMultiplier($text);
        $this->tokenCache[$hash] = $tokens;

        return $tokens;
    }

    public function shouldStop(int $usedTokens, int $incomingTokens): bool
    {
        if ($usedTokens + $incomingTokens > $this->globalCap) {
            return 'trim' === $this->overflowStrategy; // stop adding more files when overflow strategy is trim
        }

        return false;
    }

    public function enforcePerFileCap(string $content): string
    {
        $estimatedTokens = $this->estimateTokens($content);
        if ($estimatedTokens <= $this->perFileCap) {
            return $content;
        }

        return $this->smartTruncate($content);
    }

    /**
     * Get cache statistics for monitoring.
     *
     * @return array<string, mixed>
     */
    public function getCacheStats(): array
    {
        return [
            'cache_size' => count($this->tokenCache),
            'provider'   => $this->provider,
        ];
    }

    /**
     * Clear the token cache.
     */
    public function clearCache(): void
    {
        $this->tokenCache = [];
    }

    /**
     * Get remaining budget for compression decisions.
     */
    public function getRemainingBudget(int $usedTokens): int
    {
        return max(0, $this->globalCap - $usedTokens);
    }

    /**
     * Compress diff content intelligently while maintaining semantic context.
     */
    public function compressDiff(string $diff, int $maxTokens): string
    {
        $lines      = preg_split('/\r?\n/', $diff);
        $compressed = [];
        $tokenCount = 0;

        if (false === $lines) {
            return $diff;
        }

        foreach ($lines as $line) {
            // Skip redundant empty lines
            if (preg_match('/^\s*$/', $line)) {
                continue;
            }

            // Compress long comments
            if (preg_match('/^\+.*\/\*.*\*\//', $line)) {
                $line = preg_replace('/\/\*.*?\*\//', '/* ... */', $line);
            }

            $lineTokens = $this->calculateTokensWithProviderMultiplier($line);
            if ($tokenCount + $lineTokens > $maxTokens) {
                $compressed[] = '... [content truncated for token budget] ...';

                break;
            }

            $compressed[] = $line;
            $tokenCount += $lineTokens;
        }

        return implode("\n", $compressed);
    }

    /**
     * Filter out trivial changes that don't need review.
     */
    public function filterTrivialChanges(string $diff): string
    {
        $lines    = explode("\n", $diff);
        $filtered = [];

        foreach ($lines as $line) {
            // Skip insignificant changes
            if (preg_match('/^\+\s*$/', $line)                    // Only whitespace
                || preg_match('/^\+\s*\/\/\s*(TODO|FIXME|XXX)/', $line) // TODO comments
                || preg_match('/^\+\s*use\s+/', $line)               // Import statements
                || preg_match('/^\+\s*\*\s*@/', $line)) {               // DocBlock annotations
                continue;
            }

            $filtered[] = $line;
        }

        return implode("\n", $filtered);
    }

    public function getMaxTokensPerFile(): int
    {
        return $this->perFileCap;
    }

    /**
     * Calculate tokens using provider-specific multipliers and content analysis.
     */
    private function calculateTokensWithProviderMultiplier(string $text): int
    {
        // Base character count
        $charCount = strlen($text);

        // Provider-specific multipliers based on empirical data
        $multipliers = [
            'openai'    => 0.3,  // GPT models: ~3.3 chars per token
            'anthropic' => 0.28, // Claude models: ~3.6 chars per token
            'gemini'    => 0.32, // Gemini models: ~3.1 chars per token
            'ollama'    => 0.3,  // Similar to OpenAI
            'mock'      => 0.25, // Original /4 ratio for backward compatibility
        ];

        $baseMultiplier = $multipliers[$this->provider] ?? 0.3;

        // Adjust multiplier based on content characteristics
        $contentMultiplier = $this->analyzeContentComplexity($text);
        $finalMultiplier   = $baseMultiplier * $contentMultiplier;

        return (int) ceil($charCount * $finalMultiplier);
    }

    /**
     * Analyze content complexity to adjust token estimation.
     */
    private function analyzeContentComplexity(string $text): float
    {
        $multiplier = 1.0;

        // Code content typically has more tokens per character
        if (preg_match('/^(\+|\-|@@|\s*(class|function|interface|namespace))/', $text)) {
            $multiplier += 0.1; // Code content adjustment
        }

        // High symbol/punctuation density increases token count
        $symbolCount = preg_match_all('/[{}();,\[\]<>]/', $text);
        $symbolRatio = $symbolCount / max(1, strlen($text));
        if ($symbolRatio > 0.05) {
            $multiplier += 0.1; // High symbol density
        }

        // Whitespace and formatting affects tokenization
        $whitespaceRatio = (substr_count($text, ' ') + substr_count($text, "\t") + substr_count($text, "\n")) / max(1, strlen($text));
        if ($whitespaceRatio > 0.3) {
            $multiplier += 0.05; // High whitespace content
        }

        return min($multiplier, 1.5); // Cap the adjustment
    }

    /**
     * Smart truncation that preserves code structure and important context.
     */
    private function smartTruncate(string $content): string
    {
        $lines          = explode("\n", $content);
        $importantLines = [];
        $contextLines   = [];

        foreach ($lines as $lineNum => $line) {
            $priority = $this->getLinePriority($line);

            if ($priority >= 3) {
                $importantLines[$lineNum] = $line; // Critical lines (diff markers, headers, signatures)
            } elseif ($priority >= 2) {
                $contextLines[$lineNum] = $line; // Important context lines
            }
        }

        // Start with all important lines
        $result       = implode("\n", $importantLines);
        $resultTokens = $this->estimateTokens($result);

        // Add context lines while under budget
        foreach ($contextLines as $lineNum => $line) {
            $candidate       = $result."\n".$line;
            $candidateTokens = $this->estimateTokens($candidate);

            if ($candidateTokens > $this->perFileCap) {
                break;
            }

            $result       = $candidate;
            $resultTokens = $candidateTokens;
        }

        // If still over budget, fall back to proportional truncation
        if ($resultTokens > $this->perFileCap) {
            $targetRatio  = $this->perFileCap / (float) $resultTokens;
            $targetLength = (int) (strlen($result) * $targetRatio);
            $result       = substr($result, 0, $targetLength);
        }

        return $result;
    }

    /**
     * Assign priority to lines based on their importance for code review.
     */
    private function getLinePriority(string $line): int
    {
        $line = trim($line);

        // Diff markers and headers (highest priority)
        if (preg_match('/^(diff --git|@@|\+\+\+|---|\ No newline)/', $line)) {
            return 4;
        }

        // Added/removed lines (high priority)
        if (preg_match('/^[\+\-]/', $line)) {
            return 3;
        }

        // Function/class signatures and important declarations (medium-high priority)
        if (preg_match('/^\s*(class|interface|trait|function|public|private|protected|namespace|use)/', $line)) {
            return 3;
        }

        // Comments and documentation (medium priority)
        if (preg_match('/^\s*(\/\/|\/\*|\*|#)/', $line)) {
            return 2;
        }

        // Regular code lines (low priority)
        if (!empty($line)) {
            return 1;
        }

        // Empty lines (lowest priority)
        return 0;
    }
}
