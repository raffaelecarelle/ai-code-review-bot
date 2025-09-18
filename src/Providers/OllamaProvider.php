<?php

declare(strict_types=1);

namespace AICR\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Adapter for local Ollama HTTP API.
 * Uses /api/generate with stream=false. Expects the model to return a JSON object
 * with a top-level `findings` array embedded in the text response.
 */
final class OllamaProvider extends AbstractLLMProvider
{
    public const DEFAULT_MODEL    = 'llama3.1';
    public const DEFAULT_ENDPOINT = 'http://localhost:11434/api/generate';
    public const DEFAULT_TIMEOUT  = 60.0;

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

        // Initialize cache if provided in options
        if (isset($options['cache']) && is_array($options['cache'])) {
            $this->initializeCache($options['cache']);
        }

        $this->model = isset($options['model']) && is_string($options['model']) && '' !== $options['model']
            ? $options['model']
            : self::DEFAULT_MODEL;

        $endpoint = isset($options['endpoint']) && is_string($options['endpoint']) && '' !== $options['endpoint']
            ? $options['endpoint']
            : self::DEFAULT_ENDPOINT;

        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : self::DEFAULT_TIMEOUT;

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

        $requestData = [
            'model'   => $this->model,
            'prompt'  => $systemPrompt."\n\n".$userPrompt,
            'stream'  => false,
            'options' => [
                'temperature' => 0.0,
            ],
        ];

        try {
            $data = $this->cachedRequest($this->client, '', $requestData);
        } catch (RequestException $e) {
            $this->handleRequestException($e, 'ollama');
        }
        if (!is_array($data)) {
            return [];
        }
        $content = (string) ($data['response'] ?? '');
        if ('' === $content) {
            return [];
        }

        return self::extractFindingsFromText($content);
    }

    public function getName(): string
    {
        return 'ollama';
    }
}
