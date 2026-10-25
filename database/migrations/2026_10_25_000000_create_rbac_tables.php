<?php

declare(strict_types=1);

use Skarner\Rbac\Support\Schema;

/**
 * 纯 PDO 环境下的 RBAC 迁移入口。
 *
 * 用法：
 *   $migration = require __DIR__.'/database/migrations/2026_10_25_000000_create_rbac_tables.php';
 *   $migration($pdo, require __DIR__.'/config/rbac.php');
 *
 * 迁移本身不插入任何种子数据；up() 使用 IF NOT EXISTS，可重复执行。
 * 需要删除表时显式调用 Schema::down()，生产环境请自行加确认。
 */
return static function (PDO $pdo, array $config = []): void {
    Schema::up($pdo, $config);
};
