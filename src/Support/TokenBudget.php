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

    public function __construct(int $globalCap, int $perFileCap, string $overflowStrategy)
    {
        $this->globalCap        = $globalCap;
        $this->perFileCap       = $perFileCap;
        $this->overflowStrategy = $overflowStrategy;
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function fromContext(array $context): self
    {
        return new self(
            (int) ($context['diff_token_limit'] ?? self::DEFAULT_DIFF_TOKEN_LIMIT),
            (int) ($context['per_file_token_cap'] ?? self::DEFAULT_PER_FILE_TOKEN_CAP),
            (string) ($context['overflow_strategy'] ?? self::DEFAULT_OVERFLOW_STRATEGY)
        );
    }

    public function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
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
        $est = $this->estimateTokens($content);
        if ($est <= $this->perFileCap) {
            return $content;
        }

        $ratio    = max(1, (int) floor($est / $this->perFileCap));
        $maxBytes = (int) floor(strlen($content) / $ratio);

        return substr($content, 0, $maxBytes);
    }
}
