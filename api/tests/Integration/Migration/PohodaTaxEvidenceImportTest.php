<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\AccountingUnitSwitch;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\TaxEvidence\CashJournalService;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Agenda POHODY vedená v daňové evidenci ({@see SyntheticPohodaExport::writeTaxEvidence()})
 * se převede do firmy v daňové evidenci: doklady, banka a úhrady bez jakéhokoli účtování,
 * firma zůstane v daňové evidenci a automatika účtování se nemění. Přijatá faktura
 * uhrazená bankou je v peněžním deníku jednou. Izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class PohodaTaxEvidenceImportTest extends TestCase
{
    private Connection $db;
    private PohodaImporter $importer;
    private AccountingUnitSwitch $unit;
    private CashJournalService $cashJournal;
    private string $tmp = '';
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PohodaImporter::class);
            $this->unit = $container->get(AccountingUnitSwitch::class);
            $this->cashJournal = $container->get(CashJournalService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('pohoda_import_map', 'pohoda_key')) {
            $this->markTestSkipped('Chybí migrace pohoda_import_map.');
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_de_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testTaxEvidenceAgendaStaysTaxEvidenceWithoutAccounting(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::writeTaxEvidence($this->tmp));
        self::assertTrue(PohodaImporter::isTaxEvidenceAgenda($export));
        $supplierId = $this->supplier();
        $automation = $this->automation($supplierId);

        $preflight = $this->importer->preflight($supplierId, $export);
        $codes = array_column($preflight, 'code');
        self::assertContains('tax_evidence_kept', $codes, json_encode($preflight, JSON_UNESCAPED_UNICODE));
        self::assertNotContains('switch_to_double_entry', $codes);
        self::assertNotContains('file_state', $codes);
        self::assertSame([], array_values(array_filter($preflight, static fn (array $m): bool => $m['level'] === 'error')));

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->assertNoAccounting($supplierId);
        self::assertSame($automation, $this->automation($supplierId));
        self::assertSame('off', $protocol->get('automation')['during']);
        self::assertFalse($protocol->get('automation')['restored']);
        self::assertSame(PohodaImporter::stepKeys(true), array_values(array_diff(array_column($protocol->toArray()['steps'], 'key'), [PohodaImporter::STEP_PREFLIGHT])));

        // Přijatá faktura uhrazená bankou: vazba na pohyb a zaplaceno k datu pohybu.
        $purchase = $this->row('SELECT pi.id, pi.status, pi.paid_at, (SELECT COUNT(*) FROM payment_matches pm WHERE pm.purchase_invoice_id = pi.id) AS matches
            FROM purchase_invoices pi WHERE pi.supplier_id = ? AND pi.varsymbol = ?', [$supplierId, '26PF0001']);
        self::assertSame('paid', $purchase['status']);
        self::assertSame('2026-01-20', substr((string) $purchase['paid_at'], 0, 10));
        self::assertSame(1, (int) $purchase['matches']);
        // Vydaná faktura uhrazená bankou: evidovaná platba s vazbou na pohyb.
        $issued = $this->row("SELECT i.status, i.paid_total, (SELECT COUNT(*) FROM invoice_payments ip WHERE ip.invoice_id = i.id AND ip.source = 'bank' AND ip.bank_transaction_id IS NOT NULL) AS payments
            FROM invoices i WHERE i.supplier_id = ? AND i.varsymbol = ?", [$supplierId, '26FV0001']);
        self::assertSame('paid', $issued['status']);
        self::assertSame(1210.0, (float) $issued['paid_total']);
        self::assertSame(1, (int) $issued['payments']);
        // Neuhrazená faktura minulého roku zůstává neuhrazená.
        self::assertSame('sent', $this->row('SELECT status FROM invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, '25FV0099'])['status']);

        // Peněžní deník: výdaj za přijatou fakturu jednou (bankovní pohyb), žádný virtuální navíc.
        $journal = $this->cashJournal->build($supplierId, '2026-01-01', '2026-12-31', ['year' => 2026]);
        $rows = array_values(array_filter($journal['rows'], static fn (array $r): bool => $r['purchase_invoice_id'] === (int) $purchase['id']));
        self::assertCount(1, $rows, json_encode($rows, JSON_UNESCAPED_UNICODE));
        self::assertSame(605.0, (float) $rows[0]['expense']);
        self::assertSame('bank', $rows[0]['source_type']);

        // Poplatek bez dokladu převod nezařazuje, protokol ho hlásí k ručnímu zařazení.
        self::assertSame(1, self::stepCounts($protocol, 'tax_evidence_reconciliation')['bank_unclassified'] ?? null, $this->explain($protocol));

        // Opakovaný převod nic nezdvojí.
        $counts = $this->counts($supplierId);
        $again = $this->importer->run($supplierId, $this->userId, PohodaExport::open($export->dir), false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($counts, $this->counts($supplierId));
        $this->assertNoAccounting($supplierId);
    }

    /**
     * Doklad, který POHODA vede jako uhrazený bez pohybu peněz (zápočet), zůstane neuhrazený:
     * peněžní deník by jinak započetl jeho datum úhrady jako virtuální výdaj, ačkoli peníze
     * neodešly. Úhrada z minulého roku je u přeneseného dokladu vyrovnáním mimo peněžní deník.
     */
    public function testPaymentsWithoutMovementAreNotCountedInCashJournal(): void
    {
        $dir = SyntheticPohodaExport::writeTaxEvidence($this->tmp);
        SyntheticPohodaExport::withPurchaseSettledByOffset($dir);
        SyntheticPohodaExport::withPurchasePaidEarlier($dir);
        $supplierId = $this->supplier();

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $offset = $this->row('SELECT id, status, paid_at FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::PURCHASE_SETTLED_BY_OFFSET]);
        self::assertNull($offset['paid_at']);
        self::assertContains('payment_without_movement', array_column($this->messages($protocol), 'code'));
        $earlier = $this->row('SELECT id, paid_at, settled_outside_amount FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::PURCHASE_PAID_EARLIER]);
        self::assertNull($earlier['paid_at']);
        self::assertSame(600.0, (float) $earlier['settled_outside_amount']);

        $journal = $this->cashJournal->build($supplierId, '2025-01-01', '2026-12-31', ['year' => 2026]);
        $ids = [(int) $offset['id'], (int) $earlier['id']];
        self::assertSame([], array_values(array_filter($journal['rows'], static fn (array $r): bool => in_array($r['purchase_invoice_id'], $ids, true))));
    }

    /**
     * Pokladna bez zápisů v deníku (agenda daňové evidence deník nemá): převod ji založí
     * podle jejích dokladů a převezme výdaj i příjem. Výdajový doklad uhradí přijatou fakturu
     * a peněžní deník ji započte jednou, pokladním řádkem. Dřív převod pokladnu přeskočil
     * a všechny pokladní doklady hlásil „nemá pokladnu, nepřevzat".
     */
    public function testCashRegisterWithoutJournalIsImported(): void
    {
        $dir = SyntheticPohodaExport::writeTaxEvidence($this->tmp);
        SyntheticPohodaExport::withPurchasePaidByCash($dir);
        $supplierId = $this->supplier();

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertNotContains('register_missing', array_column($this->messages($protocol), 'code'), $this->explain($protocol));
        self::assertSame(1, (int) $this->row('SELECT COUNT(*) AS n FROM cash_registers WHERE supplier_id = ?', [$supplierId])['n']);

        $purchase = $this->row('SELECT id, status, paid_at FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?', [$supplierId, SyntheticPohodaExport::PURCHASE_PAID_BY_CASH]);
        self::assertSame('paid', $purchase['status']);
        self::assertSame('2026-02-15', substr((string) $purchase['paid_at'], 0, 10));
        $payment = $this->row('SELECT purchase_invoice_id, purpose FROM cash_documents WHERE supplier_id = ? AND doc_number LIKE ?', [$supplierId, SyntheticPohodaExport::CASH_PAYMENT . '%']);
        self::assertSame((int) $purchase['id'], (int) $payment['purchase_invoice_id']);
        self::assertSame('purchase_payment', $payment['purpose']);

        $journal = $this->cashJournal->build($supplierId, '2026-01-01', '2026-12-31', ['year' => 2026]);
        $rows = array_values(array_filter($journal['rows'], static fn (array $r): bool => $r['purchase_invoice_id'] === (int) $purchase['id']));
        self::assertCount(1, $rows, json_encode($rows, JSON_UNESCAPED_UNICODE));
        self::assertSame('cash', $rows[0]['source_type']);
        self::assertSame(400.0, (float) $rows[0]['expense']);
        $receipts = array_values(array_filter($journal['rows'], static fn (array $r): bool => $r['source_type'] === 'cash' && (float) ($r['income'] ?? 0) === 300.0));
        self::assertCount(1, $receipts, json_encode($journal['rows'], JSON_UNESCAPED_UNICODE));
    }

    public function testDryRunLeavesNothing(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::writeTaxEvidence($this->tmp));
        $supplierId = $this->supplier();
        $before = $this->counts($supplierId);

        $protocol = $this->importer->run($supplierId, $this->userId, $export, true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertGreaterThan(0, self::stepCounts($protocol, 'purchase_invoices')['created'] ?? 0);
        self::assertSame($before, $this->counts($supplierId));
        $this->assertNoAccounting($supplierId);
    }

    public function testLegalEntityIsRefused(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::writeTaxEvidence($this->tmp));
        $supplierId = $this->supplier('po');

        $codes = array_column($this->importer->preflight($supplierId, $export), 'code');
        self::assertContains('tax_evidence_legal_entity', $codes);
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertTrue($protocol->hasErrors());
        self::assertSame(['purchase_invoices' => 0, 'invoices' => 0, 'bank_transactions' => 0, 'map' => 0], $this->counts($supplierId));
    }

    public function testDoubleEntryTargetIsRefused(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::writeTaxEvidence($this->tmp));
        $supplierId = $this->supplier();
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode) VALUES (?, '2026-01-01', 'double_entry')")
            ->execute([$supplierId]);

        $preflight = $this->importer->preflight($supplierId, $export);
        $codes = array_column($preflight, 'code');
        self::assertContains('target_double_entry', $codes, json_encode($preflight, JSON_UNESCAPED_UNICODE));
        self::assertNotContains('tax_evidence_kept', $codes);
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertTrue($protocol->hasErrors());
        self::assertSame(0, $this->counts($supplierId)['invoices']);
    }

    private function assertNoAccounting(int $supplierId): void
    {
        $supplier = $this->row('SELECT accounting_mode, accounting_activation_status FROM supplier WHERE id = ?', [$supplierId]);
        self::assertSame('tax_evidence', $supplier['accounting_mode']);
        self::assertSame('none', $supplier['accounting_activation_status']);
        foreach (['accounting_periods', 'chart_of_accounts', 'journal_entries', 'posting_rules'] as $table) {
            self::assertSame(0, (int) $this->row("SELECT COUNT(*) AS n FROM {$table} WHERE supplier_id = ?", [$supplierId])['n'], $table);
        }
        self::assertSame(0, (int) $this->row('SELECT COUNT(*) AS n FROM invoices WHERE supplier_id = ? AND booked_at IS NOT NULL', [$supplierId])['n']);
        self::assertSame(0, (int) $this->row('SELECT COUNT(*) AS n FROM purchase_invoices WHERE supplier_id = ? AND booked_at IS NOT NULL', [$supplierId])['n']);
    }

    /** @return array<string,mixed> */
    private function automation(int $supplierId): array
    {
        return $this->unit->snapshot($supplierId)
            + $this->row('SELECT auto_post_invoices, auto_post_purchases FROM supplier WHERE id = ?', [$supplierId]);
    }

    /** @return array<string,int> */
    private function counts(int $supplierId): array
    {
        return [
            'purchase_invoices' => (int) $this->row('SELECT COUNT(*) AS n FROM purchase_invoices WHERE supplier_id = ?', [$supplierId])['n'],
            'invoices' => (int) $this->row('SELECT COUNT(*) AS n FROM invoices WHERE supplier_id = ?', [$supplierId])['n'],
            'bank_transactions' => (int) $this->row('SELECT COUNT(*) AS n FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ?', [$supplierId])['n'],
            'map' => (int) $this->row('SELECT COUNT(*) AS n FROM pohoda_import_map WHERE supplier_id = ?', [$supplierId])['n'],
        ];
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, $sql);
        return $row;
    }

    private function supplier(?string $taxpayerType = 'fo'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode, taxpayer_type)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence", ?)'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, SyntheticPohodaExport::ICO, 'CZ' . SyntheticPohodaExport::ICO, $this->currencyId, $this->vatRateId, $taxpayerType]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    /** @return list<array<string,mixed>> zprávy všech kroků i kontroly před převodem */
    private function messages(ImportProtocol $protocol): array
    {
        $out = (array) $protocol->get('preflight');
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ((array) ($step['messages'] ?? []) as $m) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /** @return array<string,int|float> */
    private static function stepCounts(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step['counts'];
            }
        }
        return [];
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
