<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Rule;
use AICR\RulesEngine;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    public function testRuleRequiresIdAndPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Rule([
            'id' => '',
            'severity' => 'minor',
            'rationale' => 'x',
            'pattern' => '',
        ]);
    }

    public function testGlobMatch(): void
    {
        $this->assertTrue(RulesEngine::globMatch('**/*.php', 'src/Foo/Bar.php'));
        $this->assertTrue(RulesEngine::globMatch('src/*/Bar.php', 'src/Foo/Bar.php'));
        $this->assertFalse(RulesEngine::globMatch('src/*.php', 'src/Foo/Bar.php'));
        $this->assertTrue(RulesEngine::globMatch('src/??r.php', 'src/Bar.php'));
    }

    public function testEvaluateFindsMatches(): void
    {
        $engine = RulesEngine::fromConfig([
            'inline' => [[
                'id' => 'PHP.NO.ECHO',
                'applies_to' => ['**/*.php'],
                'severity' => 'minor',
                'rationale' => 'Avoid echo',
                'pattern' => '(^|\s)echo\s',
                'suggestion' => 'Use logger',
                'enabled' => true,
            ]],
        ]);

        $findings = $engine->evaluate('src/Test.php', [
            ['line' => 10, 'content' => ' echo "hi";'],
            ['line' => 11, 'content' => '$a = 1;'],
        ]);

        $this->assertNotEmpty($findings);
        $this->assertSame('PHP.NO.ECHO', $findings[0]['rule_id']);
        $this->assertSame(10, $findings[0]['start_line']);
    }
}
