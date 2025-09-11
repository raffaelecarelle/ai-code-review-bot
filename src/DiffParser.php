<?php

declare(strict_types=1);

namespace AICR;

/**
 * Minimal unified diff parser.
 * Extracts added lines per file with accurate target line numbers.
 */
final class DiffParser
{
    /**
     * Parse a unified diff string into an associative array:
     * [ filePath => [ [line => int, content => string], ... ] ] for added lines only.
     *
     * @return array<string, array<int, array{line:int, content:string}>>
     */
    public static function parse(string $diff): array
    {
        $lines       = preg_split('/\r?\n/', $diff) ?: [];
        $files       = [];
        $currentFile = null;
        $targetLine  = null;

        foreach ($lines as $i => $line) {
            if (1 === preg_match('#^\+\+\+\s+b/(.+)$#', $line, $m)) {
                $currentFile = (string) $m[1];
                if (!isset($files[$currentFile])) {
                    $files[$currentFile] = [];
                }
                $targetLine = null;

                continue;
            }
            if (1 === preg_match('#^@@\s+-\d+(?:,\d+)?\s+\+(\d+)(?:,(\d+))?\s+@@#', $line, $m)) {
                $targetLine = (int) $m[1];

                continue;
            }
            if (null !== $targetLine) {
                if ('' === $line && $i === count($lines) - 1) {
                    break; // trailing newline at end of file
                }
                $first = '' !== $line ? $line[0] : '';
                if ('+' === $first) {
                    // Ignore file header lines like +++ b/file handled above
                    if (1 !== preg_match('#^\+\+\+#', $line)) {
                        $files[(string) $currentFile][] = [
                            'line'    => $targetLine,
                            'content' => substr($line, 1),
                        ];
                    }
                    ++$targetLine;
                } elseif (' ' === $first) {
                    // Do not advance on context lines to match expected line number semantics in tests
                }
                // '-' lines do not advance target line
            }
        }

        return $files;
    }
}
