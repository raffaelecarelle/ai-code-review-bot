<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Providers\AbstractLLMProvider;
use AICR\Providers\AIProvider;
use PHPUnit\Framework\TestCase;

final class AbstractLLMProviderTest extends TestCase
{
    private function harness(): AIProvider
    {
        return new class extends AbstractLLMProvider {
            /** @param array<int, array<string, mixed>> $chunks */
            public function reviewChunks(array $chunks): array { return []; }
            /** @param array<int, array<string, mixed>> $chunks */
            public function callBuildPrompt(array $chunks): string { return self::buildPrompt($chunks); }
            /** @param array<string, mixed> $options */
            public function callMergePrompts(string $sys, string $user, array $options): array { return self::mergeAdditionalPrompts($sys, $user, $options); }
            public function callExtract(string $txt): array { return self::extractFindingsFromText($txt); }
            public function callSystem(): string { return self::systemPrompt(); }
        };
    }

    public function testBuildPromptWithUnifiedDiffAndLegacyLines(): void
    {
        $h = $this->harness();
        $chunks = [
            ['file_path' => 'a.php', 'start_line' => 10, 'unified_diff' => "diff --git a/a.php b/a.php\n@@ -1,2 +1,2 @@\n- old\n+ new\n"],
            ['file_path' => 'b.php', 'start_line' => 1, 'lines' => [
                ['line' => 1, 'content' => '<?php echo 1;'],
                ['line' => 2, 'content' => 'echo 2;'],
            ]],
        ];
        $prompt = $h->callBuildPrompt($chunks);
        $this->assertStringContainsString('You are an AI Code Review bot', $prompt);
        $this->assertStringContainsString('FILE: a.php (first +hunk starts ~10)', $prompt);
        $this->assertStringContainsString('diff --git', $prompt);
        $this->assertStringContainsString('FILE: b.php (first +hunk starts ~1)', $prompt);
        $this->assertStringContainsString('+ 1: <?php echo 1;', $prompt);
    }

    public function testMergeAdditionalPrompts(): void
    {
        $h = $this->harness();
        [$sys, $user] = $h->callMergePrompts('S', 'U', [
            'prompts' => [
                'system_append' => ['A', 'B'],
                'user_append' => 'C',
                'extra' => ['D', '']
            ]
        ]);
        $this->assertStringContainsString("S\n\nA\n\nB", $sys);
        $this->assertStringContainsString("U\n\nC\n\nD", $user);
    }

    public function testExtractFindingsFromTextVariants(): void
    {
        $h = $this->harness();
        $plain = json_encode(['findings' => [['rule_id' => 'X']]]);
        $this->assertSame('X', $h->callExtract($plain)[0]['rule_id'] ?? null);

        $fenced = "```json\n{".'"findings"'.": [{".'"rule_id"'.": ".'"Y"' ."}]}\n```";
        $this->assertSame('Y', $h->callExtract($fenced)[0]['rule_id'] ?? null);

        $this->assertSame([], $h->callExtract('nonsense'));
        $this->assertStringContainsString('ONLY valid JSON', $h->callSystem());
    }
}
