<?php

declare(strict_types=1);

namespace AICR\Providers;

use GuzzleHttp\Client;

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
        $apiKey        = $options['api_key'] ?? getenv('ANTHROPIC_API_KEY');
        $apiKey        = false !== $apiKey ? (string) $apiKey : '';
        if ('' === $apiKey) {
            throw new \InvalidArgumentException('AnthropicProvider requires api_key (config providers.anthropic.api_key or env ANTHROPIC_API_KEY).');
        }
        $this->model  = isset($options['model']) && is_string($options['model']) && '' !== $options['model']
            ? $options['model']
            : self::DEFAULT_MODEL;

        $endpoint = isset($options['endpoint']) && is_string($options['endpoint']) && '' !== $options['endpoint']
            ? $options['endpoint']
            : self::DEFAULT_ENDPOINT;

        $this->client = new Client([
            'base_uri' => $endpoint,
            'headers'  => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION,
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

        $payload = [
            'model'      => $this->model,
            'max_tokens' => self::DEFAULT_MAX_TOKENS,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $resp = $this->client->post('', [
            'json' => $payload,
        ]);
        $status = $resp->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('AnthropicProvider error status: '.$status);
        }
        $data = json_decode((string) $resp->getBody(), true);
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
}
