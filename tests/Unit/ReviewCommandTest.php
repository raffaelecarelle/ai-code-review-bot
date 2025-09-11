<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Command\ReviewCommand;
use PHPUnit\Framework\TestCase;

final class ReviewCommandTest extends TestCase
{
    public function testConfiguration(): void
    {
        $cmd = new ReviewCommand();
        $this->assertSame('review', $cmd->getName());
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('diff-file'));
        $this->assertTrue($def->hasOption('config'));
        $this->assertTrue($def->hasOption('output'));
        // New unified identifier option and comment flag
        $this->assertTrue($def->hasOption('id'));
        $this->assertTrue($def->hasOption('comment'));
        // Ensure legacy options are not present anymore
        $this->assertFalse($def->hasOption('gh-pr'));
        $this->assertFalse($def->hasOption('gl-mr'));
    }
}
