<?php

declare(strict_types=1);

namespace App\Repository;

interface VoteRepositoryInterface
{
    public function hasVoted(string $postId, string $userId): bool;

    /** true = the vote changed (counter ±1), false = it already was in that state, null = the post is not published */
    public function set(string $postId, string $userId, bool $wanted): ?bool;

    /** The stored vote count; 0 if there is no counter row. */
    public function count(string $postId): int;
}
