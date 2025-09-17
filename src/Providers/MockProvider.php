<?php

declare(strict_types=1);

namespace AICR\Providers;

final class MockProvider implements AIProvider
{
    /** @var array<int, array<string, mixed>> */
    private array $responses;

    /**
     * @param array<int, array<string, mixed>> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function reviewChunks(array $chunks, ?array $policyConfig = null): array
    {
        if ([] !== $this->responses) {
            return $this->responses;
        }
        if ([] === $chunks) {
            return [];
        }
        $first = $chunks[0];
        $file  = (string) ($first['file'] ?? 'unknown');
        $start = (int) ($first['start_line'] ?? 1);

        return [[
            'rule_id'    => 'AI.MOCK.CHECK',
            'title'      => 'Mock AI Finding',
            'severity'   => 'info',
            'file'       => $file,
            'start_line' => $start,
            'end_line'   => $start,
            'rationale'  => 'Mock provider used for tests.',
            'suggestion' => 'Consider addressing this mock suggestion.',
            'content'    => '',
        ]];
    }

    public function getName(): string
    {
        return 'mock';
    }
}
