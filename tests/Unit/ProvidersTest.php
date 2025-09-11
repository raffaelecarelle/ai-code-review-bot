<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Providers\AnthropicProvider;
use AICR\Providers\GeminiProvider;
use AICR\Providers\OpenAIProvider;
use PHPUnit\Framework\TestCase;

final class ProvidersTest extends TestCase
{
    public function testOpenAIConstructorRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpenAIProvider([]);
    }

    public function testGeminiConstructorRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GeminiProvider([]);
    }

    public function testAnthropicConstructorRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AnthropicProvider([]);
    }
}
