<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\LoginAttemptRepositoryInterface;
use Psr\Clock\ClockInterface;

/** Exponential-backoff login lock on top of the login_attempts table (no library offers this). */
final readonly class LoginThrottle
{
    public function __construct(
        private LoginAttemptRepositoryInterface $attempts,
        private ClockInterface $clock,
        private int $freeAttempts,
        private int $baseSeconds,
        private int $maxSeconds,
    ) {
    }

    /** Seconds until the account may try again (0 = not locked). */
    public function retryAfter(string $key): int
    {
        $until = $this->attempts->lockedUntil($key);
        if ($until === null) {
            return 0;
        }

        return max(0, $until->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    public function recordFailure(string $key): void
    {
        $now = $this->clock->now();
        $failures = $this->attempts->incrementFailures($key, $now, $this->maxSeconds);
        if ($failures >= $this->freeAttempts) {
            $this->attempts->extendLock($key, $now->modify('+' . $this->lockSeconds($failures) . ' seconds'));
        }
    }

    /** Lock length for the Nth failure: base, then doubling, capped. Pure. */
    public function lockSeconds(int $failures): int
    {
        return min($this->baseSeconds * 2 ** min($failures - $this->freeAttempts, 30), $this->maxSeconds);
    }

    public function reset(string $key): void
    {
        $this->attempts->reset($key);
    }

    public function acquire(string $key, int $timeoutSeconds): bool
    {
        return $this->attempts->acquire($key, $timeoutSeconds);
    }

    public function release(string $key): void
    {
        $this->attempts->release($key);
    }
}
