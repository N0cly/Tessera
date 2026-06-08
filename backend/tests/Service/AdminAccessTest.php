<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\AdminAccess;
use PHPUnit\Framework\TestCase;

final class AdminAccessTest extends TestCase
{
    private function user(string $email, array $roles = []): User
    {
        return (new User())->setEmail($email)->setRoles($roles);
    }

    public function testDbRoleGrantsAdmin(): void
    {
        $access = new AdminAccess('', '');

        self::assertTrue($access->isAdmin($this->user('a@x.com', ['ROLE_ADMIN'])));
        self::assertFalse($access->isAdmin($this->user('b@x.com', [])));
    }

    public function testEmailAllowlistGrantsAdminCaseInsensitively(): void
    {
        $access = new AdminAccess('Admin@X.com, other@y.com', '');

        self::assertTrue($access->isAdmin($this->user('admin@x.com')), 'allowlist match is case-insensitive');
        self::assertTrue($access->isAdmin($this->user('OTHER@Y.com')));
        self::assertFalse($access->isAdmin($this->user('nope@x.com')));
    }

    public function testNonAdminWithoutRoleOrAllowlistIsDenied(): void
    {
        $access = new AdminAccess('', '');

        self::assertFalse($access->isAdmin($this->user('user@x.com')));
    }

    public function testIpAllowlistEmptyAllowsAll(): void
    {
        $access = new AdminAccess('', '');

        self::assertTrue($access->isIpAllowed('1.2.3.4'));
        self::assertTrue($access->isIpAllowed(null));
        self::assertFalse($access->hasIpAllowlist());
    }

    public function testIpAllowlistRestrictsToListedIps(): void
    {
        $access = new AdminAccess('', '10.0.0.1, 10.0.0.2');

        self::assertTrue($access->hasIpAllowlist());
        self::assertTrue($access->isIpAllowed('10.0.0.1'));
        self::assertTrue($access->isIpAllowed('10.0.0.2'));
        self::assertFalse($access->isIpAllowed('10.0.0.3'));
        self::assertFalse($access->isIpAllowed(null), 'unknown IP is refused when an allowlist exists');
    }

    public function testEmailAllowlistParsing(): void
    {
        $access = new AdminAccess("  one@x.com ,, two@x.com\tthree@x.com ", '');

        self::assertSame(['one@x.com', 'two@x.com', 'three@x.com'], $access->emailAllowlist());
    }
}
