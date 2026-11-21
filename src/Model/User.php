<?php

declare(strict_types=1);

namespace App\Model;

final readonly class User
{
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public string $passwordHash,
        public Role $role,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            (string) $row['email'],
            (string) $row['name'],
            (string) $row['password_hash'],
            Role::from((string) $row['role']),
        );
    }
}
