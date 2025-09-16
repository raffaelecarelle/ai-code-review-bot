<?php

declare(strict_types=1);

namespace AICR\Output;

final class SummaryFormatter implements OutputFormatter
{
    public const MSG_NO_FINDINGS = "No findings.\n";

    /**
     * @param array<int, array<string, mixed>> $findings
     */
    public function format(array $findings): string
    {
        if ([] === $findings) {
            return self::MSG_NO_FINDINGS;
        }

        $out = 'Findings ('.count($findings)."):\n";
        foreach ($findings as $f) {
            $out .= sprintf(
                "- [%s] %s (%s:%d-%d) %s\n  Suggestion: %s\n",
                strtoupper((string) ($f['severity'] ?? '')),
                (string) ($f['rule_id'] ?? ''),
                (string) ($f['file'] ?? ''),
                (int) ($f['start_line'] ?? 0),
                (int) ($f['end_line'] ?? 0),
                (string) ($f['rationale'] ?? ''),
                (string) ($f['suggestion'] ?? '')
            );
        }

        return $out;
    }
}
