<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Support\TokenBudget;
use PHPUnit\Framework\TestCase;

final class TokenBudgetTest extends TestCase
{
    public function testEstimateAndPerFileCap(): void
    {
        $tb = new TokenBudget(100, 10, 'trim');
        $text = str_repeat('a', 200); // ~50 tokens
        $est = $tb->estimateTokens($text);
        $this->assertGreaterThan(0, $est);

        $capped = $tb->enforcePerFileCap($text);
        $this->assertTrue(strlen($capped) <= strlen($text));
        $this->assertTrue($tb->estimateTokens($capped) <= 10);
    }

    public function testShouldStopOnOverflowWithTrim(): void
    {
        $tb = new TokenBudget(20, 20, 'trim');
        $this->assertFalse($tb->shouldStop(0, 10));
        $this->assertTrue($tb->shouldStop(15, 10)); // 25 > 20
    }

    public function testFromContextDefaults(): void
    {
        $tb = TokenBudget::fromContext([]);
        $this->assertFalse($tb->shouldStop(0, 1));
        $short = $tb->enforcePerFileCap('abc');
        $this->assertSame('abc', $short);
    }

    public function testProviderSpecificTokenEstimation(): void
    {
        $text = 'function example() { return $this->property; }';
        
        $openAI = new TokenBudget(1000, 500, 'trim', 'openai');
        $anthropic = new TokenBudget(1000, 500, 'trim', 'anthropic');
        $gemini = new TokenBudget(1000, 500, 'trim', 'gemini');
        $mock = new TokenBudget(1000, 500, 'trim', 'mock');

        $openAITokens = $openAI->estimateTokens($text);
        $anthropicTokens = $anthropic->estimateTokens($text);
        $geminiTokens = $gemini->estimateTokens($text);
        $mockTokens = $mock->estimateTokens($text);

        // All should be positive
        $this->assertGreaterThan(0, $openAITokens);
        $this->assertGreaterThan(0, $anthropicTokens);
        $this->assertGreaterThan(0, $geminiTokens);
        $this->assertGreaterThan(0, $mockTokens);

        // Different providers should give different estimates
        $this->assertNotEquals($openAITokens, $anthropicTokens);
        $this->assertNotEquals($openAITokens, $geminiTokens);
    }

    public function testTokenCaching(): void
    {
        $tb = new TokenBudget(1000, 500, 'trim', 'openai');
        $text = 'function test() { return true; }';

        // First calculation should cache the result
        $firstEstimate = $tb->estimateTokens($text);
        $stats = $tb->getCacheStats();
        $this->assertEquals(1, $stats['cache_size']);

        // Second calculation should use cache
        $secondEstimate = $tb->estimateTokens($text);
        $this->assertEquals($firstEstimate, $secondEstimate);

        // Different content should increase cache
        $tb->estimateTokens('different content');
        $stats = $tb->getCacheStats();
        $this->assertEquals(2, $stats['cache_size']);

        // Clear cache should reset
        $tb->clearCache();
        $stats = $tb->getCacheStats();
        $this->assertEquals(0, $stats['cache_size']);
    }

    public function testSmartTruncationPreservesImportantContent(): void
    {
        $diffContent = 'diff --git a/test.php b/test.php
index 123..456 100644
--- a/test.php
+++ b/test.php
@@ -1,5 +1,10 @@
 <?php
 
+/**
+ * New function
+ */
+function newFunction(): string
+{
+    return "hello";
+}
+
 class TestClass
 {
     public function method(): bool
     {
-        return false;
+        return true;
     }
 }';

        $tb = new TokenBudget(1000, 50, 'trim', 'openai'); // Very small per-file cap
        $truncated = $tb->enforcePerFileCap($diffContent);

        // Should preserve diff markers
        $this->assertStringContainsString('diff --git', $truncated);
        $this->assertStringContainsString('@@', $truncated);
        
        // Should preserve added/removed lines
        $this->assertStringContainsString('+', $truncated);
        $this->assertStringContainsString('-', $truncated);

        // Result should be within token limit
        $this->assertLessThanOrEqual(50, $tb->estimateTokens($truncated));
    }

