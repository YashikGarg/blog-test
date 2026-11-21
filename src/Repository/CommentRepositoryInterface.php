<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Comment;

interface CommentRepositoryInterface
{
    /** @return list<Comment> oldest first */
    public function findVisibleByPost(string $postId): array;

    public function findVisibleById(string $id): ?Comment;

    /** @return string the new comment UUID */
    public function create(string $postId, string $userId, string $body): string;

    public function update(string $id, string $body): void;

    /** Soft delete; $deletedBy is the acting user (owner or admin). */
    public function softDelete(string $id, string $deletedBy): void;
}
