<?php

declare(strict_types=1);

namespace AICR\Exception;

/**
 * Exception thrown for configuration-related errors.
 */
class ConfigurationException extends AICRException
{
    protected function getDefaultPublicMessage(): string
    {
        return 'Configuration error occurred. Please check your configuration file.';
    }

    protected function getLogLevel(): string
    {
        return 'warning';
    }
}
