<?php

declare(strict_types=1);

namespace AICR\Tests\E2E;

use AICR\Command\ReviewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReviewCommandTestModeE2ETest extends TestCase
{
    private string $tmpCfg;

    protected function setUp(): void
    {
        $this->tmpCfg = sys_get_temp_dir().'/aicr_cmd_testmode_'.uniqid('', true).'.yml';
        $yaml = <<<'YML'
version: 1
test: true
providers:
  default: mock
rules:
  include: []
  inline:
    - { id: "PHP.NO.ECHO", applies_to: ["**/*.php"], severity: "minor", rationale: "Avoid echo", pattern: "(^|\s)echo\s", suggestion: "Use logger", enabled: true }
YML;
        file_put_contents($this->tmpCfg, $yaml);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpCfg);
    }

    public function testReviewCommand_printsCommentWhenTestFlagTrue(): void
    {
        $app = new Application('AI Code Review Bot TestMode');
        $cmd = new ReviewCommand();
        $app->add($cmd);

        $command = $app->find('review');
        $tester  = new CommandTester($command);

        $diffPath = __DIR__.'/../../examples/sample.diff';

        $exit = $tester->execute([
            '--diff-file' => $diffPath,
            '--config'    => $this->tmpCfg,
            '--output'    => 'summary',
            '--comment'   => true,
        ]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();

        // Should contain the summary once as command output
        $this->assertStringContainsString('Findings (', $display);
        $this->assertStringContainsString('src/Example.php', $display);

        // In test mode, the summary is printed again as the "comment" body
        $this->assertGreaterThanOrEqual(2, substr_count($display, 'Findings ('));

        // No posting occurs, so these success messages must not appear
        $this->assertStringNotContainsString('Comment posted', $display);
    }
}
