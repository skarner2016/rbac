<?php

declare(strict_types=1);

namespace Skarner\Rbac\Models;

use Skarner\Rbac\Enums\PermissionType;

/**
 * 权限只读值对象。
 *
 * menu 用于前端菜单树，route 只用于后端请求校验，button 用于前端按钮/后端动作。
 */
final readonly class Permission
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public int $id,
        public ?int $parentId,
        public PermissionType $type,
        public string $code,
        public string $name,
        public ?string $description,
        public ?string $path,
        public ?string $component,
        public ?string $redirect,
        public ?string $icon,
        public ?string $method,
        public int $sort,
        public int $status,
        public bool $hidden,
        public array $meta,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $meta = [];
        if (isset($row['meta']) && is_string($row['meta']) && $row['meta'] !== '') {
            $decoded = json_decode($row['meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        return new self(
            (int) $row['id'],
            isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            PermissionType::parse((string) $row['type']),
            (string) $row['code'],
            (string) $row['name'],
            isset($row['description']) ? (string) $row['description'] : null,
            isset($row['path']) ? (string) $row['path'] : null,
            isset($row['component']) ? (string) $row['component'] : null,
            isset($row['redirect']) ? (string) $row['redirect'] : null,
            isset($row['icon']) ? (string) $row['icon'] : null,
            isset($row['method']) ? (string) $row['method'] : null,
            (int) $row['sort'],
            (int) $row['status'],
            (bool) (int) $row['hidden'],
            $meta,
            isset($row['created_at']) ? (string) $row['created_at'] : null,
            isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        );
    }

    public function isEnabled(): bool
    {
        return $this->status === 1;
    }

    public function isMenu(): bool
    {
        return $this->type->isMenu();
    }

    public function isRoute(): bool
    {
        return $this->type->isRoute();
    }

    public function isButton(): bool
    {
        return $this->type->isButton();
    }

    /**
     * 大小写不敏感查找键（ASCII）。
     */
    public function codeKey(): string
    {
        return strtolower($this->code);
    }
}
