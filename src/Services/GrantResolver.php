<?php

declare(strict_types=1);

namespace Skarner\Rbac\Services;

use Skarner\Rbac\Models\Permission;
use Skarner\Rbac\Models\Subject;
use Skarner\Rbac\Repositories\AssignmentRepository;
use Skarner\Rbac\Repositories\PermissionRepository;
use Skarner\Rbac\Repositories\RoleRepository;
use Skarner\Rbac\Support\PermissionCache;

/**
 * 有效授权解析器。
 *
 * 合并主体启用角色的权限，并在实例内缓存全量权限、角色→权限映射与主体 Grants。
 * 写操作后由 Rbac::clearCache() 统一失效。
 */
final class GrantResolver
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly PermissionRepository $permissions,
        private readonly AssignmentRepository $assignments,
        private readonly PermissionCache $cache,
    ) {}

    /**
     * 解析主体的有效授权。
     *
     * 超级角色只授予全部启用权限，不为菜单树额外开分支。
     */
    public function resolve(Subject $subject): Grants
    {
        /** @var Grants $grants */
        $grants = $this->cache->remember(
            'grants:' . $subject->key(),
            function () use ($subject): Grants {
                $roles = $this->roles->forSubject($subject, true);

                $isSuper = false;
                $roleIds = [];

                foreach ($roles as $role) {
                    $roleIds[$role->id] = $role->id;

                    if ($role->isSuper) {
                        $isSuper = true;
                    }
                }

                $granted = [];

                if (! $isSuper) {
                    $map = $this->rolePermissionMap();

                    foreach ($roleIds as $roleId) {
                        foreach ($map[$roleId] ?? [] as $permissionId) {
                            $granted[$permissionId] = true;
                        }
                    }
                }

                $effective = [];

                foreach ($this->allPermissions() as $id => $permission) {
                    if (! $permission->isEnabled()) {
                        continue;
                    }

                    if ($isSuper || isset($granted[$id])) {
                        $effective[$id] = $permission;
                    }
                }

                return new Grants($roles, $effective, $isSuper);
            },
        );

        return $grants;
    }

    /**
     * 全量权限（含停用），按仓库的 sort, id 顺序以 id 为键。
     *
     * @return array<int, Permission> keyed by id, includes disabled rows
     */
    public function allPermissions(): array
    {
        /** @var array<int, Permission> $permissions */
        $permissions = $this->cache->remember(
            'permissions',
            function (): array {
                $map = [];

                foreach ($this->permissions->all() as $permission) {
                    $map[$permission->id] = $permission;
                }

                return $map;
            },
        );

        return $permissions;
    }

    /**
     * 角色→权限 ID 映射。
     *
     * @return array<int, int[]> role_id => permission_id[]
     */
    public function rolePermissionMap(): array
    {
        /** @var array<int, int[]> $map */
        $map = $this->cache->remember(
            'role_permissions',
            fn (): array => $this->assignments->rolePermissionMap(),
        );

        return $map;
    }
}
