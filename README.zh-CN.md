# Skarner RBAC

[English](README.md) | 简体中文

[![Latest Version](https://img.shields.io/packagist/v/skarner2016/rbac.svg?style=flat-square)](https://packagist.org/packages/skarner2016/rbac)
[![PHP Version](https://img.shields.io/packagist/php-v/skarner2016/rbac.svg?style=flat-square)](https://packagist.org/packages/skarner2016/rbac)
[![License](https://img.shields.io/packagist/l/skarner2016/rbac.svg?style=flat-square)](https://packagist.org/packages/skarner2016/rbac)

一个基于纯 PDO 的轻量 RBAC 权限包，不依赖 Laravel 或任何第三方运行时包。

权限**只能通过角色授予**，没有用户直授权限的表，也没有直授权限的 API。权限统一放在一棵树里，分为菜单（`menu`）、路由（`route`）、按钮（`button`）三类。

## 特性

- 运行时零第三方依赖，只要求 `ext-pdo`、`ext-json`。
- 支持 MySQL/MariaDB 与 SQLite。
- 菜单树自动补全祖先节点。
- 权限码/角色码在 ASCII 范围内大小写不敏感。
- 路由匹配支持 `*`、`{id}`、`:id`。
- 支持停用角色/权限与超级角色（`is_super`）。
- 不需要框架、容器或 ORM。

## 环境要求

- PHP 8.2+
- `ext-pdo`、`ext-json`
- MySQL 5.7+/8.x、MariaDB 10.2+ 或 SQLite 3

## 安装

```bash
composer require skarner2016/rbac
```

## 快速开始

### 1. 建表

```php
use PDO;
use Skarner\Rbac\Support\Schema;

$config = require __DIR__ . '/config/rbac.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app;charset=utf8mb4', $user, $password);
Schema::up($pdo, $config);
```

`Schema::up()` 使用 `IF NOT EXISTS`，可以重复执行。

### 2. 初始化

```php
use Skarner\Rbac\Rbac;

$rbac = new Rbac($pdo, $config);
```

### 3. 创建权限树

```php
use Skarner\Rbac\Enums\PermissionType;

$permissions = $rbac->permissions();

$system = $permissions->create(PermissionType::Menu, 'System', '系统管理', [
    'path' => '/system',
    'icon' => 'settings',
    'sort' => 10,
]);

$users = $permissions->create(PermissionType::Menu, 'System:User', '用户管理', [
    'parent_id' => $system->id,
    'path' => 'user',
    'component' => 'system/user/index',
    'sort' => 10,
]);

$permissions->create(PermissionType::Route, 'System:User:List', '用户列表接口', [
    'parent_id' => $users->id,
    'method' => 'GET',
    'path' => '/api/users',
]);

$permissions->create(PermissionType::Button, 'System:User:Create', '新增用户', [
    'parent_id' => $users->id,
]);
```

### 4. 角色授权并分配给主体

```php
$role = $rbac->roles()->create('auditor', '审计员');

$rbac->roles()->syncPermissions($role->id, [
    'System:User:List',
    'System:User:Create',
]);

// subject_type 默认是 user
$rbac->roles()->assign($role->id, 7);
```

### 5. 鉴权

```php
$access = $rbac->for(7);

$access->can('System:User:Create');          // true
$access->can('system:user:create');          // true，大小写不敏感
$access->canAny(['A', 'System:User:List']);  // true
$access->canRoute('GET', '/api/users');      // true
$access->authorize('System:User:Create');    // 不满足时抛 AccessDeniedException

$menus = $access->menus();                   // 前端菜单树
```

## 核心概念

### 只通过角色授权

```text
subjects ──< subject_has_roles >── roles ──< role_has_permissions >── permissions
```

没有 `subject_has_permissions` 表，`Access` 也没有 `givePermissionTo()` 之类的方法。主体只能通过角色获得权限。

### 三类权限

| 类型 | 用途 | 常用字段 |
|---|---|---|
| `menu` | 前端菜单节点 | `path`、`component`、`redirect`、`icon`、`sort`、`hidden`、`meta` |
| `route` | 后端接口权限 | `method`、`path` |
| `button` | 前端按钮/后端动作 | `code` |

只有 `menu` 可以作为父节点。一个菜单下可以挂多条 `route`/`button` 权限，这就是“一个菜单可能有多个权限”的建模方式。

### 菜单可见性

菜单可见的条件：

- 菜单本身被授予；或
- 它的任一后代被授予。

只授予 `System:User:Create` 时，`System` 和 `System:User` 会自动补全。停用的父菜单会隐藏整棵子树；停用的角色或权限不参与授权。

### 大小写

权限码和角色码在 ASCII 范围内大小写不敏感：

- MySQL：`code` 使用 `utf8mb4_unicode_ci`
- SQLite：`code` 使用 `COLLATE NOCASE`
- PHP：用 `strtolower()` 做查找键

数据库中的原始大小写会保留，`codes()`、菜单 `code` 和 `permissions` 输出不会变成小写。SQLite `NOCASE` 只折叠 ASCII，权限码建议只用 ASCII。

## 常用 API

### 权限管理

```php
$permissions = $rbac->permissions();

$permissions->find($id);
$permissions->findByCode('system:user:list');
$permissions->all(PermissionType::Menu);
$permissions->all(PermissionType::Route, true); // 只取启用
$permissions->children($parentId);
$permissions->update($id, ['name' => '新名称', 'status' => 1]);
$permissions->delete($leafId);
```

约束：

- 父节点必须是 menu，或传 `null`/`0` 表示根节点。
- 不能以自己为父节点，也不能形成环。
- 有子节点的权限不能删除。
- 有子节点的 menu 不能改成 route/button。
- `meta` 必须是数组，会编码为 JSON。

### 角色管理

```php
$roles = $rbac->roles();

$role = $roles->create('auditor', '审计员', [
    'description' => '只读审计角色',
    'status' => 1,
    'is_super' => 0,
    'sort' => 10,
]);

$roles->grant($role->id, ['System:User:List', $permissionId, $permissionObject]);
$roles->revoke($role->id, ['System:User:List']);
$roles->syncPermissions($role->id, ['System:User:List']);
$roles->permissionCodes($role->id);
$roles->permissions($role->id);

$roles->assign($role->id, 7);
$roles->unassign($role->id, 7);
$roles->syncSubjectRoles(7, [$roleA->id, $roleB->id]);
$roles->subjectRoles(7);
$roles->subjectCount($role->id);
$roles->delete($role->id);
```

权限引用可以是权限码字符串、权限 ID、数字字符串（按 ID 处理）或 `Permission` 对象。删除角色会在同一事务内清理权限关联和主体分配。

### 鉴权

```php
$access = $rbac->for(7);

$access->codes();
$access->permissions();
$access->routeCodes();
$access->roleCodes();
$access->isSuper();

$access->can('System:User:Create');
$access->canAny(['A', 'B']);
$access->canAll(['A', 'B']);

$access->hasRole('auditor');
$access->hasAnyRole(['auditor', 'admin']);
$access->hasAllRoles(['auditor', 'admin']);

$access->authorize('System:User:Create');
$access->authorizeAny('A', 'B');
```

空集合语义：

- `canAny([])` → `false`
- `canAll([])` → `true`
- `hasAnyRole([])` → `false`
- `hasAllRoles([])` → `true`

超级角色（`is_super=1` 且启用）会绕过权限检查并看到全部启用菜单，但不会绕过 `hasRole()`。

### 路由权限

```php
$access->canRoute('GET', '/api/users');
$access->canRoute('GET', '/api/orders/42');
```

规则：

- `method` 为空或 `*` 匹配所有方法；否则大小写不敏感比较。
- `path` 支持精确路径、`*`、`{id}`、`:id`。
  - `*` 匹配任意字符，包括 `/`。
  - `{id}` / `:id` 只匹配一个路径段。
- 忽略 query string 和 fragment。
- 统一首尾斜杠。
- 路径本身大小写敏感。

关键动作仍建议使用明确的 `can('code')`，不要只依赖 `canRoute()`。

## 菜单树输出

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
    "permissions": ["System:User:List", "System:User:Create"],
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
        "permissions": ["System:User:List", "System:User:Create"],
        "children": []
      }
    ]
  }
]
```

- 只输出启用且可见的 `menu` 节点。
- 每个菜单的 `permissions` 是它子树内已授予的 route/button 权限码。
- ID 输出为字符串，`children` 始终是数组。
- `meta` 原样返回，不自动合并 `icon`/`sort`。

## 配置

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

    'subject_type' => 'user',

    'menu' => [
        'include_permission_codes' => true,
    ],
];
```

- 表名必须是合法 SQL 标识符，不能带库名前缀；可以整体改成 `admin_*` 等。
- 多主体类型时，用 `assign($roleId, $id, 'admin_user')` 和 `for($id, 'admin_user')`。
- `include_permission_codes` 设为 `false` 可省略菜单节点的 `permissions` 字段。

## 迁移

```php
use Skarner\Rbac\Support\Schema;

Schema::up($pdo, $config);
Schema::down($pdo, $config);     // 删除四张表
Schema::sql('mysql', $config);   // 返回不含分号的 DDL 数组
Schema::sql('sqlite', $config);
```

迁移文件返回闭包：

```php
$migration = require __DIR__ . '/database/migrations/2026_10_25_000000_create_rbac_tables.php';
$migration($pdo, $config);
```

支持 `mysql`（含 MariaDB）与 `sqlite`。不建外键，关联表清理由 Manager 在事务内完成。

## 缓存

`Rbac` 实例内使用数组缓存，缓存全量权限、角色→权限映射和主体授权。

通过 `RoleManager` / `PermissionManager` 的写操作会自动清空缓存；`Access` 始终从 `GrantResolver` 读取，所以同一个 `Access` 实例在写操作后也能看到新授权。

绕过本包直接改库不会自动失效，需要手动调用 `$rbac->clearCache()`。本版本没有跨请求持久缓存。

## 测试

```bash
composer install
composer test
```

测试使用 SQLite 内存库，覆盖建表、权限树校验、角色授权、大小写不敏感、菜单祖先补全、路由匹配、缓存失效、自定义表名，以及“没有主体直授权限”的约束。

```
OK (120 tests, 286 assertions)
```

## 发布

发布到 Packagist 的步骤见 [docs/releasing.md](docs/releasing.md)。

## 安全建议

- 所有受保护的路由和动作都必须在服务端鉴权，不能只靠隐藏菜单/按钮。
- 角色和权限管理接口必须单独做管理员鉴权。
- 高风险操作使用明确的 `can('code')`，不要只依赖 `canRoute()`。
- 按设计不存在主体直授权限的入口，角色分配应只走可信的管理流程。

## 许可证

MIT，见 [LICENSE](LICENSE)。
