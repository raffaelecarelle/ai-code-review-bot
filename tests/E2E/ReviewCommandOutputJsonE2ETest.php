<?php

declare(strict_types=1);

namespace AICR\Tests\E2E;

use AICR\Command\ReviewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReviewCommandOutputJsonE2ETest extends TestCase
{
    private string $tmpCfg;

    protected function setUp(): void
    {
        $this->tmpCfg = sys_get_temp_dir().'/aicr_cmd_e2e_json_'.uniqid('', true).'.yml';
        $yaml = <<<'YML'
providers:
  default: mock
vcs:
  platform: github
context:
  diff_token_limit: 10000
  per_file_token_cap: 5000
  overflow_strategy: trim
policy:
  min_severity_to_comment: info
  max_comments: 50
  redact_secrets: true
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

    public function testReviewCommand_outputsJsonByDefault_withoutComment(): void
    {
        $cmd = new ReviewCommand();
        $app = new Application('AI Code Review Bot Test');
        $app->add($cmd);

        $command = $app->find('review');
        $tester  = new CommandTester($command);

        $diffPath = __DIR__.'/../../examples/sample.diff';

        $exit = $tester->execute([
            '--diff-file' => $diffPath,
            '--config'    => $this->tmpCfg,
            // no --output provided, defaults to json
        ]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertJson($display);
        $data = json_decode($display, true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('rule_id', $data[0]);
    }
}
