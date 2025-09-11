<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Support\TokenBudget;
use PHPUnit\Framework\TestCase;

final class TokenBudgetTest extends TestCase
{
    public function testEstimateAndPerFileCap(): void
    {
        $tb = new TokenBudget(100, 10, 'trim');
        $text = str_repeat('a', 200); // ~50 tokens
        $est = $tb->estimateTokens($text);
        $this->assertGreaterThan(0, $est);

        $capped = $tb->enforcePerFileCap($text);
        $this->assertTrue(strlen($capped) <= strlen($text));
        $this->assertTrue($tb->estimateTokens($capped) <= 10);
    }

    public function testShouldStopOnOverflowWithTrim(): void
    {
        $tb = new TokenBudget(20, 20, 'trim');
        $this->assertFalse($tb->shouldStop(0, 10));
        $this->assertTrue($tb->shouldStop(15, 10)); // 25 > 20
    }

    public function testFromContextDefaults(): void
    {
        $tb = TokenBudget::fromContext([]);
        $this->assertFalse($tb->shouldStop(0, 1));
        $short = $tb->enforcePerFileCap('abc');
        $this->assertSame('abc', $short);
    }
}
