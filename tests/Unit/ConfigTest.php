<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Skarner\Rbac\Config;
use Skarner\Rbac\Exceptions\RbacException;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::fromArray();

        self::assertSame('rbac_roles', $config->table('roles'));
        self::assertSame('rbac_permissions', $config->table('permissions'));
        self::assertSame('rbac_role_has_permissions', $config->table('role_has_permissions'));
        self::assertSame('rbac_subject_has_roles', $config->table('subject_has_roles'));
        self::assertSame('user', $config->subjectType());
        self::assertTrue($config->includePermissionCodes());
    }

    public function testCustomTablesAreMergedWithDefaults(): void
    {
        $config = Config::fromArray([
            'tables' => [
                'roles' => 'custom_roles',
                'permissions' => 'custom_permissions',
            ],
        ]);

        self::assertSame('custom_roles', $config->table('roles'));
        self::assertSame('custom_permissions', $config->table('permissions'));
        self::assertSame('rbac_role_has_permissions', $config->table('role_has_permissions'));
        self::assertSame('rbac_subject_has_roles', $config->table('subject_has_roles'));
    }

    public function testCustomSubjectType(): void
    {
        $config = Config::fromArray(['subject_type' => 'admin_user']);

        self::assertSame('admin_user', $config->subjectType());
    }

    public function testUnknownTableKeyThrows(): void
    {
        $this->expectException(RbacException::class);

        Config::fromArray(['tables' => ['unknown' => 'x']]);
    }

    public function testInvalidTableNameThrows(): void
    {
        $this->expectException(RbacException::class);

        Config::fromArray(['tables' => ['roles' => 'bad-name']]);
    }

    public function testTooLongTableNameThrows(): void
    {
        $this->expectException(RbacException::class);

        Config::fromArray(['tables' => ['roles' => str_repeat('a', 65)]]);
    }

    public function testEmptySubjectTypeThrows(): void
    {
        $this->expectException(RbacException::class);

        Config::fromArray(['subject_type' => '']);
    }

    public function testTooLongSubjectTypeThrows(): void
    {
        $this->expectException(RbacException::class);

        Config::fromArray(['subject_type' => str_repeat('a', 192)]);
    }

    public function testUnknownMenuKeyThrows(): void
    {
        $this->expectException(RbacException::class);

        Config::fromArray(['menu' => ['unknown' => true]]);
    }

    public function testMenuFlagCanBeDisabled(): void
    {
        $config = Config::fromArray(['menu' => ['include_permission_codes' => false]]);

        self::assertFalse($config->includePermissionCodes());
    }

    public function testToArray(): void
    {
        $config = Config::fromArray(['subject_type' => 'admin']);
        $array = $config->toArray();

        self::assertSame('admin', $array['subject_type']);
        self::assertSame('rbac_roles', $array['tables']['roles']);
        self::assertTrue($array['menu']['include_permission_codes']);
    }
}
