<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Providers\GeminiProvider;
use PHPUnit\Framework\TestCase;

final class GeminiProviderTest extends TestCase
{
    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GeminiProvider requires api_key');

        new GeminiProvider([]);
    }

    public function testConstructorRequiresNonEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GeminiProvider requires api_key');

        new GeminiProvider(['api_key' => '']);
    }

    public function testConstructorWithValidApiKey(): void
    {
        $provider = new GeminiProvider(['api_key' => 'test-api-key']);

        $this->assertInstanceOf(GeminiProvider::class, $provider);
        $this->assertSame('gemini', $provider->getName());
    }

    public function testConstructorWithDifferentModels(): void
    {
        // Test that constructor accepts different model configurations without errors
        $provider1 = new GeminiProvider(['api_key' => 'test-api-key']);
        $this->assertInstanceOf(GeminiProvider::class, $provider1);
        
        $provider2 = new GeminiProvider(['api_key' => 'test-api-key', 'model' => 'gemini-pro']);
        $this->assertInstanceOf(GeminiProvider::class, $provider2);
        
        $provider3 = new GeminiProvider(['api_key' => 'test-api-key', 'model' => '']);
        $this->assertInstanceOf(GeminiProvider::class, $provider3);
    }

    public function testConstructorWithCustomEndpointAndTimeout(): void
    {
        // Test that constructor accepts custom endpoint and timeout without errors
        $provider = new GeminiProvider([
            'api_key' => 'test-api-key',
            'endpoint' => 'https://custom-gemini.example.com',
            'timeout' => 120.0,
        ]);

        $this->assertInstanceOf(GeminiProvider::class, $provider);
        $this->assertSame('gemini', $provider->getName());
    }

    public function testGetName(): void
    {
        $provider = new GeminiProvider(['api_key' => 'test-api-key']);
        $this->assertSame('gemini', $provider->getName());
    }

    public function testReviewChunksReturnsArrayOrThrows(): void
    {
        $provider = new GeminiProvider(['api_key' => 'test-api-key']);
        
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
        $this->expectExceptionMessage('GeminiProvider error');
        
        $provider->reviewChunks($chunks);
    }

    public function testReviewChunksWithEmptyChunks(): void
    {
        $provider = new GeminiProvider(['api_key' => 'test-api-key']);
        
        // Test with empty chunks - should still attempt the call but fail due to network
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GeminiProvider error');
        
        $provider->reviewChunks([]);
    }

    public function testReviewChunksWithInvalidApiKey(): void
    {
        $provider = new GeminiProvider(['api_key' => 'invalid-key']);
        
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
        $this->expectExceptionMessage('GeminiProvider error');
        
        $provider->reviewChunks($chunks);
    }
}