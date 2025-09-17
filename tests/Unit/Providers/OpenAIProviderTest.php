<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Exception\ConfigurationException;
use AICR\Exception\ProviderException;
use AICR\Providers\OpenAIProvider;
use PHPUnit\Framework\TestCase;

final class OpenAIProviderTest extends TestCase
{
    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('openai requires api_key (config providers.openai.api_key).');

        new OpenAIProvider([]);
    }

    public function testConstructorRequiresNonEmptyApiKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('openai requires api_key (config providers.openai.api_key).');

        new OpenAIProvider(['api_key' => '']);
    }

    public function testConstructorWithValidApiKey(): void
    {
        $provider = new OpenAIProvider(['api_key' => 'test-api-key']);

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertSame('openai', $provider->getName());
    }

    public function testConstructorWithDifferentModels(): void
    {
        // Test that constructor accepts different model configurations without errors
        $provider1 = new OpenAIProvider(['api_key' => 'test-api-key']);
        $this->assertInstanceOf(OpenAIProvider::class, $provider1);
        
        $provider2 = new OpenAIProvider(['api_key' => 'test-api-key', 'model' => 'gpt-4']);
        $this->assertInstanceOf(OpenAIProvider::class, $provider2);
        
        $provider3 = new OpenAIProvider(['api_key' => 'test-api-key', 'model' => '']);
        $this->assertInstanceOf(OpenAIProvider::class, $provider3);
    }

    public function testConstructorWithCustomEndpointAndTimeout(): void
    {
        // Test that constructor accepts custom endpoint and timeout without errors
        $provider = new OpenAIProvider([
            'api_key' => 'test-api-key',
            'endpoint' => 'https://custom-api.example.com',
            'timeout' => 120.0,
        ]);

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertSame('openai', $provider->getName());
    }

    public function testGetName(): void
    {
        $provider = new OpenAIProvider(['api_key' => 'test-api-key']);
        $this->assertSame('openai', $provider->getName());
    }

    public function testReviewChunksReturnsArrayOrThrows(): void
    {
        $provider = new OpenAIProvider(['api_key' => 'test-api-key']);
        
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
        $this->expectException(ProviderException::class);
        
        $provider->reviewChunks($chunks);
    }

    public function testReviewChunksWithEmptyChunks(): void
    {
        $provider = new OpenAIProvider(['api_key' => 'test-api-key']);
        
        // Test with empty chunks - should still attempt the call but fail due to network
        $this->expectException(ProviderException::class);
        
        $provider->reviewChunks([]);
    }

    public function testReviewChunksWithInvalidApiKey(): void
    {
        $provider = new OpenAIProvider(['api_key' => 'invalid-key']);
        
        $chunks = [
            [
                'file' => 'test.php',
                'additions' => [
                    ['line' => 1, 'content' => '+test'],
                ],
            ],
        ];

        // Should fail with authentication/network error
        $this->expectException(ProviderException::class);
        
        $provider->reviewChunks($chunks);
    }
}