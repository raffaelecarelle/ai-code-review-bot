<?php

declare(strict_types=1);

namespace AICR\Config;

/**
 * Centralized constants to replace magic numbers and strings throughout the codebase.
 * Improves maintainability and reduces the risk of typos in repeated values.
 */
final class Constants
{
    // HTTP Status Code Constants
    public const HTTP_OK                    = 200;
    public const HTTP_CREATED               = 201;
    public const HTTP_BAD_REQUEST           = 400;
    public const HTTP_UNAUTHORIZED          = 401;
    public const HTTP_FORBIDDEN             = 403;
    public const HTTP_NOT_FOUND             = 404;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;

    // String Length Limits
    public const MAX_BRANCH_NAME_LENGTH  = 255;
    public const MAX_REPO_NAME_LENGTH    = 100;
    public const MAX_FILE_PATH_LENGTH    = 1000;
    public const MAX_URL_LENGTH          = 2000;
    public const MAX_STRING_VALUE_LENGTH = 10000;

    // Git SHA Constants
    public const MIN_SHA_LENGTH = 7;
    public const MAX_SHA_LENGTH = 40;

    // Regex Patterns
    public const BRANCH_NAME_PATTERN  = '/^[a-zA-Z0-9._\/-]+$/';
    public const REPO_NAME_PATTERN    = '/^[a-zA-Z0-9._-]+$/';
    public const FILE_PATH_PATTERN    = '/^[a-zA-Z0-9._\/\-\s]+$/';
    public const SHA_PATTERN          = '/^[a-fA-F0-9]+$/';
    public const JSON_EXTRACT_PATTERN = '/```(?:json)?\n(.+?)\n```/s';
    public const JSON_INLINE_PATTERN  = '/\{.*"findings".*\}/s';

    // Valid HTTP Schemes
    public const VALID_HTTP_SCHEMES = ['http', 'https'];

    // Special Characters and Sequences
    public const PATH_TRAVERSAL_SEQUENCE = '..';
    public const WINDOWS_PATH_SEPARATOR  = '\\';

    private function __construct()
    {
        // Prevent instantiation
    }
}
