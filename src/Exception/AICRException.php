<?php

declare(strict_types=1);

namespace AICR\Exception;

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
        $logLevel   = $this->getLogLevel();
        $logMessage = sprintf(
            '[%s] %s',
            get_class($this),
            $this->getMessage()
        );

        $context = array_merge($this->context, [
            'exception_class' => get_class($this),
            'code'            => $this->getCode(),
            'file'            => $this->getFile(),
            'line'            => $this->getLine(),
        ]);

        // Use error_log for now, can be replaced with proper logger
        error_log(sprintf(
            '[%s] %s - Context: %s',
            strtoupper($logLevel),
            $logMessage,
            json_encode($context)
        ));
    }

    /**
     * Get appropriate log level for this exception.
     */
    protected function getLogLevel(): string
    {
        return 'error';
    }
}
