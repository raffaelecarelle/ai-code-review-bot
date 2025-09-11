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
