<?php

declare(strict_types=1);

namespace Skarner\Rbac\Repositories;

use Skarner\Rbac\Config;
use Skarner\Rbac\Models\Subject;
use Skarner\Rbac\Support\Database;

/**
 * 关联表数据访问层。
 *
 * 负责 role_has_permissions 与 subject_has_roles 的附加、解除、同步与查询。
 * 不开启事务、不读写缓存；批量写入的原子性由 Manager 的事务保证。
 */
final class AssignmentRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
    ) {}

    /**
     * @param int[] $roleIds
     * @return int[]
     */
    public function permissionIdsForRoles(array $roleIds): array
    {
        $values = $this->intValues($roleIds);

        if ($values === []) {
            return [];
        }

        $sql = 'SELECT DISTINCT permission_id'
            . ' FROM ' . $this->db->table('role_has_permissions')
            . ' WHERE role_id IN (' . $this->db->in($values) . ')'
            . ' ORDER BY permission_id';

        $permissionIds = [];

        foreach ($this->db->select($sql, $values) as $row) {
            $permissionIds[] = (int) $row['permission_id'];
        }

        return $permissionIds;
    }

    /**
     * @return array<int, int[]> role_id => permission_id[]
     */
    public function rolePermissionMap(): array
    {
        $rows = $this->db->select(
            'SELECT role_id, permission_id'
            . ' FROM ' . $this->db->table('role_has_permissions')
            . ' ORDER BY role_id, permission_id',
        );

        $map = [];

        foreach ($rows as $row) {
            $roleId = (int) $row['role_id'];
            $permissionId = (int) $row['permission_id'];
            $map[$roleId][$permissionId] = $permissionId;
        }

        foreach ($map as $roleId => $permissionIds) {
            $map[$roleId] = array_values($permissionIds);
        }

        return $map;
    }

    /**
     * @param int[] $roleIds
     */
    public function deleteByRoles(array $roleIds): void
    {
        $values = $this->intValues($roleIds);

        if ($values === []) {
            return;
        }

        $this->db->execute(
            'DELETE FROM ' . $this->db->table('role_has_permissions')
            . ' WHERE role_id IN (' . $this->db->in($values) . ')',
            $values,
        );
    }

    public function deleteByPermission(int $permissionId): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->db->table('role_has_permissions') . ' WHERE permission_id = ?',
            [$permissionId],
        );
    }

    /**
     * 用给定权限集合整体替换角色的权限。
     *
     * @param int[] $permissionIds
     */
    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $table = $this->db->table('role_has_permissions');

        $this->db->execute(
            'DELETE FROM ' . $table . ' WHERE role_id = ?',
            [$roleId],
        );

        foreach ($this->intValues($permissionIds) as $permissionId) {
            $this->db->execute(
                'INSERT INTO ' . $table . ' (role_id, permission_id) VALUES (?, ?)',
                [$roleId, $permissionId],
            );
        }
    }

    public function attachPermission(int $roleId, int $permissionId): void
    {
        $table = $this->db->table('role_has_permissions');

        $existing = $this->db->selectOne(
            'SELECT 1 AS present'
            . ' FROM ' . $table
            . ' WHERE role_id = ? AND permission_id = ? LIMIT 1',
            [$roleId, $permissionId],
        );

        if ($existing !== null) {
            return;
        }

        $this->db->execute(
            'INSERT INTO ' . $table . ' (role_id, permission_id) VALUES (?, ?)',
            [$roleId, $permissionId],
        );
    }

    /**
     * @param int[] $permissionIds
     */
    public function detachPermissions(int $roleId, array $permissionIds): void
    {
        $values = $this->intValues($permissionIds);

        if ($values === []) {
            return;
        }

        $params = array_merge([$roleId], $values);

        $this->db->execute(
            'DELETE FROM ' . $this->db->table('role_has_permissions')
            . ' WHERE role_id = ? AND permission_id IN (' . $this->db->in($values) . ')',
            $params,
        );
    }

    /**
     * @return string[]
     */
    public function permissionCodesForRole(int $roleId): array
    {
        $sql = 'SELECT p.code'
            . ' FROM ' . $this->db->table('role_has_permissions') . ' rp'
            . ' INNER JOIN ' . $this->db->table('permissions') . ' p ON p.id = rp.permission_id'
            . ' WHERE rp.role_id = ?'
            . ' ORDER BY p.sort, p.id';

        $codes = [];

        foreach ($this->db->select($sql, [$roleId]) as $row) {
            $codes[] = (string) $row['code'];
        }

        return $codes;
    }

    /**
     * @return int[]
     */
    public function roleIdsForSubject(Subject $subject): array
    {
        $sql = 'SELECT role_id'
            . ' FROM ' . $this->db->table('subject_has_roles')
            . ' WHERE subject_type = ? AND subject_id = ?'
            . ' ORDER BY role_id';

        $roleIds = [];

        foreach ($this->db->select($sql, [$subject->type, $subject->id]) as $row) {
            $roleIds[] = (int) $row['role_id'];
        }

        return $roleIds;
    }

    /**
     * 用给定角色集合整体替换主体的角色。
     *
     * @param int[] $roleIds
     */
    public function syncSubjectRoles(Subject $subject, array $roleIds): void
    {
        $table = $this->db->table('subject_has_roles');

        $this->db->execute(
            'DELETE FROM ' . $table . ' WHERE subject_type = ? AND subject_id = ?',
            [$subject->type, $subject->id],
        );

        foreach ($this->intValues($roleIds) as $roleId) {
            $this->db->execute(
                'INSERT INTO ' . $table . ' (subject_type, subject_id, role_id) VALUES (?, ?, ?)',
                [$subject->type, $subject->id, $roleId],
            );
        }
    }

    public function attachRole(Subject $subject, int $roleId): void
    {
        $table = $this->db->table('subject_has_roles');

        $existing = $this->db->selectOne(
            'SELECT 1 AS present'
            . ' FROM ' . $table
            . ' WHERE subject_type = ? AND subject_id = ? AND role_id = ? LIMIT 1',
            [$subject->type, $subject->id, $roleId],
        );

        if ($existing !== null) {
            return;
        }

        $this->db->execute(
            'INSERT INTO ' . $table . ' (subject_type, subject_id, role_id) VALUES (?, ?, ?)',
            [$subject->type, $subject->id, $roleId],
        );
    }

    public function detachRole(Subject $subject, int $roleId): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->db->table('subject_has_roles')
            . ' WHERE subject_type = ? AND subject_id = ? AND role_id = ?',
            [$subject->type, $subject->id, $roleId],
        );
    }

    public function countSubjectsForRole(int $roleId): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS aggregate'
            . ' FROM ' . $this->db->table('subject_has_roles')
            . ' WHERE role_id = ?',
            [$roleId],
        );

        return $row === null ? 0 : (int) $row['aggregate'];
    }

    /**
     * 删除某角色在 subject_has_roles 中的全部关联。
     */
    public function deleteSubjectRolesByRole(int $roleId): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->db->table('subject_has_roles') . ' WHERE role_id = ?',
            [$roleId],
        );
    }

    /**
     * 归一化为去重后的整数数组，避免空数组进入 IN 查询。
     *
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
}
