<?php

declare(strict_types=1);

namespace AICR\Support;

/**
 * Provides input sanitization and validation utilities.
 * Addresses security concerns with user-provided data processing.
 */
final class InputSanitizer
{
    /** @var string Regex pattern for valid branch names */
    private const BRANCH_NAME_PATTERN = '/^[a-zA-Z0-9._\/-]+$/';

    /** @var string Regex pattern for valid repository names */
    private const REPO_NAME_PATTERN = '/^[a-zA-Z0-9._-]+$/';

    /** @var string Regex pattern for valid file paths (restrictive) */
    private const FILE_PATH_PATTERN = '/^[a-zA-Z0-9._\/\-\s]+$/';

    /** @var int Maximum length for branch names */
    private const MAX_BRANCH_NAME_LENGTH = 255;

    /** @var int Maximum length for repository names */
    private const MAX_REPO_NAME_LENGTH = 100;

    /** @var int Maximum length for file paths */
    private const MAX_FILE_PATH_LENGTH = 1000;

    /**
     * Sanitizes and validates a branch name.
     *
     * @param string $branchName Raw branch name input
     *
     * @throws \InvalidArgumentException If branch name is invalid
     */
    public static function sanitizeBranchName(string $branchName): string
    {
        $trimmed = trim($branchName);

        if ('' === $trimmed) {
            throw new \InvalidArgumentException('Branch name cannot be empty');
        }

        if (strlen($trimmed) > self::MAX_BRANCH_NAME_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('Branch name too long (max %d characters)', self::MAX_BRANCH_NAME_LENGTH)
            );
        }

        if (!preg_match(self::BRANCH_NAME_PATTERN, $trimmed)) {
            throw new \InvalidArgumentException('Branch name contains invalid characters');
        }

        return $trimmed;
    }

    /**
     * Sanitizes and validates a repository name.
     *
     * @param string $repoName Raw repository name input
     *
     * @throws \InvalidArgumentException If repository name is invalid
     */
    public static function sanitizeRepositoryName(string $repoName): string
    {
        $trimmed = trim($repoName);

        if ('' === $trimmed) {
            throw new \InvalidArgumentException('Repository name cannot be empty');
        }

        if (strlen($trimmed) > self::MAX_REPO_NAME_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('Repository name too long (max %d characters)', self::MAX_REPO_NAME_LENGTH)
            );
        }

        if (!preg_match(self::REPO_NAME_PATTERN, $trimmed)) {
            throw new \InvalidArgumentException('Repository name contains invalid characters');
        }

        return $trimmed;
    }

    /**
     * Sanitizes and validates a file path.
     *
     * @param string $filePath Raw file path input
     *
     * @throws \InvalidArgumentException If file path is invalid
     */
    public static function sanitizeFilePath(string $filePath): string
    {
        $trimmed = trim($filePath);

        if ('' === $trimmed) {
            throw new \InvalidArgumentException('File path cannot be empty');
        }

        if (strlen($trimmed) > self::MAX_FILE_PATH_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('File path too long (max %d characters)', self::MAX_FILE_PATH_LENGTH)
            );
        }

        // Prevent path traversal attacks
        if (str_contains($trimmed, '..') || str_contains($trimmed, '\\')) {
            throw new \InvalidArgumentException('File path contains invalid sequences');
        }

        if (!preg_match(self::FILE_PATH_PATTERN, $trimmed)) {
            throw new \InvalidArgumentException('File path contains invalid characters');
        }

        return $trimmed;
    }

    /**
     * Sanitizes API response data by removing potentially harmful content.
     *
     * @param array<string, mixed> $data Raw API response data
     *
     * @return array<string, mixed> Sanitized data
     */
    public static function sanitizeApiResponse(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $sanitizedKey = self::sanitizeArrayKey((string) $key);

            if (is_array($value)) {
                $sanitized[$sanitizedKey] = self::sanitizeApiResponse($value);
            } elseif (is_string($value)) {
                $sanitized[$sanitizedKey] = self::sanitizeStringValue($value);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $sanitized[$sanitizedKey] = $value;
            }
            // Skip null and other types for security
        }

        return $sanitized;
    }

    /**
     * Validates and sanitizes a URL.
     *
     * @param string $url Raw URL input
     *
     * @throws \InvalidArgumentException If URL is invalid
     */
    public static function sanitizeUrl(string $url): string
    {
        $trimmed = trim($url);

        if ('' === $trimmed) {
            throw new \InvalidArgumentException('URL cannot be empty');
        }

        if (strlen($trimmed) > 2000) {
            throw new \InvalidArgumentException('URL too long (max 2000 characters)');
        }

        if (!filter_var($trimmed, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid URL format');
        }

        $parsedUrl = parse_url($trimmed);
        if (false === $parsedUrl) {
            throw new \InvalidArgumentException('Unable to parse URL');
        }

        // Only allow HTTP and HTTPS schemes
        $scheme = $parsedUrl['scheme'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('URL must use HTTP or HTTPS scheme');
        }

        return $trimmed;
    }

    /**
     * Sanitizes commit SHA identifiers.
     *
     * @param string $sha Raw SHA input
     *
     * @throws \InvalidArgumentException If SHA is invalid
     */
    public static function sanitizeCommitSha(string $sha): string
    {
        $trimmed = trim($sha);

        if ('' === $trimmed) {
            throw new \InvalidArgumentException('Commit SHA cannot be empty');
        }

        // Git SHA can be 7-40 characters (short to full)
        if (strlen($trimmed) < 7 || strlen($trimmed) > 40) {
            throw new \InvalidArgumentException('Commit SHA must be 7-40 characters long');
        }

        if (!preg_match('/^[a-fA-F0-9]+$/', $trimmed)) {
            throw new \InvalidArgumentException('Commit SHA must contain only hexadecimal characters');
        }

        return strtolower($trimmed);
    }

    /**
     * Sanitizes array keys to prevent injection attacks.
     */
    private static function sanitizeArrayKey(string $key): string
    {
        // Remove non-alphanumeric characters except underscore
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $key);

        if (null === $sanitized || '' === $sanitized) {
            throw new \InvalidArgumentException('Invalid array key after sanitization');
        }

        return $sanitized;
    }

    /**
     * Sanitizes string values by escaping special characters.
     */
    private static function sanitizeStringValue(string $value): string
    {
        // Limit string length for security
        if (strlen($value) > 10000) {
            $value = substr($value, 0, 10000);
        }

        // Remove null bytes and other control characters
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    }
}
