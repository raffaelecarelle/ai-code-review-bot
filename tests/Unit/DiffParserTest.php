<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\DiffParser;
use PHPUnit\Framework\TestCase;

final class DiffParserTest extends TestCase
{
    public function testParseSampleDiff(): void
    {
        $diff = file_get_contents(__DIR__.'/../../examples/sample.diff');
        $this->assertNotFalse($diff);
        $parsed = DiffParser::parse($diff);
        $this->assertIsArray($parsed);
        $this->assertNotEmpty($parsed);
        $firstFile = array_key_first($parsed);
        $this->assertIsString($firstFile);
        $this->assertIsArray($parsed[$firstFile]);
        $firstLine = $parsed[$firstFile][0] ?? null;
        $this->assertIsArray($firstLine);
        $this->assertArrayHasKey('line', $firstLine);
        $this->assertArrayHasKey('content', $firstLine);
    }

    public function testAccurateLineNumbersAndIgnoreDeletions(): void
    {
        $diff = <<<DIFF
--- a/x.php
+++ b/x.php
@@ -1,3 +10,4 @@
- old
  same
+ add
+ add2
- gone
DIFF;
        $parsed = DiffParser::parse($diff);
        $this->assertArrayHasKey('x.php', $parsed);
        $lines = $parsed['x.php'];
        $this->assertSame(10, $lines[0]['line']);
        $this->assertSame(' add', substr($lines[0]['content'], -4));
        $this->assertSame(11, $lines[1]['line']);
        $this->assertStringEndsWith('add2', $lines[1]['content']);
    }
}
