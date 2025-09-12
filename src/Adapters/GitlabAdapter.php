<?php

declare(strict_types=1);

namespace AICR\Adapters;

class GitlabAdapter extends BaseAdapter
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $this->initializeFromConfig($config);

        if ('' === $this->repository) {
            throw new \RuntimeException('Cannot infer GitLab project id. Set vcs.repository in config, GL_PROJECT_ID env, or ensure origin remote URL is a GitLab repo.');
        }
    }

    public function resolveBranchesFromId(int $id): array
    {
        $data = $this->gitlabApi('/projects/'.rawurlencode($this->repository).'/merge_requests/'.$id, $this->token, 'GET');
        $base = (string) ($data['target_branch'] ?? '');
        $head = (string) ($data['source_branch'] ?? '');
        if ('' === $base || '' === $head) {
            throw new \RuntimeException('Failed to resolve branches from GitLab MR.');
        }

        return [$base, $head];
    }

    public function postComment(int $id, string $body): void
    {
        if ('' === $this->token) {
            throw new \RuntimeException('Missing token for GitLab. Set GL_TOKEN or GITLAB_TOKEN.');
        }
        $this->gitlabApi('/projects/'.rawurlencode($this->repository).'/merge_requests/'.$id.'/notes', $this->token, 'POST', [
            'body' => $body,
        ]);
    }

    protected function getDefaultApiBase(): string
    {
        return 'https://gitlab.com/api/v4';
    }

    protected function resolveApiBase(array $config): string
    {
        if (isset($config['api_base']) && is_string($config['api_base']) && '' !== $config['api_base']) {
            return rtrim($config['api_base'], '/');
        }

        return $this->getDefaultApiBase();
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    protected function gitlabApi(string $path, string $token, string $method = 'GET', array $payload = []): array
    {
        $url     = $this->apiBase.rtrim($path, '/');
        $headers = [
            'Accept: application/json',
        ];
        if ('' !== $token) {
            $headers[] = 'PRIVATE-TOKEN: '.$token;
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
            throw new \RuntimeException('GitLab API request failed: '.$url);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid GitLab API response');
        }

        return $data;
    }
}
