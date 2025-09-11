<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Providers\AbstractLLMProvider;
use PHPUnit\Framework\TestCase;


final class AbstractLLMProviderTest extends TestCase
{
    private function getTestDouble(): object
    {
        return new class extends AbstractLLMProvider {
            public static function callBuildPrompt(array $chunks): string { return self::buildPrompt($chunks); }
            public static function callExtract(string $content): array { return self::extractFindingsFromText($content); }
            public function reviewChunks(array $chunks): array { return []; }
        };
    }

    public function testBuildPromptContainsFileAndLines(): void
    {
        $double = $this->getTestDouble();
        $prompt = $double::callBuildPrompt([
            ['file_path' => 'app/Test.php', 'start_line' => 5, 'lines' => [
                ['line' => 5, 'content' => 'echo "x";'],
            ]],
        ]);
        $this->assertStringContainsString('FILE: app/Test.php (first +hunk starts ~5)', $prompt);
        $this->assertStringContainsString('+ 5: echo "x";', $prompt);
    }

    public function testBuildPromptUsesUnifiedDiffWhenProvided(): void
    {
        $double = $this->getTestDouble();
        $udiff = "diff --git a/app/Test.php b/app/Test.php\n@@ -1,1 +1,2 @@\n+echo 'x';\n";
        $prompt = $double::callBuildPrompt([
            ['file_path' => 'app/Test.php', 'start_line' => 1, 'unified_diff' => $udiff],
        ]);
        $this->assertStringContainsString('FILE: app/Test.php (first +hunk starts ~1)', $prompt);
        $this->assertStringContainsString('diff --git a/app/Test.php b/app/Test.php', $prompt);
        $this->assertStringContainsString('@@ -1,1 +1,2 @@', $prompt);
    }

    public function testExtractFindingsFromTextParsesJsonAndCodeFence(): void
    {
        $double = $this->getTestDouble();
        $json = '{"findings":[{"rule_id":"X","title":"t","severity":"info","file_path":"f","start_line":1,"end_line":1,"rationale":"r","suggestion":"s","content":"c"}] }';
        $findings = $double::callExtract($json);
        $this->assertCount(1, $findings);

        $fenced = "```json\n{".
            "\"findings\":[{\"rule_id\":\"Y\",\"title\":\"t\",\"severity\":\"info\",\"file_path\":\"f\",\"start_line\":2,\"end_line\":2,\"rationale\":\"r\",\"suggestion\":\"s\",\"content\":\"c\"}]}\n".
            "```";
        $findings2 = $double::callExtract($fenced);
        $this->assertCount(1, $findings2);
    }
}
