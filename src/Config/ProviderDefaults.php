<?php

declare(strict_types=1);

namespace AICR\Config;

/**
 * Centralized provider defaults to eliminate hardcoded values.
 * Addresses issue #9 about scattered hardcoded configuration values.
 */
final class ProviderDefaults
{
    // Common provider settings
    public const DEFAULT_TIMEOUT     = 60.0;
    public const DEFAULT_MAX_RETRIES = 3;
    public const DEFAULT_RATE_LIMIT  = 60; // requests per minute

    // OpenAI Provider defaults
    public const OPENAI_DEFAULT_MODEL    = 'gpt-4o-mini';
    public const OPENAI_DEFAULT_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    public const OPENAI_RATE_LIMIT       = 500; // requests per minute for paid accounts

    // Anthropic Provider defaults
    public const ANTHROPIC_DEFAULT_MODEL      = 'claude-3-5-sonnet-20240620';
    public const ANTHROPIC_DEFAULT_ENDPOINT   = 'https://api.anthropic.com/v1/messages';
    public const ANTHROPIC_API_VERSION        = '2023-06-01';
    public const ANTHROPIC_DEFAULT_MAX_TOKENS = 2048;
    public const ANTHROPIC_RATE_LIMIT         = 50; // requests per minute

    // Gemini Provider defaults
    public const GEMINI_DEFAULT_MODEL    = 'gemini-1.5-pro';
    public const GEMINI_DEFAULT_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';
    public const GEMINI_RATE_LIMIT       = 60; // requests per minute

    // Ollama Provider defaults
    public const OLLAMA_DEFAULT_MODEL    = 'llama3.1';
    public const OLLAMA_DEFAULT_ENDPOINT = 'http://localhost:11434/api/generate';
    public const OLLAMA_DEFAULT_TIMEOUT  = 120.0; // Longer timeout for local models
    public const OLLAMA_RATE_LIMIT       = 10; // Lower rate limit for local instances

    // VCS Adapter defaults
    public const VCS_DEFAULT_TIMEOUT    = 30;
    public const VCS_DEFAULT_RATE_LIMIT = 60; // requests per minute

    // GitHub specific
    public const GITHUB_DEFAULT_API_BASE = 'https://api.github.com';
    public const GITHUB_RATE_LIMIT       = 5000; // requests per hour for authenticated users

    // GitLab specific
    public const GITLAB_DEFAULT_API_BASE = 'https://gitlab.com/api/v4';
    public const GITLAB_RATE_LIMIT       = 2000; // requests per minute

    // Bitbucket specific
    public const BITBUCKET_DEFAULT_API_BASE = 'https://api.bitbucket.org/2.0';
    public const BITBUCKET_RATE_LIMIT       = 60; // requests per minute

    // Memory and file processing limits
    public const MAX_FILE_SIZE      = 104857600; // 100MB
    public const MAX_MEMORY_USAGE   = 50331648; // 48MB
    public const DEFAULT_CHUNK_SIZE = 8192; // 8KB

    // Token budget defaults
    public const DEFAULT_DIFF_TOKEN_LIMIT   = 8000;
    public const DEFAULT_PER_FILE_TOKEN_CAP = 2000;
    public const DEFAULT_OVERFLOW_STRATEGY  = 'trim';

