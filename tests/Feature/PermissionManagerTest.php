<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests\Feature;

use Skarner\Rbac\Enums\PermissionType;
use Skarner\Rbac\Exceptions\RbacException;
use Skarner\Rbac\Tests\TestCase;

final class PermissionManagerTest extends TestCase
{
    public function testCreatesEachType(): void
    {
        $menu = $this->createMenu('System', null, ['path' => '/system', 'icon' => 'settings', 'sort' => 10]);
        self::assertSame(PermissionType::Menu, $menu->type);
        self::assertSame('/system', $menu->path);
        self::assertSame('settings', $menu->icon);
        self::assertSame(10, $menu->sort);
        self::assertTrue($menu->isEnabled());

        $route = $this->createRoute('System:User:List', '/api/users', 'get', $menu->id);
        self::assertSame(PermissionType::Route, $route->type);
        self::assertSame('GET', $route->method);
        self::assertSame('/api/users', $route->path);

        $button = $this->createButton('System:User:Create', $menu->id);
        self::assertSame(PermissionType::Button, $button->type);
        self::assertNull($button->method);
    }

    public function testMetaRoundTrip(): void
    {
        $menu = $this->createMenu('Meta', null, [
            'meta' => ['title' => 'meta.title', 'order' => 5],
        ]);

        self::assertSame(['title' => 'meta.title', 'order' => 5], $menu->meta);
    }

    public function testParentIdZeroBecomesNull(): void
    {
        $menu = $this->rbac->permissions()->create(PermissionType::Menu, 'Root', 'Root', ['parent_id' => 0]);

        self::assertNull($menu->parentId);
    }

    public function testFindByCodeIsCaseInsensitive(): void
    {
        $permission = $this->createButton('System:User:Create');

        self::assertNotNull($this->rbac->permissions()->findByCode('system:user:create'));
        self::assertSame($permission->id, $this->rbac->permissions()->findByCode('SYSTEM:USER:CREATE')?->id);
    }

    public function testDuplicateCodeIsCaseInsensitive(): void
    {
        $this->createButton('System:User:Create');

        $this->expectException(RbacException::class);

        $this->createButton('system:user:create');
    }

    public function testRouteParentMustBeMenu(): void
    {
        $button = $this->createButton('Button');

        $this->expectException(RbacException::class);

        $this->createRoute('Route', '/api', 'GET', $button->id);
    }

    public function testMenuParentMustBeMenu(): void
    {
        $route = $this->createRoute('Route', '/api');

        $this->expectException(RbacException::class);

        $this->createMenu('Menu', $route->id);
    }

    public function testMissingParentThrows(): void
    {
        $this->expectException(RbacException::class);

        $this->createMenu('Menu', 9999);
    }

    public function testUpdateRejectsSelfParent(): void
    {
        $menu = $this->createMenu('Menu');

        $this->expectException(RbacException::class);

        $this->rbac->permissions()->update($menu->id, ['parent_id' => $menu->id]);
    }

    public function testUpdateRejectsCycle(): void
    {
        $parent = $this->createMenu('Parent');
        $child = $this->createMenu('Child', $parent->id);

        $this->expectException(RbacException::class);

        $this->rbac->permissions()->update($parent->id, ['parent_id' => $child->id]);
    }

    public function testUpdateMovesSubtree(): void
    {
        $first = $this->createMenu('First');
        $child = $this->createMenu('Child', $first->id);
        $second = $this->createMenu('Second');

        $updated = $this->rbac->permissions()->update($child->id, ['parent_id' => $second->id]);

        self::assertSame($second->id, $updated->parentId);
        self::assertSame([], $this->rbac->permissions()->children($first->id));
        self::assertCount(1, $this->rbac->permissions()->children($second->id));
    }

    public function testUpdateParentToRoot(): void
    {
        $parent = $this->createMenu('Parent');
        $child = $this->createMenu('Child', $parent->id);

        $updated = $this->rbac->permissions()->update($child->id, ['parent_id' => 0]);

        self::assertNull($updated->parentId);
    }

    public function testCannotChangeTypeWithChildren(): void
    {
        $menu = $this->createMenu('Menu');
        $this->createButton('Child', $menu->id);

        $this->expectException(RbacException::class);

        $this->rbac->permissions()->update($menu->id, ['type' => PermissionType::Route]);
    }

    public function testCannotDeleteWithChildren(): void
    {
        $menu = $this->createMenu('Menu');
        $this->createButton('Child', $menu->id);

        $this->expectException(RbacException::class);

        $this->rbac->permissions()->delete($menu->id);
    }

    public function testDeleteLeafDetachesRolePermission(): void
    {
        $permission = $this->createButton('Do:Sth');
        $role = $this->createRole('Role');
        $this->rbac->roles()->syncPermissions($role->id, [$permission->code]);

        self::assertSame([$permission->code], $this->rbac->roles()->permissionCodes($role->id));

        $this->rbac->permissions()->delete($permission->id);

        self::assertSame([], $this->rbac->roles()->permissionCodes($role->id));
    }

    public function testChildrenAndFilters(): void
    {
        $parent = $this->createMenu('Parent');
        $this->createButton('Child:A', $parent->id);
        $this->createButton('Child:B', $parent->id, ['status' => 0]);

        $permissions = $this->rbac->permissions();

        self::assertCount(2, $permissions->children($parent->id));
        self::assertCount(1, $permissions->children($parent->id, true));
        self::assertCount(2, $permissions->all(PermissionType::Button));
        self::assertCount(1, $permissions->all(PermissionType::Button, true));
    }

    public function testInvalidMetaThrows(): void
    {
        $this->expectException(RbacException::class);

        $this->rbac->permissions()->create(PermissionType::Menu, 'Bad', 'Bad', ['meta' => 'string']);
    }

    public function testTooLongMethodThrows(): void
    {
        $this->expectException(RbacException::class);

        $this->createRoute('Route', '/api', 'GET', null, ['method' => str_repeat('A', 17)]);
    }

    public function testTooLongCodeThrows(): void
    {
        $this->expectException(RbacException::class);

        $this->rbac->permissions()->create(PermissionType::Button, str_repeat('a', 192), 'Name');
    }

    public function testTooLongNameThrows(): void
    {
        $this->expectException(RbacException::class);

        $this->rbac->permissions()->create(PermissionType::Button, 'Code', str_repeat('a', 129));
    }

    public function testUpdateCodeDuplicateIsCaseInsensitive(): void
    {
        $first = $this->createButton('First');
        $second = $this->createButton('Second');

        $this->expectException(RbacException::class);

        $this->rbac->permissions()->update($second->id, ['code' => strtolower($first->code)]);
    }

    public function testUpdateMissingPermissionThrows(): void
    {
        $this->expectException(RbacException::class);

        $this->rbac->permissions()->update(9999, ['name' => 'x']);
    }

    public function testDeleteMissingPermissionThrows(): void
    {
        $this->expectException(RbacException::class);

        $this->rbac->permissions()->delete(9999);
    }
}
