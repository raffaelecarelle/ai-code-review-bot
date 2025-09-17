<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Support;

use AICR\Support\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \AICR\Support\Logger
 */
final class LoggerTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $logRecords = [];
    
    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logRecords = [];
        $this->mockLogger = $this->createMockLogger();
        Logger::setInstance($this->mockLogger);
    }

    protected function tearDown(): void
    {
        // Reset logger instance for other tests
        Logger::setInstance(Logger::getInstance());
        parent::tearDown();
    }

    private function createMockLogger(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        
        $logger->method('info')
            ->willReturnCallback(function (string $message, array $context = []) {
                $this->logRecords[] = [
                    'level' => 'info',
                    'message' => $message,
                    'context' => $context,
                ];
            });
            
        $logger->method('error')
            ->willReturnCallback(function (string $message, array $context = []) {
                $this->logRecords[] = [
                    'level' => 'error',
                    'message' => $message,
                    'context' => $context,
                ];
            });

        return $logger;
    }

    private function hasInfoRecords(): bool
    {
        return !empty(array_filter($this->logRecords, fn($record) => $record['level'] === 'info'));
    }

    private function hasErrorRecords(): bool
    {
        return !empty(array_filter($this->logRecords, fn($record) => $record['level'] === 'error'));
    }

    public function testGetInstanceReturnsLoggerInterface(): void
    {
        $logger = Logger::getInstance();
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testLogApiCallStructuresDataCorrectly(): void
    {
        Logger::logApiCall('openai', 'chat/completions', ['model' => 'gpt-4']);

        $this->assertTrue($this->hasInfoRecords());
        $this->assertCount(1, $this->logRecords);
        
        $record = $this->logRecords[0];
        $this->assertSame('API call', $record['message']);
        $this->assertSame('openai', $record['context']['provider']);
        $this->assertSame('chat/completions', $record['context']['method']);
        $this->assertSame('gpt-4', $record['context']['model']);
        $this->assertArrayHasKey('timestamp', $record['context']);
    }

    public function testLogPerformanceStructuresDataCorrectly(): void
    {
        Logger::logPerformance('diff_processing', 1.234, ['file_count' => 5]);

        $this->assertTrue($this->hasInfoRecords());
        $this->assertCount(1, $this->logRecords);
        
        $record = $this->logRecords[0];
        $this->assertSame('Performance metric', $record['message']);
        $this->assertSame('diff_processing', $record['context']['operation']);
        $this->assertSame(1234.0, $record['context']['duration_ms']);
        $this->assertSame(5, $record['context']['file_count']);
        $this->assertArrayHasKey('timestamp', $record['context']);
    }

    public function testLogErrorStructuresDataCorrectly(): void
    {
        $exception = new \RuntimeException('Test error', 123);
        Logger::logError($exception, ['custom_field' => 'custom_value']);

        $this->assertTrue($this->hasErrorRecords());
        $this->assertCount(1, $this->logRecords);
        
        $record = $this->logRecords[0];
        $this->assertSame('Test error', $record['message']);
        $this->assertSame('RuntimeException', $record['context']['exception_class']);
        $this->assertSame(123, $record['context']['code']);
        $this->assertArrayHasKey('file', $record['context']);
        $this->assertArrayHasKey('line', $record['context']);
        $this->assertArrayHasKey('trace', $record['context']);
        $this->assertArrayHasKey('correlation_id', $record['context']);
        $this->assertArrayHasKey('timestamp', $record['context']);
        $this->assertSame('custom_value', $record['context']['custom_field']);
    }

    public function testLogConfigChangeStructuresDataCorrectly(): void
    {
        Logger::logConfigChange('load', ['source' => '/path/to/config.yml']);

        $this->assertTrue($this->hasInfoRecords());
        $this->assertCount(1, $this->logRecords);
        
        $record = $this->logRecords[0];
        $this->assertSame('Configuration change', $record['message']);
        $this->assertSame('load', $record['context']['action']);
        $this->assertSame('/path/to/config.yml', $record['context']['source']);
        $this->assertArrayHasKey('timestamp', $record['context']);
    }

    public function testSetInstanceChangesLogger(): void
    {
        $customLogger = $this->createMock(LoggerInterface::class);
        Logger::setInstance($customLogger);
        
        $retrieved = Logger::getInstance();
        $this->assertSame($customLogger, $retrieved);
    }
}