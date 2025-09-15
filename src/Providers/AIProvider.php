<?php

declare(strict_types=1);

namespace AICR\Providers;

interface AIProvider
{
    /**
     * Review token-budgeted chunks and return findings.
     * Each finding should contain:
     *  rule_id, title, severity, file_path, start_line, end_line, rationale, suggestion, content.
     *
     * @param array<int, array<string, mixed>> $chunks
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewChunks(array $chunks): array;

    public function getName(): string;
}
