<?php

declare(strict_types=1);

namespace AICR\Providers;

use GuzzleHttp\Client;

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
        $apiKey        = $options['api_key'] ?? '';
        $apiKey        = false !== $apiKey ? (string) $apiKey : '';
        if ('' === $apiKey) {
            throw new \InvalidArgumentException('GeminiProvider requires api_key (config providers.gemini.api_key or env GEMINI_API_KEY).');
        }
        $this->apiKey = $apiKey;
        $this->model  = isset($options['model']) && is_string($options['model']) && '' !== $options['model']
            ? $options['model']
            : self::DEFAULT_MODEL;

        $endpoint = isset($options['endpoint']) && is_string($options['endpoint']) && '' !== $options['endpoint']
            ? $options['endpoint']
            : self::DEFAULT_ENDPOINT_BASE.$this->model.':generateContent';

        $this->client = new Client([
            'base_uri' => $endpoint,
            'headers'  => [
                'Content-Type' => 'application/json',
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

        $resp = $this->client->post('', [
            'query' => ['key' => $this->apiKey],
            'json'  => $payload,
        ]);

        $status = $resp->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('GeminiProvider error status: '.$status);
        }
        $data = json_decode((string) $resp->getBody(), true);
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
