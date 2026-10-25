<?php

declare(strict_types=1);

namespace Skarner\Rbac\Support;

use PDO;
use PDOException;
use PDOStatement;
use Skarner\Rbac\Config;
use Skarner\Rbac\Exceptions\RbacException;

/**
 * PDO 薄封装。
 *
 * 不修改宿主 PDO 的 ERRMODE 等全局属性；所有 prepare/execute 都检查返回值，
 * 并把 PDOException 统一包装为 RbacException。
 */
final class Database
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {}

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function assertSupportedDriver(): void
    {
        if (! in_array($this->driver(), ['mysql', 'sqlite'], true)) {
            throw RbacException::unsupportedDriver($this->driver());
        }
    }

    /**
     * 返回已引用、已校验的表名。
     */
    public function table(string $key): string
    {
        return $this->quote($this->config->table($key));
    }

    public function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw RbacException::invalidConfig("非法标识符：{$identifier}");
        }

        return $this->driver() === 'mysql'
            ? "`{$identifier}`"
            : "\"{$identifier}\"";
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $rows = $this->prepare($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->prepare($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * 执行写语句并返回受影响行数。
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->prepare($sql, $params)->rowCount();
    }

    /**
     * 执行 INSERT 并返回自增 ID。
     *
     * @param array<int|string, mixed> $params
     */
    public function insert(string $sql, array $params = []): int
    {
        $this->prepare($sql, $params);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 生成 IN 查询的占位符，调用方自行保证 $values 非空。
     *
     * @param array<int, mixed> $values
     */
    public function in(array $values): string
    {
        if ($values === []) {
            throw RbacException::invalidConfig('IN 查询的占位符不能为空。');
        }

        return implode(', ', array_fill(0, count($values), '?'));
    }

    public function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function prepare(string $sql, array $params): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt === false) {
                throw RbacException::database($this->pdoError());
            }

            if ($stmt->execute($params) === false) {
                $info = $stmt->errorInfo();
                throw RbacException::database((string) ($info[2] ?? 'unknown error'));
            }
        } catch (PDOException $e) {
            throw RbacException::database($e->getMessage(), $e);
        }

        return $stmt;
    }

    private function pdoError(): string
    {
        $info = $this->pdo->errorInfo();

        return (string) ($info[2] ?? 'unknown error');
    }
}
