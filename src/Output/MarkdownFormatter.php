<?php

declare(strict_types=1);

namespace AICR\Output;

/**
 * Example Markdown formatter demonstrating custom output formatting.
 */
final class MarkdownFormatter implements OutputFormatter
{
    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     */
    public function format(array $findings): string
    {
        if (empty($findings)) {
            return $this->renderNoFindings();
        }

        $output = $this->renderHeader($findings);
        $output .= $this->renderSummary($findings);
        $output .= $this->renderFindings($findings);

        if ($this->options['include_metadata'] ?? true) {
            $output .= $this->renderMetadata();
        }

        return $output;
    }

    /**
     * @param array<int, array{
     *     file?: string,
     *     title?: string,
     *     severity?: string,
     *     start_line?: int,
     *     rationale?: string,
     *     suggestion?: string,
     *     rule_id?: string
     * }> $findings
     */
    private function renderHeader(array $findings): string
    {
        $count = count($findings);

        return "# 🔍 Code Review Results\n\n";
    }

    /**
     * @param array<int, array{
     *     file?: string,
     *     title?: string,
     *     severity?: string,
     *     start_line?: int,
     *     rationale?: string,
     *     suggestion?: string,
     *     rule_id?: string
     * }> $findings
     */
    private function renderSummary(array $findings): string
    {
        $count         = count($findings);
        $severityCount = $this->countBySeverity($findings);

        $output = "## 📊 Summary\n\n";
        $output .= "**Total Issues:** {$count}\n\n";

        if (!empty($severityCount)) {
            $output .= "### By Severity\n\n";
            foreach ($severityCount as $severity => $count) {
                $emoji = $this->getSeverityEmoji($severity);
                $output .= "- {$emoji} **{$severity}**: {$count}\n";
            }
            $output .= "\n";
        }

        return $output;
    }

    /**
     * @param array<int, array{
     *     file?: string,
     *     title?: string,
     *     severity?: string,
     *     start_line?: int,
     *     rationale?: string,
     *     suggestion?: string,
     *     rule_id?: string
     * }> $findings
     */
    private function renderFindings(array $findings): string
    {
        $output = "## 🚨 Issues Found\n\n";

        $groupedByFile = $this->groupFindingsByFile($findings);

        foreach ($groupedByFile as $file => $fileFindings) {
            $output .= "### 📄 `{$file}`\n\n";

            foreach ($fileFindings as $finding) {
                $output .= $this->renderSingleFinding($finding);
            }
        }

        return $output;
    }

    /**
     * @param array{
     *     title?: string,
     *     severity?: string,
     *     start_line?: int,
     *     rationale?: string,
     *     suggestion?: string,
     *     rule_id?: string
     * } $finding
     */
    private function renderSingleFinding(array $finding): string
    {
        $title      = $finding['title'] ?? 'Unknown Issue';
        $severity   = $finding['severity'] ?? 'unknown';
        $line       = $finding['start_line'] ?? 0;
        $rationale  = $finding['rationale'] ?? '';
        $suggestion = $finding['suggestion'] ?? '';
        $ruleId     = $finding['rule_id'] ?? '';

        $emoji = $this->getSeverityEmoji($severity);

        $output = "#### {$emoji} {$title}\n\n";
        $output .= "- **Line:** {$line}\n";
        $output .= "- **Severity:** {$severity}\n";
        if ($ruleId) {
            $output .= "- **Rule:** `{$ruleId}`\n";
        }
        $output .= "\n";

        if ($rationale) {
            $output .= "**Rationale:** {$rationale}\n\n";
        }

        if ($suggestion) {
            $output .= "**Suggestion:** {$suggestion}\n\n";
        }

        $output .= "---\n\n";

        return $output;
    }

    private function renderNoFindings(): string
    {
        return "# 🎉 Code Review Results\n\n## ✅ No Issues Found\n\nGreat work! No issues were identified in the code review.\n";
    }

    private function renderMetadata(): string
    {
        $timestamp = date('Y-m-d H:i:s T');

        return "## 📋 Metadata\n\n- **Generated:** {$timestamp}\n- **Formatter:** Markdown (Custom Plugin)\n";
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     *
     * @return array<string, int>
     */
    private function countBySeverity(array $findings): array
    {
        $counts = [];
        foreach ($findings as $finding) {
            $severity          = $finding['severity'] ?? 'unknown';
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }

        // Sort by severity priority instead of count
        uksort($counts, function ($a, $b) {
            return $this->getSeverityPriority($b) <=> $this->getSeverityPriority($a);
        });

        return $counts;
    }

    /**
     * Get numeric priority for severity levels (higher number = higher priority).
     */
    private function getSeverityPriority(string $severity): int
    {
        return match (strtolower($severity)) {
            'error'   => 6,
            'high'    => 5,
            'warning' => 4,
            'medium'  => 3,
            'info'    => 2,
            'low'     => 1,
            default   => 0, // unknown
        };
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupFindingsByFile(array $findings): array
    {
        $grouped = [];
        foreach ($findings as $finding) {
            $file             = $finding['file'] ?? 'unknown';
            $grouped[$file][] = $finding;
        }
        ksort($grouped);

        return $grouped;
    }

    private function getSeverityEmoji(string $severity): string
    {
        return match (strtolower($severity)) {
            'high', 'error' => '🔴',
            'medium', 'warning' => '🟡',
            'low', 'info' => '🔵',
            default => '⚪',
        };
    }
}
