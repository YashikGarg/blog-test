<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use PDOException;

final readonly class PdoVoteRepository implements VoteRepositoryInterface
{
    public const HAS_VOTED = "SELECT 1 FROM post_votes WHERE post_id = UUID_TO_BIN(:p) AND user_id = UUID_TO_BIN(:u) AND status = 'active'";
    /** Upvote: insert the row, or switch removed -> active. rowCount 1 or 2 = changed, 0 = already active or post not published. */
    public const UPVOTE = "INSERT INTO post_votes (post_id, user_id, status)
        SELECT p.id, UUID_TO_BIN(:u), 'active' FROM posts p WHERE p.id = UUID_TO_BIN(:p) AND p.status = 'published'
        ON DUPLICATE KEY UPDATE status = 'active'";
    /** Devote: only active -> removed; never inserts a row. rowCount 1 = changed, 0 = no vote, already removed or post not published. */
    public const DEVOTE = "UPDATE post_votes v JOIN posts p ON p.id = v.post_id AND p.status = 'published'
        SET v.status = 'removed'
        WHERE v.post_id = UUID_TO_BIN(:p) AND v.user_id = UUID_TO_BIN(:u) AND v.status = 'active'";
    public const COUNT_UPDATE = 'UPDATE post_vote_counts SET upvotes = upvotes + :d WHERE post_id = UUID_TO_BIN(:p)';
    public const COUNT = 'SELECT upvotes FROM post_vote_counts WHERE post_id = UUID_TO_BIN(:p)';
    /** Only when nothing changed: tells a repeat click (published) from a post that is not published (404). */
    public const IS_PUBLISHED = "SELECT 1 FROM posts WHERE id = UUID_TO_BIN(:p) AND status = 'published'";

    public function __construct(private PDO $pdo)
    {
    }

    public function hasVoted(string $postId, string $userId): bool
    {
        $stmt = $this->pdo->prepare(self::HAS_VOTED);
        $stmt->execute(['p' => $postId, 'u' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function set(string $postId, string $userId, bool $wanted): ?bool
    {
        // On a deadlock, retry once.
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

    /** One short transaction: the vote row, then the counter ±1 only if the vote really changed (fixed lock order). */
    private function write(string $postId, string $userId, bool $wanted): ?bool
    {
        $this->pdo->beginTransaction();
        try {
            $vote = $this->pdo->prepare($wanted ? self::UPVOTE : self::DEVOTE);
            $vote->execute(['u' => $userId, 'p' => $postId]);
            $changed = $vote->rowCount() > 0;
            if ($changed) {
                $update = $this->pdo->prepare(self::COUNT_UPDATE);
                $update->bindValue('d', $wanted ? 1 : -1, PDO::PARAM_INT);
                $update->bindValue('p', $postId);
                $update->execute();
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('Vote counter row is missing');
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        if ($changed) {
            return true;
        }
        $stmt = $this->pdo->prepare(self::IS_PUBLISHED); // rare path: a repeat click, or a post that is not published
        $stmt->execute(['p' => $postId]);

        return $stmt->fetchColumn() === false ? null : false;
    }

    private function isDeadlock(PDOException $e): bool
    {
        return $e->getCode() === '40001' || ($e->errorInfo[1] ?? null) === 1213;
    }
}
