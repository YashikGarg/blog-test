<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\NotFoundException;
use App\Model\PostStatus;
use App\Repository\PostRepositoryInterface;
use App\Repository\VoteRepositoryInterface;

final readonly class VoteService
{
    public function __construct(
        private VoteRepositoryInterface $votes,
        private PostRepositoryInterface $posts,
    ) {
    }

    /** @return array{voted: bool, count: int} */
    public function toggle(string $postId, string $userId): array
    {
        $post = $this->posts->findById($postId);
        if ($post === null || $post->status !== PostStatus::Published) {
            throw new NotFoundException('Post not found');
        }

        return $this->votes->toggle($postId, $userId);
    }

    public function hasVoted(string $postId, string $userId): bool
    {
        return $this->votes->hasVoted($postId, $userId);
    }
}
