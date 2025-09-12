<?php

declare(strict_types=1);

namespace AICR\Command;

use AICR\Adapters\BitbucketAdapter;
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
            ->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Output format: json|summary|markdown', 'json')
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
                $config = Config::load(is_string($configFile) ? $configFile : null);
                // Validate ID early to avoid running any git commands if it's missing
                if (!is_string($idOpt) || '' === $idOpt) {
                    throw new \InvalidArgumentException('Missing --id for the configured platform.');
                }
                $adapter                    = $this->buildAdapter($config);
                [$resolvedId, $base, $head] = $this->resolveBranchesViaAdapter($adapter, $idOpt);
                $tmpFile                    = $this->computeGitDiffToTempFile($io, $base, $head);
                $diffPath                   = $tmpFile;
            }

            // Load config if not already
            if (!isset($config)) {
                $config = Config::load(is_string($configFile) ? $configFile : null);
            }

            $pipeline = new Pipeline($config);

            $comment = $pipeline->run($diffPath, $format);

            if ($doComment) {
                $adapter   = $this->buildAdapter($config);
                $commentId = null;
                if (isset($resolvedId)) {
                    $commentId = (int) $resolvedId;
                } elseif (is_string($idOpt) && '' !== $idOpt) {
                    $commentId = (int) $idOpt;
                }
                if (null !== $commentId) {
                    $adapter->postComment($commentId, $comment);

                    $io->success('Comment posted.');
                } else {
                    $io->warning('Skipping comment: missing PR/MR --id.');
                }
            } else {
                $output->writeln($comment);
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
        if ('bitbucket' === $platform) {
            return new BitbucketAdapter($vcs['workspace'], $vcs['repository'], $vcs['accessToken'], $vcs['timeout'] ?? null);
        }

        throw new \InvalidArgumentException('Configure vcs.platform as "github" or "gitlab" to enable API-based diff.');
    }

    /**
     * @param mixed $id PR/MR ID provided via --id
     *
     * @return array{0:int,1:string,2:string} [platform, id, base, head]
     */
    private function resolveBranchesViaAdapter(VcsAdapter $adapter, $id): array
    {
        if (!is_string($id) || '' === $id) {
            throw new \InvalidArgumentException('Missing --id for the configured platform.');
        }
        if ($adapter instanceof GithubAdapter) {
            [$base, $head] = $adapter->resolveBranchesFromId((int) $id);

            return [(int) $id, $base, $head];
        }
        if ($adapter instanceof GitlabAdapter) {
            [$base, $head] = $adapter->resolveBranchesFromId((int) $id);

            return [(int) $id, $base, $head];
        }

        throw new \InvalidArgumentException('Unsupported VCS adapter.');
    }

    private function computeGitDiffToTempFile(SymfonyStyle $io, string $base, string $head): string
    {
        $git = new \AICR\Support\GitRunner();
        $git->run('fetch --all --prune');
        // Ensure we have the latest for both branches
        $git->run('fetch origin '.$git->esc($base));
        $git->run('fetch origin '.$git->esc($head));

        $range = $git->esc($base).'...'.$git->esc($head);
        $diff  = $git->run('diff --unified=3 '.$range);
        $tmp   = tempnam(sys_get_temp_dir(), 'aicr_diff_');
        if (false === $tmp) {
            throw new \RuntimeException('Failed to create temp file for diff');
        }
        file_put_contents($tmp, $diff);

        return $tmp;
    }
}
