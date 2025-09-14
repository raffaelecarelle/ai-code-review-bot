<?php

declare(strict_types=1);

namespace AICR\Providers;

use AICR\Config;
use AICR\Pipeline;

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

    public function build(?AIProvider $override = null): AIProvider
    {
        if (null !== $override) {
            return $override;
        }

        $provider = $this->config->provider();
        $default  = (string) ($provider['type'] ?? null);

        switch ($default) {
            case Pipeline::PROVIDER_OPENAI:
                $opts = $this->withPrompts($provider['openai'] ?? []);

                return new OpenAIProvider($opts);

            case Pipeline::PROVIDER_GEMINI:
                $opts = $this->withPrompts($provider['gemini'] ?? []);

                return new GeminiProvider($opts);

            case Pipeline::PROVIDER_ANTHROPIC:
                $opts = $this->withPrompts($provider['anthropic'] ?? []);

                return new AnthropicProvider($opts);

            case Pipeline::PROVIDER_OLLAMA:
                $opts = $this->withPrompts($provider['ollama'] ?? []);

                return new OllamaProvider($opts);

            case 'mock':
                return new MockProvider();
        }

        throw new \InvalidArgumentException("Unknown provider: {$default}");
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
