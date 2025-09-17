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
        $fileConfig = [];
        $fs         = new Filesystem();
        if (null !== $path && $fs->exists($path)) {
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $raw = file_get_contents($path);
            if (false === $raw) {
                throw new ConfigurationException("Failed to read config file: {$path}");
            }

            $parsed    = null;
            $lastError = null;

            $tryParseYaml = static function (string $content) use (&$lastError) {
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
            };

            $tryParseJson = static function (string $content) use (&$lastError) {
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

                throw new ConfigurationException("Unsupported or invalid config format for file type: {$hint}. Use YAML or JSON. Last error: ".($lastError ? $lastError->getMessage() : 'unknown'));
            }

            $fileConfig = $parsed;
        }
        $merged   = self::merge($defaults, $fileConfig);
        $expanded = self::expandEnv($merged);
        // Inject guidelines content into prompts.extra at load time so the rest of the app (and tests)
        // can rely on config already containing the hint.
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
            'excludes' => [
                // Array of paths to exclude from code review
                // Each element is treated as glob, regex, or relative path from project root
            ],
        ];
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
