<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Skarner\Rbac\Enums\PermissionType;
use Skarner\Rbac\Models\Permission;
use Skarner\Rbac\Models\Role;
use Skarner\Rbac\Rbac;
use Skarner\Rbac\Support\Schema;

/**
 * SQLite in-memory 测试基类。
 */
abstract class TestCase extends BaseTestCase
{
    protected PDO $pdo;

    protected Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::up($this->pdo);
        $this->rbac = new Rbac($this->pdo);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function createMenu(string $code, ?int $parentId = null, array $attributes = []): Permission
    {
        return $this->rbac->permissions()->create(PermissionType::Menu, $code, $code, [
            'parent_id' => $parentId,
            ...$attributes,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function createRoute(
        string $code,
        string $path,
        string $method = 'GET',
        ?int $parentId = null,
        array $attributes = [],
    ): Permission {
        return $this->rbac->permissions()->create(PermissionType::Route, $code, $code, [
            'parent_id' => $parentId,
            'path' => $path,
            'method' => $method,
            ...$attributes,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function createButton(string $code, ?int $parentId = null, array $attributes = []): Permission
    {
        return $this->rbac->permissions()->create(PermissionType::Button, $code, $code, [
            'parent_id' => $parentId,
            ...$attributes,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function createRole(string $code, array $attributes = []): Role
    {
        return $this->rbac->roles()->create($code, $code, $attributes);
    }

    /**
     * @return string[]
     */
    protected function tableNames(): array
    {
        /** @var string[] $names */
        $names = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }
}
