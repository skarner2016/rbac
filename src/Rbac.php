<?php

declare(strict_types=1);

namespace Skarner\Rbac;

use PDO;
use Skarner\Rbac\Exceptions\RbacException;
use Skarner\Rbac\Models\Subject;
use Skarner\Rbac\Repositories\AssignmentRepository;
use Skarner\Rbac\Repositories\PermissionRepository;
use Skarner\Rbac\Repositories\RoleRepository;
use Skarner\Rbac\Services\Access;
use Skarner\Rbac\Services\GrantResolver;
use Skarner\Rbac\Services\MenuTree;
use Skarner\Rbac\Services\PermissionManager;
use Skarner\Rbac\Services\RoleManager;
use Skarner\Rbac\Support\Database;
use Skarner\Rbac\Support\PermissionCache;
use Throwable;

/**
 * RBAC 组合根。
 *
 * 用法：
 *   $rbac = new Rbac($pdo, require __DIR__ . '/config/rbac.php');
 *   $rbac->for(7)->can('System:User:Create');
 *   $rbac->for(7)->menus();
 *
 * 角色是唯一授权入口：本类只暴露 RoleManager 的写操作，Access 完全只读。
 */
final class Rbac
{
    private readonly Config $config;

    private readonly Database $db;

    private readonly PermissionCache $cache;

    private readonly RoleRepository $roles;

    private readonly PermissionRepository $permissions;

    private readonly AssignmentRepository $assignments;

    private readonly GrantResolver $resolver;

    private readonly MenuTree $menuTree;

    private readonly RoleManager $roleManager;

    private readonly PermissionManager $permissionManager;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->config = Config::fromArray($config);
        $this->db = new Database($pdo, $this->config);
        $this->db->assertSupportedDriver();
        $this->cache = new PermissionCache();

        $this->roles = new RoleRepository($this->db, $this->config);
        $this->permissions = new PermissionRepository($this->db, $this->config);
        $this->assignments = new AssignmentRepository($this->db, $this->config);

        $this->resolver = new GrantResolver(
            $this->roles,
            $this->permissions,
            $this->assignments,
            $this->cache,
        );
        $this->menuTree = new MenuTree($this->resolver, $this->config);

        $this->roleManager = new RoleManager(
            $this,
            $this->roles,
            $this->permissions,
            $this->assignments,
        );
        $this->permissionManager = new PermissionManager(
            $this,
            $this->permissions,
            $this->assignments,
        );
    }

    public function for(int|string $subjectId, ?string $subjectType = null): Access
    {
        return new Access(
            $this->resolver,
            $this->menuTree,
            new Subject($subjectType ?? $this->config->subjectType(), (string) $subjectId),
        );
    }

    public function roles(): RoleManager
    {
        return $this->roleManager;
    }

    public function permissions(): PermissionManager
    {
        return $this->permissionManager;
    }

    public function clearCache(): void
    {
        $this->cache->clear();
    }

    /**
     * 若当前 PDO 已在事务中则直接参与，否则自行开启并提交/回滚。
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();

        if ($pdo->inTransaction()) {
            return $callback();
        }

        try {
            $began = $pdo->beginTransaction();
        } catch (Throwable $e) {
            throw RbacException::database($e->getMessage(), $e);
        }

        if ($began === false) {
            throw RbacException::database('无法开启事务。');
        }

        try {
            $result = $callback();

            if ($pdo->commit() === false) {
                throw RbacException::database('无法提交事务。');
            }

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public function pdo(): PDO
    {
        return $this->db->pdo();
    }

    public function config(): Config
    {
        return $this->config;
    }
}
