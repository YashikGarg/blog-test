<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use PDOException;

final readonly class PdoVoteRepository implements VoteRepositoryInterface
{
    public const HAS_VOTED = "SELECT 1 FROM post_votes WHERE post_id = UUID_TO_BIN(:p) AND user_id = UUID_TO_BIN(:u) AND status = 'active'";
    public const UPSERT = "INSERT INTO post_votes (post_id, user_id, status) VALUES (UUID_TO_BIN(:p), UUID_TO_BIN(:u), 'active') AS new
        ON DUPLICATE KEY UPDATE status = IF(post_votes.status = 'active', 'removed', 'active')";
    public const COUNT_UPDATE = "UPDATE post_vote_counts
        SET upvotes = upvotes + IF(EXISTS(SELECT 1 FROM post_votes WHERE post_id = UUID_TO_BIN(:p2) AND user_id = UUID_TO_BIN(:u2) AND status = 'active'), 1, -1)
        WHERE post_id = UUID_TO_BIN(:p3)";
    public const STATE = 'SELECT v.status AS my_vote, c.upvotes
        FROM post_vote_counts c
        LEFT JOIN post_votes v ON v.post_id = c.post_id AND v.user_id = UUID_TO_BIN(:u)
        WHERE c.post_id = UUID_TO_BIN(:p)';

    public function __construct(private PDO $pdo)
    {
    }

    public function hasVoted(string $postId, string $userId): bool
    {
        $stmt = $this->pdo->prepare(self::HAS_VOTED);
        $stmt->execute(['p' => $postId, 'u' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function toggle(string $postId, string $userId): array
    {
        // Write: one short transaction, fixed lock order (vote row first, then counter).
        // On a deadlock, roll back and retry once.
        try {
            $this->write($postId, $userId);
        } catch (PDOException $e) {
            if (!$this->isDeadlock($e)) {
                throw $e;
            }
            $this->write($postId, $userId);
        }

        // Read after COMMIT: committed data only, holds no locks.
        $stmt = $this->pdo->prepare(self::STATE);
        $stmt->execute(['u' => $userId, 'p' => $postId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new \RuntimeException('Vote counter row is missing');
        }

        return ['voted' => $row['my_vote'] === 'active', 'count' => (int) $row['upvotes']];
    }

    private function write(string $postId, string $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(self::UPSERT)->execute(['p' => $postId, 'u' => $userId]);
            $update = $this->pdo->prepare(self::COUNT_UPDATE);
            $update->execute(['p2' => $postId, 'u2' => $userId, 'p3' => $postId]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Vote counter row is missing');
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function isDeadlock(PDOException $e): bool
    {
        return $e->getCode() === '40001' || ($e->errorInfo[1] ?? null) === 1213;
    }
}
