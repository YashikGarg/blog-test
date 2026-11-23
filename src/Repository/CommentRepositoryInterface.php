<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Comment;

interface CommentRepositoryInterface
{
    /**
     * Visible comment ids of a post, newest first (index only).
     *
     * @param array{createdAt:string,id:string}|null $before keyset cursor
     * @return list<array{id:string,createdAt:string}>
     */
    public function visibleIdsPage(string $postId, int $limit, ?array $before): array;

    /**
     * @param list<string> $ids at most 100
     * @return array<string,Comment> keyed by id; deleted or unknown ids are absent
     */
    public function findVisibleByIds(array $ids): array;

    public function findVisibleById(string $id): ?Comment;

    /** @return ?string the new id, or null when the post is not published (0 rows inserted) */
    public function createOnPublished(string $postId, string $userId, string $body): ?string;

    /** True when 1 row changed. 0 rows means: not visible, not the owner, OR the same text (the service tells them apart). */
    public function updateIfOwner(string $id, string $actorId, string $body): bool;

    /** Soft delete when the actor owns the comment or is an admin. True when 1 row changed. */
    public function softDeleteIfAllowed(string $id, string $actorId, bool $isAdmin): bool;
}
