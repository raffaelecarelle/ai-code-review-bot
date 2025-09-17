<?php

declare(strict_types=1);

namespace AICR\Exception;

use AICR\Support\Logger;

/**
 * Base exception class for all AICR-specific exceptions.
 * Provides sanitized error messages and logging integration.
 */
abstract class AICRException extends \Exception
{
    protected bool $shouldLog       = true;
    protected string $publicMessage = '';

    /** @var array<string, mixed> */
    protected array $context = [];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        string $publicMessage = '',
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->publicMessage = $publicMessage ?: $this->getDefaultPublicMessage();
        $this->context       = $context;

        if ($this->shouldLog) {
            $this->logError();
        }
    }

    /**
     * Get sanitized message safe for user display.
     */
    public function getPublicMessage(): string
    {
        return $this->publicMessage;
    }

    /**
     * Get additional context for logging.
     *
     * @return array<string, mixed> Additional context data for logging
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Check if this exception should be logged.
     */
    public function shouldLog(): bool
    {
        return $this->shouldLog;
    }

    /**
     * Get default public message for this exception type.
     */
    abstract protected function getDefaultPublicMessage(): string;

    /**
     * Log the error with appropriate level and context.
     */
    protected function logError(): void
    {
        Logger::logError($this, $this->context);
    }

    /**
     * Get appropriate log level for this exception.
     */
    protected function getLogLevel(): string
    {
        return 'error';
    }
}
