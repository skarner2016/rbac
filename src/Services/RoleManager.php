<?php

declare(strict_types=1);

namespace Skarner\Rbac\Services;

use Skarner\Rbac\Exceptions\RbacException;
use Skarner\Rbac\Models\Permission;
use Skarner\Rbac\Models\Role;
use Skarner\Rbac\Models\Subject;
use Skarner\Rbac\Rbac;
use Skarner\Rbac\Repositories\AssignmentRepository;
use Skarner\Rbac\Repositories\PermissionRepository;
use Skarner\Rbac\Repositories\RoleRepository;

/**
 * 角色管理：角色 CRUD、权限授予/解除、主体分配。
 *
 * 角色是唯一授权入口。所有写操作都包在 Rbac::transaction() 中，
 * 成功提交后清空实例缓存；回调抛异常时事务回滚且不清缓存。
 */
final class RoleManager
{
    public function __construct(
        private readonly Rbac $rbac,
        private readonly RoleRepository $roles,
        private readonly PermissionRepository $permissions,
        private readonly AssignmentRepository $assignments,
    ) {}

    /**
     * 创建角色。角色码与名称会去除首尾空白，但保留调用方传入的原始大小写。
     *
     * @param array<string, mixed> $options 支持 description、status、is_super、sort
     * @return Role
     */
    public function create(string $code, string $name, array $options = []): Role
    {
        $code = $this->normalizeCode($code, 64);
        $name = $this->normalizeName($name);

        if ($this->roles->findByCode($code) !== null) {
            throw RbacException::duplicateRoleCode($code);
        }

        $attributes = [
            'code' => $code,
            'name' => $name,
            'description' => $this->normalizeDescription($options['description'] ?? null),
            'status' => $this->normalizeFlag($options['status'] ?? 1),
            'is_super' => $this->normalizeFlag($options['is_super'] ?? 0),
            'sort' => (int) ($options['sort'] ?? 0),
        ];

        return $this->write(fn (): Role => $this->roles->create($attributes));
    }

    /**
     * 更新角色。只处理白名单字段，未提供的字段保持不变。
     *
     * @param array<string, mixed> $attributes 支持 code、name、description、status、is_super、sort
     * @return Role
     */
    public function update(int $id, array $attributes): Role
    {
        $this->assertRoleExists($id);

        $normalized = [];

        if (array_key_exists('code', $attributes)) {
            $code = $this->normalizeCode($attributes['code'], 64);
            $existing = $this->roles->findByCode($code);

            if ($existing !== null && $existing->id !== $id) {
                throw RbacException::duplicateRoleCode($code);
            }

            $normalized['code'] = $code;
        }

        if (array_key_exists('name', $attributes)) {
            $normalized['name'] = $this->normalizeName($attributes['name']);
        }

        if (array_key_exists('description', $attributes)) {
            $normalized['description'] = $this->normalizeDescription($attributes['description']);
        }

        if (array_key_exists('status', $attributes)) {
            $normalized['status'] = $this->normalizeFlag($attributes['status']);
        }

        if (array_key_exists('is_super', $attributes)) {
            $normalized['is_super'] = $this->normalizeFlag($attributes['is_super']);
        }

        if (array_key_exists('sort', $attributes)) {
            $normalized['sort'] = (int) $attributes['sort'];
        }

        return $this->write(fn (): Role => $this->roles->update($id, $normalized));
    }

    /**
     * 删除角色，并在同一事务内清理角色-权限与主体-角色两张关联表。
     */
    public function delete(int $id): void
    {
        $this->assertRoleExists($id);

        $this->write(function () use ($id): void {
            $this->assignments->deleteByRoles([$id]);
            $this->assignments->deleteSubjectRolesByRole($id);
            $this->roles->delete($id);
        });
    }

    public function find(int $id): ?Role
    {
        return $this->roles->find($id);
    }

    /**
     * 按角色码查找；大小写不敏感由数据库列排序规则保证。
     */
    public function findByCode(string $code): ?Role
    {
        return $this->roles->findByCode($code);
    }

    /**
     * @return Role[]
     */
    public function all(bool $onlyEnabled = false): array
    {
        return $this->roles->all($onlyEnabled);
    }

    /**
     * 授予权限（已存在的关联会被跳过）。
     *
     * @param array<int|string, Permission|int|string> $permissions
     */
    public function grant(int $roleId, array $permissions): void
    {
        $this->assertRoleExists($roleId);
        $permissionIds = $this->normalizePermissionIds($permissions);

        $this->write(function () use ($roleId, $permissionIds): void {
            foreach ($permissionIds as $permissionId) {
                $this->assignments->attachPermission($roleId, $permissionId);
            }
        });
    }

    /**
     * 解除指定权限；未授予的权限会被忽略。
     *
     * @param array<int|string, Permission|int|string> $permissions
     */
    public function revoke(int $roleId, array $permissions): void
    {
        $this->assertRoleExists($roleId);
        $permissionIds = $this->normalizePermissionIds($permissions);

        $this->write(function () use ($roleId, $permissionIds): void {
            $this->assignments->detachPermissions($roleId, $permissionIds);
        });
    }

    /**
     * 用给定权限集合整体替换角色的权限。
     *
     * @param array<int|string, Permission|int|string> $permissions
     */
    public function syncPermissions(int $roleId, array $permissions): void
    {
        $this->assertRoleExists($roleId);
        $permissionIds = $this->normalizePermissionIds($permissions);

        $this->write(function () use ($roleId, $permissionIds): void {
            $this->assignments->syncPermissions($roleId, $permissionIds);
        });
    }

    /**
     * @return string[]
     */
    public function permissionCodes(int $roleId): array
    {
        return $this->assignments->permissionCodesForRole($roleId);
    }

