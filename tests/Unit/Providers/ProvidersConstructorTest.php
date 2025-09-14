<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Providers\AnthropicProvider;
use AICR\Providers\GeminiProvider;
use AICR\Providers\OllamaProvider;
use AICR\Providers\OpenAIProvider;
use PHPUnit\Framework\TestCase;

final class ProvidersConstructorTest extends TestCase
{
    public function testOpenAIRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpenAIProvider([]);
    }

    public function testGeminiRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GeminiProvider([]);
    }

    public function testAnthropicRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AnthropicProvider([]);
    }

    public function testConstructorsSucceedWithApiKeysAndOllamaDefaults(): void
    {
        $openai = new OpenAIProvider(['api_key' => 'k-openai']);
        $this->assertInstanceOf(OpenAIProvider::class, $openai);

        $gemini = new GeminiProvider(['api_key' => 'k-gem']);
        $this->assertInstanceOf(GeminiProvider::class, $gemini);

        $anth  = new AnthropicProvider(['api_key' => 'k-anth']);
        $this->assertInstanceOf(AnthropicProvider::class, $anth);

        $ollama = new OllamaProvider([]);
        $this->assertInstanceOf(OllamaProvider::class, $ollama);
    }
}
