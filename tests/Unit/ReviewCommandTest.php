<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Command\ReviewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReviewCommandTest extends TestCase
{
    private function makeAppWithCommand(): array
    {
        $app = new Application('aicr');
        $cmd = new ReviewCommand();
        $app->add($cmd);

        return [$app, $cmd];
    }

    public function testExecuteWithDiffFileJson(): void
    {
        [$app, $cmd] = $this->makeAppWithCommand();
        $command = $app->find('review');
        $tester  = new CommandTester($command);

        $diffPath = __DIR__.'/../../examples/sample.diff';
        $tmpCfg   = sys_get_temp_dir().'/aicr_cmd_'.uniqid('', true).'.yml';
        file_put_contents($tmpCfg, "providers:\n  default: mock\n");

        $exit = $tester->execute([
            'command' => $command->getName(),
            '--diff-file' => $diffPath,
            '--config' => $tmpCfg,
            '--output' => 'json',
        ]);
        @unlink($tmpCfg);

        $this->assertSame(0, $exit);
        $this->assertNotSame('', trim($tester->getDisplay()));
    }

    public function testExecuteMissingIdWithPlatformConfigured(): void
    {
        [$app, $cmd] = $this->makeAppWithCommand();
        $command = $app->find('review');
        $tester  = new CommandTester($command);

        $tmpCfg   = sys_get_temp_dir().'/aicr_cmd_'.uniqid('', true).'.yml';
        file_put_contents($tmpCfg, "vcs:\n  platform: github\n");

        $exit = $tester->execute([
            'command' => $command->getName(),
            '--config' => $tmpCfg,
            // no --diff-file and no --id
        ]);
        @unlink($tmpCfg);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Missing --id', $tester->getDisplay());
    }

    public function testExecuteInvalidPlatform(): void
    {
        [$app, $cmd] = $this->makeAppWithCommand();
        $command = $app->find('review');
        $tester  = new CommandTester($command);

        $tmpCfg   = sys_get_temp_dir().'/aicr_cmd_'.uniqid('', true).'.yml';
        file_put_contents($tmpCfg, "vcs:\n  platform: foo\n\n");

        $exit = $tester->execute([
            'command' => $command->getName(),
            '--config' => $tmpCfg,
            '--id' => '123',
        ]);
        @unlink($tmpCfg);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Configure vcs.platform as "github" or "gitlab"', $tester->getDisplay());
    }
}
