<?php

declare(strict_types=1);

namespace AICR;

final class Rule
{
    private string $id;

    /** @var array<int, string> */
    private array $appliesTo;
    private string $severity;
    private string $rationale;
    private string $pattern;
    private string $suggestion;
    private bool $enabled;

    /**
     * @param array{id:string, applies_to?:array<int,string>, severity:string, rationale:string, pattern:string, suggestion?:string, enabled?:bool} $data
     */
    public function __construct(array $data)
    {
        // Required fields per shape
        $this->id        = (string) $data['id'];
        $this->severity  = (string) $data['severity'];
        $this->rationale = (string) $data['rationale'];
        $this->pattern   = (string) $data['pattern'];

        // Optional fields
        $applies          = $data['applies_to'] ?? [];
        $this->appliesTo  = array_values(array_map(static fn ($v): string => (string) $v, $applies));
        $this->suggestion = (string) ($data['suggestion'] ?? '');
        $this->enabled    = (bool) ($data['enabled'] ?? true);

        if ('' === $this->id || '' === $this->pattern) {
            throw new \InvalidArgumentException('Rule must have non-empty id and pattern');
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** @return array<int, string> */
    public function getAppliesTo(): array
    {
        return $this->appliesTo;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getRationale(): string
    {
        return $this->rationale;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getSuggestion(): string
    {
        return $this->suggestion;
    }
}

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
