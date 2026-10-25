<?php

declare(strict_types=1);

namespace Skarner\Rbac\Tests\Unit;

use Skarner\Rbac\Exceptions\RbacException;
use Skarner\Rbac\Support\Schema;
use Skarner\Rbac\Tests\TestCase;

final class SchemaTest extends TestCase
{
    public function testMysqlStatements(): void
    {
        $statements = Schema::sql('mysql');

        self::assertCount(4, $statements);
        self::assertStringContainsString('utf8mb4_unicode_ci', $statements[1]);
        self::assertStringContainsString('UNIQUE KEY `rbac_permissions_code_unique`', $statements[1]);
        self::assertStringContainsString('JSON NULL', $statements[1]);
        self::assertStringNotContainsString('FOREIGN KEY', implode("\n", $statements));

        foreach ($statements as $statement) {
            self::assertFalse(str_ends_with($statement, ';'));
        }
    }

    public function testSqliteStatements(): void
    {
        $statements = Schema::sql('sqlite');

        self::assertCount(11, $statements);
        self::assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $statements[0]);
        self::assertStringContainsString('COLLATE NOCASE', $statements[1]);
        self::assertStringContainsString('CREATE UNIQUE INDEX IF NOT EXISTS', implode("\n", $statements));
        self::assertStringContainsString('"rbac_roles"', $statements[0]);
    }

    public function testUnsupportedDriverThrows(): void
    {
        $this->expectException(RbacException::class);

        Schema::sql('pgsql');
    }

    public function testUpIsIdempotent(): void
    {
        Schema::up($this->pdo);
        Schema::up($this->pdo);

        $tables = $this->tableNames();

        foreach (['rbac_roles', 'rbac_permissions', 'rbac_role_has_permissions', 'rbac_subject_has_roles'] as $table) {
            self::assertContains($table, $tables);
        }
    }

    public function testDownDropsAllTables(): void
    {
        Schema::down($this->pdo);

        $tables = array_filter(
            $this->tableNames(),
            static fn (string $table): bool => str_starts_with($table, 'rbac_'),
        );

        self::assertSame([], array_values($tables));
    }

    public function testCustomTableNamesAreCreatedAndDropped(): void
    {
        $config = [
            'tables' => [
                'roles' => 'custom_roles',
                'permissions' => 'custom_permissions',
                'role_has_permissions' => 'custom_role_has_permissions',
                'subject_has_roles' => 'custom_subject_has_roles',
            ],
        ];

        $sql = implode("\n", Schema::sql('sqlite', $config));
        self::assertStringContainsString('"custom_roles"', $sql);
        self::assertStringContainsString('"custom_permissions"', $sql);

        Schema::up($this->pdo, $config);
        self::assertContains('custom_roles', $this->tableNames());
        self::assertContains('custom_permissions', $this->tableNames());

        Schema::down($this->pdo, $config);
        self::assertNotContains('custom_roles', $this->tableNames());
    }
}
