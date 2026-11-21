<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\ValidationException;
use App\Model\Role;
use App\Model\User;
use App\Repository\LoginAttemptRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\AuthService;
use App\Service\LoginThrottle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[Group('unit')]
#[CoversClass(AuthService::class)]
final class AuthServiceTest extends TestCase
{
    public function test_login_succeeds_with_correct_password(): void
    {
        $user = $this->user('correct-pass-1');
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturn($user);
        $service = $this->service($users);

        $result = $service->login('ann@example.com', 'correct-pass-1', '127.0.0.1');

        self::assertSame($user, $result);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturn($this->user('correct-pass-1'));
        $service = $this->service($users);

        $errors = $this->errorsFrom(static fn () => $service->login('ann@example.com', 'wrong-pass', '127.0.0.1'));

        self::assertSame(['_form' => 'Email or password is incorrect'], $errors);
    }

    public function test_register_rejects_password_not_matching_policy(): void
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturn(null);
        $service = $this->service($users);

        $errors = $this->errorsFrom(static fn () => $service->register('ann@example.com', 'Ann', 'short'));

        self::assertArrayHasKey('password', $errors);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByEmail')->willReturn($this->user('whatever-pass'));
        $users->expects(self::never())->method('create');
        $service = $this->service($users);

        $errors = $this->errorsFrom(static fn () => $service->register('ann@example.com', 'Ann', 'long-enough-pass'));

        self::assertArrayHasKey('email', $errors);
    }

    /** The field errors of the ValidationException the call throws (fails the test if it throws nothing). */
    private function errorsFrom(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors;
        }

        self::fail('Expected ValidationException');
    }

    private function service(UserRepositoryInterface $users): AuthService
    {
        $attempts = $this->createStub(LoginAttemptRepositoryInterface::class);
        $attempts->method('acquire')->willReturn(true);
        $attempts->method('lockedUntil')->willReturn(null);
        $throttle = new LoginThrottle($attempts, new MockClock(), 5, 60, 7200);

        return new AuthService($users, $throttle, new NullLogger(), '/^.{8,}$/', 'at least 8 characters');
    }

    private function user(string $password): User
    {
        return new User('user-1', 'ann@example.com', 'Ann', password_hash($password, PASSWORD_ARGON2ID), Role::User);
    }
}
