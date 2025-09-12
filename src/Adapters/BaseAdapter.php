<?php

declare(strict_types=1);

namespace AICR\Adapters;

use Symfony\Component\Process\Process;

/**
 * Base adapter class providing common functionality for VCS adapters.
 */
abstract class BaseAdapter implements VcsAdapter
{
    protected string $repository  = '';
    protected string $accessToken = '';
    protected string $apiBase     = '';
    protected int $timeout        = 30;

    /**
     * @param array<string,mixed> $config VCS configuration array
     */
    protected function initializeFromConfig(array $config): void
    {
        $this->repository  = $this->resolveRepository($config);
        $this->accessToken = $this->resolveToken($config);
        $this->apiBase     = $this->resolveApiBase($config);
        $this->timeout     = $this->resolveTimeout($config);
    }

    /**
     * Resolve repository identifier from config with platform-specific logic.
     *
     * @param array<string,mixed> $config
     */
    protected function resolveRepository(array $config): string
    {
        if (isset($config['repository']) && is_string($config['repository']) && '' !== $config['repository']) {
            return $config['repository'];
        }

        throw new \RuntimeException('Repository not specified');
    }

    /**
     * Resolve authentication token with environment variable fallbacks.
     *
     * @param array<string,mixed> $config
     */
    protected function resolveToken(array $config): string
    {
        if (isset($config['access_token']) && is_string($config['access_token']) && '' !== $config['access_token']) {
            return $config['access_token'];
        }

        throw new \RuntimeException('Access Token not specified');
    }

    /**
     * Resolve API base URL with platform defaults.
     *
     * @param array<string,mixed> $config
     */
    protected function resolveApiBase(array $config): string
    {
        if (isset($config['api_base']) && is_string($config['api_base']) && '' !== $config['api_base']) {
            return rtrim($config['api_base'], '/');
        }

        return $this->getDefaultApiBase();
    }

    /**
     * Resolve timeout with default fallback.
     *
     * @param array<string,mixed> $config
     */
    protected function resolveTimeout(array $config): int
    {
        if (isset($config['timeout']) && is_int($config['timeout']) && $config['timeout'] > 0) {
            return $config['timeout'];
        }

        return 30; // Default timeout
    }

    /**
     * Run git command with error handling.
     */
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

    abstract protected function getDefaultApiBase(): string;
}
