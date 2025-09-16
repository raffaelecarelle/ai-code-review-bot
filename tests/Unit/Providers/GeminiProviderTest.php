<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Providers\GeminiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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

    public function testConstructorUsesDefaultModel(): void
    {
        $provider = new GeminiProvider(['api_key' => 'test-api-key']);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame(GeminiProvider::DEFAULT_MODEL, $modelProperty->getValue($provider));
    }

    public function testConstructorUsesCustomModel(): void
    {
        $customModel = 'gemini-1.5-flash';
        $provider = new GeminiProvider([
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
        $provider = new GeminiProvider([
            'api_key' => 'test-api-key',
            'model' => '',
        ]);

        $reflection = new ReflectionClass($provider);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertSame(GeminiProvider::DEFAULT_MODEL, $modelProperty->getValue($provider));
    }

    public function testConstructorSetsHttpClientWithCorrectConfiguration(): void
    {
        $apiKey = 'test-api-key';
        $provider = new GeminiProvider(['api_key' => $apiKey]);

        $reflection = new ReflectionClass($provider);
        
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($provider);

        $apiKeyProperty = $reflection->getProperty('apiKey');
        $apiKeyProperty->setAccessible(true);
        $storedApiKey = $apiKeyProperty->getValue($provider);

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame($apiKey, $storedApiKey);
        
        $config = $client->getConfig();
        $expectedEndpoint = GeminiProvider::DEFAULT_ENDPOINT_BASE . GeminiProvider::DEFAULT_MODEL . ':generateContent';
        $this->assertSame($expectedEndpoint, (string) $config['base_uri']);
        $this->assertSame(GeminiProvider::DEFAULT_TIMEOUT, $config['timeout']);
        $this->assertArrayHasKey('headers', $config);
        $this->assertSame('application/json', $config['headers']['Content-Type']);
    }

    public function testConstructorWithCustomEndpointAndTimeout(): void
    {
        $customEndpoint = 'https://custom-gemini-api.example.com';
        $customTimeout = 120.0;
        
        $provider = new GeminiProvider([
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

    public function testConstructorBuildsCorrectEndpointWithCustomModel(): void
    {
        $customModel = 'gemini-1.5-flash';
        $provider = new GeminiProvider([
            'api_key' => 'test-api-key',
            'model' => $customModel,
        ]);

        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($provider);

        $config = $client->getConfig();
        $expectedEndpoint = GeminiProvider::DEFAULT_ENDPOINT_BASE . $customModel . ':generateContent';
        $this->assertSame($expectedEndpoint, (string) $config['base_uri']);
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
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode(['findings' => $findings]),
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new GeminiProvider(['api_key' => 'test-api-key']);
        
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

        $provider = new GeminiProvider(['api_key' => 'test-api-key']);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GeminiProvider error status: 400');

        $provider->reviewChunks([]);
    }

    public function testReviewChunksWithInvalidJsonResponse(): void
    {
        $mockResponse = new Response(200, [], 'not valid json');

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new GeminiProvider(['api_key' => 'test-api-key']);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $result = $provider->reviewChunks([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testReviewChunksWithMissingCandidatesOrContent(): void
    {
        $responses = [
            new Response(200, [], json_encode(['invalid' => 'structure'])),
            new Response(200, [], json_encode(['candidates' => []])),
            new Response(200, [], json_encode(['candidates' => [['content' => ['invalid' => 'structure']]]])),
            new Response(200, [], json_encode(['candidates' => [['content' => ['parts' => []]]]])),
            new Response(200, [], json_encode(['candidates' => [['content' => ['parts' => [['invalid' => 'text']]]]]])),
        ];

        foreach ($responses as $mockResponse) {
            $mock = new MockHandler([$mockResponse]);
            $handlerStack = HandlerStack::create($mock);

            $provider = new GeminiProvider(['api_key' => 'test-api-key']);
            
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
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => $textResponse],
                        ],
                    ],
                ],
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new GeminiProvider(['api_key' => 'test-api-key']);
        
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

    public function testReviewChunksCallsCorrectEndpointWithApiKey(): void
    {
        $apiKey = 'test-api-key-123';
        $mockResponse = new Response(200, [], json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => '{"findings": []}'],
                        ],
                    ],
                ],
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);

        $provider = new GeminiProvider(['api_key' => $apiKey]);
        
        $reflection = new ReflectionClass($provider);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($provider, new Client(['handler' => $handlerStack]));

        $provider->reviewChunks([]);

        // Verify that the last request was made with the correct query parameter
        $lastRequest = $mock->getLastRequest();
        $this->assertNotNull($lastRequest);
        
        $queryParams = [];
        parse_str($lastRequest->getUri()->getQuery(), $queryParams);
        $this->assertArrayHasKey('key', $queryParams);
        $this->assertSame($apiKey, $queryParams['key']);
    }

    public function testGetName(): void
    {
        $provider = new GeminiProvider(['api_key' => 'test-api-key']);
        $this->assertSame('gemini', $provider->getName());
    }
}