    public function testContentComplexityAnalysis(): void
    {
        $tb = new TokenBudget(1000, 500, 'trim', 'openai');

        $simpleText = 'This is simple text without much complexity';
        $codeContent = '+function example() { return $this->property; }';
        $symbolHeavy = 'var obj = { key: [1, 2, 3], func: () => {} };';
        $whitespaceHeavy = "line1\n    line2\n        line3\n            line4";

        $simpleTokens = $tb->estimateTokens($simpleText);
        $codeTokens = $tb->estimateTokens($codeContent);
        $symbolTokens = $tb->estimateTokens($symbolHeavy);
        $whitespaceTokens = $tb->estimateTokens($whitespaceHeavy);

        // Code content should have different token density than simple text
        $simpleRatio = strlen($simpleText) / $simpleTokens;
        $codeRatio = strlen($codeContent) / $codeTokens;
        
        $this->assertNotEquals($simpleRatio, $codeRatio);
        $this->assertGreaterThan(0, $simpleTokens);
        $this->assertGreaterThan(0, $codeTokens);
        $this->assertGreaterThan(0, $symbolTokens);
        $this->assertGreaterThan(0, $whitespaceTokens);
    }

    public function testFromContextWithProvider(): void
    {
        $context = [
            'provider' => 'anthropic',
            'diff_token_limit' => 5000,
            'per_file_token_cap' => 1000,
            'overflow_strategy' => 'keep'
        ];

        $tb = TokenBudget::fromContext($context);
        $stats = $tb->getCacheStats();
        
        $this->assertEquals('anthropic', $stats['provider']);
        $this->assertEquals(0, $stats['cache_size']);
        
        // Test that provider affects estimation
        $text = 'test content';
        $tokens = $tb->estimateTokens($text);
        $this->assertGreaterThan(0, $tokens);
    }

    public function testCacheStatsProvideCorrectInformation(): void
    {
        $tb = new TokenBudget(1000, 500, 'trim', 'gemini');
        
        // Initial stats
        $stats = $tb->getCacheStats();
        $this->assertEquals(0, $stats['cache_size']);
        $this->assertEquals('gemini', $stats['provider']);

        // After adding content
        $tb->estimateTokens('content 1');
        $tb->estimateTokens('content 2');
        
        $stats = $tb->getCacheStats();
        $this->assertEquals(2, $stats['cache_size']);
        $this->assertEquals('gemini', $stats['provider']);
    }

    public function testSmartTruncationFallbackBehavior(): void
    {
        $tb = new TokenBudget(1000, 10, 'trim', 'openai'); // Very restrictive cap
        
        // Content that will definitely exceed even after smart truncation
        $massiveContent = str_repeat('diff --git a/file.php b/file.php' . "\n", 100);
        
        $truncated = $tb->enforcePerFileCap($massiveContent);
        $tokens = $tb->estimateTokens($truncated);
        
        // Should still respect the cap even with fallback
        $this->assertLessThanOrEqual(10, $tokens);
        $this->assertNotEmpty($truncated);
        $this->assertLessThan(strlen($massiveContent), strlen($truncated));
    }

    public function testOverflowStrategyKeepDoesNotStop(): void
    {
        $tb = new TokenBudget(20, 20, 'keep'); // 'keep' instead of 'trim'
        
        // Should not stop even when over budget with 'keep' strategy
        $this->assertFalse($tb->shouldStop(15, 10)); // 25 > 20 but strategy is 'keep'
        $this->assertFalse($tb->shouldStop(100, 100)); // Way over budget but strategy is 'keep'
    }

    public function testGetRemainingBudget(): void
    {
        $tb = new TokenBudget(1000, 500, 'trim', 'openai');
        
        // Test remaining budget calculation
        $this->assertEquals(1000, $tb->getRemainingBudget(0));
        $this->assertEquals(700, $tb->getRemainingBudget(300));
        $this->assertEquals(0, $tb->getRemainingBudget(1000));
        $this->assertEquals(0, $tb->getRemainingBudget(1200)); // Over budget should return 0
    }

