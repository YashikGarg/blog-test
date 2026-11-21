<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;

interface LoginAttemptRepositoryInterface
{
    public function lockedUntil(string $key): ?DateTimeImmutable;

    /** Atomic: returns the new failure count (restarts at 1 after a quiet window). */
    public function incrementFailures(string $key, DateTimeImmutable $now, int $windowSeconds): int;

    /** Never shortens an existing lock. */
    public function extendLock(string $key, DateTimeImmutable $until): void;

    public function reset(string $key): void;

    public function acquire(string $key, int $timeoutSeconds): bool;

    public function release(string $key): void;
}
