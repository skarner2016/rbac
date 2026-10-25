# Skarner RBAC

[![Latest Version on Packagist](https://img.shields.io/packagist/v/skarner2016/rbac.svg?style=flat-square)](https://packagist.org/packages/skarner2016/rbac)
[![Total Downloads](https://img.shields.io/packagist/dt/skarner2016/rbac.svg?style=flat-square)](https://packagist.org/packages/skarner2016/rbac)
[![PHP Version Require](https://img.shields.io/packagist/php-v/skarner2016/rbac.svg?style=flat-square)](https://packagist.org/packages/skarner2016/rbac)
[![License](https://img.shields.io/packagist/l/skarner2016/rbac.svg?style=flat-square)](https://packagist.org/packages/skarner2016/rbac)
[![Tests](https://github.com/skarner2016/rbac/actions/workflows/tests.yml/badge.svg)](https://github.com/skarner2016/rbac/actions/workflows/tests.yml)

[简体中文](README.zh-CN.md)

A small, framework-agnostic RBAC library for PHP built directly on PDO.

Permissions are granted **only through roles**. There is no user-to-permission
table and no direct grant API. Permissions live in one tree and are classified
as **menu**, **route**, or **button**.

- Zero third-party runtime dependencies: only `ext-pdo` and `ext-json`.
- MySQL/MariaDB and SQLite support.
- Menu tree with automatic ancestor completion.
- Case-insensitive ASCII permission and role codes.
- Route matching with `*`, `{id}`, and `:id`.
- Optional super role and disabled role/permission handling.
- No framework, no service container, no Eloquent.

## Requirements

- PHP 8.2 or newer
- `ext-pdo`
- MySQL 5.7+/8.x, MariaDB 10.2+, or SQLite 3
- MySQL `utf8mb4` is recommended

## Installation

```bash
composer require skarner2016/rbac
```

The package has no runtime dependencies beyond PHP extensions. It does not
publish a service provider or require Laravel.

## Quick start

### 1. Create the schema

```php
use PDO;
use Skarner\Rbac\Support\Schema;

$config = require __DIR__ . '/config/rbac.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app;charset=utf8mb4', $user, $password);
Schema::up($pdo, $config);
```

`Schema::up()` is idempotent. It creates four tables using the names in the
configuration.

### 2. Create the RBAC instance

```php
use Skarner\Rbac\Rbac;

$rbac = new Rbac($pdo, $config);
```

### 3. Create a permission tree

```php
use Skarner\Rbac\Enums\PermissionType;

$permissions = $rbac->permissions();

$system = $permissions->create(PermissionType::Menu, 'System', 'System', [
    'path' => '/system',
    'icon' => 'settings',
    'sort' => 10,
]);

$users = $permissions->create(PermissionType::Menu, 'System:User', 'Users', [
    'parent_id' => $system->id,
    'path' => 'user',
    'component' => 'system/user/index',
    'sort' => 10,
]);

$permissions->create(PermissionType::Route, 'System:User:List', 'List users', [
    'parent_id' => $users->id,
    'method' => 'GET',
    'path' => '/api/users',
]);

$permissions->create(PermissionType::Button, 'System:User:Create', 'Create user', [
    'parent_id' => $users->id,
]);
```

### 4. Grant permissions to a role and assign the role

```php
$role = $rbac->roles()->create('auditor', 'Auditor');

$rbac->roles()->syncPermissions($role->id, [
    'System:User:List',
    'System:User:Create',
]);

// subject_type defaults to "user"
$rbac->roles()->assign($role->id, 7);
```

### 5. Check access

```php
$access = $rbac->for(7);

$access->can('System:User:Create');          // true
$access->can('system:user:create');          // true (case-insensitive)
$access->canAny(['A', 'System:User:List']);  // true
$access->canAll(['System:User:List', 'System:User:Create']); // true
$access->canRoute('GET', '/api/users');      // true
$access->routeCodes();                       // ["System:User:List"]

$access->authorize('System:User:Create');    // throws AccessDeniedException if denied
```

### 6. Return the menu tree

```php
$menus = $rbac->for(7)->menus();
```

The tree contains only `menu` permissions. Each node also lists the granted
`route`/`button` codes below it:

```json
[
  {
    "id": "1",
    "parentId": null,
    "type": "menu",
    "code": "System",
    "name": "System",
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
        "name": "Users",
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

Node IDs are strings so JavaScript consumers do not lose large integers.
`children` is always an array. `meta` is returned as stored; merge it into your
frontend route meta if needed.

## Core concepts

### Role-only grants

```text
subjects ──< subject_has_roles >── roles ──< role_has_permissions >── permissions
```

There is deliberately no `subject_has_permissions` table and no
`$access->givePermissionTo()` method. A subject gets permissions only through
its roles.

### Permission types

| Type | Purpose | Useful fields |
|---|---|---|
| `menu` | Frontend navigation node | `path`, `component`, `redirect`, `icon`, `sort`, `hidden`, `meta` |
| `route` | Backend endpoint guard | `method`, `path` |
| `button` | Frontend action or backend action | `code` |

Only a `menu` can be a parent. A menu can own many `route` and `button`
permissions through `parent_id`, which is what "one menu, multiple permissions"
means in this package.

### Menu visibility

A menu is visible when:

- the menu itself is granted, or
- any of its descendants is granted.

Ancestor menus are added automatically, so granting only
`System:User:Create` still produces the `System` → `System:User` path. A
disabled parent hides the whole subtree. Disabled roles and disabled
permissions never grant access.

### Case-insensitive codes

Permission and role codes are ASCII case-insensitive for lookup and uniqueness:

- MySQL: `utf8mb4_unicode_ci` on `code`
- SQLite: `COLLATE NOCASE` on `code`
- PHP: `strtolower()` lookup keys

Stored and returned codes keep their original casing, so a code created as
`System:User:Create` is still returned that way. SQLite `NOCASE` folds ASCII
only, so keep codes ASCII.

## Configuration

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

- Table names must be valid SQL identifiers and cannot contain a schema prefix.
  They can all be changed, for example to `admin_*` names.
- `subject_type` is an arbitrary discriminator. Use `assign($roleId, $id,
  'admin_user')` and `for($id, 'admin_user')` if your application has more than
  one subject type.
- Set `menu.include_permission_codes` to `false` to omit the `permissions`
  arrays from menu nodes.

## Permission management

```php
$permissions = $rbac->permissions();

$permissions->create(PermissionType::Menu, 'Orders', 'Orders', [
    'path' => '/orders',
    'meta' => ['title' => 'menu.orders', 'order' => 20],
]);

$permissions->update($permissionId, [
    'name' => 'Order management',
    'status' => 1,
    'hidden' => false,
]);

$permissions->find($id);
$permissions->findByCode('orders');
$permissions->all(PermissionType::Menu);
$permissions->all(PermissionType::Route, true); // only enabled
$permissions->children($parentId);
$permissions->delete($leafId);
```

Rules enforced by `PermissionManager`:

- `parent_id` must reference an existing `menu`, or be `null`/`0`.
- A permission cannot be its own parent and cycles are rejected.
- A permission with children cannot be deleted.
- A permission with children cannot change from `menu` to `route`/`button`.
- `meta` must be an array and is stored as JSON.
- `method` is uppercased and limited to 16 characters.

## Role management

```php
$roles = $rbac->roles();

$role = $roles->create('auditor', 'Auditor', [
    'description' => 'Read-only auditor',
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

Permission references accept:

- a permission code string, e.g. `System:User:List`
- a permission ID, e.g. `42`
- a numeric string, e.g. `"42"` (treated as an ID, so avoid purely numeric codes)
- a `Permission` object

Deleting a role removes both its permission links and subject assignments in
one transaction.

## Authorization

```php
$access = $rbac->for(7);

$access->codes();         // all granted codes, original casing
$access->permissions();   // granted Permission DTOs
$access->routeCodes();    // only type=route
$access->roleCodes();     // active role codes
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

Empty-set semantics:

- `canAny([])` is `false`
- `canAll([])` is `true`
- `hasAnyRole([])` is `false`
- `hasAllRoles([])` is `true`

A super role (`is_super = 1`, enabled) bypasses permission checks and sees all
enabled menus. It does not bypass `hasRole()` checks.

`authorize()` and `authorizeAny()` throw
`Skarner\Rbac\Exceptions\AccessDeniedException`, which carries
`subjectType`, `subjectId`, and `codes`.

### Route permissions

`canRoute()` checks only granted `route` permissions:

```php
$access->canRoute('GET', '/api/users');
$access->canRoute('GET', '/api/orders/42');
```

Matching rules:

- `method` empty or `*` matches any method; otherwise comparison is
  case-insensitive.
- `path` supports exact paths, `*`, `{id}`, and `:id`.
  - `*` matches any characters, including `/`.
  - `{id}` and `:id` match exactly one path segment.
- Query strings and fragments are ignored.
- Leading/trailing slashes are normalized.
- Paths are case-sensitive.

Use an explicit `can('code')` check for critical actions. `canRoute()` is a
convenience for endpoint guards, not a replacement for explicit permission
codes.

## Schema and migrations

```php
use Skarner\Rbac\Support\Schema;

Schema::up($pdo, $config);
Schema::down($pdo, $config);     // drops all four tables
Schema::sql('mysql', $config);   // array of DDL statements without semicolons
Schema::sql('sqlite', $config);
```

The migration file returns a closure:

```php
$migration = require __DIR__ . '/database/migrations/2026_10_25_000000_create_rbac_tables.php';
$migration($pdo, $config);
```

Supported drivers: `mysql` (including MariaDB) and `sqlite`. Other drivers throw
`RbacException`. No foreign keys are created; the managers clean pivot rows in
transactions.

## Caching

`Rbac` keeps an in-memory array cache per instance:

- full permission list
- role → permission IDs
- subject grants

All writes through `RoleManager` / `PermissionManager` clear the cache
automatically. `Access` always reads through `GrantResolver`, so the same
`Access` instance sees changes after a write.

Direct database writes bypass the cache. Call `$rbac->clearCache()` if you
modify tables outside this package. There is no persistent/shared cache in this
release.

## Exceptions

| Exception | When |
|---|---|
| `RbacException` | invalid configuration, missing role/permission, duplicate code, invalid parent, permission with children, unsupported driver |
| `AccessDeniedException` | `authorize()` / `authorizeAny()` denied |

`AccessDeniedException` extends `RbacException`.

## Testing

```bash
composer install
composer test
```

The suite runs on SQLite in-memory and covers the schema, permission tree
validation, role grants, case-insensitive lookup, menu ancestor completion,
route matching, cache invalidation, custom table names, and the absence of
direct subject grants.

```
OK (120 tests, 286 assertions)
```

## Releasing

See [docs/releasing.md](docs/releasing.md) for the Packagist release checklist.

## Security

- Enforce authorization on the server for every protected route and action.
  Do not rely on hidden menus or buttons as access control.
- Protect role and permission management endpoints with a separate
  administrator authorization.
- `canRoute()` is a convenience matcher. Use explicit `can('code')` checks for
  high-risk operations.
- Assign roles only through trusted administration flows; there is no direct
  subject-to-permission grant path by design.

## License

MIT. See [LICENSE](LICENSE).
