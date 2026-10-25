<?php

declare(strict_types=1);

namespace Skarner\Rbac\Support;

use PDO;
use PDOException;
use Skarner\Rbac\Config;
use Skarner\Rbac\Exceptions\RbacException;

/**
 * RBAC 表结构生成与迁移。
 *
 * 只支持 MySQL/MariaDB 与 SQLite；表名统一从 Config 解析，并在拼接前再次校验，
 * 生成的 SQL 不包含结尾分号，便于逐条执行或展示。
 */
final class Schema
{
    /** @var string[] */
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    /** @var string[] 建表顺序（被依赖表在前）。 */
    private const TABLE_KEYS = [
        'roles',
        'permissions',
        'role_has_permissions',
        'subject_has_roles',
    ];

    /** 索引名上限，兼容 MySQL 的 64 字符限制并留出余量。 */
    private const INDEX_NAME_MAX_LENGTH = 60;

    /**
     * 生成指定驱动的建表语句。
     *
     * @param string $driver mysql 或 sqlite（大小写不敏感）
     * @param array<string, mixed> $config
     * @return string[] 不含结尾分号的 SQL 语句
     */
    public static function sql(string $driver, array $config = []): array
    {
        $driver = strtolower(trim($driver));
        self::assertDriver($driver);

        $tables = self::tables($config);

        return $driver === 'mysql'
            ? self::mysql($tables)
            : self::sqlite($tables);
    }

    /**
     * 创建全部 RBAC 表。
     *
     * 语句均使用 IF NOT EXISTS，可重复执行。
     *
     * @param array<string, mixed> $config
     */
    public static function up(PDO $pdo, array $config = []): void
    {
        self::execute($pdo, self::sql(self::driver($pdo), $config));
    }

    /**
     * 按依赖倒序删除全部 RBAC 表。
     *
     * 顺序为 subject_has_roles、role_has_permissions、permissions、roles，
     * 使用 DROP TABLE IF EXISTS，因此重复执行是安全的。
     *
     * @param array<string, mixed> $config
     */
    public static function down(PDO $pdo, array $config = []): void
    {
        $driver = self::driver($pdo);
        self::assertDriver($driver);

        $tables = self::tables($config);

        $statements = [];
        foreach (['subject_has_roles', 'role_has_permissions', 'permissions', 'roles'] as $key) {
            $statements[] = 'DROP TABLE IF EXISTS ' . self::quote($tables[$key], $driver);
        }

        self::execute($pdo, $statements);
    }

    /**
     * 从 PDO 读取当前驱动名。
     */
    private static function driver(PDO $pdo): string
    {
        return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    private static function assertDriver(string $driver): void
    {
        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw RbacException::unsupportedDriver($driver);
        }
    }

