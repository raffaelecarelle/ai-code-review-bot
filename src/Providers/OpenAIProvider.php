<?php

declare(strict_types=1);

namespace AICR\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Adapter for OpenAI Chat Completions API (ChatGPT models).
 * Expects the model to return a JSON object with a top-level `findings` array.
 */
final class OpenAIProvider extends AbstractLLMProvider
{
    public const DEFAULT_MODEL    = 'gpt-4o-mini';
    public const DEFAULT_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
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
        $apiKey        = (string) ($options['api_key'] ?? '');
        $this->validateApiKey($apiKey, 'openai');

        $this->model = $this->getStringOption($options, 'model', self::DEFAULT_MODEL);
        $endpoint    = $this->getStringOption($options, 'endpoint', self::DEFAULT_ENDPOINT);
        $timeout     = isset($options['timeout']) ? (float) $options['timeout'] : self::DEFAULT_TIMEOUT;

        $headers      = ['Authorization' => 'Bearer '.$apiKey];
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

        try {
            $resp = $this->client->post('', [
                'json' => [
                    'model'    => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.0,
                ],
            ]);
        } catch (RequestException $e) {
            $this->handleRequestException($e, 'openai');
        }

        $this->validateResponseStatus($resp->getStatusCode(), 'openai');
        $data = json_decode((string) $resp->getBody(), true);

        if (!is_array($data)) {
            return [];
        }
        $choices = $data['choices'] ?? [];
        if (!is_array($choices) || !isset($choices[0]['message']['content'])) {
            return [];
        }
        $content = (string) $choices[0]['message']['content'];
        $parsed  = json_decode($content, true);
        if (!is_array($parsed)) {
            // Try to extract JSON if wrapped in code fences
            if (1 === preg_match('/```(?:json)?\n(.+?)\n```/s', $content, $m)) {
                $parsed = json_decode($m[1], true);
            }
        }
        if (!is_array($parsed)) {
            return [];
        }
        $findings = $parsed['findings'] ?? [];

        return is_array($findings) ? $findings : [];
    }

    public function getName(): string
    {
        return 'openai';
    }
}
