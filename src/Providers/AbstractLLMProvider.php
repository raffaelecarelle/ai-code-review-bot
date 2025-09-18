<?php

declare(strict_types=1);

namespace AICR\Providers;

use AICR\Exception\ConfigurationException;
use AICR\Exception\ProviderException;
use AICR\Support\ApiCache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Shared helpers for LLM-backed providers (OpenAI, Gemini, Anthropic).
 * Provides common prompt construction, HTTP client setup, error handling, and robust JSON findings extraction.
 */
abstract class AbstractLLMProvider implements AIProvider
{
    private ?ApiCache $cache = null;

    /**
     * Initializes API cache if enabled in configuration.
     *
     * @param array<string, mixed> $cacheConfig Cache configuration from config
     */
    protected function initializeCache(array $cacheConfig): void
    {
        $enabled = (bool) ($cacheConfig['enabled'] ?? false);
        if (!$enabled) {
            return;
        }

        $cacheDir = isset($cacheConfig['directory']) && is_string($cacheConfig['directory'])
            ? $cacheConfig['directory']
            : null;
        $defaultTtl = (int) ($cacheConfig['default_ttl'] ?? 3600);

        $this->cache = new ApiCache($cacheDir, $defaultTtl);
    }

    /**
     * Creates a standard HTTP client with common configuration.
     *
     * @param string                $baseUri The API endpoint base URI
     * @param array<string, string> $headers HTTP headers for the client
     * @param float                 $timeout Request timeout in seconds
     */
    protected function createHttpClient(string $baseUri, array $headers = [], float $timeout = 60.0): Client
    {
        $defaultHeaders = ['Content-Type' => 'application/json'];
        $mergedHeaders  = array_merge($defaultHeaders, $headers);

        return new Client([
            'base_uri' => $baseUri,
            'headers'  => $mergedHeaders,
            'timeout'  => $timeout,
        ]);
    }

    /**
     * Makes a cached HTTP POST request to the API.
     *
     * @param Client               $client The HTTP client to use
     * @param string               $url    The request URL (relative to base_uri)
     * @param array<string, mixed> $data   The JSON data to send
     * @param string               $method HTTP method (POST, GET, etc.)
     *
     * @return array<string, mixed> The decoded JSON response
     *
     * @throws RequestException If the HTTP request fails
     */
    protected function cachedRequest(Client $client, string $url, array $data, string $method = 'POST'): array
    {
        // If caching is not enabled, make the request directly
        if (null === $this->cache) {
            $response = $client->request($method, $url, ['json' => $data]);

            return json_decode((string) $response->getBody(), true) ?: [];
        }

        // Generate cache key for this request
        $baseUri  = (string) $client->getConfig('base_uri');
        $fullUrl  = rtrim($baseUri, '/').'/'.ltrim($url, '/');
        $cacheKey = ApiCache::generateApiKey($method, $fullUrl, $data);

        // Try to get cached response
        $cached = $this->cache->get($cacheKey);
        if (null !== $cached) {
            return $cached;
        }

        // Make the actual API request
        $response     = $client->request($method, $url, ['json' => $data]);
        $responseData = json_decode((string) $response->getBody(), true) ?: [];

        // Cache the response
        $this->cache->set($cacheKey, $responseData);

        return $responseData;
    }

    /**
     * Validates that an API key is provided and non-empty.
     *
     * @param string $apiKey       The API key to validate
     * @param string $providerName The provider name for error messages
     *
     * @throws ConfigurationException If API key is missing or empty
     */
    protected function validateApiKey(string $apiKey, string $providerName): void
    {
        if ('' === $apiKey) {
            throw new ConfigurationException("{$providerName} requires api_key (config providers.{$providerName}.api_key).");
        }
    }

    /**
     * Extracts and validates string option from configuration array.
     *
     * @param array<string, mixed> $options      Configuration options
     * @param string               $key          The option key to extract
     * @param string               $defaultValue Default value if key not found or invalid
     */
    protected function getStringOption(array $options, string $key, string $defaultValue): string
    {
        return isset($options[$key]) && is_string($options[$key]) && '' !== $options[$key]
            ? $options[$key]
            : $defaultValue;
    }

    /**
     * Handles HTTP request exceptions with standardized error reporting.
     *
     * @param RequestException $e            The caught request exception
     * @param string           $providerName The provider name for error context
     *
     * @throws ProviderException Standardized provider exception
     */
    protected function handleRequestException(RequestException $e, string $providerName): never
    {
        $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 500;

        throw ProviderException::fromHttpError($status, $providerName, $e->getMessage());
    }