    /**
     * Get provider-specific defaults.
     *
     * @param string $provider The AI provider name
     *
     * @return array{
     *     model?: string,
     *     endpoint?: string,
     *     timeout: float,
     *     max_retries: int,
     *     rate_limit: int,
     *     api_version?: string,
     *     max_tokens?: int
     * }
     */
    public static function getProviderDefaults(string $provider): array
    {
        return match (strtolower($provider)) {
            'openai' => [
                'model'       => self::OPENAI_DEFAULT_MODEL,
                'endpoint'    => self::OPENAI_DEFAULT_ENDPOINT,
                'timeout'     => self::DEFAULT_TIMEOUT,
                'max_retries' => self::DEFAULT_MAX_RETRIES,
                'rate_limit'  => self::OPENAI_RATE_LIMIT,
            ],
            'anthropic' => [
                'model'       => self::ANTHROPIC_DEFAULT_MODEL,
                'endpoint'    => self::ANTHROPIC_DEFAULT_ENDPOINT,
                'timeout'     => self::DEFAULT_TIMEOUT,
                'max_retries' => self::DEFAULT_MAX_RETRIES,
                'rate_limit'  => self::ANTHROPIC_RATE_LIMIT,
                'api_version' => self::ANTHROPIC_API_VERSION,
                'max_tokens'  => self::ANTHROPIC_DEFAULT_MAX_TOKENS,
            ],
            'gemini' => [
                'model'       => self::GEMINI_DEFAULT_MODEL,
                'endpoint'    => self::GEMINI_DEFAULT_ENDPOINT,
                'timeout'     => self::DEFAULT_TIMEOUT,
                'max_retries' => self::DEFAULT_MAX_RETRIES,
                'rate_limit'  => self::GEMINI_RATE_LIMIT,
            ],
            'ollama' => [
                'model'       => self::OLLAMA_DEFAULT_MODEL,
                'endpoint'    => self::OLLAMA_DEFAULT_ENDPOINT,
                'timeout'     => self::OLLAMA_DEFAULT_TIMEOUT,
                'max_retries' => self::DEFAULT_MAX_RETRIES,
                'rate_limit'  => self::OLLAMA_RATE_LIMIT,
            ],
            default => [
                'timeout'     => self::DEFAULT_TIMEOUT,
                'max_retries' => self::DEFAULT_MAX_RETRIES,
                'rate_limit'  => self::DEFAULT_RATE_LIMIT,
            ],
        };
    }

    /**
     * Get VCS adapter defaults.
     *
     * @return array{
     *     api_base?: string,
     *     timeout: int,
     *     rate_limit: int
     * }
     */
    public static function getVcsDefaults(string $platform): array
    {
        return match (strtolower($platform)) {
            'github' => [
                'api_base'   => self::GITHUB_DEFAULT_API_BASE,
                'timeout'    => self::VCS_DEFAULT_TIMEOUT,
                'rate_limit' => self::GITHUB_RATE_LIMIT,
            ],
            'gitlab' => [
                'api_base'   => self::GITLAB_DEFAULT_API_BASE,
                'timeout'    => self::VCS_DEFAULT_TIMEOUT,
                'rate_limit' => self::GITLAB_RATE_LIMIT,
            ],
            'bitbucket' => [
                'api_base'   => self::BITBUCKET_DEFAULT_API_BASE,
                'timeout'    => self::VCS_DEFAULT_TIMEOUT,
                'rate_limit' => self::BITBUCKET_RATE_LIMIT,
            ],
            default => [
                'timeout'    => self::VCS_DEFAULT_TIMEOUT,
                'rate_limit' => self::VCS_DEFAULT_RATE_LIMIT,
            ],
        };
    }

    /**
     * Get memory and file processing defaults.
     *
     * @return array{
     *     max_file_size: int,
     *     max_memory_usage: int,
     *     chunk_size: int
     * }
     */
    public static function getFileProcessingDefaults(): array
    {
        return [
            'max_file_size'    => self::MAX_FILE_SIZE,
            'max_memory_usage' => self::MAX_MEMORY_USAGE,
            'chunk_size'       => self::DEFAULT_CHUNK_SIZE,
        ];
    }

    /**
     * Get token budget defaults.
     *
     * @return array{
     *     diff_token_limit: int,
     *     per_file_token_cap: int,
     *     overflow_strategy: string
     * }
     */
    public static function getTokenBudgetDefaults(): array
    {
        return [
            'diff_token_limit'   => self::DEFAULT_DIFF_TOKEN_LIMIT,
            'per_file_token_cap' => self::DEFAULT_PER_FILE_TOKEN_CAP,
            'overflow_strategy'  => self::DEFAULT_OVERFLOW_STRATEGY,
        ];
    }

