<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Comment;
use App\Model\Role;
use App\Repository\CommentRepositoryInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Uid\Uuid;

/** Full comment CRUD. Ownership is enforced here, never only in the view. Every method returns a Comment (with its post slug). */
final readonly class CommentService
{
    public function __construct(
        private CommentRepositoryInterface $comments,
        private CacheInterface $cache,
        private int $maxLength,
        private int $perPage,
    ) {
    }

    /** @return array{comments: list<Comment>, nextCursor: ?string} newest first */
    public function listForPost(string $postId, ?string $cursor): array
    {
        $rows = $this->comments->visibleIdsPage($postId, $this->perPage + 1, $this->decodeCursor($cursor));
        $hasMore = count($rows) > $this->perPage;
        $rows = array_slice($rows, 0, $this->perPage);
        $ids = array_column($rows, 'id');

        $cached = [];
        foreach ($this->cache->getMultiple(array_map(self::key(...), $ids)) as $comment) {
            if ($comment instanceof Comment) {
                $cached[$comment->id] = $comment;
            }
        }
        $missing = array_values(array_diff($ids, array_keys($cached)));
        $loaded = $this->comments->findVisibleByIds($missing); // ONE query for all misses
        if ($loaded !== []) {
            $this->cache->setMultiple(array_combine(array_map(self::key(...), array_keys($loaded)), $loaded));
        }

        $all = $cached + $loaded;
        $list = array_values(array_filter(array_map(fn(string $id) => $all[$id] ?? null, $ids)));
        $last = $rows === [] ? null : end($rows);

        return ['comments' => $list, 'nextCursor' => $hasMore && $last !== null ? $this->encodeCursor($last) : null];
    }

    public function add(string $postId, string $userId, string $body): Comment
    {
        $id = $this->comments->createOnPublished($postId, $userId, $this->validateBody($body))
            ?? throw new NotFoundException('Post not found');
        $comment = $this->comments->findVisibleById($id) ?? throw new \RuntimeException('Comment vanished after insert');
        $this->cache->set(self::key($id), $comment);

        return $comment;
    }

    /** Only the owner may edit. One conditional UPDATE; the lookup runs only when it changed nothing. */
    public function update(string $commentId, string $actorId, string $body): Comment
    {
        $changed = $this->comments->updateIfOwner($commentId, $actorId, $this->validateBody($body));
        $comment = $this->comments->findVisibleById($commentId) ?? throw new NotFoundException('Comment not found');
        if (!$changed && $comment->userId !== $actorId) {
            throw new ForbiddenException();
        }
        $this->cache->set(self::key($commentId), $comment); // !$changed + owner = same text: harmless refresh

        return $comment;
    }

    /** The owner or an admin may delete (soft delete). One conditional UPDATE on success. */
    public function delete(string $commentId, string $actorId, Role $actorRole): void
    {
        if (!$this->comments->softDeleteIfAllowed($commentId, $actorId, $actorRole->satisfies(Role::Admin))) {
            if ($this->comments->findVisibleById($commentId) === null) {
                throw new NotFoundException('Comment not found');
            }
            throw new ForbiddenException();
        }
        $this->cache->delete(self::key($commentId));
    }

    private static function key(string $id): string
    {
        return 'comment.' . $id;
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

    /** @param array{id:string,createdAt:string} $row */
    private function encodeCursor(array $row): string
    {
        return rtrim(strtr(base64_encode($row['createdAt'] . '|' . $row['id']), '+/', '-_'), '=');
    }

    /** @return array{createdAt:string,id:string}|null an invalid cursor is ignored, never an error */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $parts = explode('|', $raw, 2);
        if (count($parts) !== 2 || preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $parts[0]) !== 1 || !Uuid::isValid($parts[1])) {
            return null;
        }

        return ['createdAt' => $parts[0], 'id' => $parts[1]];
    }
}
