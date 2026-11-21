<?php

declare(strict_types=1);

namespace App\Http;

use App\Model\Role;
use App\Model\User;

/** The only class that touches $_SESSION. */
final class Session
{
    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function userId(): ?string
    {
        $id = $this->get('user_id');

        return is_string($id) ? $id : null;
    }

    public function role(): ?Role
    {
        $role = $this->get('role');

        return is_string($role) ? Role::tryFrom($role) : null;
    }

    public function login(User $user): void
    {
        $this->regenerate();
        $this->set('user_id', $user->id);
        $this->set('user_name', $user->name);
        $this->set('role', $user->role->value);
        $this->rotateCsrf();
    }

    /** Destroy the login, regenerate the id and rotate the CSRF token. */
    public function logout(): void
    {
        $_SESSION = [];
        $this->regenerate();
        $this->rotateCsrf();
    }

    public function csrfToken(): string
    {
        $token = $this->get('csrf');
        if (!is_string($token) || $token === '') {
            $token = $this->rotateCsrf();
        }

        return $token;
    }

    public function lastSeen(): ?int
    {
        $t = $this->get('last_seen');

        return is_int($t) ? $t : null;
    }

    public function touch(int $now): void
    {
        $this->set('last_seen', $now);
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public function pullFlash(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return is_array($flash) ? array_values($flash) : [];
    }

    /** Variables every template gets (layout needs them). */
    public function viewData(): array
    {
        $id = $this->userId();

        return [
            'csrf' => $this->csrfToken(),
            'currentUser' => $id === null ? null : [
                'id' => $id,
                'name' => (string) $this->get('user_name'),
                'role' => $this->role()?->value,
            ],
            'isAdmin' => $this->role() === Role::Admin,
            'flash' => $this->pullFlash(),
        ];
    }

    private function rotateCsrf(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->set('csrf', $token);

        return $token;
    }
}
