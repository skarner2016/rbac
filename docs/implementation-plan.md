# RBAC 基础包实施计划

> 状态：已实施，发布准备完成
> 目标仓库：`/Users/skarner/workspace/skarner2016/rbac`
> 更新日期：2026-10-25

## 0. 本次执行范围

- 计划已确认，按本文档实施。
- 交付：20 个 `src/` 文件 + `composer.json` + `.gitignore` + `config/rbac.php` + 迁移。
- 不改动 `admin-starter`，不执行 `git commit`。
- 首版骨架不含 PHPUnit/README/CI；发布准备阶段已补齐 `tests/`、`README.md`、`.github/workflows/tests.yml`。

## 1. 背景与决策记录

### 1.1 仓库现状

- 仓库只有 `.git/`，`master` 分支没有任何提交，也没有源码、配置、README 或测试。
- 远端 `git@github.com:skarner2016/rbac.git` 已配置，但没有任何 ref。
- 本次从零开始，没有既有代码需要兼容。

### 1.2 参考项目调研（只读）

同目录的 `admin-starter` 是当前唯一相关宿主：

| 项 | 现状 |
|---|---|
| 后端 | Laravel 12 / PHP 8.3 |
| 权限包 | spatie/laravel-permission 8.3（Eloquent） |
| 表名 | `admin_roles`、`admin_permissions`、`admin_model_has_roles`、`admin_role_has_permissions`、`admin_role_menu`、`admin_menus` |
| 授权入口 | `admin_role_menu` 是事实源；spatie 权限由 `type=button` 的 `auth_code` 派生 |
| 用户 | `AdminUserModel`，`HasRoles`，`web` guard，Sanctum 认证 |
| 停用角色 | `hasPermissionViaRole()` 和菜单查询都过滤 `roles.status=1` |
| 超级角色 | 角色码 `super`，`Gate::before` 全量放行 |
| 前端 | Vue3 + Vben Admin 5.7，`accessMode: 'backend'`，`/menu/all` 返回路由树，`/auth/codes` 返回权限码数组 |

本轮**不改造** `admin-starter`，但新包保留可配置表名、`subject_type`、`is_super` 等接入点，便于后续替换。

### 1.3 已确认决策

| # | 决策项 | 选择 |
|---|---|---|
| 1 | 技术栈 | **纯 PDO 轻量库**，不依赖 Laravel/Illuminate 或任何第三方包 |
| 2 | 权限模型 | **统一权限树 + 祖先补全**：menu/route/button 同表，`parent_id` 组树 |
| 3 | 交付范围 | **仅最小骨架**：src + 迁移 + 配置；不写测试、README、CI |
| 4 | 验证方式 | **Docker / colima**，用 `php:8.3-cli` 和 `mysql:8.4` 实跑 |

### 1.4 设计原则

1. **角色是唯一授权入口**：没有 `subject_has_permissions`，`Access` 只读，写权限只能通过 `RoleManager`。
2. **零第三方运行时依赖**：只 require `php ^8.2`、`ext-pdo`、`ext-json`。
3. **显式优先**：权限码显式校验；`canRoute()` 只是路由层的便捷匹配，不替代 `can()`。
4. **安全默认**：停用角色/权限不授权；删除有子节点的权限直接失败；配置/表名校验后才拼 SQL。
5. **前端友好**：菜单树直接输出 `path/component/icon/sort/meta/children`，并附带已授予的 route/button 权限码。

## 2. 数据模型

### 2.1 表结构

#### `rbac_roles`

| 列 | 类型 | 约束 | 说明 |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| `code` | VARCHAR(64) | NOT NULL, UNIQUE | 角色标识，大小写不敏感（ASCII） |
| `name` | VARCHAR(128) | NOT NULL | 显示名 |
| `description` | VARCHAR(255) | NULL | |
| `status` | TINYINT | NOT NULL DEFAULT 1 | 1 启用，0 停用 |
| `is_super` | TINYINT | NOT NULL DEFAULT 0 | 1 = 超级角色 |
| `sort` | INT | NOT NULL DEFAULT 0 | |
| `created_at` | DATETIME | NULL | UTC |
| `updated_at` | DATETIME | NULL | UTC |

