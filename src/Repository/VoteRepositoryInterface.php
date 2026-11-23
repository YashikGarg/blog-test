<?php

declare(strict_types=1);

namespace App\Repository;

interface VoteRepositoryInterface
{
    public function hasVoted(string $postId, string $userId): bool;

    /** @return array{voted: bool, count: int}|null null when the post is not published */
    public function set(string $postId, string $userId, bool $wanted): ?array;

    /** The stored vote count; 0 if there is no counter row. */
    public function count(string $postId): int;
}
