<?php

declare(strict_types=1);

namespace AICR\Tests\Support;

use AICR\Providers\AIProvider;

final class MockAIProvider implements AIProvider
{
    /** @var array<int, array<string, mixed>> */
    private array $responses;

    /** @var array<int, array<string, mixed>> */
    public array $lastChunks = [];

    /**
     * @param array<int, array<string, mixed>> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     * @return array<int, array<string, mixed>>
     */
    public function reviewChunks(array $chunks): array
    {
        // Capture chunks for assertions in tests
        $this->lastChunks = $chunks;

        if ($this->responses !== []) {
            return $this->responses;
        }
        // Default: generate one finding per first chunk, if any
        if ($chunks === []) {
            return [];
        }
        $first = $chunks[0];
        $file = (string)($first['file_path'] ?? 'unknown');
        $start = (int)($first['start_line'] ?? 1);

        return [[
            'rule_id' => 'AI.GENERAL.CHECK',
            'title' => 'AI General Observation',
            'severity' => 'info',
            'file_path' => $file,
            'start_line' => $start,
            'end_line' => $start,
            'rationale' => 'Mock AI finding for testing.',
            'suggestion' => 'Consider improving code as suggested by AI.',
            'content' => '',
        ]];
    }

    public function getName(): string
    {
        return 'test_mock';
    }
}
