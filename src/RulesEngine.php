<?php

declare(strict_types=1);

namespace AICR;

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
            $engine->addRule(new Rule($r));
        }

        // Note: rules.include support could be added here (globs). Minimal version omits file IO include.
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
