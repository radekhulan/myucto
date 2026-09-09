<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\Instance\TenantScopeResolver;
use MyInvoice\Service\Export\Instance\TenantTableScope;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class TenantScopeMetadataTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private ?Connection $db = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/cfg.php')) {
            self::markTestSkipped('cfg.php missing');
        }
        $config = Config::load($root);
        $this->server = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                (string) $config->get('db.host', '127.0.0.1'),
                (int) $config->get('db.port', 3306),
            ),
            (string) $config->get('db.user'),
            (string) $config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->database = 'myucto_test_scope_' . bin2hex(random_bytes(6));
        $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $values = $config->all();
        $values['db']['name'] = $this->database;
        $this->db = Connection::withoutSharedTestConnection(
            static fn (): Connection => new Connection(new Config($values)),
        );
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->invalidateSchemaCache();
            $this->db = null;
        }
        if ($this->server !== null && $this->database !== '') {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
        $this->server = null;
    }

    public function testMetadataPreservesTenantFiltersAndExcludesUnsupportedObjects(): void
    {
        $pdo = $this->db->pdo();
        foreach ([
            'CREATE TABLE supplier (id INT PRIMARY KEY)',
            'CREATE TABLE tenant_parent (id INT AUTO_INCREMENT PRIMARY KEY, supplier_id INT NOT NULL, amount INT NOT NULL, calculated INT AS (amount * 2) STORED, UNIQUE KEY parent_pair (id, supplier_id))',
            'CREATE TABLE tenant_child (id INT AUTO_INCREMENT PRIMARY KEY, parent_id INT NOT NULL, CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES tenant_parent(id))',
            'CREATE TABLE tenant_grandchild (id INT AUTO_INCREMENT PRIMARY KEY, child_id INT NOT NULL, CONSTRAINT fk_grandchild_child FOREIGN KEY (child_id) REFERENCES tenant_child(id))',
            'CREATE TABLE tenant_optional (id INT PRIMARY KEY, a_optional INT NULL, z_required INT NOT NULL, CONSTRAINT fk_optional_parent FOREIGN KEY (a_optional) REFERENCES tenant_parent(id), CONSTRAINT fk_required_parent FOREIGN KEY (z_required) REFERENCES tenant_parent(id))',
            'CREATE TABLE composite_only (id INT PRIMARY KEY, parent_id INT NOT NULL, tenant_key INT NOT NULL, CONSTRAINT fk_composite_parent FOREIGN KEY (parent_id, tenant_key) REFERENCES tenant_parent(id, supplier_id))',
            'CREATE TABLE temporal_evidence (id INT PRIMARY KEY, supplier_id INT NOT NULL, amount INT NOT NULL) WITH SYSTEM VERSIONING',
            'CREATE VIEW tenant_view AS SELECT id, supplier_id FROM tenant_parent',
            'INSERT INTO supplier VALUES (11), (22)',
            'INSERT INTO tenant_parent (id, supplier_id, amount) VALUES (101, 11, 10), (202, 22, 20)',
            'INSERT INTO tenant_child VALUES (301, 101), (302, 202)',
            'INSERT INTO tenant_grandchild VALUES (401, 301), (402, 302)',
            'INSERT INTO tenant_optional VALUES (501, NULL, 101), (502, 101, 202)',
        ] as $sql) {
            $pdo->exec($sql);
        }
        $resolver = new TenantScopeResolver($this->db);
        $scopes = $resolver->resolveAll(11);
        self::assertSame([
            'supplier', 'temporal_evidence', 'tenant_parent', 'tenant_child', 'tenant_optional', 'tenant_grandchild',
        ], array_keys($scopes));
        self::assertSame(['id', 'supplier_id', 'amount'], $scopes['tenant_parent']->columns);
        self::assertSame('id', $scopes['tenant_parent']->keysetPk);
        self::assertSame('`id`', $scopes['tenant_parent']->orderBy);
        self::assertSame('supplier_id = ?', $scopes['temporal_evidence']->where);
        self::assertSame('no_tenant_scope', $resolver->skipped()['composite_only']);
        self::assertArrayNotHasKey('tenant_view', $resolver->skipped());
        self::assertSame('`parent_id` IN (SELECT `id` FROM `tenant_parent` WHERE supplier_id = ?)', $scopes['tenant_child']->where);
        self::assertSame('parent_id → tenant_parent.id', $scopes['tenant_child']->via);
        self::assertSame(1, $scopes['tenant_child']->depth);
        self::assertSame('`child_id` IN (SELECT `id` FROM `tenant_child` WHERE `parent_id` IN (SELECT `id` FROM `tenant_parent` WHERE supplier_id = ?))', $scopes['tenant_grandchild']->where);
        self::assertSame('child_id → tenant_child.id', $scopes['tenant_grandchild']->via);
        self::assertSame(2, $scopes['tenant_grandchild']->depth);
        self::assertSame('z_required → tenant_parent.id', $scopes['tenant_optional']->via);
        foreach ($scopes as $scope) {
            self::assertSame([11], $scope->params);
        }
        self::assertSame([301], $this->selectedIds($scopes['tenant_child']));
        self::assertSame([401], $this->selectedIds($scopes['tenant_grandchild']));
        self::assertSame([501], $this->selectedIds($scopes['tenant_optional']));
        $otherScopes = $resolver->resolveAll(22);
        self::assertSame([302], $this->selectedIds($otherScopes['tenant_child']));
        self::assertSame([402], $this->selectedIds($otherScopes['tenant_grandchild']));
        self::assertSame([502], $this->selectedIds($otherScopes['tenant_optional']));
        self::assertSame([22], $otherScopes['tenant_grandchild']->params);
    }

    public function testSameResolverRefreshesAfterExplicitDdlInvalidation(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('CREATE TABLE evidence (id INT PRIMARY KEY, supplier_id INT NOT NULL)');
        $resolver = new TenantScopeResolver($this->db);
        self::assertSame(['id', 'supplier_id'], $resolver->resolveAll(11)['evidence']->columns);
        $generation = $this->db->schemaGeneration();
        $this->server->exec("ALTER TABLE `{$this->database}`.evidence ADD COLUMN note VARCHAR(20) NULL");
        $this->db->invalidateSchemaCache();
        self::assertNotSame($generation, $this->db->schemaGeneration());
        self::assertSame(['id', 'supplier_id', 'note'], $resolver->resolveAll(11)['evidence']->columns);
        $this->server->exec("ALTER TABLE `{$this->database}`.evidence DROP COLUMN supplier_id");
        $this->db->invalidateSchemaCache();
        self::assertSame([], $resolver->resolveAll(11));
        self::assertSame(['evidence' => 'no_tenant_scope'], $resolver->skipped());
    }

    private function selectedIds(TenantTableScope $scope): array
    {
        $statement = $this->db->pdo()->prepare('SELECT id FROM `' . $scope->table . '` WHERE ' . $scope->where . ' ORDER BY id');
        $statement->execute($scope->params);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