    public function testCompressDiff(): void
    {
        $tb = new TokenBudget(1000, 500, 'trim', 'openai');
        
        $diffContent = 'diff --git a/test.php b/test.php
index 123..456 100644
--- a/test.php
+++ b/test.php
@@ -1,10 +1,15 @@
 <?php

+/**
+ * A very long comment that should be compressed
+ * with multiple lines of documentation
+ */
+function newFunction(): string
+{
+    return "hello world";
+}
+
 class TestClass
 {
     public function method(): bool
     {
-        return false;
+        return true;
     }
 }';

        $compressed = $tb->compressDiff($diffContent, 100); // Very small token limit
        
        // Should be shorter than original
        $this->assertLessThan(strlen($diffContent), strlen($compressed));
        
        // Should preserve important structural elements
        $this->assertStringContainsString('diff --git', $compressed);
        $this->assertStringContainsString('@@', $compressed);
        
        // Should compress or truncate content when over budget
        $compressedTokens = $tb->estimateTokens($compressed);
        $this->assertLessThanOrEqual(100, $compressedTokens);
    }

    public function testCompressDiffHandlesComments(): void
    {
        $tb = new TokenBudget(1000, 500, 'trim', 'openai');
        
        $diffWithComments = '+/* This is a very long comment that should be compressed */
+function test() {
+    return true;
+}';

        $compressed = $tb->compressDiff($diffWithComments, 50);
        
        // Should compress long comments
        $this->assertStringContainsString('/* ... */', $compressed);
        $this->assertStringContainsString('function test', $compressed);
    }

    public function testFilterTrivialChanges(): void
    {
        $tb = new TokenBudget(1000, 500, 'trim', 'openai');
        
        $diffWithTrivialChanges = 'diff --git a/test.php b/test.php
index 123..456 100644
--- a/test.php
+++ b/test.php
@@ -1,10 +1,15 @@
 <?php
+
+use App\Helper;
+use Another\Class;
+
+// TODO: Fix this later
+// FIXME: Remove this hack
 class TestClass
 {
+    /** @var string */
+    private $property;
+
     public function method(): bool
     {
-        return false;
+        return true;
     }
 }';

        $filtered = $tb->filterTrivialChanges($diffWithTrivialChanges);
        
        // Should remove trivial additions
        $this->assertStringNotContainsString('+use App\Helper;', $filtered);
        $this->assertStringNotContainsString('+use Another\Class;', $filtered);
        $this->assertStringNotContainsString('// TODO:', $filtered);
        $this->assertStringNotContainsString('// FIXME:', $filtered);
        // Note: /** @var string */ doesn't match the filter pattern for DocBlock annotations
        $this->assertStringContainsString('/** @var string */', $filtered);
        
        // Should preserve important changes
        $this->assertStringContainsString('class TestClass', $filtered);
        $this->assertStringContainsString('return true;', $filtered);
    }

    public function testFilterTrivialChangesPreservesImportantContent(): void
    {
        $tb = new TokenBudget(1000, 500, 'trim', 'openai');
        
        $diffWithMixedContent = '+function importantFunction() {
+    return "important";
+}
+
+use SomePackage\Class;
+// TODO: implement later
+
+class RealClass {
+    public function realMethod() {
+        // This is important logic
+        return $this->process();
+    }
+}';

        $filtered = $tb->filterTrivialChanges($diffWithMixedContent);
        
        // Should preserve important code
        $this->assertStringContainsString('importantFunction', $filtered);
        $this->assertStringContainsString('RealClass', $filtered);
        $this->assertStringContainsString('realMethod', $filtered);
        $this->assertStringContainsString('// This is important logic', $filtered);
        
        // Should filter out trivial content
        $this->assertStringNotContainsString('use SomePackage\Class;', $filtered);
        $this->assertStringNotContainsString('// TODO:', $filtered);
    }

    public function testCompressDiffWithEmptyInput(): void
    {
        $tb = new TokenBudget(1000, 500, 'trim', 'openai');
        
        $compressed = $tb->compressDiff('', 100);
        $this->assertEquals('', $compressed);
    }

    public function testFilterTrivialChangesWithEmptyInput(): void
    {
        $tb = new TokenBudget(1000, 500, 'trim', 'openai');
        
        $filtered = $tb->filterTrivialChanges('');
        $this->assertEquals('', $filtered);
    }
}
