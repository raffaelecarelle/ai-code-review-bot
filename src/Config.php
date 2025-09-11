<?php

declare(strict_types=1);

namespace AICR;

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
        $fileConfig = [];
        $fs         = new Filesystem();
        if (null !== $path && $fs->exists($path)) {
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $raw = file_get_contents($path);
            if (false === $raw) {
                throw new \RuntimeException("Failed to read config file: {$path}");
            }

            $parsed    = null;
            $lastError = null;

            $tryParseYaml = static function (string $content) use (&$lastError) {
                try {
                    $result = Yaml::parse($content);
                    if (!is_array($result)) {
                        throw new \RuntimeException('YAML config must parse to an array.');
                    }

                    return $result;
                } catch (\Throwable $e) {
                    $lastError = $e;

                    return null;
                }
            };

            $tryParseJson = static function (string $content) use (&$lastError) {
                $data = json_decode($content, true);
                if (null === $data && JSON_ERROR_NONE !== json_last_error()) {
                    $lastError = new \RuntimeException('Invalid JSON config: '.json_last_error_msg());

                    return null;
                }
                if (!is_array($data)) {
                    $lastError = new \RuntimeException('JSON config must decode to an array.');

                    return null;
                }

                return $data;
            };

            if (in_array($ext, ['yml', 'yaml'], true)) {
                $parsed = $tryParseYaml($raw);
                if (null === $parsed) {
                    // Retry by escaping backslashes to tolerate sequences like \s used in regex within quoted YAML strings
                    $parsed = $tryParseYaml(str_replace('\\', '\\\\', $raw));
                }
            } elseif ('json' === $ext) {
                $parsed = $tryParseJson($raw);
            } else {
                // Unknown extension: try YAML (robust), then JSON
                $parsed = $tryParseYaml($raw);
                if (null === $parsed) {
                    $parsed = $tryParseYaml(str_replace('\\', '\\\\', $raw));
                }
                if (null === $parsed) {
                    $parsed = $tryParseJson($raw);
                }
            }

            if (null === $parsed) {
                $hint = '' !== $ext ? ".{$ext}" : '(no extension)';

                throw new \InvalidArgumentException("Unsupported or invalid config format for file type: {$hint}. Use YAML or JSON. Last error: ".($lastError ? $lastError->getMessage() : 'unknown'));
            }

            $fileConfig = $parsed;
        }
        $merged   = self::merge($defaults, $fileConfig);
        $expanded = self::expandEnv($merged);

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
        return $this->config['providers'];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->config['context'];
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
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'version'   => 1,
            'test'      => false,
            'providers' => [
                'default' => 'mock',
            ],
            'context' => [
                'diff_token_limit'   => 8000,
                'overflow_strategy'  => 'trim',
                'per_file_token_cap' => 2000,
            ],
            'policy' => [
                'min_severity_to_comment' => 'info',
                'max_comments'            => 50,
                'allow_suggested_fixes'   => true,
                'redact_secrets'          => true,
            ],
            'guidelines_file' => null,
            'vcs'             => [
                // platform: github|gitlab (required to use PR/MR auto-resolve)
                'platform' => null,
                // For GitHub: owner/repo (optional if GH_REPO or remote origin inferrable)
                'repo' => null,
                // For GitLab: numeric id or full path namespace/repo (optional if GL_PROJECT_ID or remote origin inferrable)
                'project_id' => null,
                // Optional GitLab API base override
                'api_base' => null,
            ],
            'prompts' => [
                // Optional strings to append to the base prompts used by the LLM providers
                // You can set either a single string or a list of strings in the config file
                'system_append' => null,
                'user_append'   => null,
                // Additional free-form instructions appended to the user prompt, in order
                'extra' => [],
            ],
        ];
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
