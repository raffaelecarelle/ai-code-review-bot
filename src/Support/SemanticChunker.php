<?php

declare(strict_types=1);

namespace AICR\Support;

final class SemanticChunker
{
    /**
     * Chunk changes by semantic context instead of file size.
     *
     * @param array<int, array{file_path: string, unified_diff: string}> $changes
     *
     * @return array<int, array<int, array{file_path: string, unified_diff: string}>>
     */
    public static function chunkByContext(array $changes): array
    {
        $chunks         = [];
        $currentChunk   = [];
        $currentContext = null;

        foreach ($changes as $change) {
            $context = self::detectContext($change['unified_diff']);

            // If context changes, start a new chunk
            if (null !== $currentContext && $context !== $currentContext) {
                if ([] !== $currentChunk) {
                    $chunks[] = $currentChunk;
                }
                $currentChunk = [];
            }

            $currentChunk[] = $change;
            $currentContext = $context;
        }

        // Add the last chunk if not empty
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    /**
     * Group similar changes to reduce verbosity.
     *
     * @param array<int, array{file_path: string, unified_diff: string}> $changes
     *
     * @return array<int, array{file_path: string, unified_diff: string}>
     */
    public static function groupSimilarChanges(array $changes): array
    {
        $grouped = [];
        $groups  = [];

        foreach ($changes as $change) {
            $signature = self::getChangeSignature($change);

            if (!isset($groups[$signature])) {
                $groups[$signature] = [];
            }
            $groups[$signature][] = $change;
        }

        foreach ($groups as $signature => $groupChanges) {
            if (count($groupChanges) > 1) {
                // Create aggregated change for similar items
                $grouped[] = self::createAggregatedChange($groupChanges);
            } else {
                $grouped[] = $groupChanges[0];
            }
        }

        return $grouped;
    }

    /**
     * Detect the semantic context of a code change.
     */
    private static function detectContext(string $content): string
    {
        // Normalize content for analysis
        $content = strtolower($content);

        if (preg_match('/class\s+\w+/', $content)) {
            return 'class_definition';
        }

        if (preg_match('/(public|private|protected)\s+function/', $content)) {
            return 'method';
        }

        if (preg_match('/\$\w+\s*=/', $content)) {
            return 'variable_assignment';
        }

        if (preg_match('/if\s*\(|while\s*\(|for\s*\(/', $content)) {
            return 'control_flow';
        }

        if (preg_match('/namespace\s+|use\s+/', $content)) {
            return 'imports_namespace';
        }

        if (preg_match('/\/\*|\*\/|\/\//', $content)) {
            return 'documentation';
        }

        return 'general';
    }

    /**
     * Create a signature for a change to identify similar changes.
     *
     * @param array{file_path: string, unified_diff: string} $change
     */
    private static function getChangeSignature(array $change): string
    {
        $content = $change['unified_diff'];
        $context = self::detectContext($content);

        // Create signature based on context and change pattern
        $lines        = explode("\n", $content);
        $addedLines   = array_filter($lines, fn ($line) => str_starts_with($line, '+'));
        $removedLines = array_filter($lines, fn ($line) => str_starts_with($line, '-'));

        return $context.'_'.count($addedLines).'_'.count($removedLines);
    }

    /**
     * Create an aggregated change from multiple similar changes.
     *
     * @param array<int, array{file_path: string, unified_diff: string}> $changes
     *
     * @return array{file_path: string, unified_diff: string}
     */
    private static function createAggregatedChange(array $changes): array
    {
        $filePaths = array_map(fn ($change) => $change['file_path'], $changes);
        $context   = self::detectContext($changes[0]['unified_diff']);

        return [
            'file_path' => implode(', ', array_slice($filePaths, 0, 3))
                          .(count($filePaths) > 3 ? ' and '.(count($filePaths) - 3).' more' : ''),
            'unified_diff' => "Aggregated {$context} changes in ".count($changes)." files:\n"
                             .implode("\n---\n", array_map(fn ($change) => $change['unified_diff'], array_slice($changes, 0, 2))),
        ];
    }
}
