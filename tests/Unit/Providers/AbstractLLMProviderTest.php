<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;

use AICR\Providers\AbstractLLMProvider;

// Stub subclass to expose protected static helpers
class _StubLLMProvider extends AbstractLLMProvider
{
    /** @param array<int, array<string,mixed>> $chunks */
    public static function callBuildPrompt(array $chunks): string { return parent::buildPrompt($chunks); }
    /** @param array<string,mixed> $options */
    public static function callMerge(string $system, string $user, array $options): array { return parent::mergeAdditionalPrompts($system, $user, $options); }
    /** @return array<int, array<string,mixed>> */
    public static function callExtract(string $content): array { return parent::extractFindingsFromText($content); }
    /** @param array<int, array<string,mixed>> $chunks */
    public function reviewChunks(array $chunks, ?array $policyConfig = null): array { return []; }

    public function getName(): string
    {
        return 'stub';
    }

}

final class AbstractLLMProviderTest extends TestCase
{
    public function testBuildPromptWithUnifiedDiffAndFallback(): void
    {
        $chunks = [
            [
                'file'    => 'src/Foo.php',
                'start_line'   => 10,
                'unified_diff' => "@@ -1,3 +1,3 @@\n- old\n+ new\n",
            ],
            [
                'file' => 'src/Bar.php',
                'start_line' => 5,
                // no unified_diff -> fallback to lines
                'lines' => [
                    ['line' => 5, 'content' => 'echo 1;'],
                    ['line' => 6, 'content' => 'echo 2;'],
                ],
            ],
        ];

        $prompt = _StubLLMProvider::callBuildPrompt($chunks);
        $this->assertStringContainsString('You are an AI Code Review bot', $prompt);
        $this->assertStringContainsString('FILE: src/Foo.php (~10)', $prompt);
        $this->assertStringContainsString('@@ -1,3 +1,3 @@', $prompt);
        $this->assertStringContainsString('FILE: src/Bar.php (~5)', $prompt);
        $this->assertStringContainsString('+ 5: echo 1;', $prompt);
        $this->assertStringContainsString('+ 6: echo 2;', $prompt);
    }

    public function testMergeAdditionalPrompts(): void
    {
        $system = 'SYS';
        $user   = 'USER';
        $options = [
            'prompts' => [
                'system_append' => 'S1',
                'user_append'   => ['U1', '', 'U2'],
                'extra'         => ['E1', null, 'E2'],
            ],
        ];

        [$sysOut, $userOut] = _StubLLMProvider::callMerge($system, $user, $options);
        $this->assertSame("SYS\n\nS1", $sysOut);
        $this->assertStringContainsString('USER', $userOut);
        $this->assertStringContainsString('U1', $userOut);
        $this->assertStringContainsString('U2', $userOut);
        $this->assertStringContainsString('E1', $userOut);
        $this->assertStringContainsString('E2', $userOut);
    }

    public function testExtractFindingsFromDirectJson(): void
    {
        $json = json_encode(['findings' => [['rule_id' => 'X']]]);
        $out  = _StubLLMProvider::callExtract((string) $json);
        $this->assertSame('X', $out[0]['rule_id'] ?? null);
    }

    public function testExtractFindingsFromCodeFence(): void
    {
        $json  = json_encode(['findings' => [['rule_id' => 'CF']]]);
        $fence = "```json\n{$json}\n```";
        $out   = _StubLLMProvider::callExtract($fence);
        $this->assertSame('CF', $out[0]['rule_id'] ?? null);
    }

    public function testExtractFindingsInvalidReturnsEmpty(): void
    {
        $out = _StubLLMProvider::callExtract('nope');
        $this->assertSame([], $out);
    }
}
