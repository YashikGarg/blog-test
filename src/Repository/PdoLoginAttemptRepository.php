<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    private const FORMAT = 'Y-m-d H:i:s';

    public const LOOKUP = 'SELECT locked_until FROM login_attempts WHERE throttle_key = UNHEX(:k)';
    // `failures` must stay before `last_failed_at`: MySQL evaluates the assignments left to right.
    public const INCREMENT = 'INSERT INTO login_attempts (throttle_key, failures, last_failed_at) VALUES (UNHEX(:k), LAST_INSERT_ID(1), :now) AS new
        ON DUPLICATE KEY UPDATE
          failures = LAST_INSERT_ID(IF(login_attempts.last_failed_at < new.last_failed_at - INTERVAL :window SECOND, 1, login_attempts.failures + 1)),
          last_failed_at = new.last_failed_at';

    public function __construct(private PDO $pdo)
    {
    }

    public function lockedUntil(string $key): ?DateTimeImmutable
    {
        $stmt = $this->pdo->prepare(self::LOOKUP);
        $stmt->execute(['k' => $key]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : null;
    }

    public function incrementFailures(string $key, DateTimeImmutable $now, int $windowSeconds): int
    {
        $stmt = $this->pdo->prepare(self::INCREMENT);
        $stmt->bindValue(':k', $key);
        $stmt->bindValue(':now', $this->format($now));
        $stmt->bindValue(':window', $windowSeconds, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function extendLock(string $key, DateTimeImmutable $until): void
    {
        $this->pdo->prepare(
            'UPDATE login_attempts SET locked_until = GREATEST(COALESCE(locked_until, :u), :u2) WHERE throttle_key = UNHEX(:k)'
        )->execute(['u' => $this->format($until), 'u2' => $this->format($until), 'k' => $key]);
    }

    public function reset(string $key): void
    {
        $this->pdo->prepare('UPDATE login_attempts SET failures = 0, locked_until = NULL WHERE throttle_key = UNHEX(:k)')
            ->execute(['k' => $key]);
    }

    public function acquire(string $key, int $timeoutSeconds): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, :t)');
        $stmt->bindValue(':name', 'login:' . $key);
        $stmt->bindValue(':t', $timeoutSeconds, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    public function release(string $key): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->execute(['name' => 'login:' . $key]);
        $stmt->fetchAll();
    }

    private function format(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }
}
