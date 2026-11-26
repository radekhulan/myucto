<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use DI\Container;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\TakenOverPayment;
use MyInvoice\Service\Migration\Pohoda\DocumentLinker;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Shared\IssuedRemainderRepair;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doplnění chráněné platby dorovnání u vydaných dokladů roku agendy převedených z POHODY,
 * jejichž `paid_total` nenesou evidované platby (`api/bin/repair-issued-remainders.php`).
 * Syntetická firma (agenda 2025), vše v transakci, která se na konci vrátí.
 */
#[Group('integration')]
final class IssuedRemainderRepairTest extends TestCase
{
    private Container $c;
    private PDO $pdo;
    private int $supplierId = 0;
    private int $czk = 0;
    private int $clientId = 0;
    private int $userId = 0;
    private int $periodId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        $this->c = Bootstrap::buildContainer();
        $this->pdo = $this->c->get(Connection::class)->pdo();
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0) {
            $this->markTestSkipped('Chybí uživatel (ci-seed).');
        }
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO supplier (company_name, street, city, zip, email, country_id, default_currency_id, default_vat_rate_id, accounting_mode)
            SELECT 'Synteticke dorovnani s.r.o.', 'Testovaci 1', 'Test', '10000', 'dorovnani@example.com',
                   (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1), (SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1),
                   (SELECT id FROM vat_rates ORDER BY id LIMIT 1), 'double_entry'");
        $this->supplierId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, 'CZK', 'CZK', 'Kč', 'CZK', 'CZK', 2, 1, 1)")->execute([$this->supplierId]);
        $this->czk = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
            VALUES (?, 'Odberatel Beta s.r.o.', 'Ulice 1', 'Mesto', '11000', (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1), ?)")->execute([$this->supplierId, $this->czk]);
        $this->clientId = (int) $this->pdo->lastInsertId();
        $this->periodId = $this->c->get(AccountingPeriodRepository::class)->create($this->supplierId, 2025, '2025-01-01', '2025-12-31');
        $this->pdo->prepare("INSERT INTO pohoda_imports (supplier_id, mode, status, agenda_year) VALUES (?, 'import', 'completed', 2025)")->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testUnbackedPaidTotalGetsProtectedRemainderOnce(): void
    {
        $paid = $this->invoice('invoice', 'FV25001', '2025-04-10', 1210.0, 'paid');
        $proforma = $this->invoice('proforma', 'ZL25001', '2025-04-12', 1000.0, 'paid', paidAt: null);
        $partial = $this->invoice('invoice', 'FV25002', '2025-04-14', 400.0, 'sent', total: 1000.0);
        $journal = $this->invoice('invoice', 'FV25003', '2025-04-16', 1210.0, 'paid');
        $locked = $this->invoice('invoice', 'FV25004', '2025-02-10', 1210.0, 'paid');
        $prior = $this->invoice('invoice', 'FV24001', '2024-12-10', 1210.0, 'paid');
        // Úhrada spárovaná s bankou bez záznamu platby: doplní ji párování, ne dorovnání.
        $linked = $this->invoice('invoice', 'FV25005', '2025-04-18', 1210.0, 'paid');
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_number,
                statement_date, prev_balance, curr_balance, credit_total, debit_total, transaction_count)
            VALUES (?, 'import', 'dorovnani.xml', ?, '1000000005', '0100', 'CZK', '1', '2025-04-20', 0, 1210, 1210, 0, 1)")
            ->execute([$this->supplierId, hash('sha256', 'remainder-' . $this->supplierId)]);
        $this->pdo->prepare("INSERT INTO bank_transactions (source, statement_id, posted_at, amount, currency, import_fingerprint, matched_invoice_id, match_status)
            VALUES ('statement', ?, '2025-04-20', 1210, 'CZK', ?, ?, 'manual')")
            ->execute([(int) $this->pdo->lastInsertId(), hash('sha256', 'remainder-tx-' . $this->supplierId), $linked]);
        $this->pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, posted_at) VALUES (?, ?, '2025-04-20', 'manual', NOW())")
            ->execute([$this->supplierId, $this->periodId]);
        $this->pdo->prepare("INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id) VALUES (?, ?, 'invoice', ?)")
            ->execute([$this->supplierId, (int) $this->pdo->lastInsertId(), $journal]);
        $this->pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)')->execute([$this->supplierId, '2025-02-28']);
        $repair = $this->c->get(IssuedRemainderRepair::class);

        $dry = $repair->run(MigrationSources::POHODA, [$this->supplierId], false)[0];
        self::assertSame('would_repair', $dry['status']);
        self::assertSame([
            $paid => IssuedRemainderRepair::FIXABLE,
            $proforma => IssuedRemainderRepair::FIXABLE,
            $partial => IssuedRemainderRepair::FIXABLE,
            $journal => IssuedRemainderRepair::JOURNAL_SETTLED,
            $locked => IssuedRemainderRepair::LOCKED,
            $linked => IssuedRemainderRepair::LINKED_UNRECORDED,
        ], self::categories($dry));
        self::assertArrayNotHasKey($prior, array_column($dry['invoices'], 'category', 'id'), 'Úhrady minulých let řeší jiná oprava.');
        self::assertSame(0, $this->rowCount('invoice_payments', "supplier_id = {$this->supplierId}"));

        $done = $repair->run(MigrationSources::POHODA, [$this->supplierId], true)[0];
        self::assertSame('repaired', $done['status']);
        $payment = $this->pdo->prepare('SELECT paid_on, amount, source, note FROM invoice_payments WHERE invoice_id = ?');
        $payment->execute([$paid]);
        self::assertSame(['paid_on' => '2025-04-10', 'amount' => '1210.00', 'source' => 'settlement', 'note' => DocumentLinker::REMAINDER_NOTE], $payment->fetch(PDO::FETCH_ASSOC));
        $payment->execute([$proforma]);
        self::assertSame('2025-04-12', $payment->fetch(PDO::FETCH_ASSOC)['paid_on'], 'Bez data úhrady se dorovnává k DUZP.');
        foreach ([$journal, $locked, $prior, $linked] as $id) {
            self::assertSame(0, $this->rowCount('invoice_payments', "invoice_id = {$id}"));
        }
        $state = $this->pdo->prepare('SELECT status, paid_total FROM invoices WHERE id = ?');
        $state->execute([$partial]);
        self::assertSame(['status' => 'sent', 'paid_total' => '400.00'], $state->fetch(PDO::FETCH_ASSOC));
        self::assertSame(3, $this->rowCount('activity_log', "action = '" . IssuedRemainderRepair::AUDIT_ACTION . "' AND supplier_id = {$this->supplierId}"));

        // Doplněná platba je chráněná a přepočet plateb uhrazenou část už nesmaže.
        self::assertSame(1, $this->rowCount('invoice_payments', "invoice_id = {$paid} AND " . TakenOverPayment::isProtectedSql('invoice_payments')));
        $this->c->get(InvoicePaymentService::class)->recompute($paid, false);
        $state->execute([$paid]);
        self::assertSame(['status' => 'paid', 'paid_total' => '1210.00'], $state->fetch(PDO::FETCH_ASSOC));

        $again = $repair->run(MigrationSources::POHODA, [$this->supplierId], true)[0];
        self::assertSame([$journal => IssuedRemainderRepair::JOURNAL_SETTLED, $locked => IssuedRemainderRepair::LOCKED,
            $linked => IssuedRemainderRepair::LINKED_UNRECORDED], self::categories($again));
        self::assertSame(3, $this->rowCount('invoice_payments', "supplier_id = {$this->supplierId}"));
    }

    public function testHeliosUnbackedPaidTotalGetsHeliosRemainder(): void
    {
        $paid = $this->invoice('invoice', 'HV25001', '2025-05-10', 1210.0, 'paid', helios: true);
        $pohodaOnly = $this->invoice('invoice', 'FV25101', '2025-05-11', 1210.0, 'paid');
        $repair = $this->c->get(IssuedRemainderRepair::class);
        self::assertContains($this->supplierId, $repair->convertedSuppliers(MigrationSources::HELIOS));

        $dry = $repair->run(MigrationSources::HELIOS, [$this->supplierId], false)[0];
        self::assertSame([$paid => IssuedRemainderRepair::FIXABLE], self::categories($dry), 'Helios vybírá jen doklady z mapy Heliosu.');
        $repair->run(MigrationSources::HELIOS, [$this->supplierId], true);
        $payment = $this->pdo->prepare('SELECT amount, source, note FROM invoice_payments WHERE invoice_id = ?');
        $payment->execute([$paid]);
        self::assertSame(['amount' => '1210.00', 'source' => 'settlement', 'note' => TakenOverPayment::HELIOS_REMAINDER_NOTE], $payment->fetch(PDO::FETCH_ASSOC));
        self::assertTrue(TakenOverPayment::isProtected(['source' => 'settlement', 'note' => TakenOverPayment::HELIOS_REMAINDER_NOTE]));
        self::assertSame(0, $this->rowCount('invoice_payments', "invoice_id = {$pohodaOnly}"));
        self::assertSame([], $repair->run(MigrationSources::HELIOS, [$this->supplierId], false)[0]['invoices']);
    }

    private function invoice(string $type, string $number, string $date, float $paidTotal, string $status, ?string $paidAt = 'date', float $total = 1210.0,
        bool $helios = false): int
    {
        $this->pdo->prepare("INSERT INTO invoices (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date, due_date, currency_id, exchange_rate,
                client_snapshot, supplier_snapshot, total_without_vat, total_vat, total_with_vat, paid_total, paid_at, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, '{}', '{}', ?, 0, ?, ?, ?, ?, ?)")
            ->execute([$this->supplierId, $this->clientId, $number, $type, $date, $date, $date, $this->czk, $total, $total, $paidTotal,
                $status === 'paid' && $paidAt !== null ? $date : null, $status, $this->userId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare($helios
            ? "INSERT INTO helios_import_map (supplier_id, kind, source_key, target_id) VALUES (?, 'invoice', ?, ?)"
            : "INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'invoice', ?, ?)")
            ->execute([$this->supplierId, $number . '|' . $date, $id]);
        return $id;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,string>
     */
    private static function categories(array $row): array
    {
        $out = array_column($row['invoices'], 'category', 'id');
        ksort($out);
        return $out;
    }

    private function rowCount(string $table, string $where): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
    }
}