#### `rbac_permissions`

| 列 | 类型 | 约束 | 说明 |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| `parent_id` | BIGINT UNSIGNED | NULL, INDEX | 父节点；仅 menu 可作父 |
| `type` | VARCHAR(16) | NOT NULL, INDEX | `menu` / `route` / `button` |
| `code` | VARCHAR(191) | NOT NULL, UNIQUE | 权限码，大小写不敏感（ASCII） |
| `name` | VARCHAR(128) | NOT NULL | 显示名 |
| `description` | VARCHAR(255) | NULL | |
| `path` | VARCHAR(255) | NULL | menu=前端路径；route=后端 URI 模式 |
| `component` | VARCHAR(255) | NULL | menu 前端组件 |
| `redirect` | VARCHAR(255) | NULL | menu |
| `icon` | VARCHAR(64) | NULL | menu |
| `method` | VARCHAR(16) | NULL | route 的 HTTP 方法，`*` = 全部 |
| `sort` | INT | NOT NULL DEFAULT 0 | |
| `status` | TINYINT | NOT NULL DEFAULT 1 | 1 启用，0 停用 |
| `hidden` | TINYINT | NOT NULL DEFAULT 0 | 前端隐藏但可路由 |
| `meta` | JSON (SQLite TEXT) | NULL | 前端扩展元数据 |
| `created_at` | DATETIME | NULL | |
| `updated_at` | DATETIME | NULL | |

索引：`UNIQUE(code)`、`INDEX(parent_id)`、`INDEX(type, status)`。

#### `rbac_role_has_permissions`

| 列 | 类型 | 约束 |
|---|---|---|
| `role_id` | BIGINT UNSIGNED | NOT NULL |
| `permission_id` | BIGINT UNSIGNED | NOT NULL |

`PRIMARY KEY(role_id, permission_id)`，`INDEX(permission_id)`。无时间戳。

#### `rbac_subject_has_roles`

| 列 | 类型 | 约束 | 说明 |
|---|---|---|---|
| `subject_type` | VARCHAR(191) | NOT NULL | 任意判别值，默认 `user` |
| `subject_id` | VARCHAR(64) | NOT NULL | 支持自增/UUID/雪花 ID |
| `role_id` | BIGINT UNSIGNED | NOT NULL | |

`PRIMARY KEY(subject_type, subject_id, role_id)`，`INDEX(subject_type, subject_id)`，`INDEX(role_id)`。无时间戳。

### 2.2 结构规则

1. **没有 `subject_has_permissions`**：这是需求 1 的硬约束，代码中也不提供 `givePermissionTo()` 之类的主体级授权方法。
2. **父子规则**：只有 `type=menu` 可以作为父节点；`route`/`button` 是叶子。更新时校验父节点存在、类型合法且不形成环。
3. **删除护栏**：权限存在子节点时抛 `RbacException`；角色/权限删除时，Manager 在同一事务内清理两张关联表。
4. **不建外键**：与 admin-starter 现有自定义 pivot 风格一致，避免 MySQL/SQLite 行为差异；完整性由 Manager 事务保证。若希望强约束，可改为外键方案。
5. **大小写不敏感（ASCII）**：`code` 在 MySQL 使用 `utf8mb4_unicode_ci`，SQLite 使用 `COLLATE NOCASE`；PHP 侧用 `strtolower()` 归一键查找，输出保留原始大小写。详见 5.7。
6. **停用语义**：只有 `status=1` 的角色和权限参与授权；停用角色等同未分配。
7. **超级角色**：任一启用角色 `is_super=1` 时，主体获得全部启用权限，菜单返回全部启用菜单。
8. **时间**：`created_at` / `updated_at` 使用 UTC，格式 `Y-m-d H:i:s`，由 Manager 写入。

