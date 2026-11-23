<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Comment;
use PDO;
use Symfony\Component\Uid\Uuid;

final readonly class PdoCommentRepository implements CommentRepositoryInterface
{
    private const COLUMNS = "SELECT BIN_TO_UUID(c.id) AS id, BIN_TO_UUID(c.post_id) AS post_id, p.slug AS post_slug,
        BIN_TO_UUID(c.user_id) AS user_id, u.name AS user_name, c.body, c.created_at, c.updated_at
        FROM comments c JOIN users u ON u.id = c.user_id JOIN posts p ON p.id = c.post_id";
    public const BY_ID = self::COLUMNS . " WHERE c.id = UUID_TO_BIN(:id) AND c.status = 'visible'";
    public const BY_IDS = self::COLUMNS . " WHERE c.status = 'visible' AND c.id IN "; // + "(UUID_TO_BIN(:id0), …)" built in code
    // Index-only (idx_comments_post_status_created + PK). Qualify comments.id: a bare `id` in ORDER BY means the
    // BIN_TO_UUID alias and causes a filesort (verified).
    public const IDS_PAGE = "SELECT BIN_TO_UUID(id) AS id, created_at FROM comments
        WHERE post_id = UUID_TO_BIN(:post) AND status = 'visible'";
    public const IDS_CURSOR = ' AND (created_at < :ca OR (created_at = :ca2 AND id < UUID_TO_BIN(:cid)))';
    public const IDS_TAIL = ' ORDER BY comments.created_at DESC, comments.id DESC LIMIT :limit';
    public const CREATE_ON_PUBLISHED = "INSERT INTO comments (id, post_id, user_id, body)
        SELECT UUID_TO_BIN(:id), p.id, UUID_TO_BIN(:u), :b FROM posts p WHERE p.id = UUID_TO_BIN(:p) AND p.status = 'published'";
    public const UPDATE_IF_OWNER = "UPDATE comments SET body = :body
        WHERE id = UUID_TO_BIN(:id) AND status = 'visible' AND user_id = UUID_TO_BIN(:actor)";
    public const DELETE_IF_ALLOWED = "UPDATE comments SET status = 'deleted', deleted_at = UTC_TIMESTAMP(), deleted_by = UUID_TO_BIN(:actor)
        WHERE id = UUID_TO_BIN(:id) AND status = 'visible' AND (user_id = UUID_TO_BIN(:actor2) OR :is_admin = 1)";

    private const MAX_IDS = 100;

    public function __construct(private PDO $pdo)
    {
    }

    public function visibleIdsPage(string $postId, int $limit, ?array $before): array
    {
        $sql = self::IDS_PAGE . ($before === null ? '' : self::IDS_CURSOR) . self::IDS_TAIL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('post', $postId);
        if ($before !== null) {
            $stmt->bindValue('ca', $before['createdAt']);
            $stmt->bindValue('ca2', $before['createdAt']);
            $stmt->bindValue('cid', $before['id']);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = ['id' => (string) $row['id'], 'createdAt' => (string) $row['created_at']];
        }

        return $out;
    }

    public function findVisibleByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        if (count($ids) > self::MAX_IDS) {
            throw new \InvalidArgumentException('Too many comment ids');
        }
        $ids = array_values($ids);
        $placeholders = '(' . implode(', ', array_map(fn(int $i) => "UUID_TO_BIN(:id$i)", array_keys($ids))) . ')';
        $stmt = $this->pdo->prepare(self::BY_IDS . $placeholders);
        foreach ($ids as $i => $id) {
            $stmt->bindValue("id$i", $id);
        }
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $comment = Comment::fromRow($row);
            $out[$comment->id] = $comment;
        }

        return $out;
    }

    public function findVisibleById(string $id): ?Comment
    {
        $stmt = $this->pdo->prepare(self::BY_ID);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Comment::fromRow($row);
    }

    public function createOnPublished(string $postId, string $userId, string $body): ?string
    {
        $id = (string) Uuid::v7();
        $stmt = $this->pdo->prepare(self::CREATE_ON_PUBLISHED);
        $stmt->execute(['id' => $id, 'u' => $userId, 'b' => $body, 'p' => $postId]);

        return $stmt->rowCount() === 1 ? $id : null;
    }

    public function updateIfOwner(string $id, string $actorId, string $body): bool
    {
        $stmt = $this->pdo->prepare(self::UPDATE_IF_OWNER);
        $stmt->execute(['body' => $body, 'id' => $id, 'actor' => $actorId]);

        return $stmt->rowCount() === 1;
    }

    public function softDeleteIfAllowed(string $id, string $actorId, bool $isAdmin): bool
    {
        $stmt = $this->pdo->prepare(self::DELETE_IF_ALLOWED);
        $stmt->bindValue('actor', $actorId);
        $stmt->bindValue('id', $id);
        $stmt->bindValue('actor2', $actorId);
        $stmt->bindValue('is_admin', $isAdmin ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }
}
