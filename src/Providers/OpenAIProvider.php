<?php

declare(strict_types=1);

namespace AICR\Providers;

use GuzzleHttp\Client;

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
        $apiKey        = $options['api_key'] ?? getenv('OPENAI_API_KEY');
        $apiKey        = false !== $apiKey ? (string) $apiKey : '';
        if ('' === $apiKey) {
            throw new \InvalidArgumentException('OpenAIProvider requires api_key (config providers.openai.api_key or env OPENAI_API_KEY).');
        }
        $this->model = isset($options['model']) && is_string($options['model']) && '' !== $options['model']
            ? $options['model']
            : self::DEFAULT_MODEL;

        $endpoint = isset($options['endpoint']) && is_string($options['endpoint']) && '' !== $options['endpoint']
            ? $options['endpoint']
            : self::DEFAULT_ENDPOINT;

        $this->client = new Client([
            'base_uri' => $endpoint,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer '.$apiKey,
            ],
            'timeout' => isset($options['timeout']) ? (float) $options['timeout'] : self::DEFAULT_TIMEOUT,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewChunks(array $chunks): array
    {
        $baseUser                    = self::buildPrompt($chunks);
        [$systemPrompt, $userPrompt] = self::mergeAdditionalPrompts(self::systemPrompt(), $baseUser, $this->options);

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
        $status = $resp->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('OpenAIProvider error status: '.$status);
        }
        $data = json_decode((string) $resp->getBody(), true);
        var_dump($data);
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
}
