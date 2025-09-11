<?php

declare(strict_types=1);

namespace AICR\Output;

final class JsonFormatter implements OutputFormatter
{
    /**
     * @param array<int, array<string, mixed>> $findings
     */
    public function format(array $findings): string
    {
        return (string) json_encode($findings, JSON_PRETTY_PRINT);
    }
}
