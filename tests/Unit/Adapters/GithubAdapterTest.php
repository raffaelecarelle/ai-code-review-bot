<?php

declare(strict_types=1);

namespace AICR\Adapters {
    // Lightweight HTTP shims to intercept calls without real network
    if (!class_exists(_HttpMock::class)) {
        class _HttpMock { public static array $last = []; public static ?string $nextResponse = '{}'; public static bool $fail = false; }
    }
    if (!function_exists(__NAMESPACE__.'\\stream_context_create')) {
        function stream_context_create(array $options, array $params = []) {
            _HttpMock::$last['options'] = $options;
            return \stream_context_create($options, $params);
        }
    }
    if (!function_exists(__NAMESPACE__.'\\file_get_contents')) {
        function file_get_contents($filename, $use_include_path = false, $context = null) {
            _HttpMock::$last['url'] = $filename;
            return _HttpMock::$fail ? false : _HttpMock::$nextResponse;
        }
    }
}

namespace AICR\Tests\Unit\Adapters {

use AICR\Adapters\GithubAdapter;
use PHPUnit\Framework\TestCase;

final class GithubAdapterTest extends TestCase
{
    public function testInferRepoFromGitRemote(): void
    {
        // Subclass to override runGit
        $g = new class(['vcs' => []]) extends GithubAdapter {
            public function __construct(array $config) { 
                $this->repository = '';
                $this->accessToken = '';
                $this->apiBase = 'https://api.github.com';
                $this->timeout = 30;
            }
            protected function runGit(string $args): string { return "git@github.com:octo/proj.git\n"; }
            protected function inferGithubRepo(): string { 
                $remoteUrl = trim($this->runGit('config --get remote.origin.url'));
                if (preg_match('/github\.com[:\\/]([^\/]+\/[^\/]+?)(?:\.git)?$/', $remoteUrl, $matches)) {
                    return $matches[1];
                }
                return '';
            }
            public function forceInfer(): string { return $this->inferGithubRepo(); }
        };
        $repo = $g->forceInfer();
        $this->assertSame('octo/proj', $repo);
    }

    public function testPostCommentWithoutTokenThrows(): void
    {
        $g = new class('owner/repo') extends GithubAdapter {
            public function __construct(string $repo) { $this->repository = $repo; $this->accessToken = ''; }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing token for GitHub');
        $g->postComment(1, 'body');
    }

    public function testResolveBranchesFromIdInvalidResponse(): void
    {
        $g = new class('owner/repo') extends GithubAdapter {
            public function __construct(string $repo) { $this->repository = $repo; $this->accessToken = 'T'; }
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
        $adapter = new class(['vcs' => ['repository' => 'owner/repo', 'access_token' => 'token']]) extends GithubAdapter {
            public array $lastCall = [];
            public function __construct(array $config) {
                $this->repository = 'owner/repo';
                $this->accessToken = 'token';
                $this->apiBase = 'https://api.github.com';
                $this->timeout = 30;
            }
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
        $adapter = new class(['vcs' => ['repository' => 'owner/repo', 'access_token' => 'tok']]) extends GithubAdapter {
            public array $lastCall = [];
            public function __construct(array $config) {
                $this->repository = 'owner/repo';
                $this->accessToken = 'tok';
                $this->apiBase = 'https://api.github.com';
                $this->timeout = 30;
            }
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

    public function testGithubApiGetIncludesHeadersAndToken(): void
    {
        \AICR\Adapters\_HttpMock::$fail = false;
        \AICR\Adapters\_HttpMock::$last = [];
        \AICR\Adapters\_HttpMock::$nextResponse = json_encode(['ok' => true]);

        $probe = new class(null, null) extends GithubAdapter {
            public function __construct(?string $a, ?string $b) { 
                $this->apiBase = 'https://api.github.com';
            }
            public function api(string $path, string $token, string $method = 'GET', array $payload = []): array { return parent::githubApi($path, $token, $method, $payload); }
            protected function runGit(string $args): string { return ""; }
        };

        $data = $probe->api('/foo/bar/', 'TOKEN', 'GET');
        $this->assertSame(['ok' => true], $data);

        $last = \AICR\Adapters\_HttpMock::$last;
        $this->assertSame('https://api.github.com/foo/bar', $last['url'] ?? null);
        $headers = $last['options']['http']['header'] ?? '';
        $this->assertStringContainsString('User-Agent: aicr-bot', $headers);
        $this->assertStringContainsString('Accept: application/vnd.github+json', $headers);
        $this->assertStringContainsString('Authorization: Bearer TOKEN', $headers);
        $this->assertSame('GET', $last['options']['http']['method'] ?? null);
    }

    public function testGithubApiPostIncludesPayloadAndContentType(): void
    {
        \AICR\Adapters\_HttpMock::$fail = false;
        \AICR\Adapters\_HttpMock::$last = [];
        \AICR\Adapters\_HttpMock::$nextResponse = json_encode(['ok' => true]);

        $probe = new class(null, null) extends GithubAdapter {
            public function __construct(?string $a, ?string $b) { 
                $this->apiBase = 'https://api.github.com';
            }
            public function api(string $path, string $token, string $method = 'GET', array $payload = []): array { return parent::githubApi($path, $token, $method, $payload); }
            protected function runGit(string $args): string { return ""; }
        };

        $payload = ['a' => 1, 'b' => 'c'];
        $probe->api('/baz', 'TT', 'POST', $payload);

        $last = \AICR\Adapters\_HttpMock::$last;
        $headers = $last['options']['http']['header'] ?? '';
        $this->assertStringContainsString('Content-Type: application/json', $headers);
        $this->assertSame(json_encode($payload), $last['options']['http']['content'] ?? null);
        $this->assertSame('POST', $last['options']['http']['method'] ?? null);
    }

    public function testGithubApiFailureThrows(): void
    {
        \AICR\Adapters\_HttpMock::$fail = true;
        \AICR\Adapters\_HttpMock::$nextResponse = null;

        $probe = new class(null, null) extends GithubAdapter {
            public function __construct(?string $a, ?string $b) { /* bypass parent */ }
            public function api(string $path, string $token, string $method = 'GET', array $payload = []): array { return parent::githubApi($path, $token, $method, $payload); }
            protected function runGit(string $args): string { return ""; }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub API request failed');
        $probe->api('/x', 'tok');
    }

    public function testGithubApiInvalidJsonThrows(): void
    {
        \AICR\Adapters\_HttpMock::$fail = false;
        \AICR\Adapters\_HttpMock::$nextResponse = 'not-json';

        $probe = new class(null, null) extends GithubAdapter {
            public function __construct(?string $a, ?string $b) { /* bypass parent */ }
            public function api(string $path, string $token, string $method = 'GET', array $payload = []): array { return parent::githubApi($path, $token, $method, $payload); }
            protected function runGit(string $args): string { return ""; }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid GitHub API response');
        $probe->api('/x', 'tok');
    }
}

}
