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

    /**
     * No count is returned: the page adds or removes 1 itself when the vote changed.
     * @return array{voted: bool, changed: bool}
     */
    public function vote(string $postId, string $userId, string $wanted): array
    {
        $flag = match ($wanted) {
            '1' => true,
            '0' => false,
            default => throw new ValidationException(['voted' => 'Invalid vote']),
        };
        $changed = $this->votes->set($postId, $userId, $flag) ?? throw new NotFoundException('Post not found');
        if ($changed) {
            // Update the cached count in place (no DB read). Not atomic: two votes at the same moment can leave it
            // off by one until the TTL expires (accepted). Nothing cached yet: the next count() loads it.
            $cached = $this->cache->get(self::key($postId));
            if (is_int($cached)) {
                $this->cache->set(self::key($postId), max(0, $cached + ($flag ? 1 : -1)), $this->countTtl);
            }
        }

        return ['voted' => $flag, 'changed' => $changed];
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
