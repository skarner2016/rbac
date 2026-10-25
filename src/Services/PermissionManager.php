<?php

declare(strict_types=1);

namespace Skarner\Rbac\Services;

use JsonException;
use Skarner\Rbac\Enums\PermissionType;
use Skarner\Rbac\Exceptions\RbacException;
use Skarner\Rbac\Models\Permission;
use Skarner\Rbac\Rbac;
use Skarner\Rbac\Repositories\AssignmentRepository;
use Skarner\Rbac\Repositories\PermissionRepository;

/**
 * 权限树管理：权限 CRUD、父子关系与环校验。
 *
 * 只有 menu 可以作为父节点，route/button 是叶子；存在子节点的权限不能删除，
 * 也不能改为非 menu 类型。所有写操作都包在 Rbac::transaction() 中，
 * 成功提交后清空实例缓存；回调抛异常时事务回滚且不清缓存。
 */
final class PermissionManager
{
    public function __construct(
        private readonly Rbac $rbac,
        private readonly PermissionRepository $permissions,
        private readonly AssignmentRepository $assignments,
    ) {}

    /**
     * 创建权限。权限码与名称会去除首尾空白，但保留调用方传入的原始大小写。
     *
     * @param array<string, mixed> $attributes
     * @return Permission
     */
    public function create(PermissionType|string $type, string $code, string $name, array $attributes = []): Permission
    {
        $type = PermissionType::parse($type);
        $code = $this->normalizeCode($code, 191);
        $name = $this->normalizeName($name);
        $parentId = $this->normalizeParentId($attributes['parent_id'] ?? null);

        if ($parentId !== null) {
            $parent = $this->permissions->find($parentId);

            if ($parent === null || ! $parent->isMenu()) {
                throw RbacException::invalidParent($parent === null
                    ? "父权限不存在：{$parentId}"
                    : "父权限必须是菜单类型：{$parentId}");
            }
        }

        if ($this->permissions->findByCode($code) !== null) {
            throw RbacException::duplicatePermissionCode($code);
        }

        $record = [
            'parent_id' => $parentId,
            'type' => $type->value,
            'code' => $code,
            'name' => $name,
            'description' => $this->normalizeNullableText($attributes['description'] ?? null, 'description', 255),
            'path' => $this->normalizeNullableText($attributes['path'] ?? null, 'path', 255),
            'component' => $this->normalizeNullableText($attributes['component'] ?? null, 'component', 255),
            'redirect' => $this->normalizeNullableText($attributes['redirect'] ?? null, 'redirect', 255),
            'icon' => $this->normalizeNullableText($attributes['icon'] ?? null, 'icon', 64),
            'method' => $this->normalizeMethod($attributes['method'] ?? null),
            'sort' => (int) ($attributes['sort'] ?? 0),
            'status' => $this->normalizeFlag($attributes['status'] ?? 1),
            'hidden' => $this->normalizeFlag($attributes['hidden'] ?? 0),
            'meta' => $this->encodeMeta($attributes['meta'] ?? null),
        ];

        return $this->write(fn (): Permission => $this->permissions->create($record));
    }

