<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repository\LoginAttemptRepositoryInterface;
use App\Service\LoginThrottle;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[Group('unit')]
#[CoversClass(LoginThrottle::class)]
final class LoginThrottleTest extends TestCase
{
    public function test_lock_doubles_after_free_attempts_and_caps_at_max(): void
    {
        $throttle = new LoginThrottle($this->createStub(LoginAttemptRepositoryInterface::class), new MockClock(), 5, 60, 7200);

        self::assertSame(60, $throttle->lockSeconds(5));
        self::assertSame(120, $throttle->lockSeconds(6));
        self::assertSame(240, $throttle->lockSeconds(7));
        self::assertSame(7200, $throttle->lockSeconds(40));
    }

    public function test_failure_at_threshold_extends_lock(): void
    {
        $clock = new MockClock('2026-01-01 12:00:00');
        $expected = $clock->now()->getTimestamp() + 60;
        $attempts = $this->createMock(LoginAttemptRepositoryInterface::class);
        $attempts->method('incrementFailures')->willReturn(5);
        $attempts->expects(self::once())->method('extendLock')->with(
            'key',
            self::callback(static fn (DateTimeImmutable $until): bool => $until->getTimestamp() === $expected),
        );
        $throttle = new LoginThrottle($attempts, $clock, 5, 60, 7200);

        $throttle->recordFailure('key');
    }
}
