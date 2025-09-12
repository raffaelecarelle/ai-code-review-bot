<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Adapters;

use AICR\Adapters\BitbucketAdapter;
use AICR\Adapters\VcsAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class BitbucketAdapterTest extends TestCase
{
    public function testConstructorWithValidOptions(): void
    {
        $adapter = new BitbucketAdapter('my-workspace', 'my-repo', 'test-token');
        
        $this->assertInstanceOf(BitbucketAdapter::class, $adapter);
        $this->assertInstanceOf(VcsAdapter::class, $adapter);
        $this->assertEquals('my-workspace/my-repo', $adapter->getRepositoryIdentifier());
    }

    public function testConstructorWithBearerAuth(): void
    {
        $adapter = new BitbucketAdapter('my-workspace', 'my-repo', 'test-token');
        
        $this->assertInstanceOf(BitbucketAdapter::class, $adapter);
    }

    public function testConstructorWithCustomTimeout(): void
    {
        $adapter = new BitbucketAdapter('my-workspace', 'my-repo', 'test-token', 60);
        
        $this->assertInstanceOf(BitbucketAdapter::class, $adapter);
    }

    public function testResolveBranchesFromIdSuccess(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'destination' => [
                'branch' => ['name' => 'main']
            ],
            'source' => [
                'branch' => ['name' => 'feature-branch']
            ]
        ], JSON_THROW_ON_ERROR));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        
        $adapter = $this->createAdapterWithMockClient($handlerStack);
        
        $result = $adapter->resolveBranchesFromId(123);
        
        $this->assertEquals(['main', 'feature-branch'], $result);
    }

    public function testResolveBranchesFromIdWithInvalidResponse(): void
    {
        $mockResponse = new Response(200, [], 'invalid json');

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        
        $adapter = $this->createAdapterWithMockClient($handlerStack);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid response from Bitbucket API');
        
        $adapter->resolveBranchesFromId(123);
    }

    public function testResolveBranchesFromIdWithMissingBranches(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'destination' => [],
            'source' => []
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        
        $adapter = $this->createAdapterWithMockClient($handlerStack);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not resolve branch names from PR data');
        
        $adapter->resolveBranchesFromId(123);
    }

    public function testResolveBranchesFromIdWithRequestException(): void
    {
        $request = new Request('GET', 'test');
        $exception = new RequestException('Network error', $request);

        $mock = new MockHandler([$exception]);
        $handlerStack = HandlerStack::create($mock);
        
        $adapter = $this->createAdapterWithMockClient($handlerStack);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to resolve branches for PR #123: Network error');
        
        $adapter->resolveBranchesFromId(123);
    }

    public function testPostCommentSuccess(): void
    {
        $mockResponse = new Response(201, []);

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        
        $adapter = $this->createAdapterWithMockClient($handlerStack);
        
        // Should not throw exception
        $adapter->postComment(123, 'Test comment');
        
        $this->assertTrue(true); // If we reach here, the test passed
    }

    public function testPostCommentWithRequestException(): void
    {
        $request = new Request('POST', 'test');
        $exception = new RequestException('API error', $request);

        $mock = new MockHandler([$exception]);
        $handlerStack = HandlerStack::create($mock);
        
        $adapter = $this->createAdapterWithMockClient($handlerStack);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to post comment to PR #123: API error');
        
        $adapter->postComment(123, 'Test comment');
    }

    public function testGetPullRequestDetailsSuccess(): void
    {
        $prData = [
            'id' => 123,
            'title' => 'Test PR',
            'state' => 'OPEN',
            'author' => ['display_name' => 'Test User']
        ];

        $mockResponse = new Response(200, [], json_encode($prData));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        
        $adapter = $this->createAdapterWithMockClient($handlerStack);
        
        $result = $adapter->getPullRequestDetails(123);
        
        $this->assertEquals($prData, $result);
    }

    public function testGetPullRequestDetailsWithInvalidResponse(): void
    {
        $mockResponse = new Response(200, [], 'invalid json');

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        
        $adapter = $this->createAdapterWithMockClient($handlerStack);
        
        $result = $adapter->getPullRequestDetails(123);
        
        $this->assertEquals([], $result);
    }

    public function testGetPullRequestDetailsWithRequestException(): void
    {
        $request = new Request('GET', 'test');
        $exception = new RequestException('Not found', $request);

        $mock = new MockHandler([$exception]);
        $handlerStack = HandlerStack::create($mock);
        
        $adapter = $this->createAdapterWithMockClient($handlerStack);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to get PR details for #123: Not found');
        
        $adapter->getPullRequestDetails(123);
    }

    public function testGetRepositoryIdentifier(): void
    {
        $adapter = new BitbucketAdapter('test-workspace', 'test-repo', 'test-token');
        
        $this->assertEquals('test-workspace/test-repo', $adapter->getRepositoryIdentifier());
    }

    public function testResolveBranchesFromIdWithCompleteResponse(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 123,
            'title' => 'Feature implementation',
            'state' => 'OPEN',
            'destination' => [
                'branch' => [
                    'name' => 'develop',
                    'target' => ['hash' => 'abc123']
                ],
                'repository' => ['full_name' => 'workspace/repo']
            ],
            'source' => [
                'branch' => [
                    'name' => 'feature/new-feature',
                    'target' => ['hash' => 'def456']
                ],
                'repository' => ['full_name' => 'workspace/repo']
            ],
            'author' => [
                'display_name' => 'John Doe',
                'uuid' => '{user-uuid}'
            ]
        ], JSON_THROW_ON_ERROR));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        
        $adapter = $this->createAdapterWithMockClient($handlerStack);
        
        $result = $adapter->resolveBranchesFromId(123);
        
        $this->assertEquals(['develop', 'feature/new-feature'], $result);
    }

    public function testInterfaceImplementation(): void
    {
        $adapter = new BitbucketAdapter('test-workspace', 'test-repo', 'test-token');
        
        $this->assertInstanceOf(VcsAdapter::class, $adapter);
        
        // Verify required methods are implemented
        $this->assertTrue(method_exists($adapter, 'resolveBranchesFromId'));
        $this->assertTrue(method_exists($adapter, 'postComment'));
    }

    private function createAdapterWithMockClient(HandlerStack $handlerStack): BitbucketAdapter
    {
        $adapter = new BitbucketAdapter('test-workspace', 'test-repo', 'test-token');
        
        // Use reflection to inject mock client
        $reflection = new \ReflectionClass($adapter);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        
        $mockClient = new Client(['handler' => $handlerStack]);
        $clientProperty->setValue($adapter, $mockClient);
        
        return $adapter;
    }
}