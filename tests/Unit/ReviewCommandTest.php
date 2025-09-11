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
    }
}
