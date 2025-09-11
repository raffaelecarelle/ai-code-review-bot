<?php

declare(strict_types=1);

namespace AICR;

final class Policy
{
    private string $minSeverity;
    private int $maxComments;
    private bool $redactSecrets;

    /**
     * @param array{min_severity_to_comment?:string, max_comments?:int, redact_secrets?:bool} $policy
     */
    public function __construct(array $policy)
    {
        $this->minSeverity   = strtolower((string) ($policy['min_severity_to_comment'] ?? 'info'));
        $this->maxComments   = (int) ($policy['max_comments'] ?? 50);
        $this->redactSecrets = (bool) ($policy['redact_secrets'] ?? true);
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     *
     * @return array<int, array<string, mixed>>
     */
    public function apply(array $findings): array
    {
        // Deduplicate and filter by severity, cap to max comments, and redact secrets.
        $out  = [];
        $seen = [];
        foreach ($findings as $f) {
            if ($this->compareSeverity((string) $f['severity'], $this->minSeverity) < 0) {
                continue;
            }
            $fingerprint = $this->fingerprint($f);
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            if ($this->redactSecrets) {
                $f = $this->redact($f);
            }
            $f['fingerprint'] = $fingerprint;
            $out[]            = $f;
            if (count($out) >= $this->maxComments) {
                break;
            }
        }

        return $out;
    }

    private function compareSeverity(string $a, string $b): int
    {
        $rank = ['info' => 0, 'minor' => 1, 'major' => 2, 'critical' => 3];
        $a    = strtolower($a);
        $b    = strtolower($b);
        $ra   = $rank[$a] ?? 0;
        $rb   = $rank[$b] ?? 0;

        return $ra - $rb;
    }

    /**
     * @param array<string, mixed> $f
     */
    private function fingerprint(array $f): string
    {
        $key = $f['file_path'].'|'.$f['start_line'].'|'.$f['end_line'].'|'.$f['rule_id'].'|'.$f['content'];

        return sha1((string) $key);
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private function redact(array $f): array
    {
        // Basic redaction for secrets-like substrings
        $content      = (string) $f['content'];
        $content      = (string) preg_replace('#(?i)(password|secret|api[_-]?key)(\s*[:=]\s*)[^\'\"\s]{4,}#', '$1$2***', $content);
        $f['content'] = $content;

        return $f;
    }
}
