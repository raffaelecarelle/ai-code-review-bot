<?php

declare(strict_types=1);

namespace AICR\Adapters;

use GuzzleHttp\Client;

/**
 * Example Bitbucket adapter demonstrating custom VCS integration.
 */
final class BitbucketAdapter implements VcsAdapter
{
    private Client $client;
    private string $workspace;
    private string $repository;

    public function __construct(string $workspace, string $repository, string $accessToken, ?int $timeout = 30)
    {
        $this->workspace  = $workspace;
        $this->repository = $repository;

        if (empty($this->workspace) || empty($this->repository)) {
            throw new \InvalidArgumentException('Bitbucket adapter requires "workspace" and "repository" options');
        }

        $headers                  = ['Content-Type' => 'application/json'];
        $headers['Authorization'] = 'Bearer '.$accessToken;

        $this->client = new Client([
            'base_uri' => 'https://api.bitbucket.org/2.0/',
            'headers'  => $headers,
            'timeout'  => $timeout,
        ]);
    }

    /**
     * Resolve base and head branches given a PR ID.
     *
     * @return array{0:string,1:string} [base, head]
     */
    public function resolveBranchesFromId(int $id): array
    {
        try {
            $response = $this->client->get(
                "repositories/{$this->workspace}/{$this->repository}/pullrequests/{$id}"
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
        } catch (\GuzzleHttp\Exception\RequestException $e) {
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
                "repositories/{$this->workspace}/{$this->repository}/pullrequests/{$id}/comments",
                [
                    'json' => [
                        'content' => [
                            'raw' => $body,
                        ],
                    ],
                ]
            );
        } catch (\GuzzleHttp\Exception\RequestException $e) {
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
     * @return array<string, mixed>
     */
    public function getPullRequestDetails(int $id): array
    {
        try {
            $response = $this->client->get(
                "repositories/{$this->workspace}/{$this->repository}/pullrequests/{$id}"
            );

            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) ? $data : [];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
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
        return "{$this->workspace}/{$this->repository}";
    }
}
