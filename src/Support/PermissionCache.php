<?php

declare(strict_types=1);

namespace Skarner\Rbac\Support;

/**
 * 单实例内存缓存。
 *
 * 不做跨请求持久化，写操作后由 Rbac::clearCache() 统一失效，避免多进程脏缓存。
 */
final class PermissionCache
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function remember(string $key, callable $loader): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $this->items[$key] = $loader();

        return $this->items[$key];
    }

    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }

    public function clear(): void
    {
        $this->items = [];
    }
}
