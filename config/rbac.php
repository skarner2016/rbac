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
