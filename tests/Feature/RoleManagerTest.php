<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests\Feature;

use Skarner\Rbac\Exceptions\RbacException;
use Skarner\Rbac\Models\Permission;
use Skarner\Rbac\Tests\TestCase;
use stdClass;

final class RoleManagerTest extends TestCase
{
    public function testCreateFindAndList(): void
    {
        $role = $this->createRole('Auditor', ['description' => 'Auditor role', 'sort' => 5]);

        self::assertSame('Auditor', $role->code);
        self::assertSame('Auditor role', $role->description);
        self::assertSame(5, $role->sort);
        self::assertTrue($role->isEnabled());
        self::assertSame($role->id, $this->rbac->roles()->find($role->id)?->id);
        self::assertSame($role->id, $this->rbac->roles()->findByCode('auditor')?->id);
        self::assertCount(1, $this->rbac->roles()->all());
    }

    public function testDuplicateCodeIsCaseInsensitive(): void
    {
        $this->createRole('Auditor');

        $this->expectException(RbacException::class);

        $this->createRole('auditor');
    }

    public function testUpdateRole(): void
    {
        $role = $this->createRole('Role');

        $updated = $this->rbac->roles()->update($role->id, [
            'name' => 'Renamed',
            'status' => 0,
            'is_super' => 1,
            'sort' => 9,
        ]);

        self::assertSame('Renamed', $updated->name);
        self::assertSame(0, $updated->status);
        self::assertTrue($updated->isSuper);
        self::assertSame(9, $updated->sort);
    }

    public function testUpdateCodeDuplicateIsCaseInsensitive(): void
    {
        $first = $this->createRole('First');
        $second = $this->createRole('Second');

        $this->expectException(RbacException::class);

        $this->rbac->roles()->update($second->id, ['code' => strtolower($first->code)]);
    }

    public function testGrantRevokeAndSync(): void
    {
        $first = $this->createButton('First', null, ['sort' => 1]);
        $second = $this->createButton('Second', null, ['sort' => 2]);
        $third = $this->createButton('Third', null, ['sort' => 3]);
        $role = $this->createRole('Role');
        $roles = $this->rbac->roles();

        $roles->grant($role->id, [$first->code, $second->id, $third]);
        self::assertEqualsCanonicalizing(
            [$first->code, $second->code, $third->code],
            $roles->permissionCodes($role->id),
        );

        $roles->revoke($role->id, [$first->code]);
        self::assertEqualsCanonicalizing(
            [$second->code, $third->code],
            $roles->permissionCodes($role->id),
        );

        $roles->syncPermissions($role->id, [$third->id]);
        self::assertSame([$third->code], $roles->permissionCodes($role->id));

        $roles->syncPermissions($role->id, []);
        self::assertSame([], $roles->permissionCodes($role->id));
    }

    public function testGrantByNumericStringId(): void
    {
        $permission = $this->createButton('Permission');
        $role = $this->createRole('Role');

        $this->rbac->roles()->grant($role->id, [(string) $permission->id]);

        self::assertSame([$permission->code], $this->rbac->roles()->permissionCodes($role->id));
    }

    public function testUnknownPermissionCodeThrows(): void
    {
        $role = $this->createRole('Role');

        $this->expectException(RbacException::class);

        $this->rbac->roles()->grant($role->id, ['Missing']);
    }

    public function testMissingPermissionIdThrows(): void
    {
        $role = $this->createRole('Role');

        $this->expectException(RbacException::class);

        $this->rbac->roles()->grant($role->id, [9999]);
    }

    public function testInvalidPermissionReferenceThrows(): void
    {
        $role = $this->createRole('Role');

        $this->expectException(RbacException::class);

        $this->rbac->roles()->grant($role->id, [new stdClass()]);
    }

    public function testPermissionsReturnsDtos(): void
    {
        $first = $this->createButton('First');
        $second = $this->createButton('Second');
        $role = $this->createRole('Role');

        $this->rbac->roles()->syncPermissions($role->id, [$first->id, $second->id]);
        $permissions = $this->rbac->roles()->permissions($role->id);

        self::assertCount(2, $permissions);

        foreach ($permissions as $permission) {
            self::assertInstanceOf(Permission::class, $permission);
        }
    }

    public function testAssignUnassignAndSubjectRoles(): void
    {
        $role = $this->createRole('Role');
        $roles = $this->rbac->roles();

        $roles->assign($role->id, 7);
        self::assertSame(1, $roles->subjectCount($role->id));
        self::assertCount(1, $roles->subjectRoles(7));

        $roles->unassign($role->id, 7);
        self::assertSame(0, $roles->subjectCount($role->id));
        self::assertCount(0, $roles->subjectRoles(7));
    }

    public function testCustomSubjectType(): void
    {
        $role = $this->createRole('Role');
        $roles = $this->rbac->roles();

        $roles->assign($role->id, 7, 'admin_user');

        self::assertCount(0, $roles->subjectRoles(7));
        self::assertCount(1, $roles->subjectRoles(7, 'admin_user'));
    }

    public function testSyncSubjectRoles(): void
    {
        $first = $this->createRole('First');
        $second = $this->createRole('Second');
        $roles = $this->rbac->roles();

        $roles->syncSubjectRoles(7, [$first->id, $second->id]);
        self::assertCount(2, $roles->subjectRoles(7));

        $roles->syncSubjectRoles(7, [$second->id]);
        self::assertCount(1, $roles->subjectRoles(7));
        self::assertSame($second->id, $roles->subjectRoles(7)[0]->id);
    }

    public function testSyncSubjectRolesRejectsMissingRole(): void
    {
        $this->expectException(RbacException::class);

        $this->rbac->roles()->syncSubjectRoles(7, [9999]);
    }

    public function testDeleteRoleCleansPivots(): void
    {
        $permission = $this->createButton('Permission');
        $role = $this->createRole('Role');
        $roles = $this->rbac->roles();
        $roles->syncPermissions($role->id, [$permission->code]);
        $roles->assign($role->id, 7);

        $roles->delete($role->id);

        self::assertSame([], $this->rbac->for(7)->codes());
        self::assertSame(
            0,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM rbac_role_has_permissions WHERE role_id = ' . $role->id,
            )->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM rbac_subject_has_roles WHERE role_id = ' . $role->id,
            )->fetchColumn(),
        );
    }

    public function testDeleteMissingRoleThrows(): void
    {
        $this->expectException(RbacException::class);

        $this->rbac->roles()->delete(9999);
    }

    public function testUpdateMissingRoleThrows(): void
    {
        $this->expectException(RbacException::class);

        $this->rbac->roles()->update(9999, ['name' => 'x']);
    }

    public function testFindMissingReturnsNull(): void
    {
        self::assertNull($this->rbac->roles()->find(9999));
        self::assertNull($this->rbac->roles()->findByCode('missing'));
    }

    public function testAllCanFilterDisabledRoles(): void
    {
        $this->createRole('Enabled');
        $this->createRole('Disabled', ['status' => 0]);

        self::assertCount(2, $this->rbac->roles()->all());
        self::assertCount(1, $this->rbac->roles()->all(true));
    }

    public function testTooLongRoleCodeThrows(): void
    {
        $this->expectException(RbacException::class);

        $this->createRole(str_repeat('a', 65));
    }

    public function testTooLongRoleNameThrows(): void
    {
        $this->expectException(RbacException::class);

        $this->rbac->roles()->create('code', str_repeat('a', 129));
    }
}
