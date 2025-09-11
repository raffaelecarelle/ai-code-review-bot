<?php

declare(strict_types=1);

namespace AICR;

use Symfony\Component\Yaml\Yaml;

final class RulesEngine
{
    /** @var array<int, Rule> */
    private array $rules = [];

    /**
     * @param array{include?:array<int, string>, inline?: array<int, array{id:string, applies_to?:array<int,string>, severity:string, rationale:string, pattern:string, suggestion?:string, enabled?:bool}>} $rulesConfig
     */
    public static function fromConfig(array $rulesConfig): self
    {
        $engine = new self();
        $inline = isset($rulesConfig['inline']) ? $rulesConfig['inline'] : [];

        /** @var array<int, array{id:string, applies_to?:array<int,string>, severity:string, rationale:string, pattern:string, suggestion?:string, enabled?:bool}> $inline */
        foreach ($inline as $r) {
            if (is_array($r)) {
                $engine->addRule(new Rule($r));
            }
        }

        // Load external rule files from rules.include (supports glob patterns)
        $includes = isset($rulesConfig['include']) ? $rulesConfig['include'] : [];
        if (is_array($includes)) {
            foreach ($includes as $pattern) {
                if (!is_string($pattern) || '' === $pattern) {
                    continue;
                }
                $files = glob($pattern, GLOB_BRACE) ?: [];
                foreach ($files as $file) {
                    if (!is_string($file) || !is_file($file) || !is_readable($file)) {
                        continue;
                    }
                    $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
                    $raw = file_get_contents($file);
                    if (false === $raw) {
                        // Skip unreadable file
                        continue;
                    }

                    $data      = null;
                    $lastError = null;

                    $tryParseYaml = static function (string $content) use (&$lastError) {
                        try {
                            $result = Yaml::parse($content);
                            if (!is_array($result)) {
                                throw new \RuntimeException('YAML rules file must parse to an array.');
                            }

                            return $result;
                        } catch (\Throwable $e) {
                            $lastError = $e;

                            return null;
                        }
                    };

                    $tryParseJson = static function (string $content) use (&$lastError) {
                        $decoded = json_decode($content, true);
                        if (null === $decoded && JSON_ERROR_NONE !== json_last_error()) {
                            $lastError = new \RuntimeException('Invalid JSON rules file: '.json_last_error_msg());

                            return null;
                        }
                        if (!is_array($decoded)) {
                            $lastError = new \RuntimeException('JSON rules file must decode to an array.');

                            return null;
                        }

                        return $decoded;
                    };

                    if (in_array($ext, ['yml', 'yaml'], true)) {
                        $data = $tryParseYaml($raw);
                        if (null === $data) {
                            // Retry to tolerate single backslashes (e.g., in regex like \s inside quoted YAML)
                            $data = $tryParseYaml(str_replace('\\', '\\\\', $raw));
                        }
                    } elseif ('json' === $ext) {
                        $data = $tryParseJson($raw);
                    } else {
                        // Unknown extension: try YAML first, then JSON
                        $data = $tryParseYaml($raw);
                        if (null === $data) {
                            $data = $tryParseYaml(str_replace('\\', '\\\\', $raw));
                        }
                        if (null === $data) {
                            $data = $tryParseJson($raw);
                        }
                    }

                    if (null === $data) {
                        // Skip file with parsing issues
                        continue;
                    }

                    // Normalize rules list: support either plain list, or {rules:[...]}, or {inline:[...]}
                    $rulesList = [];
                    if (isset($data['rules']) && is_array($data['rules'])) {
                        $rulesList = $data['rules'];
                    } elseif (isset($data['inline']) && is_array($data['inline'])) {
                        $rulesList = $data['inline'];
                    } elseif (is_array($data) && function_exists('array_is_list') && array_is_list($data)) {
                        $rulesList = $data;
                    }

                    /** @var array<int, array{id:string, applies_to?:array<int,string>, severity:string, rationale:string, pattern:string, suggestion?:string, enabled?:bool}> $rulesList */
                    foreach ($rulesList as $ruleDef) {
                        if (is_array($ruleDef)) {
                            try {
                                $engine->addRule(new Rule($ruleDef));
                            } catch (\Throwable $e) {
                                // Invalid rule shape: skip
                                continue;
                            }
                        }
                    }
                }
            }
        }

        return $engine;
    }

    public function addRule(Rule $rule): void
    {
        if ($rule->isEnabled()) {
            $this->rules[] = $rule;
        }
    }

    /**
     * @param array<int, array{line:int, content:string}> $addedLines
     *
     * @return array<int, array<string, mixed>>
     */
    public function evaluate(string $filePath, array $addedLines): array
    {
        $findings = [];
        foreach ($this->rules as $rule) {
            if (!$this->pathMatchesAny($filePath, $rule->getAppliesTo())) {
                continue;
            }
            $regex = '#'.$rule->getPattern().'#';
            foreach ($addedLines as $entry) {
                $lineNo  = (int) $entry['line'];
                $content = (string) $entry['content'];
                if (1 === preg_match($regex, $content)) {
                    $findings[] = [
                        'rule_id'    => $rule->getId(),
                        'title'      => $rule->getId(),
                        'severity'   => $rule->getSeverity(),
                        'file_path'  => $filePath,
                        'start_line' => $lineNo,
                        'end_line'   => $lineNo,
                        'rationale'  => $rule->getRationale(),
                        'suggestion' => $rule->getSuggestion(),
                        'content'    => $content,
                    ];
                }
            }
        }

        return $findings;
    }

    public static function globMatch(string $pattern, string $path): bool
    {
        // Convert a glob with ** to a regex. Treat path separators as '/'.
        $pattern = str_replace('\\', '/', $pattern);
        $path    = str_replace('\\', '/', $path);
        $escaped = preg_quote($pattern, '#');
        // Undo escapes for our glob tokens and translate
        $escaped = str_replace(['\*\*', '\*', '\?'], ['__GLOBSTAR__', '__GLOB__', '__QMARK__'], $escaped);

        $regex = str_replace(
            ['__GLOBSTAR__', '__GLOB__', '__QMARK__'],
            ['.*', '[^/]*', '.'],
            $escaped
        );
        $regex = '^'.$regex.'$';

        return (bool) preg_match('#'.$regex.'#', $path);
    }

    /**
     * @param array<int, string> $globs
     */
    private function pathMatchesAny(string $path, array $globs): bool
    {
        if ([] === $globs) {
            return true;
        }
        foreach ($globs as $glob) {
            if (self::globMatch($glob, $path)) {
                return true;
            }
        }

        return false;
    }
}
