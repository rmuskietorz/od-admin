<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    public function testGetUserIdentifierReturnsUsername(): void
    {
        $user = new User('robin', 'hashed');
        self::assertSame('robin', $user->getUserIdentifier());
        self::assertSame('robin', $user->getUsername());
    }

    public function testRolesAlwaysIncludeUserRole(): void
    {
        $user = new User('robin', 'hashed');
        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function testCustomRolesAreNotDuplicated(): void
    {
        $user = new User('robin', 'hashed');
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $roles = $user->getRoles();
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $roles);
        self::assertCount(2, $roles);
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $before = new \DateTimeImmutable();
        $user = new User('robin', 'hashed');
        $after = new \DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $user->getCreatedAt());
        self::assertLessThanOrEqual($after, $user->getCreatedAt());
    }

    public function testEraseCredentialsIsNoOp(): void
    {
        $user = new User('robin', 'hashed');
        $user->eraseCredentials();
        self::assertSame('hashed', $user->getPassword());
    }

    public function testSetPasswordUpdatesHash(): void
    {
        $user = new User('robin', 'old-hash');
        $user->setPassword('new-hash');
        self::assertSame('new-hash', $user->getPassword());
    }
}
