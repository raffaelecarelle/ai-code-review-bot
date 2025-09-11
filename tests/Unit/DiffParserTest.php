<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\DiffParser;
use PHPUnit\Framework\TestCase;

final class DiffParserTest extends TestCase
{
    public function testParsesAddedLinesWithCorrectLineNumbers(): void
    {
        $diff = <<<DIFF
--- a/src/Foo.php
+++ b/src/Foo.php
@@ -1,3 +1,4 @@
 class Foo {
+    public function bar() {}
     // comment
 }
DIFF;
        $res = DiffParser::parse($diff);
        $this->assertArrayHasKey('src/Foo.php', $res);
        $this->assertCount(1, $res['src/Foo.php']);
        $this->assertSame(2, $res['src/Foo.php'][0]['line']);
        $this->assertSame('    public function bar() {}', $res['src/Foo.php'][0]['content']);
    }

    public function testDeletionsOnlyFileYieldsNoAddedLines(): void
    {
        $diff = <<<DIFF
--- a/src/OnlyDel.php
+++ b/src/OnlyDel.php
@@ -2,2 +2,0 @@
-    old1
-    old2
DIFF;
        $res = DiffParser::parse($diff);
        // Parser only returns added lines; deletions only should lead to an empty array for that file
        $this->assertArrayHasKey('src/OnlyDel.php', $res);
        $this->assertSame([], $res['src/OnlyDel.php']);
    }

    public function testMultipleHunksAdvancesTargetLineCorrectly(): void
    {
        $diff = <<<DIFF
--- a/a.txt
+++ b/a.txt
@@ -1,2 +1,2 @@
 line1
+line2
@@ -10,2 +11,3 @@
 context
+addedX
+addedY
DIFF;
        $res = DiffParser::parse($diff);
        $this->assertArrayHasKey('a.txt', $res);
        $this->assertCount(3, $res['a.txt']);
        $this->assertSame(2, $res['a.txt'][0]['line']);
        $this->assertSame('line2', $res['a.txt'][0]['content']);
        $this->assertSame(12, $res['a.txt'][1]['line']);
        $this->assertSame('addedX', $res['a.txt'][1]['content']);
        $this->assertSame(13, $res['a.txt'][2]['line']);
        $this->assertSame('addedY', $res['a.txt'][2]['content']);
    }
}