    /**
     * Validates HTTP response status code.
     *
     * @param int    $status       HTTP status code
     * @param string $providerName The provider name for error context
     *
     * @throws ProviderException If status indicates error
     */
    protected function validateResponseStatus(int $status, string $providerName): void
    {
        if ($status < 200 || $status >= 300) {
            throw ProviderException::fromHttpError($status, $providerName);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     * @param null|array<string, mixed>        $policyConfig
     */
    protected static function buildPrompt(array $chunks, ?array $policyConfig = null): string
    {
        $lines   = [];
        $lines[] = 'You are an AI Code Review bot. Analyze the following UNIFIED DIFFS per file, considering both added/modified lines (+) and deleted lines (-).';
        $lines[] = 'Focus your reasoning primarily on the resulting code state, but consider deletions for potential regressions, removed validations, or security checks.';
        $lines[] = 'Return a JSON object with key "findings" which is an array of objects with keys:';
        $lines[] = 'rule_id, title, severity, file, start_line, end_line, rationale, suggestion, content';

        // Add policy constraints to the prompt
        if (null !== $policyConfig) {
            $maxFindings        = (int) ($policyConfig['max_findings_per_file'] ?? 5);
            $minSeverity        = strtolower((string) ($policyConfig['min_severity_to_comment'] ?? 'info'));
            $consolidateSimilar = (bool) ($policyConfig['consolidate_similar_findings'] ?? false);
            $redactSecrets      = (bool) ($policyConfig['redact_secrets'] ?? true);

            $lines[] = 'IMPORTANT CONSTRAINTS:';
            $lines[] = "- Find a maximum of {$maxFindings} findings per file";
            $lines[] = "- Only report findings with severity '{$minSeverity}' or higher (critical > high > medium > low > info)";
            if ($consolidateSimilar) {
                $lines[] = '- Consolidate similar findings into single reports when possible';
            }
            if ($redactSecrets) {
                $lines[] = '- Redact any sensitive information like passwords, tokens, or API keys in your output';
            }
        }

        $lines[] = 'If no issues, return {"findings":[]}. Do not include commentary.';
        $lines[] = '';
        foreach ($chunks as $chunk) {
            $file    = (string) ($chunk['file'] ?? '');
            $start   = (int) ($chunk['start_line'] ?? 1);
            $lines[] = "FILE: {$file} (~{$start})";
            $lines[] = '---';
            if (isset($chunk['unified_diff']) && is_string($chunk['unified_diff']) && '' !== $chunk['unified_diff']) {
                $lines[] = $chunk['unified_diff'];
            } else {
                // Fallback for legacy chunks containing only added lines
                $entries = isset($chunk['lines']) && is_array($chunk['lines']) ? $chunk['lines']
                          : (isset($chunk['additions']) && is_array($chunk['additions']) ? $chunk['additions'] : []);
                foreach ($entries as $entry) {
                    $ln      = (int) ($entry['line'] ?? 0);
                    $ct      = (string) ($entry['content'] ?? '');
                    $lines[] = '+ '.$ln.': '.$ct;
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    protected static function systemPrompt(): string
    {
        return 'You are a strict assistant that outputs ONLY valid JSON following the requested schema.';
    }

    /**
     * Merge additional prompts from provider options (options['prompts']) into base system and user prompts.
     * Supports strings or arrays for system_append, user_append, and extra (array of strings) appended to the user content.
     *
     * @param array<string, mixed> $options
     *
     * @return array{0:string,1:string} [systemPrompt, userPrompt]
     */
    protected static function mergeAdditionalPrompts(string $system, string $user, array $options): array
    {
        $cfg = isset($options['prompts']) && is_array($options['prompts']) ? $options['prompts'] : [];

        $normalize = static function ($val): array {
            if (null === $val) {
                return [];
            }
            if (is_string($val) && '' !== trim($val)) {
                return [trim($val)];
            }
            if (is_array($val)) {
                $out = [];
                foreach ($val as $v) {
                    if (is_string($v) && '' !== trim($v)) {
                        $out[] = trim($v);
                    }
                }

                return $out;
            }

            return [];
        };

        $sysAppend  = $normalize($cfg['system_append'] ?? null);
        $userAppend = $normalize($cfg['user_append'] ?? null);
        $extra      = $normalize($cfg['extra'] ?? []);

        if (!empty($sysAppend)) {
            $system = rtrim($system)."\n\n".implode("\n\n", $sysAppend);
        }

        $userParts = [rtrim($user)];
        if (!empty($userAppend)) {
            $userParts[] = implode("\n\n", $userAppend);
        }
        if (!empty($extra)) {
            $userParts[] = implode("\n\n", $extra);
        }
        $user = implode("\n\n", array_filter($userParts, static fn ($s) => '' !== trim((string) $s)));

        return [$system, $user];
    }

    /**
     * Attempts to parse a provider response text into an array of findings.
     * Accepts direct JSON or JSON within fenced code blocks.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function extractFindingsFromText(string $content): array
    {
        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            // Try to extract JSON if wrapped in code fences
            if (1 === preg_match('/```(?:json)?\n(.+?)\n```/s', $content, $m)) {
                $parsed = json_decode($m[1], true);
            }
            // Try to extract inline JSON from text
            if (!is_array($parsed) && 1 === preg_match('/\{.*"findings".*\}/s', $content, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }
        if (!is_array($parsed)) {
            return [];
        }
        $findings = $parsed['findings'] ?? [];

        return is_array($findings) ? $findings : [];
    }
}