    /**
     * @return Permission[]
     */
    public function permissions(int $roleId): array
    {
        $permissionIds = $this->assignments->permissionIdsForRoles([$roleId]);

        return $this->permissions->findByIds($permissionIds);
    }

    /**
     * 将角色分配给主体；重复分配会被跳过。
     */
    public function assign(int $roleId, int|string $subjectId, ?string $subjectType = null): void
    {
        $this->assertRoleExists($roleId);
        $subject = $this->subject($subjectId, $subjectType);

        $this->write(function () use ($subject, $roleId): void {
            $this->assignments->attachRole($subject, $roleId);
        });
    }

    /**
     * 解除主体上的角色。
     */
    public function unassign(int $roleId, int|string $subjectId, ?string $subjectType = null): void
    {
        $this->assertRoleExists($roleId);
        $subject = $this->subject($subjectId, $subjectType);

        $this->write(function () use ($subject, $roleId): void {
            $this->assignments->detachRole($subject, $roleId);
        });
    }

    /**
     * 用给定角色集合整体替换主体的角色。
     *
     * @param int[] $roleIds
     */
    public function syncSubjectRoles(int|string $subjectId, array $roleIds, ?string $subjectType = null): void
    {
        $normalized = $this->normalizeRoleIds($roleIds);
        $subject = $this->subject($subjectId, $subjectType);

        $this->write(function () use ($subject, $normalized): void {
            $this->assignments->syncSubjectRoles($subject, $normalized);
        });
    }

    /**
     * 查询主体的角色；管理场景下包含已停用角色。
     *
     * @return Role[]
     */
    public function subjectRoles(int|string $subjectId, ?string $subjectType = null): array
    {
        return $this->roles->forSubject($this->subject($subjectId, $subjectType), false);
    }

    public function subjectCount(int $roleId): int
    {
        return $this->assignments->countSubjectsForRole($roleId);
    }

    /**
     * 在事务中执行写操作，成功后再清空实例缓存。
     *
     * 回调抛异常时 Rbac::transaction() 会回滚并继续抛出，
     * 因此这里不会执行到 clearCache()。
     */
    private function write(callable $callback): mixed
    {
        $result = $this->rbac->transaction($callback);
        $this->rbac->clearCache();

        return $result;
    }

    private function assertRoleExists(int $roleId): void
    {
        if ($this->roles->find($roleId) === null) {
            throw RbacException::roleNotFound($roleId);
        }
    }

    private function subject(int|string $subjectId, ?string $subjectType): Subject
    {
        return new Subject($subjectType ?? $this->rbac->config()->subjectType(), (string) $subjectId);
    }

    /**
     * 将权限引用归一化为去重后的权限 ID 列表，并校验权限均存在。
     *
     * 注意：纯数字字符串按权限 ID 处理，因此权限码不应使用纯数字。
     *
     * @param array<int|string, Permission|int|string> $permissions
     * @return int[]
     */
    private function normalizePermissionIds(array $permissions): array
    {
        $ids = [];

        foreach ($permissions as $reference) {
            if ($reference instanceof Permission) {
                $ids[] = $reference->id;
                continue;
            }

            if (is_int($reference)) {
                $ids[] = $reference;
                continue;
            }

            if (is_string($reference)) {
                $code = trim($reference);

                if ($code !== '' && preg_match('/^\d+$/', $code) === 1) {
                    $ids[] = (int) $code;
                    continue;
                }

                $permission = $this->permissions->findByCode($code);

                if ($permission === null) {
                    throw RbacException::permissionCodeNotFound($code);
                }

                $ids[] = $permission->id;
                continue;
            }

            throw RbacException::invalidPermissionReference();
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $found = [];

        foreach ($this->permissions->findByIds($ids) as $permission) {
            $found[$permission->id] = true;
        }

        foreach ($ids as $id) {
            if (! isset($found[$id])) {
                throw RbacException::permissionNotFound($id);
            }
        }

        return $ids;
    }

    /**
     * 归一化角色 ID 列表，并校验角色均存在。
     *
     * @param int[] $roleIds
     * @return int[]
     */
    private function normalizeRoleIds(array $roleIds): array
    {
        $ids = [];

        foreach ($roleIds as $roleId) {
            $ids[] = (int) $roleId;
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $found = [];

        foreach ($this->roles->findByIds($ids) as $role) {
            $found[$role->id] = true;
        }

        foreach ($ids as $roleId) {
            if (! isset($found[$roleId])) {
                throw RbacException::roleNotFound($roleId);
            }
        }

        return $ids;
    }

    private function normalizeCode(mixed $value, int $maxLength): string
    {
        if (! is_string($value)) {
            throw RbacException::invalidConfig('code 必须是字符串。');
        }

        $code = trim($value);

        if ($code === '' || strlen($code) > $maxLength) {
            throw RbacException::invalidConfig("code 必须为 1-{$maxLength} 字符的非空字符串。");
        }

        return $code;
    }

    private function normalizeName(mixed $value): string
    {
        if (! is_string($value)) {
            throw RbacException::invalidConfig('name 必须是字符串。');
        }

        $name = trim($value);

        if ($name === '' || strlen($name) > 128) {
            throw RbacException::invalidConfig('name 必须为 1-128 字符的非空字符串。');
        }

        return $name;
    }

    private function normalizeDescription(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw RbacException::invalidConfig('description 必须是字符串或 null。');
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > 255) {
            throw RbacException::invalidConfig('description 最多 255 字符。');
        }

        return $value;
    }

    private function normalizeFlag(mixed $value): int
    {
        return $value ? 1 : 0;
    }
}
