<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use Skarner\Rbac\Enums\PermissionType;
use Skarner\Rbac\Rbac;
use Skarner\Rbac\Support\Schema;

final class CustomTablesTest extends TestCase
{
    public function testCustomTablesAndSubjectTypeEndToEnd(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $config = [
            'tables' => [
                'roles' => 'custom_roles',
                'permissions' => 'custom_permissions',
                'role_has_permissions' => 'custom_role_has_permissions',
                'subject_has_roles' => 'custom_subject_has_roles',
            ],
            'subject_type' => 'admin_user',
        ];

        Schema::up($pdo, $config);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('custom_roles', $tables);
        self::assertContains('custom_permissions', $tables);
        self::assertNotContains('rbac_roles', $tables);

        $rbac = new Rbac($pdo, $config);
        $menu = $rbac->permissions()->create(PermissionType::Menu, 'Menu', 'Menu');
        $button = $rbac->permissions()->create(PermissionType::Button, 'Menu:Open', 'Open', [
            'parent_id' => $menu->id,
        ]);
        $role = $rbac->roles()->create('role', 'Role');
        $rbac->roles()->syncPermissions($role->id, [$button->code]);
        $rbac->roles()->assign($role->id, 7);

        self::assertTrue($rbac->for(7)->can('menu:open'));
        self::assertFalse($rbac->for(7, 'user')->can('menu:open'));

        Schema::down($pdo, $config);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertNotContains('custom_roles', $tables);
    }
}
