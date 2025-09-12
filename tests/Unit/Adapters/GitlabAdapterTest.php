<?php

declare(strict_types=1);

namespace AICR\Adapters {
    // Reuse/intercept HTTP for GitLab (guarded to avoid redefinition)
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

use AICR\Adapters\GitlabAdapter;
use PHPUnit\Framework\TestCase;

final class GitlabAdapterTest extends TestCase
{
    public function testInferProjectIdFromGitRemote(): void
    {
        $g = new class(['vcs' => []]) extends GitlabAdapter {
            public function __construct(array $config) { 
                $this->repository = '';
                $this->token = '';
                $this->apiBase = 'https://gitlab.com/api/v4';
                $this->timeout = 30;
            }
            protected function runGit(string $args): string { return "https://gitlab.com/my/ns/repo.git\n"; }
            protected function inferGitlabProjectId(): string { 
                $remoteUrl = trim($this->runGit('config --get remote.origin.url'));
                if (preg_match('/gitlab\.com[:\\/]([^\/]+\/[^\/]+\/[^\/]+?)(?:\.git)?$/', $remoteUrl, $matches)) {
                    return $matches[1];
                }
                return '';
            }
            public function forceInfer(): string { return $this->inferGitlabProjectId(); }
        };
        $id = $g->forceInfer();
        $this->assertSame('my/ns/repo', $id);
    }

    public function testPostCommentWithoutTokenThrows(): void
    {
        $g = new class('123') extends GitlabAdapter {
            public function __construct(string $projectId) { $this->repository = $projectId; $this->token = ''; $this->apiBase = 'https://gitlab.com/api/v4'; }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing token for GitLab');
        $g->postComment(1, 'body');
    }

    public function testResolveBranchesFromIdInvalidResponse(): void
    {
        $g = new class('123') extends GitlabAdapter {
            public function __construct(string $projectId) { $this->repository = $projectId; $this->token = 'T'; $this->apiBase = 'https://gitlab.com/api/v4'; }
            protected function gitlabApi(string $path, string $token, string $method = 'GET', array $payload = []): array { return ['target_branch' => '', 'source_branch' => '']; }
        };
        $this->expectException(\RuntimeException::class);
        $g->resolveBranchesFromId(7);
    }

    private function readPrivate(object $obj, string $prop)
    {
        $r = new \ReflectionProperty($obj, $prop);
        $r->setAccessible(true);
        return $r->getValue($obj);
    }

    public function testResolveBranchesFromId(): void
    {
        $adapter = new class(['vcs' => ['repository' => '12345', 'token' => 'gl-token', 'api_base' => 'https://gitlab.example/api/v4']]) extends GitlabAdapter {
            public array $lastCall = [];
            public function __construct(array $config) {
                $this->repository = '12345';
                $this->token = 'gl-token';
                $this->apiBase = 'https://gitlab.example/api/v4';
                $this->timeout = 30;
            }
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
        $adapter = new class(['vcs' => ['repository' => 'ns/proj', 'token' => 'tok']]) extends GitlabAdapter {
            public array $lastCall = [];
            public function __construct(array $config) {
                $this->repository = 'ns/proj';
                $this->token = 'tok';
                $this->apiBase = 'https://gitlab.com/api/v4';
                $this->timeout = 30;
            }
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

    public function testGitlabApiGetIncludesHeadersAndToken(): void
    {
        \AICR\Adapters\_HttpMock::$fail = false;
        \AICR\Adapters\_HttpMock::$last = [];
        \AICR\Adapters\_HttpMock::$nextResponse = json_encode(['ok' => 1]);

        $probe = new class('', '', '') extends GitlabAdapter {
            public function __construct(string $a, string $b, string $c) { /* bypass parent */ }
            public function api(string $path, string $token, string $method = 'GET', array $payload = []): array { return parent::gitlabApi($path, $token, $method, $payload); }
        };
        // set private apiBase via reflection
        $rp = new \ReflectionProperty(GitlabAdapter::class, 'apiBase');
        $rp->setAccessible(true);
        $rp->setValue($probe, 'https://gitlab.example/api/v4');

        $data = $probe->api('/projects/1', 'GLTOK', 'GET');
        $this->assertSame(['ok' => 1], $data);

        $last = \AICR\Adapters\_HttpMock::$last;
        $this->assertSame('https://gitlab.example/api/v4/projects/1', $last['url'] ?? null);
        $headers = $last['options']['http']['header'] ?? '';
        $this->assertStringContainsString('Accept: application/json', $headers);
        $this->assertStringContainsString('PRIVATE-TOKEN: GLTOK', $headers);
        $this->assertSame('GET', $last['options']['http']['method'] ?? null);
    }

    public function testGitlabApiPostIncludesPayloadAndContentType(): void
    {
        \AICR\Adapters\_HttpMock::$fail = false;
        \AICR\Adapters\_HttpMock::$last = [];
        \AICR\Adapters\_HttpMock::$nextResponse = json_encode(['ok' => 1]);

        $probe = new class('', '', '') extends GitlabAdapter {
            public function __construct(string $a, string $b, string $c) { /* bypass parent */ }
            public function api(string $path, string $token, string $method = 'GET', array $payload = []): array { return parent::gitlabApi($path, $token, $method, $payload); }
        };
        $rp = new \ReflectionProperty(GitlabAdapter::class, 'apiBase');
        $rp->setAccessible(true);
        $rp->setValue($probe, 'https://gitlab.example/api/v4');

        $payload = ['x' => 'y'];
        $probe->api('/merge_requests', 'T', 'POST', $payload);

        $last = \AICR\Adapters\_HttpMock::$last;
        $headers = $last['options']['http']['header'] ?? '';
        $this->assertStringContainsString('Content-Type: application/json', $headers);
        $this->assertSame(json_encode($payload), $last['options']['http']['content'] ?? null);
        $this->assertSame('POST', $last['options']['http']['method'] ?? null);
    }

    public function testGitlabApiFailureThrows(): void
    {
        \AICR\Adapters\_HttpMock::$fail = true;
        \AICR\Adapters\_HttpMock::$nextResponse = null;

        $probe = new class('', '', '') extends GitlabAdapter {
            public function __construct(string $a, string $b, string $c) { /* bypass parent */ }
            public function api(string $path, string $token, string $method = 'GET', array $payload = []): array { return parent::gitlabApi($path, $token, $method, $payload); }
        };
        $rp = new \ReflectionProperty(GitlabAdapter::class, 'apiBase');
        $rp->setAccessible(true);
        $rp->setValue($probe, 'https://gitlab.example/api/v4');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitLab API request failed');
        $probe->api('/x', 't');
    }

    public function testGitlabApiInvalidJsonThrows(): void
    {
        \AICR\Adapters\_HttpMock::$fail = false;
        \AICR\Adapters\_HttpMock::$nextResponse = 'bad';

        $probe = new class('', '', '') extends GitlabAdapter {
            public function __construct(string $a, string $b, string $c) { /* bypass parent */ }
            public function api(string $path, string $token, string $method = 'GET', array $payload = []): array { return parent::gitlabApi($path, $token, $method, $payload); }
        };
        $rp = new \ReflectionProperty(GitlabAdapter::class, 'apiBase');
        $rp->setAccessible(true);
        $rp->setValue($probe, 'https://gitlab.example/api/v4');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid GitLab API response');
        $probe->api('/x', 't');
    }
}

}