    /**
     * 解析并校验四张表的名称（不含引号）。
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private static function tables(array $config): array
    {
        $resolved = Config::fromArray($config);
        $tables = [];

        foreach (self::TABLE_KEYS as $key) {
            $tables[$key] = self::identifier($resolved->table($key));
        }

        return $tables;
    }

    /**
     * 校验标识符，防止配置层之外的表名进入 SQL。
     */
    private static function identifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw RbacException::invalidConfig("非法标识符：{$identifier}");
        }

        return $identifier;
    }

    /**
     * 按驱动引用标识符。
     */
    private static function quote(string $identifier, string $driver): string
    {
        return $driver === 'mysql'
            ? "`{$identifier}`"
            : "\"{$identifier}\"";
    }

    /**
     * 生成包含表名且不超过 60 字符的索引名。
     *
     * 超长时优先保留后缀，避免不同索引因截断而重名。
     */
    private static function indexName(string $table, string $suffix): string
    {
        $name = $table . '_' . $suffix;
        if (strlen($name) <= self::INDEX_NAME_MAX_LENGTH) {
            return $name;
        }

        $tail = '_' . $suffix;

        return substr($table, 0, self::INDEX_NAME_MAX_LENGTH - strlen($tail)) . $tail;
    }

    /**
     * 逐条执行 SQL。
     *
     * 不依赖宿主的 ERRMODE：exec() 返回 false 时主动抛异常，
     * 抛出 PDOException 时统一包装为 RbacException。
     *
     * @param string[] $statements
     */
    private static function execute(PDO $pdo, array $statements): void
    {
        foreach ($statements as $statement) {
            try {
                if ($pdo->exec($statement) === false) {
                    $info = $pdo->errorInfo();
                    throw RbacException::database((string) ($info[2] ?? 'unknown error'));
                }
            } catch (PDOException $e) {
                throw RbacException::database($e->getMessage(), $e);
            }
        }
    }

    /**
     * MySQL/MariaDB 建表语句。
     *
     * @param array<string, string> $tables
     * @return string[]
     */
    private static function mysql(array $tables): array
    {
        $roles = $tables['roles'];
        $permissions = $tables['permissions'];
        $roleHasPermissions = $tables['role_has_permissions'];
        $subjectHasRoles = $tables['subject_has_roles'];

        $rolesCodeUnique = self::indexName($roles, 'code_unique');
        $permissionsCodeUnique = self::indexName($permissions, 'code_unique');
        $permissionsParentIndex = self::indexName($permissions, 'parent_id_index');
        $permissionsTypeStatusIndex = self::indexName($permissions, 'type_status_index');
        $roleHasPermissionsPermissionIndex = self::indexName($roleHasPermissions, 'permission_id_index');
        $subjectHasRolesSubjectIndex = self::indexName($subjectHasRoles, 'subject_index');
        $subjectHasRolesRoleIndex = self::indexName($subjectHasRoles, 'role_id_index');

        $statements = [];

        $statements[] = <<<SQL
CREATE TABLE IF NOT EXISTS `{$roles}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `description` VARCHAR(255) NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `is_super` TINYINT NOT NULL DEFAULT 0,
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `{$rolesCodeUnique}` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        $statements[] = <<<SQL
CREATE TABLE IF NOT EXISTS `{$permissions}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` BIGINT UNSIGNED NULL,
  `type` VARCHAR(16) NOT NULL,
  `code` VARCHAR(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `description` VARCHAR(255) NULL,
  `path` VARCHAR(255) NULL,
  `component` VARCHAR(255) NULL,
  `redirect` VARCHAR(255) NULL,
  `icon` VARCHAR(64) NULL,
  `method` VARCHAR(16) NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `hidden` TINYINT NOT NULL DEFAULT 0,
  `meta` JSON NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `{$permissionsCodeUnique}` (`code`),
  KEY `{$permissionsParentIndex}` (`parent_id`),
  KEY `{$permissionsTypeStatusIndex}` (`type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        $statements[] = <<<SQL
CREATE TABLE IF NOT EXISTS `{$roleHasPermissions}` (
  `role_id` BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `{$roleHasPermissionsPermissionIndex}` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        $statements[] = <<<SQL
CREATE TABLE IF NOT EXISTS `{$subjectHasRoles}` (
  `subject_type` VARCHAR(191) NOT NULL,
  `subject_id` VARCHAR(64) NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`subject_type`, `subject_id`, `role_id`),
  KEY `{$subjectHasRolesSubjectIndex}` (`subject_type`, `subject_id`),
  KEY `{$subjectHasRolesRoleIndex}` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        return $statements;
    }

    /**
     * SQLite 建表与索引语句。
     *
     * code 列统一使用 COLLATE NOCASE，保证 ASCII 范围内大小写不敏感；
     * 索引以独立语句创建，便于 IF NOT EXISTS 重复执行。
     *
     * @param array<string, string> $tables
     * @return string[]
     */
    private static function sqlite(array $tables): array
    {
        $roles = $tables['roles'];
        $permissions = $tables['permissions'];
        $roleHasPermissions = $tables['role_has_permissions'];
        $subjectHasRoles = $tables['subject_has_roles'];

        $rolesCodeUnique = self::indexName($roles, 'code_unique');
        $permissionsCodeUnique = self::indexName($permissions, 'code_unique');
        $permissionsParentIndex = self::indexName($permissions, 'parent_id_index');
        $permissionsTypeStatusIndex = self::indexName($permissions, 'type_status_index');
        $roleHasPermissionsPermissionIndex = self::indexName($roleHasPermissions, 'permission_id_index');
        $subjectHasRolesSubjectIndex = self::indexName($subjectHasRoles, 'subject_index');
        $subjectHasRolesRoleIndex = self::indexName($subjectHasRoles, 'role_id_index');

        $statements = [];

        $statements[] = <<<SQL
CREATE TABLE IF NOT EXISTS "{$roles}" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "code" TEXT NOT NULL COLLATE NOCASE,
  "name" TEXT NOT NULL,
  "description" TEXT NULL,
  "status" INTEGER NOT NULL DEFAULT 1,
  "is_super" INTEGER NOT NULL DEFAULT 0,
  "sort" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NULL,
  "updated_at" TEXT NULL
)
SQL;

        $statements[] = <<<SQL
CREATE TABLE IF NOT EXISTS "{$permissions}" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "parent_id" INTEGER NULL,
  "type" TEXT NOT NULL,
  "code" TEXT NOT NULL COLLATE NOCASE,
  "name" TEXT NOT NULL,
  "description" TEXT NULL,
  "path" TEXT NULL,
  "component" TEXT NULL,
  "redirect" TEXT NULL,
  "icon" TEXT NULL,
  "method" TEXT NULL,
  "sort" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 1,
  "hidden" INTEGER NOT NULL DEFAULT 0,
  "meta" TEXT NULL,
  "created_at" TEXT NULL,
  "updated_at" TEXT NULL
)
SQL;

        $statements[] = <<<SQL
