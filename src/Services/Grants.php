<?php

declare(strict_types=1);

namespace Skarner\Rbac\Services;

use Skarner\Rbac\Models\Permission;
use Skarner\Rbac\Models\Role;

/**
 * 主体有效授权的只读值对象。
 *
 * 权限集合由 GrantResolver 解析并已按 sort, id 排序；本类只做只读查询。
 * 输出保持数据库中的原始大小写，查找统一用 strtolower 归一化为键。
 */
final class Grants
{
    /**
     * @param Role[] $roles 启用的角色，按 sort, id 排序
     * @param array<int, Permission> $permissions keyed by permission id
     */
    public function __construct(
        public array $roles,
        public array $permissions,
        public bool $isSuper,
    ) {}

    /**
     * 已授予的权限码，保持数据库原始大小写与稳定顺序。
     *
     * @return string[]
     */
    public function codes(): array
    {
        $codes = [];

        foreach ($this->permissions as $permission) {
            $codes[] = $permission->code;
        }

        return $codes;
    }

    /**
     * 仅 route 类型的已授予权限码。
     *
     * @return string[]
     */
    public function routeCodes(): array
    {
        $codes = [];

        foreach ($this->permissions as $permission) {
            if ($permission->isRoute()) {
                $codes[] = $permission->code;
            }
        }

        return $codes;
    }

    /**
     * 大小写不敏感地判断是否已授予某权限码。
     */
    public function hasCode(string $code): bool
    {
        $needle = strtolower($code);

        foreach ($this->permissions as $permission) {
            if ($permission->codeKey() === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * 启用角色码，保持数据库原始大小写。
     *
     * @return string[]
     */
    public function roleCodes(): array
    {
        $codes = [];

        foreach ($this->roles as $role) {
            $codes[] = $role->code;
        }

        return $codes;
    }

    /**
     * 大小写不敏感地判断主体是否拥有某启用角色。
     */
    public function hasRole(string $code): bool
    {
        $needle = strtolower($code);

        foreach ($this->roles as $role) {
            if ($role->codeKey() === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * 已授予的权限对象列表，顺序与 codes() 一致。
     *
     * @return Permission[]
     */
    public function permissionList(): array
    {
        return array_values($this->permissions);
    }
}
