<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\User;
use PDO;
use Symfony\Component\Uid\Uuid;

final readonly class PdoUserRepository implements UserRepositoryInterface
{
    public const BY_EMAIL = 'SELECT BIN_TO_UUID(id) AS id, email, name, password_hash, role FROM users WHERE email = :email';
    public const BY_ID = 'SELECT BIN_TO_UUID(id) AS id, email, name, password_hash, role FROM users WHERE id = UUID_TO_BIN(:id)';

    public function __construct(private PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare(self::BY_EMAIL);
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function findById(string $id): ?User
    {
        $stmt = $this->pdo->prepare(self::BY_ID);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function create(string $email, string $name, string $passwordHash): string
    {
        $id = (string) Uuid::v7();
        $this->pdo->prepare(
            "INSERT INTO users (id, email, name, password_hash, role) VALUES (UUID_TO_BIN(:id), :email, :name, :hash, 'user')"
        )->execute(['id' => $id, 'email' => $email, 'name' => $name, 'hash' => $passwordHash]);

        return $id;
    }
}
