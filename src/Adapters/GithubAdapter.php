<?php

declare(strict_types=1);

namespace AICR\Adapters;

use Symfony\Component\Process\Process;

class GithubAdapter implements VcsAdapter
{
    private string $repo  = ''; // owner/repo
    private string $token = '';

    public function __construct(?string $repo = null, ?string $token = null)
    {
        $this->repo  = $repo ?? $this->inferGithubRepo();
        $this->token = $token ?? (getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN') ?: '');
        if ('' === $this->repo) {
            throw new \RuntimeException('Cannot infer GitHub repo. Set vcs.repo in config, GH_REPO env, or ensure origin remote URL is a GitHub repo.');
        }
    }

    public function resolveBranchesFromId(int $id): array
    {
        $data = $this->githubApi('/repos/'.$this->repo.'/pulls/'.$id, $this->token, 'GET');
        $base = (string) ($data['base']['ref'] ?? '');
        $head = (string) ($data['head']['ref'] ?? '');
        if ('' === $base || '' === $head) {
            throw new \RuntimeException('Failed to resolve branches from GitHub PR.');
        }

        return [$base, $head];
    }

    public function postComment(int $id, string $body): void
    {
        if ('' === $this->token) {
            throw new \RuntimeException('Missing token for GitHub. Set GH_TOKEN or GITHUB_TOKEN.');
        }
        $this->githubApi('/repos/'.$this->repo.'/issues/'.$id.'/comments', $this->token, 'POST', [
            'body' => $body,
        ]);
    }

    protected function runGit(string $args): string
    {
        $process = Process::fromShellCommandline('git '.$args);
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
    protected function githubApi(string $path, string $token, string $method = 'GET', array $payload = []): array
    {
        $url     = 'https://api.github.com'.rtrim($path, '/');
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

    private function inferGithubRepo(): string
    {
        $env = getenv('GH_REPO');
        if ($env && '' !== $env) {
            return (string) $env;
        }
        $url = trim($this->runGit('remote get-url origin'));
        if (preg_match('#github.com[:/](?P<owner>[^/]+)/(?P<repo>[^\.]+)(?:\.git)?#', $url, $m)) {
            return $m['owner'].'/'.$m['repo'];
        }

        return '';
    }
}
