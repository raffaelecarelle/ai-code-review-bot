<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Adapters;

use AICR\Adapters\GithubAdapter;
use PHPUnit\Framework\TestCase;

final class GithubAdapterTest extends TestCase
{
    public function testResolveBranchesFromId(): void
    {
        $adapter = new class('owner/repo', 'token') extends GithubAdapter {
            public array $lastCall = [];
            protected function githubApi(string $path, string $token, string $method = 'GET', array $payload = []): array
            {
                $this->lastCall = compact('path', 'token', 'method', 'payload');
                return [
                    'base' => ['ref' => 'main'],
                    'head' => ['ref' => 'feature/branch'],
                ];
            }
            protected function runGit(string $args): string { return ""; }
        };

        [$base, $head] = $adapter->resolveBranchesFromId(123);
        $this->assertSame('/repos/owner/repo/pulls/123', $adapter->lastCall['path'] ?? null);
        $this->assertSame('token', $adapter->lastCall['token'] ?? null);
        $this->assertSame('GET', $adapter->lastCall['method'] ?? null);
        $this->assertSame('main', $base);
        $this->assertSame('feature/branch', $head);
    }

    public function testPostCommentCallsIssuesCommentsEndpoint(): void
    {
        $adapter = new class('owner/repo', 'tok') extends GithubAdapter {
            public array $lastCall = [];
            protected function githubApi(string $path, string $token, string $method = 'GET', array $payload = []): array
            {
                $this->lastCall = compact('path', 'token', 'method', 'payload');
                return ['ok' => true];
            }
            protected function runGit(string $args): string { return ""; }
        };

        $adapter->postComment(55, 'Hello');

        $this->assertSame('/repos/owner/repo/issues/55/comments', $adapter->lastCall['path'] ?? null);
        $this->assertSame('tok', $adapter->lastCall['token'] ?? null);
        $this->assertSame('POST', $adapter->lastCall['method'] ?? null);
        $this->assertSame(['body' => 'Hello'], $adapter->lastCall['payload'] ?? null);
    }
}