    /**
     * 更新权限。只处理白名单字段，未提供的字段保持不变。
     *
     * @param array<string, mixed> $attributes
     * @return Permission
     */
    public function update(int $id, array $attributes): Permission
    {
        $existing = $this->permissions->find($id);

        if ($existing === null) {
            throw RbacException::permissionNotFound($id);
        }

        $normalized = [];

        $hasType = array_key_exists('type', $attributes);
        $newType = $hasType ? $this->parseType($attributes['type']) : $existing->type;

        if ($hasType) {
            if (! $newType->isMenu() && $this->permissions->hasChildren($id)) {
                throw RbacException::cannotChangeTypeWithChildren($id);
            }

            $normalized['type'] = $newType->value;
        }

        if (array_key_exists('parent_id', $attributes)) {
            $parentId = $this->normalizeParentId($attributes['parent_id']);

            if ($parentId !== null) {
                if ($parentId === $id) {
                    throw RbacException::invalidParent('父节点不能是自己。');
                }

                $parent = $this->permissions->find($parentId);

                if ($parent === null || ! $parent->isMenu()) {
                    throw RbacException::invalidParent($parent === null
                        ? "父权限不存在：{$parentId}"
                        : "父权限必须是菜单类型：{$parentId}");
                }

                $visited = [];
                $cursor = $parent;

                while ($cursor !== null) {
                    if ($cursor->id === $id || isset($visited[$cursor->id])) {
                        throw RbacException::invalidParent('不能形成环。');
                    }

                    $visited[$cursor->id] = true;
                    $cursor = $cursor->parentId === null
                        ? null
                        : $this->permissions->find($cursor->parentId);
                }

                if (! $newType->isMenu() && $this->permissions->hasChildren($id)) {
                    throw RbacException::cannotChangeTypeWithChildren($id);
                }
            }

            $normalized['parent_id'] = $parentId;
        }

        if (array_key_exists('code', $attributes)) {
            $code = $this->normalizeCode($attributes['code'], 191);
            $found = $this->permissions->findByCode($code);

            if ($found !== null && $found->id !== $id) {
                throw RbacException::duplicatePermissionCode($code);
            }

            $normalized['code'] = $code;
        }

        if (array_key_exists('name', $attributes)) {
            $normalized['name'] = $this->normalizeName($attributes['name']);
        }

        if (array_key_exists('description', $attributes)) {
            $normalized['description'] = $this->normalizeNullableText(
                $attributes['description'],
                'description',
                255,
            );
        }

        if (array_key_exists('path', $attributes)) {
            $normalized['path'] = $this->normalizeNullableText($attributes['path'], 'path', 255);
        }

        if (array_key_exists('component', $attributes)) {
            $normalized['component'] = $this->normalizeNullableText($attributes['component'], 'component', 255);
        }

        if (array_key_exists('redirect', $attributes)) {
            $normalized['redirect'] = $this->normalizeNullableText($attributes['redirect'], 'redirect', 255);
        }

        if (array_key_exists('icon', $attributes)) {
            $normalized['icon'] = $this->normalizeNullableText($attributes['icon'], 'icon', 64);
        }

        if (array_key_exists('method', $attributes)) {
            $normalized['method'] = $this->normalizeMethod($attributes['method']);
        }

        if (array_key_exists('sort', $attributes)) {
            $normalized['sort'] = (int) $attributes['sort'];
        }

        if (array_key_exists('status', $attributes)) {
            $normalized['status'] = $this->normalizeFlag($attributes['status']);
        }

        if (array_key_exists('hidden', $attributes)) {
            $normalized['hidden'] = $this->normalizeFlag($attributes['hidden']);
        }

        if (array_key_exists('meta', $attributes)) {
            $normalized['meta'] = $this->encodeMeta($attributes['meta']);
        }

        return $this->write(fn (): Permission => $this->permissions->update($id, $normalized));
    }

    /**
     * 删除权限，并在同一事务内清理角色-权限关联。
     */
    public function delete(int $id): void
    {
        if ($this->permissions->find($id) === null) {
            throw RbacException::permissionNotFound($id);
        }

        if ($this->permissions->hasChildren($id)) {
            throw RbacException::permissionHasChildren($id);
        }

        $this->write(function () use ($id): void {
            $this->assignments->deleteByPermission($id);
            $this->permissions->delete($id);
        });
    }

    public function find(int $id): ?Permission
    {
        return $this->permissions->find($id);
    }

    /**
     * 按权限码查找；大小写不敏感由数据库列排序规则保证。
     */
    public function findByCode(string $code): ?Permission
    {
        return $this->permissions->findByCode($code);
    }

    /**
     * @return Permission[]
     */
    public function all(?PermissionType $type = null, bool $onlyEnabled = false): array
    {
        return $this->permissions->all($type, $onlyEnabled);
    }

    /**
     * @return Permission[]
     */
    public function children(int $parentId, bool $onlyEnabled = false): array
    {
        return $this->permissions->children($parentId, $onlyEnabled);
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

    private function parseType(mixed $value): PermissionType
    {
        if ($value instanceof PermissionType) {
            return $value;
        }

        if (! is_string($value)) {
            throw RbacException::invalidPermissionType(get_debug_type($value));
        }

        return PermissionType::parse($value);
    }

    /**
     * 归一化父节点：null、''、0、'0' 视为根节点；其余必须是正整数。
     */
    private function normalizeParentId(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        if (is_int($value)) {
            if ($value < 0) {
                throw RbacException::invalidParent('parent_id 必须是正整数、0 或 null。');
            }

            return $value === 0 ? null : $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $parentId = (int) $value;

            return $parentId === 0 ? null : $parentId;
        }

        throw RbacException::invalidParent('parent_id 必须是正整数、0 或 null。');
    }

    /**
     * meta 统一编码为 JSON 字符串；null 保持为 null。
     */
    private function encodeMeta(mixed $meta): ?string
    {
        if ($meta === null) {
            return null;
        }

        if (! is_array($meta)) {
            throw RbacException::invalidConfig('meta 必须是数组或 null。');
        }

        try {
            return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw RbacException::invalidConfig('meta 无法编码为 JSON：' . $e->getMessage());
        }
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

    private function normalizeNullableText(mixed $value, string $field, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw RbacException::invalidConfig("{$field} 必须是字符串或 null。");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > $maxLength) {
            throw RbacException::invalidConfig("{$field} 最多 {$maxLength} 字符。");
        }

        return $value;
    }

    private function normalizeMethod(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw RbacException::invalidConfig('method 必须是字符串或 null。');
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = strtoupper($value);

        if (strlen($value) > 16) {
            throw RbacException::invalidConfig('method 最多 16 字符。');
        }

        return $value;
    }

    private function normalizeFlag(mixed $value): int
    {
        return $value ? 1 : 0;
    }
}
