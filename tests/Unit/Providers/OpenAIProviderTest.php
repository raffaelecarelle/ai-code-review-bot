<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Providers\OpenAIProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OpenAIProviderTest extends TestCase
{
    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OpenAIProvider requires api_key');

        new OpenAIProvider([]);
    }

    public function testConstructorRequiresNonEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OpenAIProvider requires api_key');

        new OpenAIProvider(['api_key' => '']);
    }

    public function testConstructorWithValidApiKey(): void
    {
        $provider = new OpenAIProvider(['api_key' => 'test-api-key']);

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertSame('openai', $provider->getName());
    }

    public function testConstructorUsesDefaultModel(): void
    {
        $provider = new OpenAIProvider(['api_key' => 'test-api-key']);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame(OpenAIProvider::DEFAULT_MODEL, $modelProperty->getValue($provider));
    }

    public function testConstructorUsesCustomModel(): void
    {
        $customModel = 'gpt-4';
        $provider = new OpenAIProvider([
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
        $provider = new OpenAIProvider([
            'api_key' => 'test-api-key',
            'model' => '',
        ]);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame(OpenAIProvider::DEFAULT_MODEL, $modelProperty->getValue($provider));
    }

    public function testConstructorSetsHttpClientWithCorrectHeaders(): void
    {
        $apiKey = 'test-api-key';
        $provider = new OpenAIProvider(['api_key' => $apiKey]);

        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($provider);

        $this->assertInstanceOf(Client::class, $client);
        
        $config = $client->getConfig();
        $this->assertSame(OpenAIProvider::DEFAULT_ENDPOINT, (string) $config['base_uri']);
        $this->assertSame(OpenAIProvider::DEFAULT_TIMEOUT, $config['timeout']);
        $this->assertArrayHasKey('headers', $config);
        $this->assertSame('application/json', $config['headers']['Content-Type']);
        $this->assertSame('Bearer ' . $apiKey, $config['headers']['Authorization']);
    }

    public function testConstructorWithCustomEndpointAndTimeout(): void
    {
        $customEndpoint = 'https://custom-api.example.com';
        $customTimeout = 120.0;
        
        $provider = new OpenAIProvider([
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
        $mockResponse = new Response(200, [], json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'findings' => [
                                [
                                    'file' => 'test.php',
                                    'line' => 10,
                                    'severity' => 'warning',
                                    'message' => 'Test finding',
                                    'rationale' => 'Test rationale',
                                ],
                            ],
                        ]),
                    ],
                ],
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new OpenAIProvider(['api_key' => 'test-api-key']);
        
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

    public function testReviewChunksWithResponseInCodeFences(): void
    {
        $jsonContent = json_encode([
            'findings' => [
                [
                    'file' => 'test.php',
                    'line' => 5,
                    'severity' => 'error',
                    'message' => 'Code fence test',
                    'rationale' => 'Wrapped in code fence',
                ],
            ],
        ]);

        $mockResponse = new Response(200, [], json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => "```json\n" . $jsonContent . "\n```",
                    ],
                ],
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new OpenAIProvider(['api_key' => 'test-api-key']);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $chunks = [['file' => 'test.php', 'additions' => [['line' => 5, 'content' => '+test']]]];
        $result = $provider->reviewChunks($chunks);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('Code fence test', $result[0]['message']);
    }

    public function testReviewChunksWithErrorStatus(): void
    {
        $mockResponse = new Response(400, [], 'Bad Request');
        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new OpenAIProvider(['api_key' => 'test-api-key']);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAIProvider error status: 400');

        $provider->reviewChunks([]);
    }

    public function testReviewChunksWithInvalidJsonResponse(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => 'not valid json',
                    ],
                ],
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new OpenAIProvider(['api_key' => 'test-api-key']);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $result = $provider->reviewChunks([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testReviewChunksWithMissingChoicesOrContent(): void
    {
        $responses = [
            new Response(200, [], json_encode(['invalid' => 'structure'])),
            new Response(200, [], json_encode(['choices' => []])),
            new Response(200, [], json_encode(['choices' => [['message' => ['invalid' => 'content']]]])),
        ];

        foreach ($responses as $mockResponse) {
            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);

            $provider = new OpenAIProvider(['api_key' => 'test-api-key']);
            
            $reflection = new ReflectionClass($provider);
            $clientProperty = $reflection->getProperty('client');
            $clientProperty->setAccessible(true);
            $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

            $result = $provider->reviewChunks([]);

            $this->assertIsArray($result);
            $this->assertEmpty($result);
        }
    }

    public function testGetName(): void
    {
        $provider = new OpenAIProvider(['api_key' => 'test-api-key']);
        $this->assertSame('openai', $provider->getName());
    }
}