## 3. 文件清单与职责

```
composer.json
.gitignore
config/rbac.php
database/migrations/2026_10_25_000000_create_rbac_tables.php
src/
  Rbac.php
  Config.php
  Enums/PermissionType.php
  Exceptions/RbacException.php
  Exceptions/AccessDeniedException.php
  Models/Role.php
  Models/Permission.php
  Models/Subject.php
  Repositories/RoleRepository.php
  Repositories/PermissionRepository.php
  Repositories/AssignmentRepository.php
  Services/Grants.php
  Services/GrantResolver.php
  Services/Access.php
  Services/MenuTree.php
  Services/RoleManager.php
  Services/PermissionManager.php
  Support/Database.php
  Support/Schema.php
  Support/PermissionCache.php
```

共 20 个 `src/` 文件，加 `composer.json`、`.gitignore`、配置、迁移。

| 类 | 职责 |
|---|---|
| `Rbac` | 组合根。持有 PDO/Config/缓存/Repository；暴露 `for()`、`roles()`、`permissions()`、`clearCache()`、`transaction()`。 |
| `Config` | 合并默认配置，校验表名/`subject_type`，提供强类型读取。 |
| `PermissionType` | 枚举：`Menu`、`Route`、`Button`。 |
| `RbacException` | 领域异常：不存在、重复、父节点非法、有子节点、驱动不支持等。 |
| `AccessDeniedException` | 继承 `RbacException`，供宿主映射 403。 |
| `Role` / `Permission` / `Subject` | readonly 值对象；`fromRow()` 做类型转换。 |
| `RoleRepository` | roles 表 CRUD、按 code 查找、启用过滤、按主体查角色。 |
| `PermissionRepository` | permissions 表 CRUD、按 type/status 查询、父链所需的全量读取。 |
| `AssignmentRepository` | `role_has_permissions` 与 `subject_has_roles` 的 attach/detach/sync/查询。 |
| `Grants` | 主体有效角色、有效权限集合的只读 DTO。 |
| `GrantResolver` | 解析有效授权（含超级角色），用 `PermissionCache` 缓存。 |
| `Access` | 只读鉴权：`can/canAny/canAll/canRoute/codes/menus/authorize`。 |
| `MenuTree` | 从已授予权限构建祖先补全后的菜单树。 |
| `RoleManager` | 角色 CRUD、`grant/revoke/syncPermissions`、`assign/unassign/syncSubjectRoles`；写后清缓存。 |
| `PermissionManager` | 权限 CRUD、父子/环/子节点校验；写后清缓存。 |
| `Database` | PDO 封装：prepared statement、标识符引用、驱动判断、`lastInsertId`。 |
| `Schema` | MySQL/SQLite DDL，`up()`、`down()`、`sql()`。 |
| `PermissionCache` | 实例内数组缓存，`remember()` / `clear()`。 |

## 4. 公共 API

### 4.1 初始化与配置

```php
use Skarner\Rbac\Rbac;

$rbac = new Rbac($pdo, require __DIR__.'/config/rbac.php');
```

`config/rbac.php` 默认内容：

```php
<?php

declare(strict_types=1);

return [
    'tables' => [
        'roles' => 'rbac_roles',
        'permissions' => 'rbac_permissions',
        'role_has_permissions' => 'rbac_role_has_permissions',
        'subject_has_roles' => 'rbac_subject_has_roles',
    ],

    // 默认主体类型；写入 subject_has_roles.subject_type。
    'subject_type' => 'user',

    'menu' => [
        // 菜单节点的 permissions 字段是否输出已授予的 route/button 权限码。
        'include_permission_codes' => true,
    ],
];
```

不传配置时使用同样的默认值，配置文件主要用于覆盖和查阅。

### 4.2 权限树管理

