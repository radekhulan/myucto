<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Check\Checks\ForeignIssuedInCzkCheck;
use MyInvoice\Service\Migration\Check\MigrationDataCheckRegistry;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaForeignIssuedRepair;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[Group('integration')]
final class PohodaForeignIssuedRepairTest extends TestCase
{
    use OwnedSandboxDatabase;

    private Connection $db;
    private ContainerInterface $container;
    private string $tmp = '';

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $container->set(Config::class, self::sandboxConfig('pohoda_fx_issued_repair'));
        $container->set(Connection::class, $this->db = self::sandboxConnection('pohoda_fx_issued_repair'));
        $this->container = $container;
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_issued_fx_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tmp);
        }
    }

    public function testIssuedRepairKeepsCrownAmountsAndSupportsDryRun(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();

        $ledger = $this->container->get(VatLedgerService::class);
        $dph = $this->container->get(DphPriznaniBuilder::class);
        $kh = $this->container->get(KontrolniHlaseniBuilder::class);
        $dphBefore = $dph->build($supplierId, SyntheticPohodaExport::YEAR, 2, 'monthly')['summary']['lines'];
        $khBefore = $kh->sectionDocuments($supplierId, SyntheticPohodaExport::YEAR, 2)['sections'];
        $journalBefore = $this->journal($supplierId, $id);
        self::assertNotEmpty($journalBefore);

        $registry = $this->container->get(MigrationDataCheckRegistry::class);
        $check = $registry->get(ForeignIssuedInCzkCheck::KEY);
        self::assertInstanceOf(ForeignIssuedInCzkCheck::class, $check);
        $found = $check->detect($supplierId);
        self::assertSame([$id], array_column($found->items, 'entityId'));
        self::assertSame('needs_export', $found->items[0]->details['outcome']);
        self::assertNotEmpty($found->notes);

        $repair = $this->container->get(PohodaForeignIssuedRepair::class);
        self::assertContains($supplierId, $repair->affectedSuppliers());
        $oldInvoice = $this->invoice($id);
        $oldItem = $this->item($id);
        $dryRun = $repair->run($supplierId, $export, false);
        self::assertSame(['convert'], array_column($dryRun, 'status'), json_encode($dryRun, JSON_UNESCAPED_UNICODE));
        self::assertSame([398.09], array_column($dryRun, 'total_foreign'));
        self::assertSame($oldInvoice, $this->invoice($id));
        self::assertSame($oldItem, $this->item($id));
        self::assertSame(0, $this->auditCount($supplierId, $id));

        $committed = $repair->run($supplierId, $export, true);
        self::assertSame(['converted'], array_column($committed, 'status'), json_encode($committed, JSON_UNESCAPED_UNICODE));
        $invoice = $this->invoice($id);
        self::assertSame(['EUR', '25.120000', '398.09', '10000.00', '10000.00'], array_slice(array_values($invoice), 0, 5));
        self::assertStringContainsString('převzat v měně dokladu kurzem 25,12 Kč', $invoice['note_below_items']);
        self::assertSame(['329.00', '69.09', '8264.45', '1735.55'], $this->item($id));
        self::assertSame(1, $this->auditCount($supplierId, $id));

        $rows = iterator_to_array($ledger->iterateRows($supplierId, '2026-02-01', '2026-02-28', false, ['sale' => [$id]]), false);
        self::assertCount(1, $rows);
        self::assertSame(8264.45, $rows[0]['base_czk']);
        self::assertSame(1735.55, $rows[0]['vat_czk']);
        self::assertSame($dphBefore, $dph->build($supplierId, SyntheticPohodaExport::YEAR, 2, 'monthly')['summary']['lines']);
        self::assertSame($khBefore, $kh->sectionDocuments($supplierId, SyntheticPohodaExport::YEAR, 2)['sections']);
        self::assertSame($journalBefore, $this->journal($supplierId, $id));
        self::assertSame([], $repair->run($supplierId, $export, true));
        self::assertSame([], $check->detect($supplierId)->items);
    }

    public function testIssuedRepairLeavesFiledPeriodUnchanged(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, xml_content, xml_size_bytes, xml_sha256, status, submitted_at)
             VALUES (?, 'dphkh1', ?, 2, '<x/>', 4, ?, 'submitted', NOW())"
        )->execute([$supplierId, SyntheticPohodaExport::YEAR, hash('sha256', '<x/>')]);
        $before = $this->invoice($id);

        $repair = $this->container->get(PohodaForeignIssuedRepair::class);
        $result = $repair->run($supplierId, $export, true);
        self::assertSame(['locked'], array_column($result, 'status'));
        self::assertStringContainsString('podaném období DPH', $result[0]['reason']);
        self::assertSame($before, $this->invoice($id));
        self::assertSame(0, $this->auditCount($supplierId, $id));
    }

    public function testIssuedRepairRejectsExistingCrownPaymentWithoutChangingIt(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $pdo = $this->db->pdo();
        $payment = $pdo->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, '2026-02-25', 10, 'CZK', 'manual')"
        );
        $payment->execute([$supplierId, $id]);
        $before = $this->invoice($id);
        $itemBefore = $this->item($id);

        $repair = $this->container->get(PohodaForeignIssuedRepair::class);
        $result = $repair->run($supplierId, $export, true);
        self::assertSame(['review'], array_column($result, 'status'));
        self::assertStringContainsString('evidovanou úhradu', $result[0]['reason']);
        self::assertSame($before, $this->invoice($id));
        self::assertSame($itemBefore, $this->item($id));
        $stmt = $pdo->prepare('SELECT amount, currency FROM invoice_payments WHERE invoice_id = ?');
        $stmt->execute([$id]);
        self::assertSame([['10.00', 'CZK']], $stmt->fetchAll(PDO::FETCH_NUM));
    }

    public function testIssuedRepairRollsBackIfEvenOneCentOfVatWouldChange(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $before = $this->invoice($id);
        $itemBefore = $this->item($id);
        $pdo = $this->db->pdo();
        $pdo->exec('CREATE TRIGGER test_issued_fx_vat_difference BEFORE UPDATE ON invoice_items FOR EACH ROW
            SET NEW.import_tax_vat_czk = NEW.import_tax_vat_czk + 0.01');
        try {
            $repair = $this->container->get(PohodaForeignIssuedRepair::class);
            $result = $repair->run($supplierId, $export, true);
            self::assertSame(['review'], array_column($result, 'status'));
            self::assertStringContainsString('evidenci DPH', $result[0]['reason']);
            self::assertSame($before, $this->invoice($id));
            self::assertSame($itemBefore, $this->item($id));
            self::assertSame(0, $this->auditCount($supplierId, $id));
        } finally {
            $pdo->exec('DROP TRIGGER test_issued_fx_vat_difference');
        }
    }

    /** @return array{int,int,PohodaExport} */
    private function legacyInvoice(): array
    {
        $pdo = $this->db->pdo();
        $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $anyCurrencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        foreach ([$userId, $czId, $anyCurrencyId, $vatRateId] as $required) {
            self::assertGreaterThan(0, $required, 'Chybí základní data testovací databáze.');
        }
        $ico = SyntheticPohodaExport::ICO;
        $pdo->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer,
                                   default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, 'Účetní 12', 'Brno', '60200', ?, 'prevod@example.invalid', ?, ?, 1, ?, ?, 'tax_evidence')"
        )->execute([SyntheticPohodaExport::NAME, $czId, $ico, 'CZ' . $ico, $anyCurrencyId, $vatRateId]);
        $supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$supplierId]);
        $czk = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$czk, $supplierId]);
        $pdo->prepare("INSERT INTO pohoda_imports (supplier_id, mode, status, agenda_ico, agenda_year) VALUES (?, 'import', 'completed', ?, ?)")
            ->execute([$supplierId, $ico, SyntheticPohodaExport::YEAR]);
        $runId = (int) $pdo->lastInsertId();

        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withForeignCurrencyDocuments($dir);
        $export = PohodaExport::open($dir);
        $protocol = $this->container->get(PohodaImporter::class)->run($supplierId, $userId, $export, false, $runId);
        self::assertFalse($protocol->hasErrors(), json_encode($protocol->toArray(), JSON_UNESCAPED_UNICODE));

        $stmt = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$supplierId, SyntheticPohodaExport::FOREIGN_MISMATCH]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id);
        $pdo->prepare(
            "UPDATE invoices SET currency_id = ?, exchange_rate = NULL, total_without_vat = 8264.45, total_vat = 1735.55, total_with_vat = 10000.00,
                    import_total_with_vat_czk = NULL, import_total_without_vat_czk = NULL, import_total_vat_czk = NULL,
                    import_amount_to_pay_czk = NULL, import_advance_paid_czk = NULL,
                    note_below_items = 'Převzato z Pohody, doklad 26FV0021; doklad v EUR, převzat v Kč (základ 1. položky 329,00 × kurz 25,12 nedává 8 264,45 Kč ze zdroje)'
              WHERE supplier_id = ? AND id = ?"
        )->execute([$czk, $supplierId, $id]);
        $pdo->prepare(
            'UPDATE invoice_items SET unit_price_without_vat = 8264.45, total_without_vat = 8264.45, total_vat = 1735.55, total_with_vat = 10000.00,
                    import_tax_base_czk = NULL, import_tax_vat_czk = NULL WHERE invoice_id = ?'
        )->execute([$id]);

        return [$supplierId, $id, $export];
    }

    private function invoice(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.code, i.exchange_rate, i.total_with_vat, i.import_total_with_vat_czk, i.import_amount_to_pay_czk, i.note_below_items
               FROM invoices i JOIN currencies c ON c.id = i.currency_id WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function item(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT total_without_vat, total_vat, import_tax_base_czk, import_tax_vat_czk FROM invoice_items WHERE invoice_id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_NUM);
    }

    private function journal(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.* FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id
              WHERE e.supplier_id = ? AND e.source_type = 'invoice' AND e.source_id = ? ORDER BY l.line_no"
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function auditCount(int $supplierId, int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ? AND entity_id = ?');
        $stmt->execute([$supplierId, PohodaForeignIssuedRepair::AUDIT_ACTION, $id]);
        return (int) $stmt->fetchColumn();
    }
}
