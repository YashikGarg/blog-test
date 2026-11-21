<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Model\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[CoversClass(Role::class)]
final class RoleTest extends TestCase
{
    public function test_admin_satisfies_user_but_user_does_not_satisfy_admin(): void
    {
        $adminSatisfiesUser = Role::Admin->satisfies(Role::User);
        $userSatisfiesAdmin = Role::User->satisfies(Role::Admin);

        self::assertTrue($adminSatisfiesUser);
        self::assertFalse($userSatisfiesAdmin);
    }
}
