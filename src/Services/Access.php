<?php

declare(strict_types=1);

namespace Skarner\Rbac\Services;

use Skarner\Rbac\Exceptions\RbacException;
use Skarner\Rbac\Models\Permission;
use Skarner\Rbac\Models\Subject;

/**
 * 主体只读鉴权入口。
 *
 * Grants 由 GrantResolver 统一缓存；写操作清空 Rbac 缓存后，同一个 Access
 * 实例也能看到新授权。权限/角色码查找大小写不敏感，输出保留数据库原始大小写。
 * 超级权限只影响权限判断，不绕过角色判断。
 */
final class Access
{
    public function __construct(
        private readonly GrantResolver $resolver,
        private readonly MenuTree $menuTree,
        private readonly Subject $subject,
    ) {}

    public function subject(): Subject
    {
        return $this->subject;
    }

    /**
     * 已授予权限码，保持数据库原始大小写与稳定顺序。
     *
     * @return string[]
     */
    public function codes(): array
    {
        return $this->grants()->codes();
    }

    public function can(string $code): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        return $this->grants()->hasCode($code);
    }

    /**
     * @param string[] $codes
     */
    public function canAny(array $codes): bool
    {
        if ($codes === []) {
            return false;
        }

        if ($this->isSuper()) {
            return true;
        }

        foreach ($codes as $code) {
            if ($this->can($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $codes
     */
    public function canAll(array $codes): bool
    {
        if ($codes === []) {
            return true;
        }

        if ($this->isSuper()) {
            return true;
        }

        foreach ($codes as $code) {
            if (! $this->can($code)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Permission[]
     */
    public function permissions(): array
    {
        return $this->grants()->permissionList();
    }

    /**
     * @return string[]
     */
    public function routeCodes(): array
    {
        return $this->grants()->routeCodes();
    }

    public function canRoute(string $method, string $path): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        $method = strtoupper(trim($method));
        $path = self::normalizePath($path);

        foreach ($this->grants()->permissionList() as $permission) {
            if (! $permission->isRoute()) {
                continue;
            }

            $pattern = $permission->path;

            if ($pattern === null || $pattern === '') {
                continue;
            }

            if (! self::methodMatches($permission->method, $method)) {
                continue;
            }

            if (self::pathMatches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 启用角色码，保持数据库原始大小写。
     *
     * @return string[]
     */
    public function roleCodes(): array
    {
        return $this->grants()->roleCodes();
    }

    public function hasRole(string $code): bool
    {
        return $this->grants()->hasRole($code);
    }

    /**
     * @param string[] $codes
     */
    public function hasAnyRole(array $codes): bool
    {
        if ($codes === []) {
            return false;
        }

        foreach ($codes as $code) {
            if ($this->hasRole($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $codes
     */
    public function hasAllRoles(array $codes): bool
    {
        if ($codes === []) {
            return true;
        }

        foreach ($codes as $code) {
            if (! $this->hasRole($code)) {
                return false;
            }
        }

        return true;
    }

    public function isSuper(): bool
    {
        return $this->grants()->isSuper;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function menus(): array
    {
        return $this->menuTree->build($this->subject);
    }

    public function authorize(string ...$codes): void
    {
        if (! $this->canAll($codes)) {
            throw RbacException::accessDenied($this->subject->type, $this->subject->id, $codes);
        }
    }

    public function authorizeAny(string ...$codes): void
    {
        if (! $this->canAny($codes)) {
            throw RbacException::accessDenied($this->subject->type, $this->subject->id, $codes);
        }
    }

    private function grants(): Grants
    {
        return $this->resolver->resolve($this->subject);
    }

    private static function methodMatches(?string $method, string $expected): bool
    {
        if ($method === null) {
            return true;
        }

        $method = trim($method);

        if ($method === '' || $method === '*') {
            return true;
        }

        return strcasecmp($method, $expected) === 0;
    }

    /**
     * 去掉 query/fragment，补齐并修剪斜杠（根路径除外）。
     */
    private static function normalizePath(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH);

        if (is_string($parsed)) {
            $path = $parsed;
        }

        if ($path === '') {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * 支持字面量、`*`、`{id}`、`:id` 三种签名的路径匹配。
     */
    private static function pathMatches(string $pattern, string $path): bool
    {
        $pattern = self::normalizePath($pattern);
        $regex = '';
        $offset = 0;
        preg_match_all(
            '/\{[A-Za-z_][A-Za-z0-9_]*\}|:[A-Za-z_][A-Za-z0-9_]*|\*/',
            $pattern,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[0] as [$token, $position]) {
            $regex .= preg_quote(substr($pattern, $offset, $position - $offset), '#');
            $regex .= $token === '*' ? '.*' : '[^/]+';
            $offset = $position + strlen($token);
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        return preg_match('#^' . $regex . '$#', $path) === 1;
    }
}
