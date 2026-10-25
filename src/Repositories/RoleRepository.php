<?php

declare(strict_types=1);

namespace Skarner\Rbac\Repositories;

use Skarner\Rbac\Config;
use Skarner\Rbac\Exceptions\RbacException;
use Skarner\Rbac\Models\Role;
use Skarner\Rbac\Models\Subject;
use Skarner\Rbac\Support\Database;

/**
 * 角色数据访问层。
 *
 * 只负责 roles 表的读写：不开启事务、不读写缓存；关联表的清理由 Manager 负责。
 */
final class RoleRepository
{
    /** @var string[] */
    private const COLUMNS = [
        'id',
        'code',
        'name',
        'description',
        'status',
        'is_super',
        'sort',
        'created_at',
        'updated_at',
    ];

    /** @var string[] */
    private const UPDATABLE = [
        'code',
        'name',
        'description',
        'status',
        'is_super',
        'sort',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
    ) {}

    /**
     * @return Role[]
     */
    public function all(bool $onlyEnabled = false): array
    {
        $sql = 'SELECT ' . $this->selectList() . ' FROM ' . $this->db->table('roles');

        if ($onlyEnabled) {
            $sql .= ' WHERE status = 1';
        }

        $sql .= ' ORDER BY sort, id';

        return $this->mapRoles($this->db->select($sql));
    }

    public function find(int $id): ?Role
    {
        $row = $this->db->selectOne(
            'SELECT ' . $this->selectList()
            . ' FROM ' . $this->db->table('roles')
            . ' WHERE id = ? LIMIT 1',
            [$id],
        );

        return $row === null ? null : Role::fromRow($row);
    }

    /**
     * 按角色码查找；大小写不敏感由 code 列排序规则保证。
     */
    public function findByCode(string $code): ?Role
    {
        $row = $this->db->selectOne(
            'SELECT ' . $this->selectList()
            . ' FROM ' . $this->db->table('roles')
            . ' WHERE code = ? LIMIT 1',
            [$code],
        );

        return $row === null ? null : Role::fromRow($row);
    }

    /**
     * @param string[] $codes
     * @return Role[]
     */
    public function findByCodes(array $codes): array
    {
        $values = $this->stringValues($codes);

        if ($values === []) {
            return [];
        }

        $sql = 'SELECT ' . $this->selectList()
            . ' FROM ' . $this->db->table('roles')
            . ' WHERE code IN (' . $this->db->in($values) . ')'
            . ' ORDER BY sort, id';

        return $this->mapRoles($this->db->select($sql, $values));
    }

    /**
     * @param int[] $ids
     * @return Role[]
     */
    public function findByIds(array $ids): array
    {
        $values = $this->intValues($ids);

        if ($values === []) {
            return [];
        }

        $sql = 'SELECT ' . $this->selectList()
            . ' FROM ' . $this->db->table('roles')
            . ' WHERE id IN (' . $this->db->in($values) . ')'
            . ' ORDER BY sort, id';

        return $this->mapRoles($this->db->select($sql, $values));
    }

    /**
     * @param array<string, mixed> $attributes
     * @return Role
     */
    public function create(array $attributes): Role
    {
        $description = $attributes['description'] ?? null;

        $id = $this->db->insert(
            'INSERT INTO ' . $this->db->table('roles')
            . ' (code, name, description, status, is_super, sort, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) ($attributes['code'] ?? ''),
                (string) ($attributes['name'] ?? ''),
                $description === null ? null : (string) $description,
                (int) ($attributes['status'] ?? 1),
                (int) ($attributes['is_super'] ?? 0),
                (int) ($attributes['sort'] ?? 0),
                $this->timestamp($attributes['created_at'] ?? null),
                $this->timestamp($attributes['updated_at'] ?? null),
            ],
        );

        $role = $this->find((int) $id);

        if ($role === null) {
            throw RbacException::database('创建角色后未能读取记录：' . $id);
        }

        return $role;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return Role
     */
    public function update(int $id, array $attributes): Role
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
            'UPDATE ' . $this->db->table('roles')
            . ' SET ' . implode(', ', $set)
            . ' WHERE id = ?',
            $params,
        );

        $role = $this->find($id);

        if ($role === null) {
            throw RbacException::roleNotFound($id);
        }

        return $role;
    }

    public function delete(int $id): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->db->table('roles') . ' WHERE id = ?',
            [$id],
        );
    }

    /**
     * @return Role[]
     */
    public function forSubject(Subject $subject, bool $onlyEnabled = true): array
    {
        $sql = 'SELECT ' . $this->selectList('r')
            . ' FROM ' . $this->db->table('roles') . ' r'
            . ' INNER JOIN ' . $this->db->table('subject_has_roles') . ' s ON s.role_id = r.id'
            . ' WHERE s.subject_type = ? AND s.subject_id = ?';

        if ($onlyEnabled) {
            $sql .= ' AND r.status = 1';
        }

        $sql .= ' ORDER BY r.sort, r.id';

        return $this->mapRoles($this->db->select($sql, [$subject->type, $subject->id]));
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
     * @return Role[]
     */
    private function mapRoles(array $rows): array
    {
        return array_map(
            static fn (array $row): Role => Role::fromRow($row),
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
            'status', 'is_super', 'sort' => (int) $value,
            'description' => $value === null ? null : (string) $value,
            default => (string) $value,
        };
    }

    private function timestamp(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : $this->db->now();
    }
}
