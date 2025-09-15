<?php

declare(strict_types=1);

namespace AICR;

final class Policy
{
    private string $minSeverity;
    private int $maxComments;
    private bool $redactSecrets;
    private bool $consolidateSimilar;
    private int $maxFindingsPerFile;

    /** @var array<string, int> */
    private array $severityLimits;

    /**
     * @param array{
     *     min_severity_to_comment?: string,
     *     max_comments?: int,
     *     redact_secrets?: bool,
     *     consolidate_similar_findings?: bool,
     *     max_findings_per_file?: int,
     *     severity_limits?: array<string, int>
     * } $policy
     */
    public function __construct(array $policy)
    {
        $this->minSeverity        = strtolower((string) ($policy['min_severity_to_comment'] ?? 'info'));
        $this->maxComments        = (int) ($policy['max_comments'] ?? 50);
        $this->redactSecrets      = (bool) ($policy['redact_secrets'] ?? true);
        $this->consolidateSimilar = (bool) ($policy['consolidate_similar_findings'] ?? false);
        $this->maxFindingsPerFile = (int) ($policy['max_findings_per_file'] ?? 5);
        $this->severityLimits     = $policy['severity_limits'] ?? [];
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

        if ($this->consolidateSimilar) {
            $findings = $this->aggregateSimilarFindings($findings);
        }

        $findings = $this->applyOutputLimits($findings);

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

    /**
     * Aggregate similar findings to reduce verbosity.
     *
     * @param array<int, array<string, mixed>> $findings
     *
     * @return array<int, array<string, mixed>>
     */
    public function aggregateSimilarFindings(array $findings): array
    {
        if (!$this->consolidateSimilar) {
            return $findings;
        }

        $aggregated = [];
        $groups     = [];

        foreach ($findings as $finding) {
            $signature = $this->getFindingSignature($finding);

            if (!isset($groups[$signature])) {
                $groups[$signature] = [];
            }
            $groups[$signature][] = $finding;
        }

        foreach ($groups as $signature => $groupFindings) {
            if (count($groupFindings) > 1) {
                // Create aggregated finding
                $aggregated[] = $this->createAggregatedFinding($groupFindings);
            } else {
                $aggregated[] = $groupFindings[0];
            }
        }

        return $aggregated;
    }

    /**
     * Apply output limits including per-file and severity limits.
     *
     * @param array<int, array<string, mixed>> $findings
     *
     * @return array<int, array<string, mixed>>
     */
    public function applyOutputLimits(array $findings): array
    {
        $limited          = [];
        $fileCounters     = [];
        $severityCounters = [];

        foreach ($findings as $finding) {
            $filePath = (string) ($finding['file_path'] ?? '');
            $severity = strtolower((string) ($finding['severity'] ?? 'info'));

            // Check per-file limit
            if (!isset($fileCounters[$filePath])) {
                $fileCounters[$filePath] = 0;
            }

            if ($fileCounters[$filePath] >= $this->maxFindingsPerFile) {
                continue;
            }

            // Check severity limit
            if (!isset($severityCounters[$severity])) {
                $severityCounters[$severity] = 0;
            }

            $severityLimit = $this->severityLimits[$severity] ?? 999;
            if ($severityCounters[$severity] >= $severityLimit) {
                continue;
            }

            $limited[] = $finding;
            ++$fileCounters[$filePath];
            ++$severityCounters[$severity];
        }

        return $limited;
    }

    /**
     * Get finding signature for similarity detection.
     *
     * @param array{
     *     rule_id?: string,
     *     severity?: string,
     *     title?: string
     * } $finding
     */
    private function getFindingSignature(array $finding): string
    {
        $ruleId   = (string) ($finding['rule_id'] ?? '');
        $severity = (string) ($finding['severity'] ?? '');
        $title    = (string) ($finding['title'] ?? '');

        // Create signature based on rule type and severity
        return md5($ruleId.'|'.$severity.'|'.substr($title, 0, 20));
    }

    /**
     * Create aggregated finding from similar findings.
     *
     * @param array<int, array<string, mixed>> $findings
     *
     * @return array<string, mixed>
     */
    private function createAggregatedFinding(array $findings): array
    {
        $first     = $findings[0];
        $filePaths = array_unique(array_map(fn ($f) => $f['file_path'] ?? '', $findings));

        return [
            'rule_id'   => $first['rule_id'] ?? '',
            'title'     => 'Aggregated: '.($first['title'] ?? ''),
            'severity'  => $first['severity'] ?? 'info',
            'file_path' => implode(', ', array_slice($filePaths, 0, 3))
                          .(count($filePaths) > 3 ? ' +'.(count($filePaths) - 3).' more' : ''),
            'start_line'       => $first['start_line'] ?? 0,
            'end_line'         => $first['end_line'] ?? 0,
            'rationale'        => 'Found in '.count($findings).' locations',
            'suggestion'       => $first['suggestion'] ?? '',
            'content'          => 'Aggregated finding across multiple files',
            'aggregated_count' => count($findings),
        ];
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

        return sha1($key);
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
        $content      = preg_replace('#(?i)(password|secret|api[_-]?key)(\s*[:=]\s*)[^\'\"\s]{4,}#', '$1$2***', $content);
        $f['content'] = $content;

        return $f;
    }
}
