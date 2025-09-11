<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Adapters;

use AICR\Adapters\GithubAdapter;
use PHPUnit\Framework\TestCase;

final class GithubAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear GH envs to control behavior
        putenv('GH_TOKEN');
        putenv('GITHUB_TOKEN');
        putenv('GH_REPO');
    }

    public function testInferRepoFromEnv(): void
    {
        putenv('GH_REPO=owner/repo');
        $g = new GithubAdapter(null, null);
        $this->assertSame('owner/repo', $this->readPrivate($g, 'repo'));
    }

    public function testInferRepoFromGitRemote(): void
    {
        // Subclass to override runGit
        $g = new class('','') extends GithubAdapter {
            public function __construct(string $dummy1, string $dummy2) { /* bypass parent */ }
            protected function runGit(string $args): string { return "git@github.com:octo/proj.git\n"; }
            public function forceInfer(): string { return (new \ReflectionClass($this))->getMethod('inferGithubRepo')->invoke($this); }
        };
        $repo = $g->forceInfer();
        $this->assertSame('octo/proj', $repo);
    }

    public function testPostCommentWithoutTokenThrows(): void
    {
        $g = new class('owner/repo') extends GithubAdapter {
            private string $repo;
            private string $token;
            public function __construct(string $repo) { $this->repo = $repo; $this->token = ''; }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing token for GitHub');
        $g->postComment(1, 'body');
    }

    public function testResolveBranchesFromIdInvalidResponse(): void
    {
        $g = new class('owner/repo') extends GithubAdapter {
            private string $repo;
            private string $token;
            public function __construct(string $repo) { $this->repo = $repo; $this->token = 'T'; }
            protected function githubApi(string $path, string $token, string $method = 'GET', array $payload = []): array { return ['base' => ['ref' => ''], 'head' => ['ref' => '']]; }
        };
        $this->expectException(\RuntimeException::class);
        $g->resolveBranchesFromId(123);
    }

    /**
     * @param object $obj
     * @return mixed
     */
    private function readPrivate(object $obj, string $prop)
    {
        $r = new \ReflectionProperty($obj, $prop);
        $r->setAccessible(true);
        return $r->getValue($obj);
    }

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
