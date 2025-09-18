<?php

declare(strict_types=1);

namespace AICR\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Adapter for Google Gemini Generative Language API v1beta.
 * Expects the model to return a JSON object with a top-level `findings` array.
 */
final class GeminiProvider extends AbstractLLMProvider
{
    public const DEFAULT_MODEL         = 'gemini-1.5-pro';
    public const DEFAULT_TIMEOUT       = 60.0;
    public const DEFAULT_ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private Client $client;
    private string $model;
    private string $apiKey;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
        $apiKey        = (string) ($options['api_key'] ?? '');
        $this->validateApiKey($apiKey, 'gemini');
        $this->apiKey = $apiKey;

        // Initialize cache if provided in options
        if (isset($options['cache']) && is_array($options['cache'])) {
            $this->initializeCache($options['cache']);
        }

        $this->model = $this->getStringOption($options, 'model', self::DEFAULT_MODEL);
        $endpoint    = $this->getStringOption($options, 'endpoint', self::DEFAULT_ENDPOINT_BASE.$this->model.':generateContent');
        $timeout     = isset($options['timeout']) ? (float) $options['timeout'] : self::DEFAULT_TIMEOUT;

        $this->client = $this->createHttpClient($endpoint, [], $timeout);
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

        $parts = [['text' => $systemPrompt."\n\n".$userPrompt]];

        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'temperature' => 0,
            ],
        ];

        // Add API key to payload for caching consistency
        $requestData = array_merge($payload, ['api_key' => $this->apiKey]);

        try {
            $data = $this->cachedRequest($this->client, '?key='.$this->apiKey, $requestData);
        } catch (RequestException $e) {
            $this->handleRequestException($e, 'gemini');
        }
        if (!is_array($data)) {
            return [];
        }
        $candidates = $data['candidates'] ?? [];
        if (!is_array($candidates) || !isset($candidates[0]['content']['parts'][0]['text'])) {
            return [];
        }
        $content = (string) $candidates[0]['content']['parts'][0]['text'];

        return self::extractFindingsFromText($content);
    }

    public function getName(): string
    {
        return 'gemini';
    }
}
