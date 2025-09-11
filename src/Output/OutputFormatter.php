<?php

declare(strict_types=1);

namespace AICR\Output;

/**
 * Formats review findings for output.
 */
interface OutputFormatter
{
    /**
     * @param array<int, array<string, mixed>> $findings
     */
    public function format(array $findings): string;
}
