<?php

declare(strict_types=1);

namespace AICR\Adapters;

class GithubAdapter extends BaseAdapter
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $this->initializeFromConfig($config);

        if ('' === $this->repository) {
            throw new \RuntimeException('Cannot infer GitHub repo. Set vcs.repository in config, GH_REPO env, or ensure origin remote URL is a GitHub repo.');
        }
    }

    /**
     * @return array<int,string>
     */
    public function resolveBranchesFromId(int $id): array
    {
        $data = $this->githubApi('/repos/'.$this->repository.'/pulls/'.$id, $this->accessToken, 'GET');
        $base = (string) ($data['base']['ref'] ?? '');
        $head = (string) ($data['head']['ref'] ?? '');
        if ('' === $base || '' === $head) {
            throw new \RuntimeException('Failed to resolve branches from GitHub PR.');
        }

        return [$base, $head];
    }

    public function postComment(int $id, string $body): void
    {
        if ('' === $this->accessToken) {
            throw new \RuntimeException('Missing token for GitHub. Set GH_TOKEN or GITHUB_TOKEN.');
        }
        $this->githubApi('/repos/'.$this->repository.'/issues/'.$id.'/comments', $this->accessToken, 'POST', [
            'body' => $body,
        ]);
    }

    protected function getDefaultApiBase(): string
    {
        return 'https://api.github.com';
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    protected function githubApi(string $path, string $token, string $method = 'GET', array $payload = []): array
    {
        $url     = $this->apiBase.rtrim($path, '/');
        $headers = [
            'User-Agent: aicr-bot',
            'Accept: application/vnd.github+json',
        ];
        if ('' !== $token) {
            $headers[] = 'Authorization: Bearer '.$token;
        }
        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers)."\r\n",
                'ignore_errors' => true,
                'timeout'       => $this->timeout,
            ],
        ];
        if (!empty($payload)) {
            $opts['http']['content'] = json_encode($payload);
            $opts['http']['header'] .= "Content-Type: application/json\r\n";
        }
        $ctx = stream_context_create($opts);
        $raw = file_get_contents($url, false, $ctx);
        if (false === $raw) {
            throw new \RuntimeException('GitHub API request failed: '.$url);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid GitHub API response');
        }

        return $data;
    }
}
