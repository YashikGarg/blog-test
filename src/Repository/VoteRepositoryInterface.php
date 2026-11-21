<?php

declare(strict_types=1);

namespace App\Repository;

interface VoteRepositoryInterface
{
    public function hasVoted(string $postId, string $userId): bool;

    /** @return array{voted: bool, count: int} */
    public function toggle(string $postId, string $userId): array;
}
