<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\DiffParser;
use PHPUnit\Framework\TestCase;

final class DiffParserTest extends TestCase
{
    public function testParseEmptyDiff(): void
    {
        $result = DiffParser::parse('');
        
        $this->assertSame([], $result);
    }

    public function testParseSingleFileWithAdditions(): void
    {
        $diff = <<<DIFF
diff --git a/src/Example.php b/src/Example.php
index 1234567..abcdefg 100644
--- a/src/Example.php
+++ b/src/Example.php
@@ -1,3 +1,5 @@
 <?php
+
+declare(strict_types=1);
 
 class Example
DIFF;

        $result = DiffParser::parse($diff);
        
        $expected = [
            'src/Example.php' => [
                ['line' => 1, 'content' => ''],
                ['line' => 2, 'content' => 'declare(strict_types=1);'],
            ]
        ];
        
        $this->assertSame($expected, $result);
    }

    public function testParseMultipleFiles(): void
    {
        $diff = <<<DIFF
diff --git a/src/File1.php b/src/File1.php
index 1234567..abcdefg 100644
--- a/src/File1.php
+++ b/src/File1.php
@@ -1,2 +1,3 @@
 <?php
+// New comment
 class File1
diff --git a/src/File2.php b/src/File2.php
index 2345678..bcdefgh 100644
--- a/src/File2.php
+++ b/src/File2.php
@@ -10,3 +10,4 @@
 {
     public function test()
     {
+        return true;
DIFF;

        $result = DiffParser::parse($diff);
        
        $expected = [
            'src/File1.php' => [
                ['line' => 1, 'content' => '// New comment'],
            ],
            'src/File2.php' => [
                ['line' => 10, 'content' => '        return true;'],
            ]
        ];
        
        $this->assertSame($expected, $result);
    }

    public function testParseWithContextLines(): void
    {
        $diff = <<<DIFF
diff --git a/test.php b/test.php
index 1234567..abcdefg 100644
--- a/test.php
+++ b/test.php
@@ -5,6 +5,8 @@
 function example()
 {
     \$var = 1;
+    \$new = 2;
+    \$another = 3;
     return \$var;
 }
DIFF;

        $result = DiffParser::parse($diff);
        
        $expected = [
            'test.php' => [
                ['line' => 5, 'content' => '    $new = 2;'],
                ['line' => 6, 'content' => '    $another = 3;'],
            ]
        ];
        
        $this->assertSame($expected, $result);
    }

    public function testParseWithRemovedLines(): void
    {
        $diff = <<<DIFF
diff --git a/example.php b/example.php
index 1234567..abcdefg 100644
--- a/example.php
+++ b/example.php
@@ -1,5 +1,4 @@
 <?php
-// Old comment
 class Example
 {
+    // New comment
DIFF;

        $result = DiffParser::parse($diff);
        
        $expected = [
            'example.php' => [
                ['line' => 1, 'content' => '    // New comment'],
            ]
        ];
        
        $this->assertSame($expected, $result);
    }

    public function testParseMultipleHunksInSameFile(): void
    {
        $diff = <<<DIFF
diff --git a/multi.php b/multi.php
index 1234567..abcdefg 100644
--- a/multi.php
+++ b/multi.php
@@ -1,3 +1,4 @@
 <?php
+// First addition
 
 class Multi
@@ -10,3 +11,4 @@
 {
     public function method1()
     {
+        // Second addition
DIFF;

        $result = DiffParser::parse($diff);
        
        $expected = [
            'multi.php' => [
                ['line' => 1, 'content' => '// First addition'],
                ['line' => 11, 'content' => '        // Second addition'],
            ]
        ];
        
        $this->assertSame($expected, $result);
    }

    public function testParseIgnoresFileHeaderLines(): void
    {
        $diff = <<<DIFF
diff --git a/test.php b/test.php
index 1234567..abcdefg 100644
--- a/test.php
+++ b/test.php
@@ -1,2 +1,3 @@
 <?php
+echo "hello";
 class Test
DIFF;

        $result = DiffParser::parse($diff);
        
        // Should not include the +++ b/test.php line as content
        $expected = [
            'test.php' => [
                ['line' => 1, 'content' => 'echo "hello";'],
            ]
        ];
        
        $this->assertSame($expected, $result);
    }

    public function testParseWithTrailingNewline(): void
    {
        $diff = <<<DIFF
diff --git a/trailing.php b/trailing.php
index 1234567..abcdefg 100644
--- a/trailing.php
+++ b/trailing.php
@@ -1,2 +1,3 @@
 <?php
+// Added line
 class Trailing

DIFF;

        $result = DiffParser::parse($diff);
        
        $expected = [
            'trailing.php' => [
                ['line' => 1, 'content' => '// Added line'],
            ]
        ];
        
        $this->assertSame($expected, $result);
    }

    public function testParseOnlyAddedLines(): void
    {
        $diff = <<<DIFF
diff --git a/mixed.php b/mixed.php
index 1234567..abcdefg 100644
--- a/mixed.php
+++ b/mixed.php
@@ -1,6 +1,6 @@
 <?php
-\$old = 1;
+\$new = 1;
 class Mixed
 {
     // unchanged
 }
DIFF;

        $result = DiffParser::parse($diff);
        
        // Should only return added lines, not removed ones
        $expected = [
            'mixed.php' => [
                ['line' => 1, 'content' => '$new = 1;'],
            ]
        ];
        
        $this->assertSame($expected, $result);
    }

    public function testParseInvalidDiffFormat(): void
    {
        $diff = "This is not a valid diff format";
        
        $result = DiffParser::parse($diff);
        
        $this->assertSame([], $result);
    }

    public function testParseHunkWithoutFileHeader(): void
    {
        $diff = <<<DIFF
@@ -1,3 +1,4 @@
 line1
+added line
 line3
DIFF;

        $result = DiffParser::parse($diff);
        
        // Parser actually handles hunks without file header by using empty string as filename
        $expected = [
            '' => [
                ['line' => 1, 'content' => 'added line'],
            ]
        ];
        
        $this->assertSame($expected, $result);
    }
}