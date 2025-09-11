<?php

declare(strict_types=1);

namespace AICR\Command;

use AICR\Adapters\GithubAdapter;
use AICR\Adapters\GitlabAdapter;
use AICR\Adapters\VcsAdapter;
use AICR\Config;
use AICR\Pipeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'review',
    description: 'Analyze a diff (from file or git branches/PR/MR) and output findings; can auto-comment on PR/MR.'
)]
final class ReviewCommand extends Command
{
    private ?VcsAdapter $adapterOverride = null;

    public function __construct(?VcsAdapter $adapterOverride = null)
    {
        parent::__construct();
        $this->adapterOverride = $adapterOverride;
    }

    protected function configure(): void
    {
        $this
            ->addOption('diff-file', null, InputOption::VALUE_REQUIRED, 'Path to the unified diff file to analyze')
            ->addOption('config', null, InputOption::VALUE_OPTIONAL, 'Path to configuration file (.aicodereview.yml or JSON)')
            ->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Output format: json|summary', 'json')
            // Git-based options (branches are resolved dynamically via API based on configured platform)
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'PR/MR ID (number/IID) depending on configured platform')
            ->addOption('comment', null, InputOption::VALUE_NONE, 'If set, post the summary as a comment to the PR/MR when applicable')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $diffFileOpt = $input->getOption('diff-file');
        $configFile  = $input->getOption('config');
        $format      = (string) $input->getOption('output');
        $idOpt       = $input->getOption('id');
        $doComment   = (bool) $input->getOption('comment');

        $tmpFile = null;

        try {
            if (is_string($diffFileOpt) && '' !== $diffFileOpt) {
                $diffPath = $diffFileOpt;
            } else {
                // Compute diff via git using adapter resolved from configuration
                $config                                = Config::load(is_string($configFile) ? $configFile : null);
                $adapter                               = $this->buildAdapter($config);
                [$platform, $resolvedId, $base, $head] = $this->resolveBranchesViaAdapter($adapter, $idOpt);
                $tmpFile                               = $this->computeGitDiffToTempFile($io, $base, $head);
                $diffPath                              = $tmpFile;
            }

            // Load config if not already
            if (!isset($config)) {
                $config = Config::load(is_string($configFile) ? $configFile : null);
            }
            $pipeline = new Pipeline($config);

            $result = $pipeline->run($diffPath, $format);
            $output->writeln($result);

            // Auto-comment with summary if requested
            if ($doComment) {
                $summary = $pipeline->run($diffPath, Pipeline::OUTPUT_FORMAT_SUMMARY);
                $isTest  = (bool) ($config->getAll()['test'] ?? false);
                if ($isTest) {
                    // In test mode, print the comment body instead of posting to VCS
                    $output->writeln($summary);
                } else {
                    $adapter   = $this->buildAdapter($config);
                    $commentId = null;
                    if (isset($resolvedId)) {
                        $commentId = (int) $resolvedId;
                    } elseif (is_string($idOpt) && '' !== $idOpt) {
                        $commentId = (int) $idOpt;
                    }
                    if (null !== $commentId) {
                        $adapter->postComment($commentId, $summary);
                        if ($adapter instanceof GithubAdapter) {
                            $io->success('Comment posted to GitHub PR #'.$commentId);
                        } elseif ($adapter instanceof GitlabAdapter) {
                            $io->success('Comment posted to GitLab MR !'.$commentId);
                        } else {
                            $io->success('Comment posted.');
                        }
                    } else {
                        $io->warning('Skipping comment: missing PR/MR --id.');
                    }
                }
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            if (is_string($tmpFile) && '' !== $tmpFile && is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    private function buildAdapter(Config $config): VcsAdapter
    {
        if ($this->adapterOverride instanceof VcsAdapter) {
            return $this->adapterOverride;
        }
        $vcs      = $config->vcs();
        $platform = strtolower((string) ($vcs['platform'] ?? ''));

        if ('github' === $platform) {
            $repo = isset($vcs['repo']) && is_string($vcs['repo']) && '' !== $vcs['repo'] ? $vcs['repo'] : null;

            return new GithubAdapter($repo, null);
        }
        if ('gitlab' === $platform) {
            $projectId = isset($vcs['project_id']) && is_string($vcs['project_id']) && '' !== $vcs['project_id'] ? $vcs['project_id'] : null;
            $apiBase   = isset($vcs['api_base']) && is_string($vcs['api_base']) && '' !== $vcs['api_base'] ? $vcs['api_base'] : null;

            return new GitlabAdapter($projectId, null, $apiBase);
        }

        throw new \InvalidArgumentException('Configure vcs.platform as "github" or "gitlab" to enable API-based diff.');
    }

    /**
     * @param mixed $id PR/MR ID provided via --id
     *
     * @return array{0:string,1:int,2:string,3:string} [platform, id, base, head]
     */
    private function resolveBranchesViaAdapter(VcsAdapter $adapter, $id): array
    {
        if (!is_string($id) || '' === $id) {
            throw new \InvalidArgumentException('Missing --id for the configured platform.');
        }
        if ($adapter instanceof GithubAdapter) {
            [$base, $head] = $adapter->resolveBranchesFromId((int) $id);

            return ['github', (int) $id, $base, $head];
        }
        if ($adapter instanceof GitlabAdapter) {
            [$base, $head] = $adapter->resolveBranchesFromId((int) $id);

            return ['gitlab', (int) $id, $base, $head];
        }

        throw new \InvalidArgumentException('Unsupported VCS adapter.');
    }

    private function computeGitDiffToTempFile(SymfonyStyle $io, string $base, string $head): string
    {
        $this->runGit('fetch --all --prune');
        // Ensure we have the latest for both branches
        $this->runGit('fetch origin '.$this->esc($base));
        $this->runGit('fetch origin '.$this->esc($head));
        $range = $this->esc($base).'...'.$this->esc($head);
        $diff  = $this->runGit('diff --unified=3 '.$range);
        $tmp   = tempnam(sys_get_temp_dir(), 'aicr_diff_');
        if (false === $tmp) {
            throw new \RuntimeException('Failed to create temp file for diff');
        }
        file_put_contents($tmp, $diff);

        return $tmp;
    }

    private function runGit(string $args): string
    {
        // Use Symfony Process instead of exec for better portability and control
        $process = Process::fromShellCommandline('git '.$args);
        $process->setTimeout(null);
        $process->run();
        if (!$process->isSuccessful()) {
            $cmdline  = $process->getCommandLine();
            $output   = trim($process->getOutput());
            $error    = trim($process->getErrorOutput());
            $combined = trim($output.('' !== $error ? "\n".$error : ''));

            throw new \RuntimeException("Git command failed ({$cmdline}):\n".$combined);
        }
        // Ensure trailing newline to match previous behavior
        $out = $process->getOutput();
        // If there is no stdout but stderr had content (git sometimes writes to stderr), include it
        if ('' === $out) {
            $out = $process->getErrorOutput();
        }

        return rtrim($out, "\n")."\n";
    }

    private function esc(string $s): string
    {
        // Simple escape for refs (no spaces)
        return escapeshellarg($s);
    }
}
