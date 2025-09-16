<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Providers\OllamaProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OllamaProviderTest extends TestCase
{
    public function testConstructorWithoutApiKey(): void
    {
        // OllamaProvider doesn't require an API key unlike other providers
        $provider = new OllamaProvider([]);

        $this->assertInstanceOf(OllamaProvider::class, $provider);
        $this->assertSame('ollama', $provider->getName());
    }

    public function testConstructorWithOptions(): void
    {
        $provider = new OllamaProvider(['model' => 'custom-model']);

        $this->assertInstanceOf(OllamaProvider::class, $provider);
        $this->assertSame('ollama', $provider->getName());
    }

    public function testConstructorUsesDefaultModel(): void
    {
        $provider = new OllamaProvider([]);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame(OllamaProvider::DEFAULT_MODEL, $modelProperty->getValue($provider));
    }

    public function testConstructorUsesCustomModel(): void
    {
        $customModel = 'llama2';
        $provider = new OllamaProvider([
            'model' => $customModel,
        ]);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame($customModel, $modelProperty->getValue($provider));
    }

    public function testConstructorIgnoresEmptyModel(): void
    {
        $provider = new OllamaProvider([
            'model' => '',
        ]);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame(OllamaProvider::DEFAULT_MODEL, $modelProperty->getValue($provider));
    }

    public function testConstructorSetsHttpClientWithCorrectConfiguration(): void
    {
        $provider = new OllamaProvider([]);

        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($provider);

        $this->assertInstanceOf(Client::class, $client);
        
        $config = $client->getConfig();
        $this->assertSame(OllamaProvider::DEFAULT_ENDPOINT, $config['base_uri']);
        $this->assertSame(OllamaProvider::DEFAULT_TIMEOUT, $config['timeout']);
        $this->assertArrayHasKey('headers', $config);
        $this->assertSame('application/json', $config['headers']['Content-Type']);
    }

    public function testConstructorWithCustomEndpointAndTimeout(): void
    {
        $customEndpoint = 'http://custom-ollama:11434/api/generate';
        $customTimeout = 120.0;
        
        $provider = new OllamaProvider([
            'endpoint' => $customEndpoint,
            'timeout' => $customTimeout,
        ]);

        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($provider);

        $config = $client->getConfig();
        $this->assertSame($customEndpoint, $config['base_uri']);
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
            'response' => json_encode(['findings' => $findings]),
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new OllamaProvider([]);
        
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

        $provider = new OllamaProvider([]);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OllamaProvider error status: 400');

        $provider->reviewChunks([]);
    }

    public function testReviewChunksWithInvalidJsonResponse(): void
    {
        $mockResponse = new Response(200, [], 'not valid json');

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new OllamaProvider([]);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $result = $provider->reviewChunks([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testReviewChunksWithMissingOrEmptyResponse(): void
    {
        $responses = [
            new Response(200, [], json_encode(['invalid' => 'structure'])),
            new Response(200, [], json_encode(['response' => ''])),
            new Response(200, [], json_encode([])),
        ];

        foreach ($responses as $mockResponse) {
            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);

            $provider = new OllamaProvider([]);
            
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
            'response' => $textResponse,
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new OllamaProvider([]);
        
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
            'response' => '{"findings": []}',
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new OllamaProvider(['model' => 'custom-model']);
        
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
        $this->assertArrayHasKey('prompt', $requestBody);
        $this->assertArrayHasKey('stream', $requestBody);
        $this->assertArrayHasKey('options', $requestBody);
        
        $this->assertSame('custom-model', $requestBody['model']);
        $this->assertIsString($requestBody['prompt']);
        $this->assertFalse($requestBody['stream']);
        $this->assertIsArray($requestBody['options']);
        $this->assertArrayHasKey('temperature', $requestBody['options']);
        $this->assertSame(0.0, $requestBody['options']['temperature']);
    }

    public function testReviewChunksCombinesSystemAndUserPrompts(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'response' => '{"findings": []}',
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new OllamaProvider([]);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $chunks = [
            [
                'file' => 'test.php',
                'additions' => [
                    ['line' => 1, 'content' => '+<?php'],
                ],
            ],
        ];

        $provider->reviewChunks($chunks);

        $lastRequest = $mock->getLastRequest();
        $requestBody = json_decode((string) $lastRequest->getBody(), true);
        
        // The prompt should contain both system and user prompts combined
        $this->assertStringContainsString('test.php', $requestBody['prompt']);
        $this->assertStringContainsString('<?php', $requestBody['prompt']);
    }

    public function testGetName(): void
    {
        $provider = new OllamaProvider([]);
        $this->assertSame('ollama', $provider->getName());
    }

    public function testConstructorHandlesNonStringModel(): void
    {
        $provider = new OllamaProvider([
            'model' => 123, // non-string model
        ]);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame(OllamaProvider::DEFAULT_MODEL, $modelProperty->getValue($provider));
    }

    public function testConstructorHandlesNonStringEndpoint(): void
    {
        $provider = new OllamaProvider([
            'endpoint' => 123, // non-string endpoint
        ]);

        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($provider);

        $config = $client->getConfig();
        $this->assertSame(OllamaProvider::DEFAULT_ENDPOINT, $config['base_uri']);
    }

    public function testConstructorStoresOptions(): void
    {
        $options = [
            'model' => 'test-model',
            'endpoint' => 'http://test:11434',
            'timeout' => 90.0,
            'custom_option' => 'value',
        ];
        
        $provider = new OllamaProvider($options);

        $reflection = new ReflectionClass($provider);
        $optionsProperty = $reflection->getProperty('options');
        $optionsProperty->setAccessible(true);
        $storedOptions = $optionsProperty->getValue($provider);

        $this->assertSame($options, $storedOptions);
    }
}