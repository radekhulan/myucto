<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Export\Instance\CompleteInstanceRestoreService;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Globální řádky instalace (`supplier_id IS NULL`, výchozí předkontace) archiv nenese,
 * cílová DB je má z migrací. Jejich `id` se mezi instalacemi liší podle pořadí migrací,
 * takže firemní předkontace z archivu narazila na globální pravidlo se stejným `id`
 * a ruční obnova (`api/bin/archive-restore.php`) skončila na „Duplicate entry … PRIMARY".
 */
#[Group('integration')]
final class CompleteInstanceRestoreGlobalRowsTest extends TestCase
{
    private const TARGET_DATABASE_PATTERN = '/^myucto_test_global_restore_[0-9a-f]{12}$/D';

    private Config $config;
    private Connection $sourceConnection;
    private InstanceExportService $export;
    private ?PDO $server = null;
    private ?PDO $target = null;
    private string $targetDatabase = '';
    private string $targetStorage = '';
    private int $supplierId = 0;
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $rootDir = Bootstrap::rootDir();
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje lokální testovací DB.');
        }
        $this->config = Config::load($rootDir);
        [$this->sourceConnection, $this->export] = Connection::withoutSharedTestConnection(static function (): array {
            $container = Bootstrap::buildContainer();
            return [$container->get(Connection::class), $container->get(InstanceExportService::class)];
        });
        $this->server = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', (string) $this->config->get('db.host', '127.0.0.1'), (int) $this->config->get('db.port', 3306)),
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->targetDatabase = 'myucto_test_global_restore_' . bin2hex(random_bytes(6));
        $this->targetStorage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->targetDatabase . '_storage';

        $source = $this->sourceConnection->pdo();
        self::assertStringEndsWith('_test', (string) $source->query('SELECT DATABASE()')->fetchColumn());
        $currencyId = (int) ($source->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($source->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $countryId = (int) ($source->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $countryId === 0) {
            $this->markTestSkipped('Testovací DB nemá základní číselníky.');
        }
        $source->beginTransaction();
        $source->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
            VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)')
            ->execute(['Syntetická obnova pravidel s.r.o.', $countryId, 'global-restore@example.test', $currencyId, $vatRateId]);
        $this->supplierId = (int) $source->lastInsertId();
        $source->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_default) VALUES (?, 'CZK', 'Syntetická měna', 'Kč', 'Syntetická měna', 'Synthetic currency', 1)")
            ->execute([$this->supplierId]);
        $source->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $source->lastInsertId(), $this->supplierId]);
    }

    protected function tearDown(): void
    {
        $this->target = null;
        if ($this->server !== null && preg_match(self::TARGET_DATABASE_PATTERN, $this->targetDatabase) === 1) {
            DatabaseCloner::dropDatabase($this->server, $this->targetDatabase);
        }
        $this->removeDirectory($this->targetStorage);
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        if ($this->supplierId > 0) {
            @unlink(RuntimePaths::storage('locks') . '/instance-export-sup' . $this->supplierId . '.lock');
            $this->removeDirectory(RuntimePaths::storage('instance-exports/sup-' . $this->supplierId));
        }
        if (isset($this->sourceConnection)) {
            if ($this->sourceConnection->pdo()->inTransaction()) {
                $this->sourceConnection->pdo()->rollBack();
            }
            $this->sourceConnection->close();
        }
    }

    public function testTenantRowsKeepIdsWhenTargetGlobalRowOccupiesThem(): void
    {
        $source = $this->sourceConnection->pdo();
        $source->prepare("INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority)
            VALUES (?, 'synthetic.tenant.rule', 'Syntetická firemní předkontace', '518', '321', 100)")->execute([$this->supplierId]);
        $tenantRuleId = (int) $source->lastInsertId();

        $result = $this->export->runForSupplier($this->supplierId, [InstanceExportService::PART_RESTORE]);
        $archive = (string) $result['abs_path'];
        $this->temporaryFiles[] = $archive;
        $this->temporaryFiles[] = $archive . '.sha256';

        $this->createTargetDatabase();
        // Čerstvá instalace má globální výchozí pravidlo právě na id firemního pravidla zdroje.
        $this->target->prepare("INSERT INTO posting_rules (id, supplier_id, rule_key, description, debit_account_code, credit_account_code, priority)
            VALUES (?, NULL, 'synthetic.global.rule', 'Syntetické globální pravidlo', '343', '221', 0)")->execute([$tenantRuleId]);

        (new CompleteInstanceRestoreService($this->target, $this->targetStorage, '', false))->restore($archive);

        $tenant = $this->target->prepare('SELECT supplier_id, rule_key FROM posting_rules WHERE id = ?');
        $tenant->execute([$tenantRuleId]);
        self::assertSame(['supplier_id' => $this->supplierId, 'rule_key' => 'synthetic.tenant.rule'],
            array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $tenant->fetch(PDO::FETCH_ASSOC)));
        $global = $this->target->query("SELECT id, supplier_id FROM posting_rules WHERE rule_key = 'synthetic.global.rule'")->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $global, 'Globální pravidlo cílové instalace musí zůstat.');
        self::assertNull($global[0]['supplier_id']);
        self::assertGreaterThan($tenantRuleId, (int) $global[0]['id']);
    }

    private function createTargetDatabase(): void
    {
        self::assertMatchesRegularExpression(self::TARGET_DATABASE_PATTERN, $this->targetDatabase);
        $this->server->exec('CREATE DATABASE `' . $this->targetDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $sourceDatabase = (string) $this->sourceConnection->pdo()->query('SELECT DATABASE()')->fetchColumn();
        $cloner = new DatabaseCloner($this->server);
        $plan = $cloner->plan($sourceDatabase);
        $rebase = fn (string $ddl): string => str_replace(DatabaseCloner::quote($sourceDatabase) . '.', DatabaseCloner::quote($this->targetDatabase) . '.', $ddl);
        $this->server->exec('USE ' . DatabaseCloner::quote($this->targetDatabase));
        $this->server->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($plan['tables'] as $table) {
                $this->server->exec($rebase($table['ddl']));
            }
            foreach (['roles', 'role_permissions'] as $name) {
                $columns = $plan['tables'][$name]['columns'];
                $rows = $this->sourceConnection->pdo()->query('SELECT ' . $columns . ' FROM ' . DatabaseCloner::quote($name))->fetchAll(PDO::FETCH_NUM);
                foreach ($rows as $row) {
                    $this->server->prepare('INSERT INTO ' . DatabaseCloner::quote($name) . ' (' . $columns . ') VALUES ('
                        . implode(', ', array_fill(0, count($row), '?')) . ')')->execute($row);
                }
            }
            $plan['views'] = array_map($rebase, $plan['views']);
            $plan['routines'] = array_map($rebase, $plan['routines']);
            foreach ($plan['triggers'] as &$trigger) {
                $trigger['ddl'] = $rebase($trigger['ddl']);
            }
            unset($trigger);
            $cloner->finalize($plan, $this->targetDatabase);
        } finally {
            $this->server->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
        }
        $this->target = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) $this->config->get('db.host', '127.0.0.1'), (int) $this->config->get('db.port', 3306), $this->targetDatabase),
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        );
        self::assertTrue(@mkdir($this->targetStorage, 0700, true) || is_dir($this->targetStorage));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
