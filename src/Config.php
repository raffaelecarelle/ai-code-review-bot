<?php

declare(strict_types=1);

namespace AICR;

use AICR\Exception\ConfigurationException;
use AICR\Support\StreamingFileReader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and merges configuration from YAML/JSON with sensible defaults.
 * - Environment variables in values are expanded using ${VAR} syntax.
 * - Provides getters for relevant sections.
 */
class Config
{
    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function load(?string $path = null): self
    {
        $defaults   = self::defaults();
        $fileConfig = self::loadConfigFile($path);

        $merged   = self::merge($defaults, $fileConfig);
        $expanded = self::expandEnv($merged);
        self::validateConfiguration($expanded);
        self::injectGuidelinesIntoPrompts($expanded);

        return new self($expanded);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        return $this->config;
    }

    /**
     * @return array<string, mixed>
     */
    public function providers(): array
    {
        return $this->config['providers'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(string $providerName): array
    {
        return array_merge($this->config['context'], ['provider' => $providerName]);
    }

    /**
     * @return array<string, mixed>
     */
    public function policy(): array
    {
        return $this->config['policy'];
    }

    /**
     * @return array<string, mixed>
     */

    /**
     * @return array<string, mixed>
     */
    public function vcs(): array
    {
        return $this->config['vcs'] ?? [];
    }

    /**
     * @return array<string>
     */
    public function excludes(): array
    {
        return $this->config['excludes'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function cache(): array
    {
        return $this->config['cache'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'version'   => 1,
            'providers' => [
                'mock' => [],
            ],
            'context' => [
                'diff_token_limit'   => 8000,
                'overflow_strategy'  => 'trim',
                'per_file_token_cap' => 2000,
            ],
            'policy' => [
                'min_severity_to_comment' => 'info',
                'max_comments'            => 50,
                'redact_secrets'          => true,
            ],
            'guidelines_file' => null,
            'vcs'             => [
                'platform'     => null,
                'repository'   => null,
                'project_id'   => null,
                'api_base'     => null,
                'access_token' => null,
            ],
            'prompts' => [
                // Optional strings to append to the base prompts used by the LLM providers
                // You can set either a single string or a list of strings in the config file
                'system_append' => null,
                'user_append'   => null,
                // Additional free-form instructions appended to the user prompt, in order
                'extra' => [],
            ],
            'cache' => [
                'enabled'        => false,
                'directory'      => null, // null = use system temp directory
                'default_ttl'    => 3600, // 1 hour in seconds
                'max_cache_size' => 52428800, // 50MB in bytes
            ],
            'excludes' => [
                // Array of paths to exclude from code review
                // Each element is treated as glob, regex, or relative path from project root
            ],
        ];
    }

    /**
     * Validates the configuration structure and values.
     *
     * @param array<string, mixed> $config Configuration to validate
     *
     * @throws ConfigurationException If configuration is invalid
     */
    private static function validateConfiguration(array $config): void
    {
        self::validateRequiredKeys($config);
        self::validateProviders($config);
        self::validatePolicy($config);
        self::validateVcs($config);
    }

    /**
     * Validates that required top-level configuration keys are present.
     *
     * @param array<string, mixed> $config Configuration to validate
     */
    private static function validateRequiredKeys(array $config): void
    {
        $requiredKeys = ['providers', 'context', 'policy', 'vcs'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $config)) {
                throw new ConfigurationException("Missing required configuration key: {$key}");
            }
        }
    }

    /**
     * Validates providers configuration.
     *
     * @param array<string, mixed> $config Configuration to validate
     */
    private static function validateProviders(array $config): void
    {
        if (!is_array($config['providers'])) {
            throw new ConfigurationException('Configuration key "providers" must be an array');
        }

        foreach ($config['providers'] as $providerName => $providerConfig) {
            if (!is_string($providerName)) {
                throw new ConfigurationException('Provider names must be strings');
            }

            // Special case: 'default' can be a string specifying which provider to use as default
            if ('default' === $providerName) {
                if (!is_string($providerConfig)) {
                    throw new ConfigurationException("Provider 'default' must be a string specifying the default provider name");
                }

                continue;
            }

            if (!is_array($providerConfig)) {
                throw new ConfigurationException("Provider '{$providerName}' configuration must be an array");
            }
        }
    }

    /**
     * Validates policy configuration.
     *
     * @param array<string, mixed> $config Configuration to validate
     */
    private static function validatePolicy(array $config): void
    {
        if (!is_array($config['policy'])) {
            throw new ConfigurationException('Configuration key "policy" must be an array');
        }
    }

    /**
     * Validates VCS configuration.
     *
     * @param array<string, mixed> $config Configuration to validate
     */
    private static function validateVcs(array $config): void
    {
        if (!is_array($config['vcs'])) {
            throw new ConfigurationException('Configuration key "vcs" must be an array');
        }

        if (isset($config['vcs']['platform'])) {
            $validPlatforms = ['github', 'gitlab', 'bitbucket'];
            $platform       = $config['vcs']['platform'];
            if (!is_string($platform) || !in_array($platform, $validPlatforms, true)) {
                throw new ConfigurationException('VCS platform must be one of: '.implode(', ', $validPlatforms));
            }
        }
    }

    /**
     * Loads and parses configuration file content.
     *
     * @return array<string, mixed> Parsed configuration or empty array if no file
     */
    private static function loadConfigFile(?string $path): array
    {
        if (null === $path) {
            return [];
        }

        $fs = new Filesystem();
        if (!$fs->exists($path)) {
            return [];
        }

        $content = self::readConfigFile($path);

        return self::parseConfigContent($content, $path);
    }

    /**
     * Reads configuration file content.
     */
    private static function readConfigFile(string $path): string
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new ConfigurationException("Failed to read config file: {$path}");
        }

        return $content;
    }

