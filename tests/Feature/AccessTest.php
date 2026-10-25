<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests\Feature;

use Skarner\Rbac\Exceptions\AccessDeniedException;
use Skarner\Rbac\Models\Permission;
use Skarner\Rbac\Services\Access;
use Skarner\Rbac\Tests\TestCase;

final class AccessTest extends TestCase
{
    /** @var array<string, Permission> */
    private array $tree;

    protected function setUp(): void
    {
        parent::setUp();

        $system = $this->createMenu('System', null, ['sort' => 10]);
        $user = $this->createMenu('System:User', $system->id, ['sort' => 10]);
        $list = $this->createRoute('System:User:List', '/api/users', 'GET', $user->id);
        $create = $this->createButton('System:User:Create', $user->id);
        $order = $this->createMenu('Order', null, ['sort' => 20]);
        $export = $this->createButton('Order:Export', $order->id);

        $this->tree = compact('system', 'user', 'list', 'create', 'order', 'export');
    }

    public function testCodesAndCaseInsensitiveChecks(): void
    {
        $access = $this->grantedAccess();

        self::assertSame(
            [$this->tree['create']->code, $this->tree['export']->code],
            $access->codes(),
        );
        self::assertTrue($access->can('system:user:create'));
        self::assertTrue($access->can('SYSTEM:USER:CREATE'));
        self::assertTrue($access->can('order:export'));
        self::assertFalse($access->can('System:User:List'));
    }

    public function testCanAnyAndCanAll(): void
    {
        $access = $this->grantedAccess();

        self::assertTrue($access->canAny(['Nope', 'System:User:Create']));
        self::assertFalse($access->canAny(['Nope']));
        self::assertFalse($access->canAny([]));
        self::assertTrue($access->canAll(['System:User:Create', 'Order:Export']));
        self::assertFalse($access->canAll(['System:User:Create', 'Nope']));
        self::assertTrue($access->canAll([]));
    }

    public function testRoleChecks(): void
    {
        $access = $this->grantedAccess();

        self::assertSame(['auditor'], $access->roleCodes());
        self::assertTrue($access->hasRole('AUDITOR'));
        self::assertTrue($access->hasAnyRole(['x', 'auditor']));
        self::assertFalse($access->hasAnyRole(['x']));
        self::assertTrue($access->hasAllRoles(['auditor']));
        self::assertFalse($access->hasAllRoles(['auditor', 'x']));
        self::assertFalse($access->hasAnyRole([]));
        self::assertTrue($access->hasAllRoles([]));
    }

    public function testPermissionList(): void
    {
        $access = $this->grantedAccess();
        $permissions = $access->permissions();

        self::assertCount(2, $permissions);
        self::assertSame($this->tree['create']->id, $permissions[0]->id);
        self::assertSame($this->tree['export']->id, $permissions[1]->id);
    }

    public function testRouteCodes(): void
    {
        $role = $this->createRole('api');
        $this->rbac->roles()->syncPermissions($role->id, [$this->tree['list']->code]);
        $this->rbac->roles()->assign($role->id, 8);

        self::assertSame([$this->tree['list']->code], $this->rbac->for(8)->routeCodes());
    }

    public function testDisabledRoleGrantsNothing(): void
    {
        $role = $this->createRole('disabled', ['status' => 0]);
        $this->rbac->roles()->syncPermissions($role->id, [$this->tree['list']->code]);
        $this->rbac->roles()->assign($role->id, 8);
        $access = $this->rbac->for(8);

        self::assertSame([], $access->codes());
        self::assertFalse($access->can($this->tree['list']->code));
        self::assertSame([], $access->menus());
    }

    public function testDisabledPermissionGrantsNothing(): void
    {
        $permission = $this->createButton('System:User:Delete', $this->tree['user']->id, ['status' => 0]);
        $role = $this->createRole('role');
        $this->rbac->roles()->syncPermissions($role->id, [$permission->code]);
        $this->rbac->roles()->assign($role->id, 9);
        $access = $this->rbac->for(9);

        self::assertSame([], $access->codes());
        self::assertFalse($access->can($permission->code));
        self::assertFalse($access->isSuper());
    }

    public function testSuperRoleBypassesPermissionChecks(): void
    {
        $role = $this->createRole('super', ['is_super' => 1]);
        $this->rbac->roles()->assign($role->id, 10);
        $access = $this->rbac->for(10);

        self::assertTrue($access->isSuper());
        self::assertTrue($access->can('anything'));
        self::assertTrue($access->canAny(['anything']));
        self::assertTrue($access->canAll(['a', 'b']));
        self::assertTrue($access->canRoute('DELETE', '/anything'));
        self::assertContains($this->tree['create']->code, $access->codes());
        self::assertCount(2, $access->menus());
    }

    public function testAuthorizeThrowsAndCarriesContext(): void
    {
        $access = $this->grantedAccess();

        try {
            $access->authorize('Nope', 'Other');
            self::fail('Expected AccessDeniedException');
        } catch (AccessDeniedException $e) {
            self::assertSame('user', $e->subjectType);
            self::assertSame('7', $e->subjectId);
            self::assertSame(['Nope', 'Other'], $e->codes);
        }
    }

    public function testAuthorizePasses(): void
    {
        $access = $this->grantedAccess();

        $access->authorize('system:user:create');
        $access->authorizeAny('Nope', 'System:User:Create');

        self::assertTrue($access->can('System:User:Create'));
    }

    public function testAuthorizeAnyThrows(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->grantedAccess()->authorizeAny('Nope');
    }

    public function testAccessHasNoWriteMethods(): void
    {
        $access = $this->rbac->for(7);

        foreach (['givePermissionTo', 'syncPermissions', 'assignRole', 'assign', 'grant'] as $method) {
            self::assertFalse(method_exists($access, $method), $method);
        }

        self::assertTrue(method_exists($this->rbac->roles(), 'syncPermissions'));
    }

    public function testSameAccessSeesManagerWrites(): void
    {
        $role = $this->createRole('role');
        $this->rbac->roles()->assign($role->id, 7);
        $access = $this->rbac->for(7);

        self::assertFalse($access->can($this->tree['list']->code));

        $this->rbac->roles()->grant($role->id, [$this->tree['list']->code]);
        self::assertTrue($access->can($this->tree['list']->code));

        $this->rbac->roles()->revoke($role->id, [$this->tree['list']->code]);
        self::assertFalse($access->can($this->tree['list']->code));
    }

    public function testCustomSubjectType(): void
    {
        $role = $this->createRole('role');
        $this->rbac->roles()->syncPermissions($role->id, [$this->tree['list']->code]);
        $this->rbac->roles()->assign($role->id, 7, 'admin_user');

        self::assertFalse($this->rbac->for(7)->can($this->tree['list']->code));
        self::assertTrue($this->rbac->for(7, 'admin_user')->can($this->tree['list']->code));
    }

    public function testUnknownSubjectHasNoGrants(): void
    {
        $access = $this->rbac->for(999);

        self::assertSame([], $access->codes());
        self::assertSame([], $access->permissions());
        self::assertSame([], $access->menus());
        self::assertFalse($access->isSuper());
        self::assertFalse($access->hasRole('anything'));
    }

    private function grantedAccess(): Access
    {
        $role = $this->createRole('auditor');
        $this->rbac->roles()->syncPermissions($role->id, [
            $this->tree['create']->code,
            $this->tree['export']->code,
        ]);
        $this->rbac->roles()->assign($role->id, 7);

        return $this->rbac->for(7);
    }
}
