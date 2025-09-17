<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Providers\AbstractLLMProvider;
use PHPUnit\Framework\TestCase;

/**
 * Concrete test double extending AbstractLLMProvider to expose prompt merging.
 */
class TestMergeProvider extends AbstractLLMProvider
{
    /**
     * @param array<string,mixed> $options
     * @return array{0:string,1:string}
     */
    public static function proxy(string $sys, string $usr, array $options): array
    {
        return self::mergeAdditionalPrompts($sys, $usr, $options);
    }

    public function reviewChunks(array $chunks, ?array $policyConfig = null): array { return []; }

    public function getName(): string
    {
        return 'test';
    }
}

final class PromptMergeTest extends TestCase
{
    /**
     * Test helper wrapper.
     *
     * @param array<string,mixed> $options
     * @return array{0:string,1:string}
     */
    private static function callMerge(string $system, string $user, array $options): array
    {
        return TestMergeProvider::proxy($system, $user, $options);
    }

    public function testMergeWithStringsAndArrays(): void
    {
        $baseSystem = 'SYS';
        $baseUser   = 'USER';
        $options    = [
            'prompts' => [
                'system_append' => 'S+ one',
                'user_append'   => ['U+ one', 'U+ two'],
                'extra'         => ['E+ one', 'E+ two'],
            ],
        ];

        [$sys, $usr] = self::callMerge($baseSystem, $baseUser, $options);

        // System should contain base + blank line + appended text
        $this->assertStringContainsString("SYS\n\nS+ one", $sys);

        // User should contain base, then user_append joined with blank lines, then extra joined
        $this->assertStringContainsString("USER", $usr);
        // user_append block
        $this->assertStringContainsString("U+ one\n\nU+ two", $usr);
        // extra block comes after
        $this->assertTrue(strpos($usr, 'U+ two') !== false && strpos($usr, 'E+ one') !== false);
        $this->assertTrue(strpos($usr, 'U+ two') < strpos($usr, 'E+ one'));
        // extras joined with blank lines
        $this->assertStringContainsString("E+ one\n\nE+ two", $usr);
    }

    public function testMergeIgnoresNullsAndWhitespace(): void
    {
        $baseSystem = "Base SYS";
        $baseUser   = "Base USER";
        $options    = [
            'prompts' => [
                'system_append' => null,
                'user_append'   => ["  ", "\n\n"], // whitespace should be ignored
                'extra'         => 'Only extra',      // string accepted
            ],
        ];

        [$sys, $usr] = self::callMerge($baseSystem, $baseUser, $options);

        // System unchanged when system_append null/empty
        $this->assertSame('Base SYS', $sys);
        // User should be base + extra (since user_append is only whitespace)
        $this->assertStringStartsWith('Base USER', $usr);
        $this->assertStringContainsString('Only extra', $usr);
        // Ensure there isn't accidental extra newlines only
        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $usr);
    }
}
