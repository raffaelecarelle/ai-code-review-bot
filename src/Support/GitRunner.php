<?php

declare(strict_types=1);

namespace AICR\Support;

use Symfony\Component\Process\Process;

final class GitRunner
{
    public function run(string $args): string
    {
        $safeDirectory = getcwd() ?: '.';
        $command       = 'git -c safe.directory='.escapeshellarg($safeDirectory).' '.$args;
        $process       = Process::fromShellCommandline($command);
        $process->setTimeout(null);
        $process->run();
        if (!$process->isSuccessful()) {
            $cmdline  = $process->getCommandLine();
            $output   = trim($process->getOutput());
            $error    = trim($process->getErrorOutput());
            $combined = trim($output.('' !== $error ? "\n".$error : ''));

            throw new \RuntimeException("Git command failed ({$cmdline}):\n".$combined);
        }
        $out = $process->getOutput();
        if ('' === $out) {
            $out = $process->getErrorOutput();
        }

        return rtrim($out, "\n")."\n";
    }

    public function esc(string $s): string
    {
        return escapeshellarg($s);
    }
}
