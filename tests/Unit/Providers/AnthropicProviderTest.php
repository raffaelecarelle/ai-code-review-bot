<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Exception\ConfigurationException;
use AICR\Providers\AnthropicProvider;
use PHPUnit\Framework\TestCase;

final class AnthropicProviderTest extends TestCase
{
    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('AnthropicProvider requires api_key');

        new AnthropicProvider([]);
    }

    public function testConstructorRequiresNonEmptyApiKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('AnthropicProvider requires api_key');

        new AnthropicProvider(['api_key' => '']);
    }

    public function testConstructorWithValidApiKey(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertSame('anthropic', $provider->getName());
    }

    public function testConstructorWithDifferentModels(): void
    {
        // Test that constructor accepts different model configurations without errors
        $provider1 = new AnthropicProvider(['api_key' => 'test-api-key']);
        $this->assertInstanceOf(AnthropicProvider::class, $provider1);
        
        $provider2 = new AnthropicProvider(['api_key' => 'test-api-key', 'model' => 'claude-3-sonnet']);
        $this->assertInstanceOf(AnthropicProvider::class, $provider2);
        
        $provider3 = new AnthropicProvider(['api_key' => 'test-api-key', 'model' => '']);
        $this->assertInstanceOf(AnthropicProvider::class, $provider3);
    }

    public function testConstructorWithCustomEndpointAndTimeout(): void
    {
        // Test that constructor accepts custom endpoint and timeout without errors
        $provider = new AnthropicProvider([
            'api_key' => 'test-api-key',
            'endpoint' => 'https://custom-anthropic.example.com',
            'timeout' => 120.0,
        ]);

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertSame('anthropic', $provider->getName());
    }

    public function testGetName(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);
        $this->assertSame('anthropic', $provider->getName());
    }

    public function testReviewChunksReturnsArrayOrThrows(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);
        
        $chunks = [
            [
                'file' => 'test.php',
                'additions' => [
                    ['line' => 10, 'content' => '+echo "test";'],
                ],
            ],
        ];

        // Since we can't mock HTTP without reflection, we expect this to fail with network error
        // but we test that it properly handles the call structure
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AnthropicProvider error');
        
        $provider->reviewChunks($chunks);
    }

    public function testReviewChunksWithEmptyChunks(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);
        
        // Test with empty chunks - should still attempt the call but fail due to network
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AnthropicProvider error');
        
        $provider->reviewChunks([]);
    }

    public function testReviewChunksWithInvalidApiKey(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'invalid-key']);
        
        $chunks = [
            [
                'file' => 'test.php',
                'additions' => [
                    ['line' => 1, 'content' => '+test'],
                ],
            ],
        ];

        // Should fail with authentication/network error
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AnthropicProvider error');
        
        $provider->reviewChunks($chunks);
    }
}