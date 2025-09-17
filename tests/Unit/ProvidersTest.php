<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Exception\ConfigurationException;
use AICR\Providers\AnthropicProvider;
use AICR\Providers\GeminiProvider;
use AICR\Providers\OpenAIProvider;
use PHPUnit\Framework\TestCase;

final class ProvidersTest extends TestCase
{
    public function testOpenAIConstructorRequiresApiKey(): void
    {
        $this->expectException(ConfigurationException::class);
        new OpenAIProvider([]);
    }

    public function testGeminiConstructorRequiresApiKey(): void
    {
        $this->expectException(ConfigurationException::class);
        new GeminiProvider([]);
    }

    public function testAnthropicConstructorRequiresApiKey(): void
    {
        $this->expectException(ConfigurationException::class);
        new AnthropicProvider([]);
    }
}
