<?php

declare(strict_types=1);

namespace AICR\Adapters;

/**
 * VCS Adapter interface for GitHub/GitLab operations used by ReviewCommand.
 */
interface VcsAdapter
{
    /**
     * Resolve base and head branches given a PR/MR ID.
     *
     * @return array{0:string,1:string} [base, head]
     */
    public function resolveBranchesFromId(int $id): array;

    /**
     * Post a text comment to the PR/MR with the given ID.
     */
    public function postComment(int $id, string $body): void;
}
