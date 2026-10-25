<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Skarner\Rbac\Enums\PermissionType;
use Skarner\Rbac\Tests\TestCase;

final class RouteAccessTest extends TestCase
{
    #[DataProvider('routePatterns')]
    public function testRouteMatching(
        ?string $permissionMethod,
        string $pattern,
        string $requestMethod,
        string $requestPath,
        bool $expected,
    ): void {
        $menu = $this->createMenu('Api');
        $route = $this->rbac->permissions()->create(PermissionType::Route, 'Api:Route', 'Api:Route', [
            'parent_id' => $menu->id,
            'path' => $pattern,
            'method' => $permissionMethod,
        ]);
        $role = $this->createRole('role');
        $this->rbac->roles()->syncPermissions($role->id, [$route->code]);
        $this->rbac->roles()->assign($role->id, 1);

        self::assertSame($expected, $this->rbac->for(1)->canRoute($requestMethod, $requestPath));
    }

    /**
     * @return array<string, array{0: ?string, 1: string, 2: string, 3: string, 4: bool}>
     */
    public static function routePatterns(): array
    {
        return [
            'exact' => ['GET', '/api/users', 'GET', '/api/users', true],
            'method mismatch' => ['GET', '/api/users', 'POST', '/api/users', false],
            'wildcard method' => ['*', '/api/users', 'DELETE', '/api/users', true],
            'null method' => [null, '/api/users', 'GET', '/api/users', true],
            'empty method' => ['', '/api/users', 'GET', '/api/users', true],
            'lowercase method' => ['get', '/api/users', 'GET', '/api/users', true],
            'star matches nested path' => ['GET', '/api/users/*', 'GET', '/api/users/42/items', true],
            'brace matches one segment' => ['GET', '/api/users/{id}', 'GET', '/api/users/42', true],
            'brace does not match nested' => ['GET', '/api/users/{id}', 'GET', '/api/users/42/items', false],
            'colon matches one segment' => ['GET', '/api/users/:id', 'GET', '/api/users/42', true],
            'query string ignored' => ['GET', '/api/users', 'GET', '/api/users?page=1', true],
            'trailing slash ignored' => ['GET', '/api/users', 'GET', '/api/users/', true],
            'leading slash added' => ['GET', '/api/users', 'GET', 'api/users', true],
            'path is case sensitive' => ['GET', '/api/users', 'GET', '/API/users', false],
            'longer path does not match' => ['GET', '/api/users', 'GET', '/api/users/1', false],
            'fragment ignored' => ['GET', '/api/users', 'GET', '/api/users#section', true],
        ];
    }

    public function testOnlyRouteTypeIsChecked(): void
    {
        $menu = $this->createMenu('Api');
        $button = $this->createButton('Api:Open', $menu->id, [
            'path' => '/api/open',
            'method' => 'GET',
        ]);
        $role = $this->createRole('role');
        $this->rbac->roles()->syncPermissions($role->id, [$button->code]);
        $this->rbac->roles()->assign($role->id, 1);

        $access = $this->rbac->for(1);

        self::assertTrue($access->can('Api:Open'));
        self::assertFalse($access->canRoute('GET', '/api/open'));
    }

    public function testSuperCanRouteAnything(): void
    {
        $role = $this->createRole('super', ['is_super' => 1]);
        $this->rbac->roles()->assign($role->id, 1);

        self::assertTrue($this->rbac->for(1)->canRoute('DELETE', '/anything'));
    }

    public function testUngrantedRouteIsDenied(): void
    {
        $this->createRoute('Api:Users', '/api/users');

        self::assertFalse($this->rbac->for(1)->canRoute('GET', '/api/users'));
    }

    public function testRouteWithoutPathIsIgnored(): void
    {
        $menu = $this->createMenu('Api');
        $route = $this->rbac->permissions()->create(PermissionType::Route, 'Api:NoPath', 'Api:NoPath', [
            'parent_id' => $menu->id,
            'path' => '',
            'method' => 'GET',
        ]);
        $role = $this->createRole('role');
        $this->rbac->roles()->syncPermissions($role->id, [$route->code]);
        $this->rbac->roles()->assign($role->id, 1);

        self::assertFalse($this->rbac->for(1)->canRoute('GET', '/anything'));
    }
}
