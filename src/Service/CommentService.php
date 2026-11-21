<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Comment;
use App\Model\PostStatus;
use App\Model\Role;
use App\Repository\CommentRepositoryInterface;
use App\Repository\PostRepositoryInterface;

/** Full comment CRUD. Ownership is enforced here, never only in the view. Every method returns a Comment (with its post slug). */
final readonly class CommentService
{
    public function __construct(
        private CommentRepositoryInterface $comments,
        private PostRepositoryInterface $posts,
        private int $maxLength,
    ) {
    }

    /** @return list<Comment> */
    public function listForPost(string $postId): array
    {
        return $this->comments->findVisibleByPost($postId);
    }

    public function add(string $postId, string $userId, string $body): Comment
    {
        $body = $this->validateBody($body);
        $post = $this->posts->findById($postId);
        if ($post === null || $post->status !== PostStatus::Published) {
            throw new NotFoundException('Post not found');
        }

        return $this->findOrFail($this->comments->create($postId, $userId, $body));
    }

    /** Only the owner may edit; admins moderate by deleting. */
    public function update(string $commentId, string $actorId, string $body): Comment
    {
        $comment = $this->findOrFail($commentId);
        if ($comment->userId !== $actorId) {
            throw new ForbiddenException();
        }
        $this->comments->update($commentId, $this->validateBody($body));

        return $this->findOrFail($commentId);
    }

    /** The owner or an admin may delete (soft delete). Returns the comment as it was, for its post slug. */
    public function delete(string $commentId, string $actorId, Role $actorRole): Comment
    {
        $comment = $this->findOrFail($commentId);
        if ($comment->userId !== $actorId && !$actorRole->satisfies(Role::Admin)) {
            throw new ForbiddenException();
        }
        $this->comments->softDelete($commentId, $actorId);

        return $comment;
    }

    private function validateBody(string $body): string
    {
        $body = trim($body);
        $length = mb_strlen($body);
        if ($length < 1 || $length > $this->maxLength) {
            throw new ValidationException(['body' => 'Write a comment of 1 to ' . $this->maxLength . ' characters']);
        }

        return $body;
    }

    private function findOrFail(string $id): Comment
    {
        return $this->comments->findVisibleById($id) ?? throw new NotFoundException('Comment not found');
    }
}
