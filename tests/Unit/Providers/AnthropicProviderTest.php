<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Providers\AnthropicProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AnthropicProviderTest extends TestCase
{
    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AnthropicProvider requires api_key');

        new AnthropicProvider([]);
    }

    public function testConstructorRequiresNonEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AnthropicProvider requires api_key');

        new AnthropicProvider(['api_key' => '']);
    }

    public function testConstructorWithValidApiKey(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertSame('anthropic', $provider->getName());
    }

    public function testConstructorUsesDefaultModel(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame(AnthropicProvider::DEFAULT_MODEL, $modelProperty->getValue($provider));
    }

    public function testConstructorUsesCustomModel(): void
    {
        $customModel = 'claude-3-opus-20240229';
        $provider = new AnthropicProvider([
            'api_key' => 'test-api-key',
            'model' => $customModel,
        ]);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame($customModel, $modelProperty->getValue($provider));
    }

    public function testConstructorIgnoresEmptyModel(): void
    {
        $provider = new AnthropicProvider([
            'api_key' => 'test-api-key',
            'model' => '',
        ]);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame(AnthropicProvider::DEFAULT_MODEL, $modelProperty->getValue($provider));
    }

    public function testConstructorSetsHttpClientWithCorrectHeaders(): void
    {
        $apiKey = 'test-api-key';
        $provider = new AnthropicProvider(['api_key' => $apiKey]);

        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($provider);

        $this->assertInstanceOf(Client::class, $client);
        
        $config = $client->getConfig();
        $this->assertSame(AnthropicProvider::DEFAULT_ENDPOINT, (string) $config['base_uri']);
        $this->assertSame(AnthropicProvider::DEFAULT_TIMEOUT, $config['timeout']);
        $this->assertArrayHasKey('headers', $config);
        $this->assertSame('application/json', $config['headers']['Content-Type']);
        $this->assertSame($apiKey, $config['headers']['x-api-key']);
        $this->assertSame(AnthropicProvider::API_VERSION, $config['headers']['anthropic-version']);
    }

    public function testConstructorWithCustomEndpointAndTimeout(): void
    {
        $customEndpoint = 'https://custom-anthropic-api.example.com';
        $customTimeout = 120.0;
        
        $provider = new AnthropicProvider([
            'api_key' => 'test-api-key',
            'endpoint' => $customEndpoint,
            'timeout' => $customTimeout,
        ]);

        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($provider);

        $config = $client->getConfig();
        $this->assertSame($customEndpoint, (string) $config['base_uri']);
        $this->assertSame($customTimeout, $config['timeout']);
    }

    public function testReviewChunksWithValidResponse(): void
    {
        $findings = [
            [
                'file' => 'test.php',
                'line' => 10,
                'severity' => 'warning',
                'message' => 'Test finding',
                'rationale' => 'Test rationale',
            ],
        ];

        $mockResponse = new Response(200, [], json_encode([
            'content' => [
                [
                    'text' => json_encode(['findings' => $findings]),
                ],
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);
        
        // Replace the HTTP client with our mocked one
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $chunks = [
            [
                'file' => 'test.php',
                'additions' => [
                    ['line' => 10, 'content' => '+echo "test";'],
                ],
            ],
        ];

        $result = $provider->reviewChunks($chunks);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('test.php', $result[0]['file']);
        $this->assertSame(10, $result[0]['line']);
        $this->assertSame('warning', $result[0]['severity']);
        $this->assertSame('Test finding', $result[0]['message']);
    }

    public function testReviewChunksWithErrorStatus(): void
    {
        $mockResponse = new Response(400, [], 'Bad Request');
        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AnthropicProvider error status: 400');

        $provider->reviewChunks([]);
    }

    public function testReviewChunksWithInvalidJsonResponse(): void
    {
        $mockResponse = new Response(200, [], 'not valid json');

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $result = $provider->reviewChunks([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testReviewChunksWithMissingContentBlocks(): void
    {
        $responses = [
            new Response(200, [], json_encode(['invalid' => 'structure'])),
            new Response(200, [], json_encode(['content' => []])),
            new Response(200, [], json_encode(['content' => [['invalid' => 'text']]])),
        ];

        foreach ($responses as $mockResponse) {
            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);

            $provider = new AnthropicProvider(['api_key' => 'test-api-key']);
            
            $reflection = new ReflectionClass($provider);
            $clientProperty = $reflection->getProperty('client');
            $clientProperty->setAccessible(true);
            $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

            $result = $provider->reviewChunks([]);

            $this->assertIsArray($result);
            $this->assertEmpty($result);
        }
    }

    public function testReviewChunksWithTextResponseUsingExtractFindingsFromText(): void
    {
        $textResponse = 'Here are the findings: {"findings": [{"file": "test.php", "line": 5, "severity": "info", "message": "Test message", "rationale": "Test rationale"}]}';
        
        $mockResponse = new Response(200, [], json_encode([
            'content' => [
                [
                    'text' => $textResponse,
                ],
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $chunks = [['file' => 'test.php', 'additions' => [['line' => 5, 'content' => '+test']]]];
        $result = $provider->reviewChunks($chunks);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('test.php', $result[0]['file']);
        $this->assertSame(5, $result[0]['line']);
        $this->assertSame('info', $result[0]['severity']);
        $this->assertSame('Test message', $result[0]['message']);
    }

    public function testReviewChunksSendsCorrectPayloadStructure(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'content' => [
                ['text' => '{"findings": []}'],
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new AnthropicProvider(['api_key' => 'test-api-key', 'model' => 'claude-3-haiku-20240307']);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $provider->reviewChunks([]);

        // Verify that the request was made with the correct payload structure
        $lastRequest = $mock->getLastRequest();
        $this->assertNotNull($lastRequest);
        
        $requestBody = json_decode((string) $lastRequest->getBody(), true);
        $this->assertIsArray($requestBody);
        $this->assertArrayHasKey('model', $requestBody);
        $this->assertArrayHasKey('max_tokens', $requestBody);
        $this->assertArrayHasKey('system', $requestBody);
        $this->assertArrayHasKey('messages', $requestBody);
        
        $this->assertSame('claude-3-haiku-20240307', $requestBody['model']);
        $this->assertSame(AnthropicProvider::DEFAULT_MAX_TOKENS, $requestBody['max_tokens']);
        $this->assertIsString($requestBody['system']);
        $this->assertIsArray($requestBody['messages']);
        $this->assertCount(1, $requestBody['messages']);
        $this->assertSame('user', $requestBody['messages'][0]['role']);
        $this->assertArrayHasKey('content', $requestBody['messages'][0]);
    }

    public function testReviewChunksUsesDefaultMaxTokens(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'content' => [
                ['text' => '{"findings": []}'],
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $provider->reviewChunks([]);

        $lastRequest = $mock->getLastRequest();
        $requestBody = json_decode((string) $lastRequest->getBody(), true);
        $this->assertSame(AnthropicProvider::DEFAULT_MAX_TOKENS, $requestBody['max_tokens']);
    }

    public function testGetName(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'test-api-key']);
        $this->assertSame('anthropic', $provider->getName());
    }

    public function testConstructorValidatesApiKeyFromFalseValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AnthropicProvider requires api_key');

        new AnthropicProvider(['api_key' => false]);
    }

    public function testConstructorHandlesNonStringModel(): void
    {
        $provider = new AnthropicProvider([
            'api_key' => 'test-api-key',
            'model' => 123, // non-string model
        ]);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame(AnthropicProvider::DEFAULT_MODEL, $modelProperty->getValue($provider));
    }

    public function testConstructorHandlesNonStringEndpoint(): void
    {
        $provider = new AnthropicProvider([
            'api_key' => 'test-api-key',
            'endpoint' => 123, // non-string endpoint
        ]);

        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($provider);

        $config = $client->getConfig();
        $this->assertSame(AnthropicProvider::DEFAULT_ENDPOINT, (string) $config['base_uri']);
    }
}