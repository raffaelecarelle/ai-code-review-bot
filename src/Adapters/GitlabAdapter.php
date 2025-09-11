<?php

declare(strict_types=1);

namespace AICR\Adapters;

class GitlabAdapter implements VcsAdapter
{
    private string $projectId = ''; // numeric or full path
    private string $token     = '';
    private string $apiBase   = '';

    public function __construct(?string $projectId = null, ?string $token = null, ?string $apiBase = null)
    {
        $this->projectId = $projectId ?? $this->inferGitlabProjectId();
        $this->token     = $token ?? (getenv('GL_TOKEN') ?: getenv('GITLAB_TOKEN') ?: '');
        $this->apiBase   = rtrim($apiBase ?? (getenv('GL_API_BASE') ?: 'https://gitlab.com/api/v4'), '/');
        if ('' === $this->projectId) {
            throw new \RuntimeException('Cannot infer GitLab project id. Set vcs.project_id in config, GL_PROJECT_ID env, or ensure origin remote URL is a GitLab repo.');
        }
    }

    public function resolveBranchesFromId(int $id): array
    {
        $data = $this->gitlabApi('/projects/'.rawurlencode($this->projectId).'/merge_requests/'.$id, $this->token, 'GET');
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
        $this->gitlabApi('/projects/'.rawurlencode($this->projectId).'/merge_requests/'.$id.'/notes', $this->token, 'POST', [
            'body' => $body,
        ]);
    }

    protected function runGit(string $args): string
    {
        $process = \Symfony\Component\Process\Process::fromShellCommandline('git '.$args);
        $process->setTimeout(null);
        $process->run();
        if (!$process->isSuccessful()) {
            $cmdline  = $process->getCommandLine();
            $output   = trim($process->getOutput());
            $error    = trim($process->getErrorOutput());
            $combined = trim($output.('' !== $error ? "\n".$error : ''));

            throw new \RuntimeException("Git command failed ({$cmdline}):\n".$combined);
        }
        $out = $process->getOutput();
        if ('' === $out) {
            $out = $process->getErrorOutput();
        }

        return rtrim($out, "\n")."\n";
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

    private function inferGitlabProjectId(): string
    {
        $env = getenv('GL_PROJECT_ID') ?: '';
        if ('' !== $env) {
            return $env;
        }
        $url = trim($this->runGit('remote get-url origin'));
        if (preg_match('#gitlab.com[:/](?P<ns>.+?)/(?P<repo>[^/\.]+)(?:\.git)?$#', $url, $m)) {
            return $m['ns'].'/'.$m['repo'];
        }

        return '';
    }
}