    /**
     * Validate configuration values against allowed ranges.
     *
     * @param array{
     *     timeout?: float|int,
     *     max_retries?: int,
     *     rate_limit?: int,
     *     max_file_size?: int,
     *     chunk_size?: int,
     *     diff_token_limit?: int,
     *     per_file_token_cap?: int,
     *     overflow_strategy?: string
     * } $config Configuration values to validate
     * @param string $type Configuration type ('provider'|'vcs'|'file_processing'|'token_budget')
     *
     * @return array{
     *     timeout?: float|int,
     *     max_retries?: int,
     *     rate_limit?: int,
     *     max_file_size?: int,
     *     chunk_size?: int,
     *     diff_token_limit?: int,
     *     per_file_token_cap?: int,
     *     overflow_strategy?: string
     * } Validated configuration values
     */
    public static function validateConfig(array $config, string $type): array
    {
        return match ($type) {
            'provider'        => self::validateProviderConfig($config),
            'vcs'             => self::validateVcsConfig($config),
            'file_processing' => self::validateFileProcessingConfig($config),
            'token_budget'    => self::validateTokenBudgetConfig($config),
            default           => $config,
        };
    }

    /**
     * @param array{
     *     timeout?: float|int,
     *     max_retries?: int,
     *     rate_limit?: int
     * } $config
     *
     * @return array{
     *     timeout?: float|int,
     *     max_retries?: int,
     *     rate_limit?: int
     * }
     */
    private static function validateProviderConfig(array $config): array
    {
        // Validate timeout
        if (isset($config['timeout'])) {
            $config['timeout'] = max(1.0, min(300.0, (float) $config['timeout']));
        }

        // Validate max_retries
        if (isset($config['max_retries'])) {
            $config['max_retries'] = max(0, min(10, (int) $config['max_retries']));
        }

        // Validate rate_limit
        if (isset($config['rate_limit'])) {
            $config['rate_limit'] = max(1, min(10000, (int) $config['rate_limit']));
        }

        return $config;
    }

    /**
     * @param array{timeout?: float|int, max_retries?: int, rate_limit?: int, max_file_size?: int, chunk_size?: int, diff_token_limit?: int, per_file_token_cap?: int, overflow_strategy?: string} $config
     *
     * @return array{timeout?: float|int, max_retries?: int, rate_limit?: int, max_file_size?: int, chunk_size?: int, diff_token_limit?: int, per_file_token_cap?: int, overflow_strategy?: string}
     */
    private static function validateVcsConfig(array $config): array
    {
        // Validate timeout
        if (isset($config['timeout'])) {
            $config['timeout'] = max(1, min(120, (int) $config['timeout']));
        }

        return $config;
    }

    /**
     * @param array{
     *     max_file_size?: int,
     *     chunk_size?: int
     * } $config
     *
     * @return array{
     *     max_file_size?: int,
     *     chunk_size?: int
     * }
     */
    private static function validateFileProcessingConfig(array $config): array
    {
        // Validate max_file_size (1MB to 1GB)
        if (isset($config['max_file_size'])) {
            $config['max_file_size'] = max(1048576, min(1073741824, (int) $config['max_file_size']));
        }

        // Validate chunk_size (1KB to 1MB)
        if (isset($config['chunk_size'])) {
            $config['chunk_size'] = max(1024, min(1048576, (int) $config['chunk_size']));
        }

        return $config;
    }

    /**
     * @param array{
     *     diff_token_limit?: int,
     *     per_file_token_cap?: int,
     *     overflow_strategy?: string
     * } $config
     *
     * @return array{
     *     diff_token_limit?: int,
     *     per_file_token_cap?: int,
     *     overflow_strategy?: string
     * }
     */
    private static function validateTokenBudgetConfig(array $config): array
    {
        // Validate token limits
        if (isset($config['diff_token_limit'])) {
            $config['diff_token_limit'] = max(100, min(100000, (int) $config['diff_token_limit']));
        }

        if (isset($config['per_file_token_cap'])) {
            $config['per_file_token_cap'] = max(50, min(50000, (int) $config['per_file_token_cap']));
        }

        // Validate overflow strategy
        if (isset($config['overflow_strategy'])) {
            $allowedStrategies = ['trim', 'compress', 'skip'];
            if (!in_array($config['overflow_strategy'], $allowedStrategies)) {
                $config['overflow_strategy'] = self::DEFAULT_OVERFLOW_STRATEGY;
            }
        }

        return $config;
    }
}
