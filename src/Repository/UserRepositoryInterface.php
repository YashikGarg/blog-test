<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function findById(string $id): ?User;

    /** @return string the new user's UUID */
    public function create(string $email, string $name, string $passwordHash): string;
}
