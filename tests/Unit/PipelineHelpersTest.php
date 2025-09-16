<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Config;
use AICR\Pipeline;
use AICR\Tests\Support\MockAIProvider;
use PHPUnit\Framework\TestCase;

final class PipelineHelpersTest extends TestCase
{
    private function makeConfig(): Config
    {
        $tmp = sys_get_temp_dir().'/aicr_ph_'.uniqid('', true).'.yml';
        file_put_contents($tmp, "providers:\n  default: mock\ncontext:\n  diff_token_limit: 10000\n  per_file_token_cap: 5000\n");
        $cfg = Config::load($tmp);
        @unlink($tmp);

        return $cfg;
    }

    public function testStartLineExtractionAcrossFiles(): void
    {
        $diff = <<<DIFF
Diff Header

diff --git a/foo.php b/foo.php
index 111..222 100644
--- a/foo.php
+++ b/foo.php
@@ -5,3 +10,4 @@
- old
+ new1
+ new2
 context
@@ -20,2 +30,2 @@
- more old
+ more new

diff --git a/bar.php b/bar.php
index 333..444 100644
--- a/bar.php
+++ b/bar.php
@@ -1,1 +1,1 @@
+ only one
DIFF;
        $tmpDiff = sys_get_temp_dir().'/aicr_diff_'.uniqid('', true).'.diff';
        file_put_contents($tmpDiff, $diff);

        $mock = new MockAIProvider();
        $pipeline = new Pipeline($this->makeConfig(), $mock);
        $pipeline->run($tmpDiff, Pipeline::OUTPUT_FORMAT_JSON);
        @unlink($tmpDiff);

        $chunks = $mock->lastChunks;
        $this->assertCount(2, $chunks);
        $byFile = [];
        foreach ($chunks as $c) {
            $byFile[$c['file']] = $c;
        }
        $this->assertSame(10, $byFile['b/foo.php']['start_line']);
        $this->assertSame(1, $byFile['b/bar.php']['start_line']);
    }
}
