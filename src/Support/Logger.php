<?php

declare(strict_types=1);

namespace AICR\Support;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;

/**
 * Structured logging service for AICR application.
 * Provides centralized logging with proper PSR-3 compliance and structured output.
 */
final class Logger
{
    private static ?LoggerInterface $instance = null;

    private function __construct() {}

    public static function getInstance(): LoggerInterface
    {
        if (null === self::$instance) {
            self::$instance = self::createLogger();
        }

        return self::$instance;
    }

    public static function setInstance(LoggerInterface $logger): void
    {
        self::$instance = $logger;
    }

    /**
     * Log API interactions with structured context.
     *
     * @param array<string, mixed> $context
     */
    public static function logApiCall(string $provider, string $method, array $context = []): void
    {
        self::getInstance()->info('API call', array_merge([
            'provider'  => $provider,
            'method'    => $method,
            'timestamp' => date('c'),
        ], $context));
    }

    /**
     * Log performance metrics with structured context.
     *
     * @param array<string, mixed> $context
     */
    public static function logPerformance(string $operation, float $duration, array $context = []): void
    {
        self::getInstance()->info('Performance metric', array_merge([
            'operation'   => $operation,
            'duration_ms' => round($duration * 1000, 2),
            'timestamp'   => date('c'),
        ], $context));
    }

    /**
     * Log errors with correlation ID and structured context.
     *
     * @param array<string, mixed> $context
     */
    public static function logError(\Throwable $exception, array $context = []): void
    {
        self::getInstance()->error($exception->getMessage(), array_merge([
            'exception_class' => get_class($exception),
            'code'            => $exception->getCode(),
            'file'            => $exception->getFile(),
            'line'            => $exception->getLine(),
            'trace'           => $exception->getTraceAsString(),
            'correlation_id'  => self::generateCorrelationId(),
            'timestamp'       => date('c'),
        ], $context));
    }

    /**
     * Log configuration changes with structured context.
     *
     * @param array<string, mixed> $context
     */
    public static function logConfigChange(string $action, array $context = []): void
    {
        self::getInstance()->info('Configuration change', array_merge([
            'action'    => $action,
            'timestamp' => date('c'),
        ], $context));
    }

    private static function createLogger(): LoggerInterface
    {
        $logger = new MonologLogger('aicr');

        // Add console handler for errors and above
        $logger->pushHandler(new StreamHandler('php://stderr', Level::Error));

        // Add file handler for all logs if log file is writable
        $logFile = self::getLogFilePath();
        if (null !== $logFile) {
            $logger->pushHandler(new StreamHandler($logFile, Level::Debug));
        }

        return $logger;
    }

    private static function getLogFilePath(): ?string
    {
        $possiblePaths = [
            '/tmp/aicr.log',
            sys_get_temp_dir().'/aicr.log',
        ];

        foreach ($possiblePaths as $path) {
            if (is_writable(dirname($path))) {
                return $path;
            }
        }

        return null;
    }

    private static function generateCorrelationId(): string
    {
        return uniqid('aicr_', true);
    }
}
