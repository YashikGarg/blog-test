<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Comment;
use PDO;
use Symfony\Component\Uid\Uuid;

final readonly class PdoCommentRepository implements CommentRepositoryInterface
{
    public const BY_POST = "SELECT BIN_TO_UUID(c.id) AS id, BIN_TO_UUID(c.post_id) AS post_id, p.slug AS post_slug,
        BIN_TO_UUID(c.user_id) AS user_id, u.name AS user_name, c.body, c.created_at, c.updated_at
        FROM comments c JOIN users u ON u.id = c.user_id JOIN posts p ON p.id = c.post_id
        WHERE c.post_id = UUID_TO_BIN(:post) AND c.status = 'visible'
        ORDER BY c.created_at, c.id";
    public const BY_ID = "SELECT BIN_TO_UUID(c.id) AS id, BIN_TO_UUID(c.post_id) AS post_id, p.slug AS post_slug,
        BIN_TO_UUID(c.user_id) AS user_id, u.name AS user_name, c.body, c.created_at, c.updated_at
        FROM comments c JOIN users u ON u.id = c.user_id JOIN posts p ON p.id = c.post_id
        WHERE c.id = UUID_TO_BIN(:id) AND c.status = 'visible'";

    public function __construct(private PDO $pdo)
    {
    }

    public function findVisibleByPost(string $postId): array
    {
        $stmt = $this->pdo->prepare(self::BY_POST);
        $stmt->execute(['post' => $postId]);

        return array_map(Comment::fromRow(...), $stmt->fetchAll());
    }

    public function findVisibleById(string $id): ?Comment
    {
        $stmt = $this->pdo->prepare(self::BY_ID);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : Comment::fromRow($row);
    }

    public function create(string $postId, string $userId, string $body): string
    {
        $id = (string) Uuid::v7();
        $this->pdo->prepare(
            'INSERT INTO comments (id, post_id, user_id, body) VALUES (UUID_TO_BIN(:id), UUID_TO_BIN(:post), UUID_TO_BIN(:user), :body)'
        )->execute(['id' => $id, 'post' => $postId, 'user' => $userId, 'body' => $body]);

        return $id;
    }

    public function update(string $id, string $body): void
    {
        $this->pdo->prepare("UPDATE comments SET body = :body WHERE id = UUID_TO_BIN(:id) AND status = 'visible'")
            ->execute(['body' => $body, 'id' => $id]);
    }

    public function softDelete(string $id): void
    {
        $this->pdo->prepare(
            "UPDATE comments SET status = 'deleted', deleted_at = UTC_TIMESTAMP() WHERE id = UUID_TO_BIN(:id) AND status = 'visible'"
        )->execute(['id' => $id]);
    }
}
