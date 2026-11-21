<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;
use App\Model\User;
use App\Repository\UserRepositoryInterface;
use PDOException;
use Psr\Log\LoggerInterface;

final class AuthService
{
    private const GENERIC_LOGIN_ERROR = 'Email or password is incorrect';
    /** A fixed argon2id hash (same cost as real ones) of a random string nobody knows: verified when the email is unknown, so timing does not reveal which emails exist. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$RC5FQmVsVTlhcmFUWEtQdQ$deoMSu6yoZW5kpuIACSaDCBW7H+17PhSCNkCjudX6Ms';

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly LoginThrottle $throttle,
        private readonly LoggerInterface $logger,
        private readonly string $passwordRegex,
        private readonly string $passwordHint,
    ) {
    }

    public function register(string $email, string $name, string $password, string $ip = ''): User
    {
        $email = trim($email);
        $name = trim($name);
        $errors = [];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            $errors['email'] = 'Enter a valid email address, like name@example.com';
        }
        if (mb_strlen($name) < 1 || mb_strlen($name) > 100) {
            $errors['name'] = 'Enter your name (1 to 100 characters)';
        }
        if (preg_match($this->passwordRegex, $password) !== 1) {
            $errors['password'] = 'Password needs ' . $this->passwordHint;
        }
        if ($errors === [] && $this->users->findByEmail($email) !== null) {
            $errors['email'] = 'This email is already registered';
        }
        if ($errors !== []) {
            $this->logger->info('register invalid', ['event' => 'auth.register_invalid', 'ip' => $ip, 'user_id' => null]);
            throw new ValidationException($errors);
        }

        try {
            $id = $this->users->create($email, $name, password_hash($password, PASSWORD_ARGON2ID));
        } catch (PDOException $e) {
            // Two sign-ups racing for one email: the UNIQUE key decides.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ValidationException(['email' => 'This email is already registered']);
            }
            throw $e;
        }

        $user = $this->users->findById($id);
        if ($user === null) {
            throw new \RuntimeException('User vanished after insert');
        }

        return $user;
    }

    public function login(string $email, string $password, string $ip): User
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $password === '') {
            $this->logger->info('login failed', ['event' => 'auth.login_failed', 'ip' => $ip, 'user_id' => null]);
            throw new ValidationException(['_form' => self::GENERIC_LOGIN_ERROR]);
        }

        $user = $this->users->findByEmail($email);
        // The key comes from the account, never from the typed text: the email column ignores accents
        // and case, so typed variants must not get fresh counters.
        $key = $user !== null
            ? sha1('u:' . $user->id . '|' . $ip)
            : sha1('e:' . strtolower($email) . '|' . $ip);

        // One attempt at a time per IP+account, so parallel guesses can't all pass the check first.
        if (!$this->throttle->acquire($key, 5)) {
            $this->logger->warning('login lock timeout', ['event' => 'auth.lock_timeout', 'ip' => $ip, 'user_id' => $user?->id]);
            throw new ValidationException(['_form' => 'Too many attempts at once, try again']);
        }

        try {
            $wait = $this->throttle->retryAfter($key);
            if ($wait > 0) {
                $this->logger->warning('login locked', ['event' => 'auth.locked', 'ip' => $ip, 'user_id' => $user?->id]);
                throw new ValidationException(['_form' => 'Too many attempts. Try again in ' . (int) ceil($wait / 60) . ' minutes.']);
            }

            // Verify against a dummy hash when the user is missing, so timing doesn't reveal which emails exist.
            $valid = password_verify($password, $user->passwordHash ?? self::DUMMY_HASH);
            if ($user === null || !$valid) {
                $this->throttle->recordFailure($key);
                $this->logger->warning('login failed', ['event' => 'auth.login_failed', 'ip' => $ip, 'user_id' => $user?->id]);
                throw new ValidationException(['_form' => self::GENERIC_LOGIN_ERROR]);
            }

            $this->throttle->reset($key);

            return $user;
        } finally {
            $this->throttle->release($key);
        }
    }

}
