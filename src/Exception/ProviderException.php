<?php

declare(strict_types=1);

namespace AICR\Exception;

/**
 * Exception thrown for AI provider-related errors.
 * Sanitizes API error messages to prevent information disclosure.
 */
class ProviderException extends AICRException
{
    /**
     * Create exception for HTTP/API errors with sanitized messages.
     *
     * @param array<string, mixed> $context Additional context information
     */
    public static function fromHttpError(int $statusCode, string $provider, string $internalMessage = '', array $context = []): self
    {
        $publicMessage = match (true) {
            $statusCode >= 500                         => 'AI service temporarily unavailable. Please try again later.',
            429 === $statusCode                        => 'Rate limit exceeded. Please wait before trying again.',
            401 === $statusCode || 403 === $statusCode => 'Authentication failed. Please check your API credentials.',
            $statusCode >= 400                         => 'Invalid request. Please check your configuration.',
            default                                    => 'AI service error occurred. Please try again later.',
        };

        $context = array_merge($context, [
            'provider'    => $provider,
            'status_code' => $statusCode,
        ]);

        return new self(
            $internalMessage ?: "Provider {$provider} returned status {$statusCode}",
            $statusCode,
            null,
            $publicMessage,
            $context
        );
    }

    /**
     * Create exception for JSON parsing errors.
     *
     * @param array<string, mixed> $context Additional context information
     */
    public static function fromParsingError(string $provider, string $content, array $context = []): self
    {
        $context = array_merge($context, [
            'provider'        => $provider,
            'content_length'  => strlen($content),
            'content_preview' => substr($content, 0, 100),
        ]);

        return new self(
            "Failed to parse response from {$provider}",
            0,
            null,
            'Invalid response from AI service. Please try again.',
            $context
        );
    }

    protected function getDefaultPublicMessage(): string
    {
        return 'AI provider error occurred. Please try again later.';
    }

    protected function getLogLevel(): string
    {
        return 'error';
    }
}
