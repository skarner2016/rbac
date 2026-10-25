<?php

declare(strict_types=1);

namespace Skarner\Rbac\Enums;

use Skarner\Rbac\Exceptions\RbacException;

/**
 * 权限分类：菜单、路由、按钮。
 */
enum PermissionType: string
{
    case Menu = 'menu';
    case Route = 'route';
    case Button = 'button';

    public static function parse(self|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom($value) ?? throw RbacException::invalidPermissionType($value);
    }

    public function isMenu(): bool
    {
        return $this === self::Menu;
    }

    public function isRoute(): bool
    {
        return $this === self::Route;
    }

    public function isButton(): bool
    {
        return $this === self::Button;
    }
}
