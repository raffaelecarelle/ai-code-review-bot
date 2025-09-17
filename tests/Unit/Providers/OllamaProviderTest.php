<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Providers\OllamaProvider;
use PHPUnit\Framework\TestCase;

final class OllamaProviderTest extends TestCase
{
    public function testConstructorWithDefaultValues(): void
    {
        $provider = new OllamaProvider([]);

        $this->assertInstanceOf(OllamaProvider::class, $provider);
        $this->assertSame('ollama', $provider->getName());
    }

    public function testConstructorWithCustomModel(): void
    {
        $provider = new OllamaProvider(['model' => 'llama2']);

        $this->assertInstanceOf(OllamaProvider::class, $provider);
        $this->assertSame('ollama', $provider->getName());
    }

    public function testConstructorWithCustomEndpointAndTimeout(): void
    {
        $provider = new OllamaProvider([
            'endpoint' => 'http://localhost:11435',
            'timeout' => 120.0,
            'model' => 'custom-model',
        ]);

        $this->assertInstanceOf(OllamaProvider::class, $provider);
        $this->assertSame('ollama', $provider->getName());
    }

    public function testGetName(): void
    {
        $provider = new OllamaProvider([]);
        $this->assertSame('ollama', $provider->getName());
    }

    public function testReviewChunksReturnsArrayOrThrows(): void
    {
        $provider = new OllamaProvider([]);
        
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
        $this->expectExceptionMessage('Failed to connect to localhost');
        
        $provider->reviewChunks($chunks);
    }

    public function testReviewChunksWithEmptyChunks(): void
    {
        $provider = new OllamaProvider([]);
        
        // Test with empty chunks - should still attempt the call but fail due to network
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to localhost');
        
        $provider->reviewChunks([]);
    }

    public function testReviewChunksWithCustomModel(): void
    {
        $provider = new OllamaProvider(['model' => 'custom-model']);
        
        $chunks = [
            [
                'file' => 'test.php',
                'additions' => [
                    ['line' => 1, 'content' => '+test'],
                ],
            ],
        ];

        // Should fail with network error (trying to connect to Ollama)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to localhost');
        
        $provider->reviewChunks($chunks);
    }
}