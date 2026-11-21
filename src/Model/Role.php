<?php

declare(strict_types=1);

namespace App\Model;

enum Role: string
{
    case Admin = 'admin';
    case User = 'user';

    /** Hierarchy: admin satisfies user; user satisfies only user. */
    public function satisfies(self $required): bool
    {
        return match ($this) {
            self::Admin => true,
            self::User => $required === self::User,
        };
    }
}
