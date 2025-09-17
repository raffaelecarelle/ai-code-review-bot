<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Support;

use AICR\Support\InputSanitizer;
use PHPUnit\Framework\TestCase;

final class InputSanitizerTest extends TestCase
{
    public function testSanitizeBranchNameWithValidInput(): void
    {
        $validBranch = 'feature/user-authentication_v2.1';
        $result = InputSanitizer::sanitizeBranchName($validBranch);
        
        $this->assertSame($validBranch, $result);
    }
    
    public function testSanitizeBranchNameTrimsWhitespace(): void
    {
        $branchWithSpaces = '  main  ';
        $result = InputSanitizer::sanitizeBranchName($branchWithSpaces);
        
        $this->assertSame('main', $result);
    }
    
    public function testSanitizeBranchNameThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Branch name cannot be empty');
        
        InputSanitizer::sanitizeBranchName('');
    }
    
    public function testSanitizeBranchNameThrowsOnTooLong(): void
    {
        $longBranch = str_repeat('a', 256);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Branch name too long');
        
        InputSanitizer::sanitizeBranchName($longBranch);
    }
    
    public function testSanitizeBranchNameThrowsOnInvalidCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Branch name contains invalid characters');
        
        InputSanitizer::sanitizeBranchName('feature<>branch');
    }
    
    public function testSanitizeRepositoryNameWithValidInput(): void
    {
        $validRepo = 'my-awesome_repo.v2';
        $result = InputSanitizer::sanitizeRepositoryName($validRepo);
        
        $this->assertSame($validRepo, $result);
    }
    
    public function testSanitizeRepositoryNameThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Repository name cannot be empty');
        
        InputSanitizer::sanitizeRepositoryName('   ');
    }
    
    public function testSanitizeRepositoryNameThrowsOnTooLong(): void
    {
        $longRepo = str_repeat('a', 101);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Repository name too long');
        
        InputSanitizer::sanitizeRepositoryName($longRepo);
    }
    
    public function testSanitizeFilePathWithValidInput(): void
    {
        $validPath = 'src/Support/InputSanitizer.php';
        $result = InputSanitizer::sanitizeFilePath($validPath);
        
        $this->assertSame($validPath, $result);
    }
    
    public function testSanitizeFilePathThrowsOnPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File path contains invalid sequences');
        
        InputSanitizer::sanitizeFilePath('../../../etc/passwd');
    }
    
    public function testSanitizeFilePathThrowsOnBackslash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File path contains invalid sequences');
        
        InputSanitizer::sanitizeFilePath('src\\windows\\path');
    }
    
    public function testSanitizeApiResponseWithNestedArray(): void
    {
        $input = [
            'user_name' => 'john_doe',
            'user@email' => 'test@example.com', // Invalid key
            'nested' => [
                'valid_key' => 'value',
                'special!key' => 'should_be_cleaned',
            ],
            'number' => 42,
            'boolean' => true,
            'null_value' => null, // Should be skipped
        ];
        
        $result = InputSanitizer::sanitizeApiResponse($input);
        
        $this->assertArrayHasKey('user_name', $result);
        $this->assertArrayHasKey('useremail', $result); // Cleaned key
        $this->assertArrayHasKey('nested', $result);
        $this->assertArrayHasKey('number', $result);
        $this->assertArrayHasKey('boolean', $result);
        $this->assertArrayNotHasKey('null_value', $result);
        
        $this->assertIsArray($result['nested']);
        $this->assertArrayHasKey('valid_key', $result['nested']);
        $this->assertArrayHasKey('specialkey', $result['nested']); // Cleaned key
    }
    
    public function testSanitizeUrlWithValidHttps(): void
    {
        $validUrl = 'https://api.example.com/v1/endpoint';
        $result = InputSanitizer::sanitizeUrl($validUrl);
        
        $this->assertSame($validUrl, $result);
    }
    
    public function testSanitizeUrlWithValidHttp(): void
    {
        $validUrl = 'http://localhost:8080/api';
        $result = InputSanitizer::sanitizeUrl($validUrl);
        
        $this->assertSame($validUrl, $result);
    }
    
    public function testSanitizeUrlThrowsOnInvalidScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('URL must use HTTP or HTTPS scheme');
        
        InputSanitizer::sanitizeUrl('ftp://example.com/file.txt');
    }
    
    public function testSanitizeUrlThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('URL cannot be empty');
        
        InputSanitizer::sanitizeUrl('');
    }
    
    public function testSanitizeUrlThrowsOnTooLong(): void
    {
        $longUrl = 'https://example.com/' . str_repeat('a', 2000);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('URL too long');
        
        InputSanitizer::sanitizeUrl($longUrl);
    }
    
    public function testSanitizeCommitShaWithValidShortSha(): void
    {
        $shortSha = 'abc1234';
        $result = InputSanitizer::sanitizeCommitSha($shortSha);
        
        $this->assertSame('abc1234', $result);
    }
    
    public function testSanitizeCommitShaWithValidFullSha(): void
    {
        $fullSha = 'abcdef1234567890abcdef1234567890abcdef12';
        $result = InputSanitizer::sanitizeCommitSha($fullSha);
        
        $this->assertSame($fullSha, $result);
    }
    
    public function testSanitizeCommitShaConvertsToLowercase(): void
    {
        $upperSha = 'ABCDEF1234567890';
        $result = InputSanitizer::sanitizeCommitSha($upperSha);
        
        $this->assertSame('abcdef1234567890', $result);
    }
    
    public function testSanitizeCommitShaThrowsOnTooShort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Commit SHA must be 7-40 characters long');
        
        InputSanitizer::sanitizeCommitSha('abc123');
    }
    
    public function testSanitizeCommitShaThrowsOnTooLong(): void
    {
        $tooLongSha = str_repeat('a', 41);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Commit SHA must be 7-40 characters long');
        
        InputSanitizer::sanitizeCommitSha($tooLongSha);
    }
    
    public function testSanitizeCommitShaThrowsOnInvalidCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Commit SHA must contain only hexadecimal characters');
        
        InputSanitizer::sanitizeCommitSha('xyz1234');
    }
    
    public function testSanitizeCommitShaThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Commit SHA cannot be empty');
        
        InputSanitizer::sanitizeCommitSha('');
    }
}