```php
use Skarner\Rbac\Enums\PermissionType;

$permissions = $rbac->permissions();

$system = $permissions->create(PermissionType::Menu, 'System', '系统管理', [
    'path' => '/system',
    'icon' => 'settings',
    'sort' => 10,
]);

$userMenu = $permissions->create(PermissionType::Menu, 'System:User', '用户管理', [
    'parent_id' => $system->id,
    'path' => 'user',
    'component' => 'system/user/index',
    'sort' => 10,
]);

$permissions->create(PermissionType::Route, 'System:User:List', '用户列表接口', [
    'parent_id' => $userMenu->id,
    'method' => 'GET',
    'path' => '/api/users',
]);

$permissions->create(PermissionType::Button, 'System:User:Create', '新增用户', [
    'parent_id' => $userMenu->id,
]);
```

### 4.3 角色授权

```php
$roles = $rbac->roles();

$role = $roles->create('auditor', '审计员', [
    'description' => '只读审计角色',
    'status' => 1,
]);

// 唯一授权入口：按权限码授予
$roles->syncPermissions($role->id, [
    'System:User:List',
    'System:User:Create',
    'Order:Export',
]);

// 也可以按权限 ID 或 Permission 对象授予
$roles->grant($role->id, [$userMenu->id]);

// 分配主体（默认 subject_type=user）
$roles->assign($role->id, 7);

// 多主体类型时显式指定
$roles->assign($role->id, 7, 'admin_user');

// 整体替换某主体的角色
$roles->syncSubjectRoles(7, [$role->id]);
```

### 4.4 主体鉴权（只读）

```php
$access = $rbac->for(7);

$access->codes();                         // 已授予权限码，稳定排序
$access->permissions();                   // 已授予 Permission DTO
$access->can('System:User:Create');       // bool
$access->canAny(['A', 'B']);
$access->canAll(['A', 'B']);
$access->routeCodes();                    // 仅 route 类型
$access->canRoute('GET', '/api/users');   // route 类型 + 方法/路径匹配
$access->roleCodes();                     // 启用角色码
$access->hasRole('auditor');
$access->hasAnyRole(['auditor', 'admin']);
$access->hasAllRoles(['auditor', 'admin']);
$access->isSuper();
$access->menus();                         // 前端菜单树
$access->authorize('System:User:Create'); // 不满足抛 AccessDeniedException
$access->authorizeAny('A', 'B');
```

### 4.5 菜单树输出

```json
[
  {
    "id": "1",
    "parentId": null,
    "type": "menu",
    "code": "System",
    "name": "系统管理",
    "path": "/system",
    "component": null,
    "redirect": null,
    "icon": "settings",
    "sort": 10,
    "hidden": false,
    "meta": {},
    "permissions": ["System:User:Create"],
    "children": [
      {
        "id": "2",
        "parentId": "1",
        "type": "menu",
        "code": "System:User",
        "name": "用户管理",
        "path": "user",
        "component": "system/user/index",
        "redirect": null,
        "icon": null,
        "sort": 10,
        "hidden": false,
        "meta": {},
        "permissions": ["System:User:Create"],
        "children": []
      }
    ]
  }
]
```

约定：

- 只输出 `type=menu` 且 `status=1` 的节点。
- 每个菜单的 `permissions` 是它子树内已授予的 `route`/`button` 权限码；菜单自身码在 `code`。
- ID 输出为字符串，避免前端大整数精度问题；`children` 始终存在，叶子为 `[]`。
- `meta` 原样输出，不自动合并 `icon`/`sort`；需要 Vben 的 `meta.title/order/icon` 时由宿主格式化器映射。

## 5. 关键行为

### 5.1 有效授权解析

`GrantResolver::resolve(Subject)`：

