<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `api/bin/migration-data-check.php`: běh nanečisto nic nemění, `--fix` opraví a opakovaný
 * běh už nic nenajde. Skript běží jako samostatný proces nad testovací databází, proto je
 * syntetická firma uložená a na konci se smaže.
 */
#[Group('integration')]
final class MigrationDataCheckCliTest extends TestCase
{
    private PDO $pdo;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        $c = Bootstrap::buildContainer();
        $this->pdo = $c->get(Connection::class)->pdo();
        $user = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($user === 0) {
            $this->markTestSkipped('Chybí uživatel (ci-seed).');
        }
        $this->pdo->exec("INSERT INTO supplier (company_name, street, city, zip, email, country_id, default_currency_id, default_vat_rate_id, accounting_mode)
            SELECT 'Synteticka migrace CLI s.r.o.', 'Testovaci 2', 'Test', '10000', 'migrace-cli@example.com',
                   (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1), (SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1),
                   (SELECT id FROM vat_rates ORDER BY id LIMIT 1), 'double_entry'");
        $this->supplierId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, 'CZK', 'CZK', 'Kč', 'CZK', 'CZK', 2, 1, 1)")->execute([$this->supplierId]);
        $czk = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
            VALUES (?, 'Dodavatel Beta s.r.o.', 'Ulice 2', 'Mesto', '11000', (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1), ?)")->execute([$this->supplierId, $czk]);
        $vendor = (int) $this->pdo->lastInsertId();
        $c->get(AccountingPeriodRepository::class)->create($this->supplierId, 2025, '2025-01-01', '2025-12-31');
        $this->pdo->prepare("INSERT INTO pohoda_imports (supplier_id, mode, status, agenda_year) VALUES (?, 'import', 'completed', 2025)")->execute([$this->supplierId]);
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                received_at, currency_id, exchange_rate, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status, paid_at, created_by)
            VALUES (?, ?, 'DF-CLI-1', 'invoice', '2025-03-01', '2025-03-01', '2025-03-15', '2025-03-01', ?, 1, '{}', 750, 0, 750, 'paid', '2025-03-10', ?)")
            ->execute([$this->supplierId, $vendor, $czk, $user]);
        $this->pdo->prepare("INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'purchase_invoice', 'DF-CLI-1', ?)")
            ->execute([$this->supplierId, (int) $this->pdo->lastInsertId()]);
    }

    protected function tearDown(): void
    {
        if ($this->supplierId === 0) {
            return;
        }
        foreach (['activity_log', 'pohoda_import_map', 'pohoda_imports', 'purchase_invoices', 'accounting_periods', 'clients', 'currencies'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE supplier_id = ?")->execute([$this->supplierId]);
        }
        $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
    }

    public function testDryRunFixAndRerun(): void
    {
        [$code, $out] = $this->cli(['--all']);
        self::assertSame(2, $this->cli([])[0], 'Bez --supplier ani --all skript odmítne běh.');
        self::assertSame(2, $this->cli(["--supplier={$this->supplierId}", '--check=unknown'])[0]);
        self::assertSame(0, $code, $out);

        [$code, $out] = $this->cli(["--supplier={$this->supplierId}", '--verbose']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('[NANEČISTO]', $out);
        self::assertMatchesRegularExpression('/purchase_settled_uncovered\s+k opravě\s+nálezů 1 \(k opravě 1/u', $out);
        self::assertStringContainsString('DF-CLI-1', $out);
        self::assertSame('0.00', $this->outside());

        [$code, $out] = $this->cli(["--supplier={$this->supplierId}", '--check=purchase_settled_uncovered', '--fix']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('opraveno 1 nálezů, 750,00 Kč', $out);
        self::assertSame('750.00', $this->outside());

        [$code, $out] = $this->cli(["--supplier={$this->supplierId}", '--check=purchase_settled_uncovered', '--json']);
        self::assertSame(0, $code, $out);
        $json = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $json[0]['checks'][0]['status']);
        self::assertSame(['pohoda'], $json[0]['sources']);
    }

    private function outside(): string
    {
        $stmt = $this->pdo->prepare('SELECT settled_outside_amount FROM purchase_invoices WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        return (string) $stmt->fetchColumn();
    }

    /**
     * @param list<string> $args
     * @return array{0:int,1:string}
     */
    private function cli(array $args): array
    {
        $script = dirname(__DIR__, 3) . '/bin/migration-data-check.php';
        $env = getenv();
        $env['MYINVOICE_DB_NAME'] = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $process = proc_open(array_merge([PHP_BINARY, $script], $args), [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out];
    }
}
