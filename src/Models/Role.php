<?php

declare(strict_types=1);

namespace Skarner\Rbac\Models;

/**
 * 角色只读值对象。
 *
 * 权限只通过角色授予，因此这里没有任何直接关联权限的字段。
 */
final readonly class Role
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $description,
        public int $status,
        public bool $isSuper,
        public int $sort,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['name'],
            isset($row['description']) ? (string) $row['description'] : null,
            (int) $row['status'],
            (bool) (int) $row['is_super'],
            (int) $row['sort'],
            isset($row['created_at']) ? (string) $row['created_at'] : null,
            isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        );
    }

    public function isEnabled(): bool
    {
        return $this->status === 1;
    }

    /**
     * 大小写不敏感查找键（ASCII）。
     */
    public function codeKey(): string
    {
        return strtolower($this->code);
    }
}
