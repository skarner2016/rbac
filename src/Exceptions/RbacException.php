<?php

declare(strict_types=1);

namespace Skarner\Rbac\Exceptions;

use RuntimeException;
use Throwable;

/**
 * RBAC 领域异常。
 */
class RbacException extends RuntimeException
{
    public static function invalidConfig(string $message): self
    {
        return new self($message);
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("不支持的数据库驱动：{$driver}");
    }

    public static function roleNotFound(int|string $id): self
    {
        return new self("角色不存在：{$id}");
    }

    public static function roleCodeNotFound(string $code): self
    {
        return new self("角色码不存在：{$code}");
    }

    public static function permissionNotFound(int|string $id): self
    {
        return new self("权限不存在：{$id}");
    }

    public static function permissionCodeNotFound(string $code): self
    {
        return new self("权限码不存在：{$code}");
    }

    public static function duplicateRoleCode(string $code): self
    {
        return new self("角色码已存在：{$code}");
    }

    public static function duplicatePermissionCode(string $code): self
    {
        return new self("权限码已存在：{$code}");
    }

    public static function invalidPermissionType(string $type): self
    {
        return new self("非法权限类型：{$type}");
    }

    public static function invalidParent(string $reason): self
    {
        return new self($reason);
    }

    public static function permissionHasChildren(int $id): self
    {
        return new self("权限 {$id} 存在子节点，不能删除。");
    }

    public static function cannotChangeTypeWithChildren(int $id): self
    {
        return new self("权限 {$id} 存在子节点，不能改为 route/button。");
    }

    public static function invalidSubject(): self
    {
        return new self('subject_type 和 subject_id 不能为空。');
    }

    public static function invalidPermissionReference(): self
    {
        return new self('权限引用必须是权限码、权限 ID 或 Permission 对象。');
    }

    /**
     * @param string[] $codes
     */
    public static function accessDenied(string $subjectType, string $subjectId, array $codes): AccessDeniedException
    {
        return new AccessDeniedException($subjectType, $subjectId, $codes);
    }

    public static function database(string $message, ?Throwable $previous = null): self
    {
        return new self("数据库操作失败：{$message}", 0, $previous);
    }
}
