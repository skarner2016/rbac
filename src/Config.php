<?php

declare(strict_types=1);

namespace Skarner\Rbac;

use Skarner\Rbac\Exceptions\RbacException;

/**
 * 配置规范化与校验。
 *
 * 只接受数组配置，不依赖任何框架配置容器；表名在这里一次性校验，
 * 后续 SQL 拼接只使用校验过的标识符。
 */
final class Config
{
    private const DEFAULT_TABLES = [
        'roles' => 'rbac_roles',
        'permissions' => 'rbac_permissions',
        'role_has_permissions' => 'rbac_role_has_permissions',
        'subject_has_roles' => 'rbac_subject_has_roles',
    ];

    private const DEFAULT_MENU = [
        'include_permission_codes' => true,
    ];

    /** @var array<string, string> */
    private readonly array $tables;

    private readonly string $subjectType;

    /** @var array<string, bool> */
    private readonly array $menu;

    /**
     * @param array<string, string> $tables
     * @param array<string, bool> $menu
     */
    private function __construct(array $tables, string $subjectType, array $menu)
    {
        $this->tables = $tables;
        $this->subjectType = $subjectType;
        $this->menu = $menu;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values = []): self
    {
        $tables = self::DEFAULT_TABLES;
        $tablesInput = $values['tables'] ?? [];
        if (! is_array($tablesInput)) {
            throw RbacException::invalidConfig('tables 必须是数组');
        }

        foreach ($tablesInput as $key => $table) {
            if (! is_string($key) || ! array_key_exists($key, self::DEFAULT_TABLES)) {
                throw RbacException::invalidConfig('未知的表配置项：' . (string) $key);
            }
            if (! is_string($table) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
                throw RbacException::invalidConfig("表名必须是合法标识符：{$key}");
            }
            if (strlen($table) > 64) {
                throw RbacException::invalidConfig("表名过长：{$key}");
            }

            $tables[$key] = $table;
        }

        $subjectType = $values['subject_type'] ?? 'user';
        if (! is_string($subjectType) || trim($subjectType) === '' || strlen($subjectType) > 191) {
            throw RbacException::invalidConfig('subject_type 必须是 1-191 字符的非空字符串');
        }

        $menu = self::DEFAULT_MENU;
        $menuInput = $values['menu'] ?? [];
        if (! is_array($menuInput)) {
            throw RbacException::invalidConfig('menu 必须是数组');
        }

        foreach ($menuInput as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, self::DEFAULT_MENU)) {
                throw RbacException::invalidConfig('未知的菜单配置项：' . (string) $key);
            }

            $menu[$key] = (bool) $value;
        }

        return new self($tables, trim($subjectType), $menu);
    }

    public function table(string $key): string
    {
        if (! isset($this->tables[$key])) {
            throw RbacException::invalidConfig("未知的表：{$key}");
        }

        return $this->tables[$key];
    }

    /**
     * @return array<string, string>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function includePermissionCodes(): bool
    {
        return $this->menu['include_permission_codes'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tables' => $this->tables,
            'subject_type' => $this->subjectType,
            'menu' => $this->menu,
        ];
    }
}
