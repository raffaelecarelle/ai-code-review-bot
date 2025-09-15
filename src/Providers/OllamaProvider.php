<?php

declare(strict_types=1);

namespace AICR\Providers;

use GuzzleHttp\Client;

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
        $this->model   = isset($options['model']) && is_string($options['model']) && '' !== $options['model']
            ? $options['model']
            : self::DEFAULT_MODEL;

        $endpoint = isset($options['endpoint']) && is_string($options['endpoint']) && '' !== $options['endpoint']
            ? $options['endpoint']
            : self::DEFAULT_ENDPOINT;

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

        $resp = $this->client->post('', [
            'json' => [
                'model'   => $this->model,
                'prompt'  => $systemPrompt."\n\n".$userPrompt,
                'stream'  => false,
                'options' => [
                    'temperature' => 0.0,
                ],
            ],
        ]);

        $status = $resp->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('OllamaProvider error status: '.$status);
        }
        $data = json_decode((string) $resp->getBody(), true);
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
