<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\DiffParser;
use PHPUnit\Framework\TestCase;

final class DiffParserTest extends TestCase
{
    public function testParsesAddedLinesWithLineNumbers(): void
    {
        $diff = <<<'DIFF'
--- a/a.php
+++ b/a.php
@@ -1,3 +1,4 @@
 line1
-line2
+line2_mod
 line3
+line4_new
DIFF;
        $out = DiffParser::parse($diff);
        $this->assertArrayHasKey('a.php', $out);
        $added = $out['a.php'];
        $this->assertSame(2, $added[0]['line']);
        $this->assertSame('line2_mod', $added[0]['content']);
        $this->assertSame(4, $added[1]['line']);
        $this->assertSame('line4_new', $added[1]['content']);
    }
}
