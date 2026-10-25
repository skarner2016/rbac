<?php

declare(strict_types=1);

namespace Skarner\Rbac\Repositories;

use Skarner\Rbac\Config;
use Skarner\Rbac\Enums\PermissionType;
use Skarner\Rbac\Exceptions\RbacException;
use Skarner\Rbac\Models\Permission;
use Skarner\Rbac\Support\Database;

/**
 * 权限数据访问层。
 *
 * 只负责 permissions 表的读写：不开启事务、不读写缓存；关联表的清理由 Manager 负责。
 * meta 始终以 JSON 字符串形式存取，编码/解码由 Manager 完成。
 */
final class PermissionRepository
{
    /** @var string[] */
    private const COLUMNS = [
        'id',
        'parent_id',
        'type',
        'code',
        'name',
        'description',
        'path',
        'component',
        'redirect',
        'icon',
        'method',
        'sort',
        'status',
        'hidden',
        'meta',
        'created_at',
        'updated_at',
    ];

    /** @var string[] */
    private const UPDATABLE = [
        'parent_id',
        'type',
        'code',
        'name',
        'description',
        'path',
        'component',
        'redirect',
        'icon',
        'method',
        'sort',
        'status',
        'hidden',
        'meta',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
    ) {}

    /**
     * @return Permission[]
     */
    public function all(?PermissionType $type = null, bool $onlyEnabled = false): array
    {
        $conditions = [];
        $params = [];

        if ($type !== null) {
            $conditions[] = 'type = ?';
            $params[] = $type->value;
        }

        if ($onlyEnabled) {
            $conditions[] = 'status = 1';
        }

        $sql = 'SELECT ' . $this->selectList() . ' FROM ' . $this->db->table('permissions');

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY sort, id';

        return $this->mapPermissions($this->db->select($sql, $params));
    }

    public function find(int $id): ?Permission
    {
        $row = $this->db->selectOne(
            'SELECT ' . $this->selectList()
            . ' FROM ' . $this->db->table('permissions')
            . ' WHERE id = ? LIMIT 1',
            [$id],
        );

        return $row === null ? null : Permission::fromRow($row);
    }

    /**
     * 按权限码查找；大小写不敏感由 code 列排序规则保证。
     */
    public function findByCode(string $code): ?Permission
    {
        $row = $this->db->selectOne(
            'SELECT ' . $this->selectList()
            . ' FROM ' . $this->db->table('permissions')
            . ' WHERE code = ? LIMIT 1',
            [$code],
        );

        return $row === null ? null : Permission::fromRow($row);
    }

    /**
     * @param string[] $codes
     * @return Permission[]
     */
    public function findByCodes(array $codes): array
    {
        $values = $this->stringValues($codes);

        if ($values === []) {
            return [];
        }

        $sql = 'SELECT ' . $this->selectList()
            . ' FROM ' . $this->db->table('permissions')
            . ' WHERE code IN (' . $this->db->in($values) . ')'
            . ' ORDER BY sort, id';

        return $this->mapPermissions($this->db->select($sql, $values));
    }

    /**
     * @param int[] $ids
     * @return Permission[]
     */
    public function findByIds(array $ids): array
    {
        $values = $this->intValues($ids);

        if ($values === []) {
            return [];
        }

        $sql = 'SELECT ' . $this->selectList()
            . ' FROM ' . $this->db->table('permissions')
            . ' WHERE id IN (' . $this->db->in($values) . ')'
            . ' ORDER BY sort, id';

        return $this->mapPermissions($this->db->select($sql, $values));
    }

    /**
     * @return Permission[]
     */
    public function children(int $parentId, bool $onlyEnabled = false): array
    {
        $sql = 'SELECT ' . $this->selectList()
            . ' FROM ' . $this->db->table('permissions')
            . ' WHERE parent_id = ?';

        if ($onlyEnabled) {
            $sql .= ' AND status = 1';
        }

        $sql .= ' ORDER BY sort, id';

        return $this->mapPermissions($this->db->select($sql, [$parentId]));
    }

    public function hasChildren(int $id): bool
    {
        $row = $this->db->selectOne(
            'SELECT 1 AS present'
            . ' FROM ' . $this->db->table('permissions')
            . ' WHERE parent_id = ? LIMIT 1',
            [$id],
        );

        return $row !== null;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return Permission
     */
    public function create(array $attributes): Permission
    {
        $type = $attributes['type'] ?? '';
        if ($type instanceof PermissionType) {
            $type = $type->value;
        }

        $meta = $attributes['meta'] ?? null;

        $id = $this->db->insert(
            'INSERT INTO ' . $this->db->table('permissions')
            . ' (parent_id, type, code, name, description, path, component, redirect, icon, method, sort, status, hidden, meta, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->nullableInt($attributes['parent_id'] ?? null),
                (string) $type,
                (string) ($attributes['code'] ?? ''),
                (string) ($attributes['name'] ?? ''),
                $this->nullableString($attributes['description'] ?? null),
                $this->nullableString($attributes['path'] ?? null),
                $this->nullableString($attributes['component'] ?? null),
                $this->nullableString($attributes['redirect'] ?? null),
                $this->nullableString($attributes['icon'] ?? null),
                $this->nullableString($attributes['method'] ?? null),
                (int) ($attributes['sort'] ?? 0),
                (int) ($attributes['status'] ?? 1),
                (int) ($attributes['hidden'] ?? 0),
                $meta === null ? null : (string) $meta,
                $this->timestamp($attributes['created_at'] ?? null),
                $this->timestamp($attributes['updated_at'] ?? null),
            ],
        );

        $permission = $this->find((int) $id);

        if ($permission === null) {
            throw RbacException::database('创建权限后未能读取记录：' . $id);
        }

        return $permission;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return Permission
     */
    public function update(int $id, array $attributes): Permission
    {
        $set = [];
        $params = [];

        foreach (self::UPDATABLE as $column) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            $set[] = $column . ' = ?';
            $params[] = $this->castValue($column, $attributes[$column]);
        }

        $set[] = 'updated_at = ?';
        $params[] = $this->timestamp($attributes['updated_at'] ?? null);
        $params[] = $id;

        $this->db->execute(
            'UPDATE ' . $this->db->table('permissions')
            . ' SET ' . implode(', ', $set)
            . ' WHERE id = ?',
            $params,
        );

        $permission = $this->find($id);

        if ($permission === null) {
            throw RbacException::permissionNotFound($id);
        }

        return $permission;
    }

    public function delete(int $id): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->db->table('permissions') . ' WHERE id = ?',
            [$id],
        );
    }

    /**
     * 生成显式列清单，可选表别名。
     */
    private function selectList(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        return implode(', ', array_map(
            static fn (string $column): string => $prefix . $column,
            self::COLUMNS,
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return Permission[]
     */
    private function mapPermissions(array $rows): array
    {
        return array_map(
            static fn (array $row): Permission => Permission::fromRow($row),
            $rows,
        );
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private function stringValues(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $normalized[] = (string) $value;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param int[] $values
     * @return int[]
     */
    private function intValues(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $normalized[] = (int) $value;
        }

        return array_values(array_unique($normalized));
    }

    private function castValue(string $column, mixed $value): mixed
    {
        return match ($column) {
            'parent_id' => $this->nullableInt($value),
            'type' => $value instanceof PermissionType ? $value->value : (string) $value,
            'sort', 'status', 'hidden' => (int) $value,
            'description', 'path', 'component', 'redirect', 'icon', 'method' => $this->nullableString($value),
            'meta' => $value === null ? null : (string) $value,
            default => (string) $value,
        };
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function timestamp(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : $this->db->now();
    }
}
