<?php

declare(strict_types=1);

namespace AICR\Providers;

/**
 * Shared helpers for LLM-backed providers (OpenAI, Gemini, Anthropic).
 * Provides common prompt construction and robust JSON findings extraction.
 */
abstract class AbstractLLMProvider implements AIProvider
{
    /**
     * @param array<int, array<string, mixed>> $chunks
     */
    protected static function buildPrompt(array $chunks): string
    {
        $lines   = [];
        $lines[] = 'You are an AI Code Review bot. Analyze the following UNIFIED DIFFS per file, considering both added/modified lines (+) and deleted lines (-).';
        $lines[] = 'Focus your reasoning primarily on the resulting code state, but consider deletions for potential regressions, removed validations, or security checks.';
        $lines[] = 'Return a JSON object with key "findings" which is an array of objects with keys:';
        $lines[] = 'rule_id, title, severity, file, start_line, end_line, rationale, suggestion, content';
        $lines[] = 'If no issues, return {"findings":[]}. Do not include commentary.';
        $lines[] = '';
        foreach ($chunks as $chunk) {
            $file    = (string) ($chunk['file'] ?? '');
            $start   = (int) ($chunk['start_line'] ?? 1);
            $lines[] = "FILE: {$file} (~{$start})";
            $lines[] = '---';
            if (isset($chunk['unified_diff']) && is_string($chunk['unified_diff']) && '' !== $chunk['unified_diff']) {
                $lines[] = $chunk['unified_diff'];
            } else {
                // Fallback for legacy chunks containing only added lines
                $entries = isset($chunk['lines']) && is_array($chunk['lines']) ? $chunk['lines']
                          : (isset($chunk['additions']) && is_array($chunk['additions']) ? $chunk['additions'] : []);
                foreach ($entries as $entry) {
                    $ln      = (int) ($entry['line'] ?? 0);
                    $ct      = (string) ($entry['content'] ?? '');
                    $lines[] = '+ '.$ln.': '.$ct;
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    protected static function systemPrompt(): string
    {
        return 'You are a strict assistant that outputs ONLY valid JSON following the requested schema.';
    }

    /**
     * Merge additional prompts from provider options (options['prompts']) into base system and user prompts.
     * Supports strings or arrays for system_append, user_append, and extra (array of strings) appended to the user content.
     *
     * @param array<string, mixed> $options
     *
     * @return array{0:string,1:string} [systemPrompt, userPrompt]
     */
    protected static function mergeAdditionalPrompts(string $system, string $user, array $options): array
    {
        $cfg = isset($options['prompts']) && is_array($options['prompts']) ? $options['prompts'] : [];

        $normalize = static function ($val): array {
            if (null === $val) {
                return [];
            }
            if (is_string($val) && '' !== trim($val)) {
                return [trim($val)];
            }
            if (is_array($val)) {
                $out = [];
                foreach ($val as $v) {
                    if (is_string($v) && '' !== trim($v)) {
                        $out[] = trim($v);
                    }
                }

                return $out;
            }

            return [];
        };

        $sysAppend  = $normalize($cfg['system_append'] ?? null);
        $userAppend = $normalize($cfg['user_append'] ?? null);
        $extra      = $normalize($cfg['extra'] ?? []);

        if (!empty($sysAppend)) {
            $system = rtrim($system)."\n\n".implode("\n\n", $sysAppend);
        }

        $userParts = [rtrim($user)];
        if (!empty($userAppend)) {
            $userParts[] = implode("\n\n", $userAppend);
        }
        if (!empty($extra)) {
            $userParts[] = implode("\n\n", $extra);
        }
        $user = implode("\n\n", array_filter($userParts, static fn ($s) => '' !== trim((string) $s)));

        return [$system, $user];
    }

    /**
     * Attempts to parse a provider response text into an array of findings.
     * Accepts direct JSON or JSON within fenced code blocks.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function extractFindingsFromText(string $content): array
    {
        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            // Try to extract JSON if wrapped in code fences
            if (1 === preg_match('/```(?:json)?\n(.+?)\n```/s', $content, $m)) {
                $parsed = json_decode($m[1], true);
            }
            // Try to extract inline JSON from text
            if (!is_array($parsed) && 1 === preg_match('/\{.*"findings".*\}/s', $content, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }
        if (!is_array($parsed)) {
            return [];
        }
        $findings = $parsed['findings'] ?? [];

        return is_array($findings) ? $findings : [];
    }
}
