<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests\Feature;

use Skarner\Rbac\Models\Permission;
use Skarner\Rbac\Rbac;
use Skarner\Rbac\Tests\TestCase;

final class MenuTreeTest extends TestCase
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

    public function testAncestorCompletionAndAggregation(): void
    {
        $this->grantToSubject([$this->tree['create']->code], 7);
        $menus = $this->rbac->for(7)->menus();

        self::assertCount(1, $menus);
        $system = $menus[0];
        self::assertSame('System', $system['code']);
        self::assertSame(['System:User:Create'], $system['permissions']);
        self::assertCount(1, $system['children']);

        $user = $system['children'][0];
        self::assertSame('System:User', $user['code']);
        self::assertSame(['System:User:Create'], $user['permissions']);
        self::assertSame([], $user['children']);
    }

    public function testMenuOnlyNodesAndShape(): void
    {
        $this->grantToSubject([$this->tree['create']->code], 7);
        $menu = $this->rbac->for(7)->menus()[0];

        self::assertIsString($menu['id']);
        self::assertNull($menu['parentId']);
        self::assertSame('menu', $menu['type']);
        self::assertIsArray($menu['meta']);
        self::assertIsArray($menu['children']);

        $child = $menu['children'][0];
        self::assertSame($menu['id'], $child['parentId']);
        self::assertSame('menu', $child['type']);
    }

    public function testOrderMenuAppearsFromGrantedButton(): void
    {
        $this->grantToSubject([$this->tree['export']->code], 7);
        $menus = $this->rbac->for(7)->menus();

        self::assertCount(1, $menus);
        self::assertSame('Order', $menus[0]['code']);
        self::assertSame(['Order:Export'], $menus[0]['permissions']);
        self::assertSame([], $menus[0]['children']);
    }

    public function testSortsRootsAndChildren(): void
    {
        $first = $this->createMenu('First', null, ['sort' => 1]);
        $firstButton = $this->createButton('First:Open', $first->id);
        $this->grantToSubject(
            [$this->tree['create']->code, $this->tree['export']->code, $firstButton->code],
            7,
        );

        $menus = $this->rbac->for(7)->menus();

        self::assertSame(['First', 'System', 'Order'], array_column($menus, 'code'));
    }

    public function testHiddenAndMetaArePreserved(): void
    {
        $hidden = $this->createMenu('Hidden', null, [
            'hidden' => true,
            'meta' => ['title' => 'hidden.title'],
        ]);
        $button = $this->createButton('Hidden:Open', $hidden->id);
        $this->grantToSubject([$button->code], 7);

        $menus = $this->rbac->for(7)->menus();

        self::assertCount(1, $menus);
        self::assertTrue($menus[0]['hidden']);
        self::assertSame(['title' => 'hidden.title'], $menus[0]['meta']);
    }

    public function testDisabledParentHidesSubtree(): void
    {
        $this->grantToSubject([$this->tree['export']->code], 7);
        $this->rbac->permissions()->update($this->tree['order']->id, ['status' => 0]);

        self::assertSame([], $this->rbac->for(7)->menus());
    }

    public function testGrantedMenuWithoutChildren(): void
    {
        $this->grantToSubject([$this->tree['order']->code], 7);
        $menus = $this->rbac->for(7)->menus();

        self::assertCount(1, $menus);
        self::assertSame('Order', $menus[0]['code']);
        self::assertSame([], $menus[0]['permissions']);
    }

    public function testGrantedSubmenuPullsParentButNotSiblings(): void
    {
        $sibling = $this->createMenu('System:Role', $this->tree['system']->id);
        $this->grantToSubject([$this->tree['user']->code], 7);

        $menus = $this->rbac->for(7)->menus();

        self::assertCount(1, $menus);
        self::assertSame('System', $menus[0]['code']);
        self::assertCount(1, $menus[0]['children']);
        self::assertSame('System:User', $menus[0]['children'][0]['code']);
        self::assertNotContains($sibling->code, array_column($menus[0]['children'], 'code'));
    }

    public function testSuperSeesAllEnabledMenus(): void
    {
        $role = $this->createRole('super', ['is_super' => 1]);
        $this->rbac->roles()->assign($role->id, 10);

        $menus = $this->rbac->for(10)->menus();

        self::assertSame(['System', 'Order'], array_column($menus, 'code'));
        self::assertSame(['System:User'], array_column($menus[0]['children'], 'code'));
    }

    public function testIncludePermissionCodesCanBeDisabled(): void
    {
        $rbac = new Rbac($this->pdo, ['menu' => ['include_permission_codes' => false]]);
        $role = $rbac->roles()->create('role', 'role');
        $rbac->roles()->syncPermissions($role->id, [$this->tree['create']->code]);
        $rbac->roles()->assign($role->id, 7);

        $menus = $rbac->for(7)->menus();

        self::assertSame([], $menus[0]['permissions']);
    }

    public function testDisabledGrantedMenuIsNotVisible(): void
    {
        $disabled = $this->createMenu('Disabled', null, ['status' => 0]);
        $button = $this->createButton('Disabled:Open', $disabled->id);
        $this->grantToSubject([$button->code], 7);

        self::assertSame([], $this->rbac->for(7)->menus());
    }

    public function testNothingGrantedReturnsEmptyTree(): void
    {
        self::assertSame([], $this->rbac->for(1)->menus());
    }

    /**
     * @param string[] $codes
     */
    private function grantToSubject(array $codes, int $subjectId): void
    {
        $role = $this->createRole('role');
        $this->rbac->roles()->syncPermissions($role->id, $codes);
        $this->rbac->roles()->assign($role->id, $subjectId);
    }
}
