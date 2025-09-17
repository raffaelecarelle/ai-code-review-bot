<?php

declare(strict_types=1);

namespace AICR\Command;

use AICR\Adapters\BitbucketAdapter;
use AICR\Adapters\GithubAdapter;
use AICR\Adapters\GitlabAdapter;
use AICR\Adapters\VcsAdapter;
use AICR\Config;
use AICR\Pipeline;
use AICR\Providers\AIProvider;
use AICR\Providers\AIProviderFactory;
use AICR\Support\GitRunner;
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
    private ?VcsAdapter $adapterOverride;

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
            ->addOption('provider', null, InputOption::VALUE_OPTIONAL, 'AI provider to use (e.g., openai, gemini, anthropic). If not specified, uses the first provider from config.')
            // Git-based options (branches are resolved dynamically via API based on configured platform)
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'PR/MR ID (number/IID) depending on configured platform')
            ->addOption('comment', null, InputOption::VALUE_NONE, 'If set, post the summary as a comment to the PR/MR when applicable')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $options = $this->parseCommandOptions($input);

        $tmpFile = null;

        try {
            [$diffPath, $config, $tmpFile] = $this->resolveDiffPathAndConfig($options, $io);

            $provider = $this->buildSpecificProvider($config, $options['provider']);
            $pipeline = new Pipeline($config, $provider);
            $result   = $pipeline->run($diffPath, $options['format']);

            $this->handleOutput($result, $options, $config, $io, $output);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            $this->cleanupTempFile($tmpFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCommandOptions(InputInterface $input): array
    {
        return [
            'diff_file' => $input->getOption('diff-file'),
            'config'    => $input->getOption('config'),
            'format'    => (string) $input->getOption('output'),
            'provider'  => $input->getOption('provider'),
            'id'        => $input->getOption('id'),
            'comment'   => (bool) $input->getOption('comment'),
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{0: string, 1: Config, 2: null|string}
     */
    private function resolveDiffPathAndConfig(array $options, SymfonyStyle $io): array
    {
        $tmpFile = null;

        if (is_string($options['diff_file']) && '' !== $options['diff_file']) {
            $diffPath = $options['diff_file'];
            $config   = Config::load(is_string($options['config']) ? $options['config'] : null);
        } else {
            $config = Config::load(is_string($options['config']) ? $options['config'] : null);

            if (!is_string($options['id']) || '' === $options['id']) {
                throw new \InvalidArgumentException('Missing --id for the configured platform.');
            }

            $adapter         = $this->buildAdapter($config);
            [, $base, $head] = $this->resolveBranchesViaAdapter($adapter, $options['id']);
            $tmpFile         = $this->computeGitDiffToTempFile($io, $base, $head);
            $diffPath        = $tmpFile;
        }

        return [$diffPath, $config, $tmpFile];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function handleOutput(string $result, array $options, Config $config, SymfonyStyle $io, OutputInterface $output): void
    {
        if ($options['comment']) {
            $this->postCommentIfRequested($result, $options, $config, $io);
        } else {
            $output->writeln($result);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function postCommentIfRequested(string $result, array $options, Config $config, SymfonyStyle $io): void
    {
        $adapter   = $this->buildAdapter($config);
        $commentId = $this->resolveCommentId($options['id']);

        if (null !== $commentId) {
            $adapter->postComment($commentId, $result);
            $io->success('Comment posted.');
        } else {
            $io->warning('Skipping comment: missing PR/MR --id.');
        }
    }

    /**
     * @param mixed $idOption
     */
    private function resolveCommentId($idOption): ?int
    {
        if (is_string($idOption) && '' !== $idOption) {
            return (int) $idOption;
        }

        return null;
    }

    private function cleanupTempFile(?string $tmpFile): void
    {
        if (is_string($tmpFile) && '' !== $tmpFile && is_file($tmpFile)) {
            @unlink($tmpFile);
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
            return new GithubAdapter($vcs);
        }
        if ('gitlab' === $platform) {
            return new GitlabAdapter($vcs);
        }
        if ('bitbucket' === $platform) {
            return new BitbucketAdapter($vcs);
        }

        throw new \InvalidArgumentException('Configure vcs.platform as "github", "gitlab", or "bitbucket" to enable API-based diff.');
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

        [$base, $head] = $adapter->resolveBranchesFromId((int) $id);

        return [(int) $id, $base, $head];
    }

    private function computeGitDiffToTempFile(SymfonyStyle $io, string $base, string $head): string
    {
        $git = new GitRunner();
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

    private function buildSpecificProvider(Config $config, ?string $providerName = null): AIProvider
    {
        $factory   = new AIProviderFactory($config);
        $providers = $config->providers();

        if (null === $providerName) {
            $availableProviders = array_keys($providers);

            // Use default provider if specified, otherwise use first non-mock provider, fallback to first provider
            if (isset($providers['default'])) {
                $defaultProvider = $providers['default'];
                if (is_string($defaultProvider)) {
                    return $factory->build($defaultProvider);
                }
            }

            // Find first non-mock provider
            foreach ($availableProviders as $provider) {
                if ('mock' !== $provider) {
                    return $factory->build($provider);
                }
            }

            // Fallback to first available provider
            if (empty($availableProviders)) {
                throw new \InvalidArgumentException('No providers are configured.');
            }

            return $factory->build($availableProviders[0]);
        }

        if (!isset($providers[$providerName])) {
            $availableProviders = array_keys($providers);

            throw new \InvalidArgumentException("Provider '{$providerName}' not found in configuration. Available providers: ".implode(', ', $availableProviders));
        }

        return $factory->build($providerName);
    }
}