1. 查 `subject_has_roles`，join `roles`，只保留 `roles.status=1`。
2. 任一角色 `is_super=1` → 有效权限 = 全部 `permissions.status=1`。
3. 否则合并这些角色的 `role_has_permissions`，再过滤 `permissions.status=1`。
4. 权限按 `sort, id` 排序，保证 `codes()` 输出稳定。
5. 结果缓存键：`grants:{subject_type}:{subject_id}`。

### 5.2 菜单树算法

1. 从每个已授予权限出发，沿 `parent_id` 向上遍历，收集命中的菜单节点。
2. 父链读取全量权限（含停用节点），因此停用父菜单不会被补出来。
3. 只保留 `type=menu`、`status=1` 的节点。
4. 可见节点之间组树；父节点不可见的节点**不提升为根**，直接丢弃（与 admin-starter 现有行为一致）。
5. 递归按 `sort, id` 排序。
6. 每个已授予的 route/button 权限码，挂到它所有可见菜单祖先的 `permissions` 上。
7. 超级角色直接返回全部启用菜单。

### 5.3 路由匹配（`canRoute`）

- 只检查已授予的 `type=route` 权限。
- 方法：`method` 为空或 `*` 匹配任意；否则大小写不敏感比较。
- 路径：精确匹配；支持 `*`、`{id}`、`:id` 三种写法。
- 调用前去掉 query string，统一首尾斜杠。
- 超级角色直接返回 `true`。
- 需要按权限码精确校验时使用 `can('code')`；`canRoute` 不替代显式码检查。

### 5.4 缓存

- 每个 `Rbac` 实例内使用纯数组缓存，无 Redis/PSR-16 依赖。
- 缓存对象：全量权限、角色→权限 ID 映射、主体→有效角色、主体→Grants。
- 所有写操作经 `RoleManager`/`PermissionManager`，成功后清空实例缓存。
- 直接绕过库改库不会被感知；公开 `$rbac->clearCache()` 供外部调用。
- 不做跨请求持久缓存，避免多进程失效问题；后续如需可加 PSR-16 适配器（非本轮）。

### 5.5 事务

- 所有写操作包在 `Rbac::transaction()` 中：当前 PDO 未在事务内则 `begin/commit/rollback`，已在事务内则直接参与。
- 角色/权限删除时，同一事务内先删关联表再删主表。
- 不修改宿主 PDO 的 `ATTR_ERRMODE` 等全局属性；`Database` 层检查 `prepare()/execute()` 返回值并抛异常。

### 5.6 异常

| 异常 | 场景 |
|---|---|
| `RbacException` | 配置非法、角色/权限不存在、权限码重复、父节点非法、存在子节点、驱动不支持 |
| `AccessDeniedException` | `authorize()` / `authorizeAny()` 失败 |

异常消息使用中文，便于 admin-starter 直接展示。

### 5.7 大小写策略

- **数据库**：MySQL 的 `roles.code` / `permissions.code` 使用 `utf8mb4_unicode_ci`；SQLite 使用 `COLLATE NOCASE`。两者都让唯一索引和 `WHERE code = ?` 大小写不敏感。
- **PHP**：`Grants`、`Access::can()`、`hasRole()` 等查找统一用 `strtolower($code)` 作为键；`codes()`、`menus()` 输出仍保留数据库中的原始大小写，避免前端 `v-access:code` 失配。
- **查重**：`RoleManager` / `PermissionManager` 创建或改码时按 ci 查重，仅大小写不同的码视为重复。
- **边界**：SQLite `NOCASE` 只折叠 ASCII A-Z；权限码约定为 ASCII（字母、数字、冒号、下划线），中文只用于 `name` / `description`。MySQL 的 Unicode 折叠范围更大，跨库行为以 ASCII 码为准。

## 6. 数据库迁移

### 6.1 Schema API

```php
use Skarner\Rbac\Support\Schema;

Schema::up($pdo, $config);          // 建 4 张表，IF NOT EXISTS，可重复执行
Schema::down($pdo, $config);        // 按依赖倒序 DROP，危险操作需显式调用
Schema::sql('mysql', $config);      // 返回不含分号的 DDL 语句列表
Schema::sql('sqlite', $config);
```

