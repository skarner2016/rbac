<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Skarner\Rbac\Enums\PermissionType;
use Skarner\Rbac\Exceptions\RbacException;

final class PermissionTypeTest extends TestCase
{
    public function testParseAcceptsEnumAndString(): void
    {
        self::assertSame(PermissionType::Menu, PermissionType::parse(PermissionType::Menu));
        self::assertSame(PermissionType::Route, PermissionType::parse('route'));
        self::assertSame(PermissionType::Button, PermissionType::parse('button'));
    }

    public function testInvalidValueThrows(): void
    {
        $this->expectException(RbacException::class);

        PermissionType::parse('unknown');
    }

    public function testHelpers(): void
    {
        self::assertTrue(PermissionType::Menu->isMenu());
        self::assertFalse(PermissionType::Menu->isRoute());
        self::assertFalse(PermissionType::Menu->isButton());

        self::assertTrue(PermissionType::Route->isRoute());
        self::assertTrue(PermissionType::Button->isButton());
    }
}
