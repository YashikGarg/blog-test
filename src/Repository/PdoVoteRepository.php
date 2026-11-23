<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use PDOException;

final readonly class PdoVoteRepository implements VoteRepositoryInterface
{
    public const HAS_VOTED = "SELECT 1 FROM post_votes WHERE post_id = UUID_TO_BIN(:p) AND user_id = UUID_TO_BIN(:u) AND status = 'active'";
    public const UPSERT = "INSERT INTO post_votes (post_id, user_id, status)
        SELECT p.id, UUID_TO_BIN(:u), :w FROM posts p WHERE p.id = UUID_TO_BIN(:p) AND p.status = 'published'
        ON DUPLICATE KEY UPDATE status = :w2";
    public const COUNT_UPDATE = 'UPDATE post_vote_counts SET upvotes = LAST_INSERT_ID(upvotes + :d) WHERE post_id = UUID_TO_BIN(:p)';
    public const COUNT = 'SELECT upvotes FROM post_vote_counts WHERE post_id = UUID_TO_BIN(:p)';
    /** Only when the upsert changed nothing: a repeat click (row found, so count) or a post that is not published (no row, so 404). */
    public const PUBLISHED_COUNT = "SELECT c.upvotes FROM post_vote_counts c JOIN posts p ON p.id = c.post_id AND p.status = 'published'
        WHERE c.post_id = UUID_TO_BIN(:p)";

    public function __construct(private PDO $pdo)
    {
    }

    public function hasVoted(string $postId, string $userId): bool
    {
        $stmt = $this->pdo->prepare(self::HAS_VOTED);
        $stmt->execute(['p' => $postId, 'u' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function set(string $postId, string $userId, bool $wanted): ?array
    {
        // One short transaction, fixed lock order (vote row first, then counter). On a deadlock, retry once.
        try {
            return $this->write($postId, $userId, $wanted);
        } catch (PDOException $e) {
            if (!$this->isDeadlock($e)) {
                throw $e;
            }

            return $this->write($postId, $userId, $wanted);
        }
    }

    public function count(string $postId): int
    {
        $stmt = $this->pdo->prepare(self::COUNT);
        $stmt->execute(['p' => $postId]);
        $count = $stmt->fetchColumn();

        return $count === false ? 0 : (int) $count;
    }

    /** @return array{voted: bool, count: int}|null */
    private function write(string $postId, string $userId, bool $wanted): ?array
    {
        $status = $wanted ? 'active' : 'removed';
        $count = null;
        $this->pdo->beginTransaction();
        try {
            $upsert = $this->pdo->prepare(self::UPSERT);
            $upsert->execute(['u' => $userId, 'w' => $status, 'p' => $postId, 'w2' => $status]);
            // rowCount: 1 = new row, 2 = status changed, 0 = nothing changed or post not published.
            $delta = match ($upsert->rowCount()) {
                1 => $wanted ? 1 : 0,
                2 => $wanted ? 1 : -1,
                default => 0,
            };
            if ($delta !== 0) {
                $update = $this->pdo->prepare(self::COUNT_UPDATE);
                $update->bindValue('d', $delta, PDO::PARAM_INT);
                $update->bindValue('p', $postId);
                $update->execute();
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('Vote counter row is missing');
                }
                // Read it HERE: LAST_INSERT_ID(upvotes + d) is the new count, but the client resets lastInsertId() to 0 after COMMIT.
                $count = (int) $this->pdo->lastInsertId();
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        if ($count !== null) {
            return ['voted' => $wanted, 'count' => $count];
        }
        $stmt = $this->pdo->prepare(self::PUBLISHED_COUNT); // rare path only
        $stmt->execute(['p' => $postId]);
        $count = $stmt->fetchColumn();

        return $count === false ? null : ['voted' => $wanted, 'count' => (int) $count];
    }

    private function isDeadlock(PDOException $e): bool
    {
        return $e->getCode() === '40001' || ($e->errorInfo[1] ?? null) === 1213;
    }
}
