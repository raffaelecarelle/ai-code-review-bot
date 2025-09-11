<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Adapters;

use AICR\Adapters\GitlabAdapter;
use PHPUnit\Framework\TestCase;

final class GitlabAdapterTest extends TestCase
{
    public function testResolveBranchesFromId(): void
    {
        $adapter = new class('12345', 'gl-token', 'https://gitlab.example/api/v4') extends GitlabAdapter {
            public array $lastCall = [];
            protected function gitlabApi(string $path, string $token, string $method = 'GET', array $payload = []): array
            {
                $this->lastCall = compact('path', 'token', 'method', 'payload');
                return [
                    'target_branch' => 'develop',
                    'source_branch' => 'feature/x',
                ];
            }
            protected function runGit(string $args): string { return ""; }
        };

        [$base, $head] = $adapter->resolveBranchesFromId(77);
        $this->assertSame('/projects/12345/merge_requests/77', $adapter->lastCall['path'] ?? null);
        $this->assertSame('gl-token', $adapter->lastCall['token'] ?? null);
        $this->assertSame('GET', $adapter->lastCall['method'] ?? null);
        $this->assertSame('develop', $base);
        $this->assertSame('feature/x', $head);
    }

    public function testPostCommentCallsNotesEndpoint(): void
    {
        $adapter = new class('ns/proj', 'tok') extends GitlabAdapter {
            public array $lastCall = [];
            protected function gitlabApi(string $path, string $token, string $method = 'GET', array $payload = []): array
            {
                $this->lastCall = compact('path', 'token', 'method', 'payload');
                return ['ok' => true];
            }
            protected function runGit(string $args): string { return ""; }
        };

        $adapter->postComment(12, 'Ciao');

        $this->assertSame('/projects/'.rawurlencode('ns/proj').'/merge_requests/12/notes', $adapter->lastCall['path'] ?? null);
        $this->assertSame('tok', $adapter->lastCall['token'] ?? null);
        $this->assertSame('POST', $adapter->lastCall['method'] ?? null);
        $this->assertSame(['body' => 'Ciao'], $adapter->lastCall['payload'] ?? null);
    }
}