支持驱动：`mysql`（含 MariaDB）、`sqlite`。其他驱动抛 `RbacException`。

### 6.2 迁移文件

`database/migrations/2026_10_25_000000_create_rbac_tables.php` 返回闭包：

```php
<?php

declare(strict_types=1);

use Skarner\Rbac\Support\Schema;

/**
 * 纯 PDO 环境下的迁移入口。
 *
 * $migration = require __DIR__.'/database/migrations/2026_10_25_000000_create_rbac_tables.php';
 * $migration($pdo, require __DIR__.'/config/rbac.php');
 */
return static function (PDO $pdo, array $config = []): void {
    Schema::up($pdo, $config);
};
```

### 6.3 方言差异

| 项 | MySQL | SQLite |
|---|---|---|
| 主键 | `BIGINT UNSIGNED AUTO_INCREMENT` | `INTEGER PRIMARY KEY AUTOINCREMENT` |
| 布尔 | `TINYINT DEFAULT 0/1` | `INTEGER DEFAULT 0/1` |
| JSON | `JSON` | `TEXT` |
| 权限码排序规则 | `utf8mb4_unicode_ci` | `COLLATE NOCASE` |
| 索引 | `CREATE TABLE` 内联 `KEY` | 独立 `CREATE INDEX IF NOT EXISTS` |
| 表选项 | InnoDB / utf8mb4 | 无 |

完整 DDL 由 `Schema` 生成，字段与第 2 节一致。

### 6.4 部署注意

- 迁移不插入任何种子数据（不创建 super 角色、不创建菜单）。
- 重复执行 `up()` 安全；`down()` 会删表，生产环境应加确认。
- 若宿主已有 `admin_*` 表，可把 `config/rbac.php` 的 `tables` 改成对应表名，但列结构需匹配。

## 7. 验证计划

### 7.1 环境

- 本机当前没有可用 PHP/Composer；Docker CLI 已安装，colima 未运行。
- 目标镜像：`php:8.3-cli`（需含 `pdo_sqlite`、`sqlite3`）、`composer:2`、`mysql:8.4`。

### 7.2 步骤

1. `colima start`（如未运行；VM 已存在，vz 驱动）。
2. `docker run --rm php:8.3-cli php -m` 确认 `pdo_sqlite`、`sqlite3`。
3. `composer validate --no-check-publish`。
4. 以当前用户 UID 运行 `composer dump-autoload`，验证 PSR-4；避免 vendor 归属 root。
5. `php -l` 检查 `src/`、`config/`、`database/` 全部 PHP 文件。
6. 运行临时冒烟脚本 `/tmp/rbac-smoke.php`（**不提交**）。
7. 生成 MySQL DDL，在 `mysql:8.4` 容器执行 `SHOW TABLES` / `SHOW CREATE TABLE`。
8. 清理容器与临时文件；`git status --short` 确认只包含计划内文件。

### 7.3 冒烟覆盖

- SQLite 内存库执行 `Schema::up()`，四张表存在。
- 大小写不敏感：已授予 `System:User:Create` 时，`can('system:user:create')` 为 true；仅大小写不同的重复码创建失败。
- 菜单/子菜单/route/button 创建；父节点类型校验；环校验；有子节点删除失败。
- 角色创建、`grant`、`revoke`、`syncPermissions`、`assign`、`syncSubjectRoles`。
- `codes()`、`can()`、`canRoute()`、`menus()` 的祖先补全与 `permissions` 汇总。
- 只授予 button 时，父菜单/祖父菜单自动出现。
- 停用角色、停用权限不生效。
- 超级角色绕过所有检查并返回全部菜单。
- 写操作后同一实例缓存自动失效。
- 确认 `Access` 没有任何写授权方法，代码中不存在 `subject_has_permissions`。

