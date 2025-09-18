<?php

declare(strict_types=1);

namespace AICR\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Adapter for Anthropic Claude Messages API v1.
 * Expects the model to return a JSON object with a top-level `findings` array.
 */
final class AnthropicProvider extends AbstractLLMProvider
{
    public const DEFAULT_MODEL      = 'claude-3-5-sonnet-20240620';
    public const DEFAULT_ENDPOINT   = 'https://api.anthropic.com/v1/messages';
    public const DEFAULT_TIMEOUT    = 60.0;
    public const API_VERSION        = '2023-06-01';
    public const DEFAULT_MAX_TOKENS = 2048;

    private Client $client;
    private string $model;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
        $apiKey        = (string) ($options['api_key'] ?? '');
        $this->validateApiKey($apiKey, 'anthropic');

        // Initialize cache if provided in options
        if (isset($options['cache']) && is_array($options['cache'])) {
            $this->initializeCache($options['cache']);
        }

        $this->model = $this->getStringOption($options, 'model', self::DEFAULT_MODEL);
        $endpoint    = $this->getStringOption($options, 'endpoint', self::DEFAULT_ENDPOINT);
        $timeout     = isset($options['timeout']) ? (float) $options['timeout'] : self::DEFAULT_TIMEOUT;

        $headers = [
            'x-api-key'         => $apiKey,
            'anthropic-version' => self::API_VERSION,
        ];
        $this->client = $this->createHttpClient($endpoint, $headers, $timeout);
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     * @param null|array<string, mixed>        $policyConfig
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewChunks(array $chunks, ?array $policyConfig = null): array
    {
        $baseUser                    = self::buildPrompt($chunks, $policyConfig);
        [$systemPrompt, $userPrompt] = self::mergeAdditionalPrompts(self::systemPrompt(), $baseUser, $this->options);

        $payload = [
            'model'      => $this->model,
            'max_tokens' => self::DEFAULT_MAX_TOKENS,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        try {
            $data = $this->cachedRequest($this->client, '', $payload);
        } catch (RequestException $e) {
            $this->handleRequestException($e, 'anthropic');
        }
        if (!is_array($data)) {
            return [];
        }
        $contentBlocks = $data['content'] ?? [];
        if (!is_array($contentBlocks) || !isset($contentBlocks[0]['text'])) {
            return [];
        }
        $content = (string) $contentBlocks[0]['text'];

        return self::extractFindingsFromText($content);
    }

    public function getName(): string
    {
        return 'anthropic';
    }
}
