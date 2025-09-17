<?php

declare(strict_types=1);

namespace AICR\Providers;

use AICR\Config;
use AICR\Providers\MockProvider;
use AICR\Providers\OpenAIProvider;
use AICR\Providers\GeminiProvider;
use AICR\Providers\AnthropicProvider;
use AICR\Providers\OllamaProvider;

/**
 * Factory responsible for constructing AIProvider instances based on config.
 * - Applies prompts merging and guidelines_file injection uniformly.
 * - Keeps Pipeline free from provider-specific construction details (SRP/OCP).
 */
final class AIProviderFactory
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function build(string $providerName): AIProvider
    {
        $provider = $this->config->providers();

        if (!isset($provider[$providerName]) && 'mock' !== $providerName) {
            $availableProviders = array_keys($provider);

            throw new \InvalidArgumentException('Unknown provider. Available providers: '.implode(', ', $availableProviders));
        }

        return match ($providerName) {
            ProviderType::OPENAI->value    => new OpenAIProvider($this->withPrompts($provider[ProviderType::OPENAI->value])),
            ProviderType::GEMINI->value    => new GeminiProvider($this->withPrompts($provider[ProviderType::GEMINI->value])),
            ProviderType::ANTHROPIC->value => new AnthropicProvider($this->withPrompts($provider[ProviderType::ANTHROPIC->value])),
            ProviderType::OLLAMA->value    => new OllamaProvider($this->withPrompts($provider[ProviderType::OLLAMA->value])),
            default                        => new MockProvider(),
        };
    }

    /**
     * @param array<string,mixed>|mixed $raw
     *
     * @return array<string,mixed>
     */
    private function withPrompts($raw): array
    {
        $opts    = is_array($raw) ? $raw : [];
        $prompts = $this->config->getAll()['prompts'] ?? [];
        if (!is_array($prompts)) {
            $prompts = [];
        }

        $guidelinesPath = $this->config->getAll()['guidelines_file'] ?? null;
        // Avoid duplicate injection if already present via Config::load
        $prefix  = 'Coding guidelines file content is provided below in base64';
        $already = false;
        if (isset($prompts['extra']) && is_array($prompts['extra'])) {
            foreach ($prompts['extra'] as $x) {
                if (is_string($x) && str_contains($x, $prefix)) {
                    $already = true;

                    break;
                }
            }
        }
        if (!$already && is_string($guidelinesPath) && '' !== trim($guidelinesPath) && is_file($guidelinesPath) && is_readable($guidelinesPath)) {
            $gl = file_get_contents($guidelinesPath);
            if (false !== $gl && '' !== trim($gl)) {
                if (!isset($prompts['extra']) || !is_array($prompts['extra'])) {
                    $prompts['extra'] = [];
                }
                $b64                = base64_encode($gl);
                $prompts['extra'][] = "Coding guidelines file content is provided below in base64 (decode and follow strictly):\n".$b64;
            }
        }
        $opts['prompts'] = $prompts;

        return $opts;
    }
}