### 7.4 清理

- `docker rm -f` 临时 MySQL 容器。
- 删除 `/tmp` 冒烟脚本和生成的 SQL。
- 不保留 `vendor/`（已 gitignore）。

## 8. 非目标

- 不提供 Laravel/Illuminate 集成、中间件、Gate、Blade 指令。
- 不做 guard、团队/租户、角色继承、数据权限。
- 不做跨请求持久缓存。
- 不提供管理后台 Controller/UI、Seeder、示例应用。
- 首版骨架未包含 PHPUnit 测试、README、CI；发布准备阶段已补齐。
- 不支持 PostgreSQL。
- 不改动 `admin-starter`。

## 9. 风险与回退

| 风险 | 影响 | 处理 |
|---|---|---|
| colima 启动失败 | 无法用 Docker 验证 | 回退免 sudo 的 static-php-cli 8.4 + composer.phar；仍失败则如实报告阻塞 |
| 首次拉取镜像慢 | 验证耗时 | SQLite 冒烟优先；MySQL 只做 DDL 验证 |
| 没有提交测试 | 后续改动缺回归保障 | 本轮按范围选择不做；下一轮可补 PHPUnit + CI |
| 不建外键 | 依赖 Manager 保证一致性 | 所有写操作走 Manager；如需强约束可改外键方案 |
| 权限码大小写不敏感（ASCII） | 非 ASCII 码不会被 SQLite NOCASE 折叠 | MySQL 用 `utf8mb4_unicode_ci`，SQLite 用 `COLLATE NOCASE`，PHP 用 `strtolower()`；非 ASCII 码建议自行统一大小写 |

## 10. 完成标准

- 20 个源文件 + `composer.json` + `.gitignore` + 配置 + 迁移全部创建。
- `composer validate` 通过；所有 PHP 文件 `php -l` 无错误。
- SQLite 冒烟脚本全部断言通过。
- MySQL 8.4 成功建出四张表，索引/唯一键符合预期。
- 仓库只新增计划内文件；`admin-starter` 未被改动；未执行 commit。
- 发布准备：PHPUnit 120 个测试、286 个断言通过；README/LICENSE/CI/Packagist 元数据已补齐。

## 11. 待确认的决策点

> 本次实施按以下默认值执行；如需调整请随时打断。

1. **表名前缀**：默认 `rbac_*`。是否改为 `admin_*` 或其它？
2. **超级角色**：默认用 `roles.is_super` 字段。是否改为配置 `super_role_code`（如 `super`）而不加字段？
3. **菜单树 ID**：默认输出字符串。是否改为整数？
4. **菜单 `permissions` 汇总**：默认输出已授予的 route/button 权限码。是否保留？
5. **`canRoute`**：默认保留（支持 `*`/`{id}`/`:id`）。是否只保留 `can(code)`？
6. **外键**：默认不建。是否改为对 role_id/permission_id/parent_id 建外键？
7. **停用语义**：默认停用角色/权限立即不授权。是否保留？
8. **路由权限列**：默认 `method` + `path`。是否还需要 `group`、`middleware` 等字段？
9. **数据库支持**：默认 MySQL/MariaDB + SQLite。是否需要 PostgreSQL？
10. **下一轮**：是否补 PHPUnit + README + CI？

## 12. 评审记录

| 日期 | 评审人 | 结论 | 备注 |
|---|---|---|---|
| 2026-10-25 | 用户 | 已确认 | 将 2.2.5 改为 `utf8mb4_unicode_ci`；其余按默认值实施 |
| 2026-10-25 | ZCode | 已实施 | 20 个 src 文件 + 配置 + 迁移；PHP lint、SQLite 69 项冒烟、MySQL 40 项冒烟通过 |
| 2026-10-25 | ZCode | 发布准备 | 补 PHPUnit（120 tests / 286 assertions）、README、LICENSE、CI、Packagist 元数据 |