    /**
     * Parses configuration file content based on file extension.
     *
     * @return array<string, mixed> Parsed configuration
     */
    private static function parseConfigContent(string $content, string $path): array
    {
        $ext       = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $lastError = null;

        $parsed = match (true) {
            in_array($ext, ['yml', 'yaml'], true) => self::parseYamlContent($content, $lastError),
            'json' === $ext                       => self::parseJsonContent($content, $lastError),
            default                               => self::parseUnknownContent($content, $lastError)
        };

        if (null === $parsed) {
            $hint = '' !== $ext ? ".{$ext}" : '(no extension)';

            throw new ConfigurationException("Unsupported or invalid config format for file type: {$hint}. Use YAML or JSON. Last error: ".($lastError ? $lastError->getMessage() : 'unknown'));
        }

        return $parsed;
    }

    /**
     * Attempts to parse YAML content with backslash escaping fallback.
     *
     * @return null|array<string, mixed> Parsed data or null on failure
     */
    private static function parseYamlContent(string $content, ?\Throwable &$lastError): ?array
    {
        $parsed = self::tryParseYaml($content, $lastError);
        if (null === $parsed) {
            // Retry by escaping backslashes to tolerate sequences like \s used in regex within quoted YAML strings
            $parsed = self::tryParseYaml(str_replace('\\', '\\\\', $content), $lastError);
        }

        return $parsed;
    }

    /**
     * Attempts to parse JSON content.
     *
     * @return null|array<string, mixed> Parsed data or null on failure
     */
    private static function parseJsonContent(string $content, ?\Throwable &$lastError): ?array
    {
        return self::tryParseJson($content, $lastError);
    }

    /**
     * Attempts to parse content with unknown extension (tries YAML then JSON).
     *
     * @return null|array<string, mixed> Parsed data or null on failure
     */
    private static function parseUnknownContent(string $content, ?\Throwable &$lastError): ?array
    {
        // Try YAML first (more robust)
        $parsed = self::parseYamlContent($content, $lastError);
        if (null === $parsed) {
            $parsed = self::parseJsonContent($content, $lastError);
        }

        return $parsed;
    }

    /**
     * Attempts to parse YAML content.
     *
     * @return null|array<string, mixed> Parsed data or null on failure
     */
    private static function tryParseYaml(string $content, ?\Throwable &$lastError): ?array
    {
        try {
            $result = Yaml::parse($content);
            if (!is_array($result)) {
                throw new ConfigurationException('YAML config must parse to an array.');
            }

            return $result;
        } catch (\Throwable $e) {
            $lastError = $e;

            return null;
        }
    }

    /**
     * Attempts to parse JSON content.
     *
     * @return null|array<string, mixed> Parsed data or null on failure
     */
    private static function tryParseJson(string $content, ?\Throwable &$lastError): ?array
    {
        $data = json_decode($content, true);
        if (null === $data && JSON_ERROR_NONE !== json_last_error()) {
            $lastError = new ConfigurationException('Invalid JSON config: '.json_last_error_msg());

            return null;
        }
        if (!is_array($data)) {
            $lastError = new ConfigurationException('JSON config must decode to an array.');

            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function injectGuidelinesIntoPrompts(array &$config): void
    {
        $guidelinesPath = $config['guidelines_file'] ?? null;
        $prompts        = $config['prompts'] ?? [];
        if (!is_array($prompts)) {
            $prompts = [];
        }
        if (!isset($prompts['extra']) || !is_array($prompts['extra'])) {
            $prompts['extra'] = [];
        }

        $prefix = 'Coding guidelines file content is provided below in base64';
        $has    = false;
        foreach ($prompts['extra'] as $x) {
            if (is_string($x) && str_contains($x, $prefix)) {
                $has = true;

                break;
            }
        }

        if (!$has && is_string($guidelinesPath) && '' !== trim($guidelinesPath)) {
            try {
                $fileReader = new StreamingFileReader();
                if ($fileReader->validatePath($guidelinesPath)) {
                    $gl = $fileReader->readFile($guidelinesPath);
                    if ('' !== trim($gl)) {
                        $b64                = base64_encode($gl);
                        $prompts['extra'][] = "Coding guidelines file content is provided below in base64 (decode and follow strictly):\n".$b64;
                    }
                }
            } catch (\Exception $e) {
                // Log error but don't fail configuration loading
                error_log("Failed to load guidelines file '{$guidelinesPath}': ".$e->getMessage());
            }
        }

        $config['prompts'] = $prompts;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return array<string, mixed>
     */
    private static function merge(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if (array_key_exists($key, $a) && is_array($a[$key]) && is_array($value)) {
                /** @var array<string, mixed> $aSub */
                $aSub = $a[$key];

                /** @var array<string, mixed> $bSub */
                $bSub    = $value;
                $a[$key] = self::merge($aSub, $bSub);
            } else {
                $a[$key] = $value;
            }
        }

        return $a;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function expandEnv(array $config): array
    {
        $recurse = function ($value) use (&$recurse) {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = $recurse($v);
                }

                return $out;
            }
            if (is_string($value)) {
                return (string) preg_replace_callback('/\$\{([A-Z0-9_]+)\}/', static function (array $m): string {
                    $env = getenv($m[1]);

                    return false !== $env ? (string) $env : $m[0];
                }, $value);
            }

            return $value;
        };

        return $recurse($config);
    }
}