CREATE TABLE IF NOT EXISTS "{$roleHasPermissions}" (
  "role_id" INTEGER NOT NULL,
  "permission_id" INTEGER NOT NULL,
  PRIMARY KEY ("role_id", "permission_id")
)
SQL;

        $statements[] = <<<SQL
CREATE TABLE IF NOT EXISTS "{$subjectHasRoles}" (
  "subject_type" TEXT NOT NULL,
  "subject_id" TEXT NOT NULL,
  "role_id" INTEGER NOT NULL,
  PRIMARY KEY ("subject_type", "subject_id", "role_id")
)
SQL;

        $statements[] = sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS "%s" ON "%s" ("code")',
            $rolesCodeUnique,
            $roles
        );
        $statements[] = sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS "%s" ON "%s" ("code")',
            $permissionsCodeUnique,
            $permissions
        );
        $statements[] = sprintf(
            'CREATE INDEX IF NOT EXISTS "%s" ON "%s" ("parent_id")',
            $permissionsParentIndex,
            $permissions
        );
        $statements[] = sprintf(
            'CREATE INDEX IF NOT EXISTS "%s" ON "%s" ("type", "status")',
            $permissionsTypeStatusIndex,
            $permissions
        );
        $statements[] = sprintf(
            'CREATE INDEX IF NOT EXISTS "%s" ON "%s" ("permission_id")',
            $roleHasPermissionsPermissionIndex,
            $roleHasPermissions
        );
        $statements[] = sprintf(
            'CREATE INDEX IF NOT EXISTS "%s" ON "%s" ("subject_type", "subject_id")',
            $subjectHasRolesSubjectIndex,
            $subjectHasRoles
        );
        $statements[] = sprintf(
            'CREATE INDEX IF NOT EXISTS "%s" ON "%s" ("role_id")',
            $subjectHasRolesRoleIndex,
            $subjectHasRoles
        );

        return $statements;
    }
}
