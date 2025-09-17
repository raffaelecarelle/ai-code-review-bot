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

    // Provider Constants
    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_GEMINI    = 'gemini';
    public const PROVIDER_OLLAMA    = 'ollama';
    public const PROVIDER_MOCK      = 'mock';

    // VCS Platform Constants
    public const VCS_GITHUB    = 'github';
    public const VCS_GITLAB    = 'gitlab';
    public const VCS_BITBUCKET = 'bitbucket';

    // Default Timeout Values (in seconds)
    public const DEFAULT_HTTP_TIMEOUT = 60.0;
    public const DEFAULT_CACHE_TTL    = 3600; // 1 hour
    public const DEFAULT_API_TIMEOUT  = 30.0;

    // File Size Limits (in bytes)
    public const MAX_DIFF_FILE_SIZE       = 1048576; // 1MB
    public const MAX_CONFIG_FILE_SIZE     = 65536; // 64KB
    public const MAX_GUIDELINES_FILE_SIZE = 262144; // 256KB

    // Token Budget Constants
    public const DEFAULT_TOKEN_BUDGET = 8000;
    public const MIN_TOKEN_BUDGET     = 1000;
    public const MAX_TOKEN_BUDGET     = 32000;
    public const TOKEN_SAFETY_MARGIN  = 100;

    // Compression Thresholds
    public const COMPRESSION_THRESHOLD_TOKENS = 1000;
    public const COMPRESSION_RATIO_THRESHOLD  = 0.7;
    public const MIN_REMAINING_BUDGET         = 100;

    // Cache Configuration
    public const MAX_CACHE_SIZE_BYTES    = 52428800; // 50MB
    public const CACHE_CLEANUP_THRESHOLD = 0.9; // 90% of max size
    public const DEFAULT_CACHE_DIR       = 'aicr_cache';

    // Batch Processing Constants
    public const MAX_BATCH_SIZE              = 25;
    public const SMALL_BATCH_SIZE            = 10;
    public const MEDIUM_BATCH_SIZE           = 20;
    public const BATCH_SIZE_THRESHOLD_SMALL  = 10;
    public const BATCH_SIZE_THRESHOLD_MEDIUM = 50;
    public const BATCH_SIZE_THRESHOLD_LARGE  = 200;

    // String Length Limits
    public const MAX_BRANCH_NAME_LENGTH  = 255;
    public const MAX_REPO_NAME_LENGTH    = 100;
    public const MAX_FILE_PATH_LENGTH    = 1000;
    public const MAX_URL_LENGTH          = 2000;
    public const MAX_STRING_VALUE_LENGTH = 10000;

    // Git SHA Constants
    public const MIN_SHA_LENGTH = 7;
    public const MAX_SHA_LENGTH = 40;

    // File Extensions
    public const YAML_EXTENSIONS      = ['yml', 'yaml'];
    public const JSON_EXTENSION       = 'json';
    public const CACHE_FILE_EXTENSION = '.cache';
    public const TEMP_FILE_EXTENSION  = '.tmp';

    // Directory Permissions
    public const DIR_PERMISSIONS      = 0755;
    public const TEMP_DIR_PERMISSIONS = 0700;

    // API Version Constants
    public const ANTHROPIC_API_VERSION = '2023-06-01';
    public const GITHUB_API_VERSION    = 'v3';
    public const GITLAB_API_VERSION    = 'v4';

    // Default Model Names
    public const DEFAULT_OPENAI_MODEL    = 'gpt-4o-mini';
    public const DEFAULT_ANTHROPIC_MODEL = 'claude-3-5-sonnet-20240620';
    public const DEFAULT_GEMINI_MODEL    = 'gemini-1.5-pro';

    // Default API Endpoints
    public const OPENAI_DEFAULT_ENDPOINT      = 'https://api.openai.com/v1/chat/completions';
    public const ANTHROPIC_DEFAULT_ENDPOINT   = 'https://api.anthropic.com/v1/messages';
    public const GEMINI_DEFAULT_ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    // HTTP Headers
    public const HEADER_CONTENT_TYPE  = 'Content-Type';
    public const HEADER_AUTHORIZATION = 'Authorization';
    public const HEADER_USER_AGENT    = 'User-Agent';
    public const CONTENT_TYPE_JSON    = 'application/json';

    // Temperature Settings
    public const DEFAULT_TEMPERATURE = 0.0;
    public const MIN_TEMPERATURE     = 0.0;
    public const MAX_TEMPERATURE     = 2.0;

    // Token Configuration
    public const DEFAULT_MAX_TOKENS = 2048;
    public const MIN_TOKENS         = 1;
    public const MAX_TOKENS         = 8192;

    // Regex Patterns
    public const BRANCH_NAME_PATTERN  = '/^[a-zA-Z0-9._\/-]+$/';
    public const REPO_NAME_PATTERN    = '/^[a-zA-Z0-9._-]+$/';
    public const FILE_PATH_PATTERN    = '/^[a-zA-Z0-9._\/\-\s]+$/';
    public const SHA_PATTERN          = '/^[a-fA-F0-9]+$/';
    public const JSON_EXTRACT_PATTERN = '/```(?:json)?\n(.+?)\n```/s';
    public const JSON_INLINE_PATTERN  = '/\{.*"findings".*\}/s';

    // Error Messages
    public const ERROR_MISSING_API_KEY    = 'API key is required but not provided';
    public const ERROR_INVALID_CONFIG     = 'Configuration validation failed';
    public const ERROR_FILE_NOT_READABLE  = 'File is not readable or does not exist';
    public const ERROR_CACHE_WRITE_FAILED = 'Failed to write cache file';
    public const ERROR_TEMP_FILE_CREATION = 'Unable to create temporary file';

    // Resource Cleanup
    public const CLEANUP_REGISTERED_FLAG = 'cleanup_registered';
    public const DEFAULT_TEMP_PREFIX     = 'aicr_';
    public const TEMP_DIR_SUFFIX         = '_dir';

    // Valid HTTP Schemes
    public const VALID_HTTP_SCHEMES = ['http', 'https'];

    // Configuration Keys
    public const CONFIG_PROVIDERS  = 'providers';
    public const CONFIG_CONTEXT    = 'context';
    public const CONFIG_POLICY     = 'policy';
    public const CONFIG_VCS        = 'vcs';
    public const CONFIG_EXCLUDES   = 'excludes';
    public const CONFIG_GUIDELINES = 'guidelines';

    // Special Characters and Sequences
    public const PATH_TRAVERSAL_SEQUENCE = '..';
    public const WINDOWS_PATH_SEPARATOR  = '\\';
    public const BEARER_PREFIX           = 'Bearer ';

    private function __construct()
    {
        // Prevent instantiation
    }
}
