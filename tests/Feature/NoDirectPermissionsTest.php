<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests\Feature;

use Skarner\Rbac\Tests\TestCase;

final class NoDirectPermissionsTest extends TestCase
{
    public function testSchemaHasNoDirectPermissionTables(): void
    {
        $tables = $this->tableNames();

        self::assertNotContains('rbac_subject_has_permissions', $tables);
        self::assertNotContains('rbac_model_has_permissions', $tables);
        self::assertContains('rbac_role_has_permissions', $tables);
        self::assertContains('rbac_subject_has_roles', $tables);
    }

    public function testAccessHasNoWriteMethods(): void
    {
        $access = $this->rbac->for(7);

        foreach (['givePermissionTo', 'assignRole', 'syncPermissions', 'grant', 'revoke'] as $method) {
            self::assertFalse(method_exists($access, $method), $method);
        }

        self::assertFalse(method_exists($this->rbac, 'givePermissionTo'));
        self::assertTrue(method_exists($this->rbac->roles(), 'syncPermissions'));
    }
}
