<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Repository\VoteRepositoryInterface;
use Psr\SimpleCache\CacheInterface;

final readonly class VoteService
{
    public function __construct(
        private VoteRepositoryInterface $votes,
        private CacheInterface $cache,
        private int $countTtl,
    ) {
    }

    /** @return array{voted: bool, count: int} */
    public function vote(string $postId, string $userId, string $wanted): array
    {
        $flag = match ($wanted) {
            '1' => true,
            '0' => false,
            default => throw new ValidationException(['voted' => 'Invalid vote']),
        };
        $result = $this->votes->set($postId, $userId, $flag) ?? throw new NotFoundException('Post not found');
        $this->cache->set(self::key($postId), $result['count'], $this->countTtl); // the DB's count, never an increment

        return $result;
    }

    public function count(string $postId): int
    {
        $cached = $this->cache->get(self::key($postId));
        if (is_int($cached)) {
            return $cached;
        }
        $count = $this->votes->count($postId);
        $this->cache->set(self::key($postId), $count, $this->countTtl);

        return $count;
    }

    public function hasVoted(string $postId, string $userId): bool
    {
        return $this->votes->hasVoted($postId, $userId);
    }

    private static function key(string $postId): string
    {
        return 'votes.' . $postId;
    }
}
