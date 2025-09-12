<?php

declare(strict_types=1);

namespace AICR\Adapters;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Bitbucket adapter with standardized configuration support.
 */
final class BitbucketAdapter extends BaseAdapter
{
    private Client $client;
    private string $workspace;
    private string $repositoryName;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $this->initializeFromConfig($config);
        $this->parseRepositoryIdentifier();

        if (empty($this->workspace) || empty($this->repositoryName)) {
            throw new \InvalidArgumentException('Bitbucket adapter requires workspace and repository. Set vcs.repository as "workspace/repo" or use legacy workspace/repository_name options.');
        }

        $this->initializeClient();
    }

    /**
     * Resolve base and head branches given a PR ID.
     *
     * @return array{0: string, 1: string} [base, head]
     */
    public function resolveBranchesFromId(int $id): array
    {
        try {
            $response = $this->client->get(
                "repositories/{$this->workspace}/{$this->repositoryName}/pullrequests/{$id}"
            );

            $data = json_decode($response->getBody()->getContents(), true);

            if (!is_array($data)) {
                throw new \RuntimeException('Invalid response from Bitbucket API');
            }

            $baseBranch = $data['destination']['branch']['name'] ?? null;
            $headBranch = $data['source']['branch']['name'] ?? null;

            if (!$baseBranch || !$headBranch) {
                throw new \RuntimeException('Could not resolve branch names from PR data');
            }

            return [$baseBranch, $headBranch];
        } catch (RequestException $e) {
            throw new \RuntimeException(
                "Failed to resolve branches for PR #{$id}: ".$e->getMessage()
            );
        }
    }

    /**
     * Post a text comment to the PR with the given ID.
     */
    public function postComment(int $id, string $body): void
    {
        try {
            $this->client->post(
                "repositories/{$this->workspace}/{$this->repositoryName}/pullrequests/{$id}/comments",
                [
                    'json' => [
                        'content' => [
                            'raw' => $body,
                        ],
                    ],
                ]
            );
        } catch (RequestException $e) {
            throw new \RuntimeException(
                "Failed to post comment to PR #{$id}: ".$e->getMessage()
            );
        }
    }

    /**
     * Additional Bitbucket-specific methods can be added here.
     */

    /**
     * Get PR details for additional functionality.
     *
     * @return array<string,mixed>
     */
    public function getPullRequestDetails(int $id): array
    {
        try {
            $response = $this->client->get(
                "repositories/{$this->workspace}/{$this->repositoryName}/pullrequests/{$id}"
            );

            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) ? $data : [];
        } catch (RequestException $e) {
            throw new \RuntimeException(
                "Failed to get PR details for #{$id}: ".$e->getMessage()
            );
        }
    }

    /**
     * Get workspace and repository for identification.
     */
    public function getRepositoryIdentifier(): string
    {
        return "{$this->workspace}/{$this->repositoryName}";
    }

    protected function getDefaultApiBase(): string
    {
        return 'https://api.bitbucket.org/2.0';
    }

    private function parseRepositoryIdentifier(): void
    {
        $parts = explode('/', $this->repository, 2);
        if (2 !== count($parts)) {
            throw new \InvalidArgumentException('Bitbucket repository must be in format "workspace/repository"');
        }
        $this->workspace      = $parts[0];
        $this->repositoryName = $parts[1];
    }

    private function initializeClient(): void
    {
        $headers = ['Content-Type' => 'application/json'];
        if ('' !== $this->accessToken) {
            $headers['Authorization'] = 'Bearer '.$this->accessToken;
        }

        $this->client = new Client([
            'base_uri' => $this->apiBase.'/',
            'headers'  => $headers,
            'timeout'  => $this->timeout,
        ]);
    }
}
