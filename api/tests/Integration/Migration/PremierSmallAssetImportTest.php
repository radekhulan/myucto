<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Assets\DisposalResiduals;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Drobný a ostatní majetek z PREMIER nad skutečnou DB: evidence odvozená z účtu položek
 * přijatých faktur (bez vlastní evidence v záloze), převzetí vlastní evidence 1:1
 * (`MAJ_OST`, karty řad DH/DN), ostatní registry (leasing…) a dlouhodobé karty registru
 * `MAJ_H`. Izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class PremierSmallAssetImportTest extends TestCase
{
    private Connection $db;
    private PremierImporter $importer;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
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
            $this->importer = $container->get(PremierImporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if (!$this->db->hasColumn('premier_import_map', 'premier_key') || !$this->db->hasColumn('small_assets', 'asset_kind')) {
            $this->markTestSkipped('Chybí migrace (premier_import_map / small_assets.asset_kind).');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_sa_int_' . bin2hex(random_bytes(5));
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

    /**
     * Bez vlastní evidence: položka na účtu „dr. majetek" od 1 000 Kč za kus dostane kartu,
     * levnější zůstane materiálem; dobropis vracející věc kartu vyřadí; odpočet zálohy (314)
     * se neoznačí, takže karta konečné faktury má plnou cenu. Opakovaný převod nic nezdvojí.
     */
    public function testDerivedSmallAssetsFromPurchaseItemsAndCreditNoteDisposal(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup(['small_assets' => true]);

        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $this->assertReconciled($first);

        self::assertSame([
            ['PF250005/2025', 'invoice', 'Notebook', '15000.00', 'small_asset'],
            ['PF250005/2025', 'invoice', 'Myš', '500.00', 'material'],
            ['PF250006/2025', 'credit_note', 'Notebook', '-15000.00', 'small_asset'],
            ['PF250007/2025', 'invoice', 'Monitor', '20000.00', 'small_asset'],
            ['PF250007/2025', 'invoice', 'Odpočet zálohy', '-5000.00', null],
        ], $this->fetch("SELECT p.varsymbol, p.document_kind, it.description, it.total_without_vat, it.expense_kind
                           FROM purchase_invoice_items it JOIN purchase_invoices p ON p.id = it.purchase_invoice_id
                          WHERE p.supplier_id = ? AND p.varsymbol IN ('PF250005/2025', 'PF250006/2025', 'PF250007/2025')
                          ORDER BY p.varsymbol, it.order_index", $supplierId), $this->explain($first));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM purchase_invoice_items it JOIN purchase_invoices p ON p.id = it.purchase_invoice_id
            WHERE p.supplier_id = ? AND p.varsymbol NOT IN ('PF250005/2025', 'PF250006/2025', 'PF250007/2025') AND it.expense_kind IS NOT NULL", $supplierId),
            'Položky na jiných účtech se neoznačují.');
        self::assertSame(3, self::stepCounts($first, 'purchase_invoices')['small_asset_items'] ?? 0, $this->explain($first));

        // Karta s plnou cenou položky (ne snížená o odpočet zálohy) a vyřazený vrácený notebook.
        self::assertSame([
            ['Monitor', 'tangible', '2025-11-05', '1.000', '20000.000000', '20000.00', 'in_use', null, 'PF250007/2025'],
            ['Notebook', 'tangible', '2025-09-10', '1.000', '15000.000000', '15000.00', 'disposed', '2025-10-01', 'PF250005/2025'],
        ], $this->cards($supplierId), $this->explain($first));
        $counts = self::stepCounts($first, 'small_assets');
        self::assertSame([2, 1], [$counts['created'] ?? 0, $counts['disposed_by_credit_note'] ?? 0], $this->explain($first));
        self::assertNotContains('return_not_matched', $this->messageCodes($first));

        // Opakovaný převod: žádná nová karta, vyřazení ani upozornění.
        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = self::stepCounts($again, 'small_assets');
        self::assertSame([0, 3], [$counts['created'] ?? 0, $counts['existing'] ?? 0], $this->explain($again));
        self::assertArrayNotHasKey('disposed_by_credit_note', $counts, $this->explain($again));
        self::assertArrayNotHasKey('returns_review', $counts, $this->explain($again));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM small_assets WHERE supplier_id = ?', $supplierId));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM premier_import_map WHERE supplier_id = ? AND kind = 'small_asset' AND premier_key LIKE 'return|%'", $supplierId),
            'Vyřízený dobropis se pamatuje v mapě pod klíčem return|….');
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM small_assets WHERE supplier_id = ? AND status = 'disposed'", $supplierId));
    }

    public function testCreditNoteWithoutMatchingCardWarnsOnlyOnce(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup(['small_assets' => true, 'unmatched_return' => true]);

        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        self::assertSame(1, array_count_values($this->messageCodes($first))['return_not_matched'] ?? 0, $this->explain($first));
        self::assertSame(1, self::stepCounts($first, 'small_assets')['returns_review'] ?? 0);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM small_assets WHERE supplier_id = ? AND status = 'disposed'", $supplierId), 'Nic se nevyřadí naslepo.');

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertNotContains('return_not_matched', $this->messageCodes($again), $this->explain($again));
        $counts = self::stepCounts($again, 'small_assets');
        // 2 karty z faktur + 1 vyřízený dobropis.
        self::assertSame([0, 3], [$counts['created'] ?? 0, $counts['existing'] ?? 0], $this->explain($again));
        self::assertArrayNotHasKey('returns_review', $counts);
    }

    /**
     * PREMIER vede vlastní evidenci (`MAJ_OST` a karta řady DH): karty se převezmou 1:1,
     * položky faktur se neoznačují a karta DH nevznikne jako dlouhodobý majetek.
     */
    public function testOwnEvidenceIsImportedOneToOneAndItemsAreNotFlagged(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup(['small_assets' => true, 'small_asset_evidence' => true]);

        $first = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM purchase_invoice_items it JOIN purchase_invoices p ON p.id = it.purchase_invoice_id
            WHERE p.supplier_id = ? AND it.expense_kind IS NOT NULL", $supplierId), $this->explain($first));
        self::assertArrayNotHasKey('small_asset_items', self::stepCounts($first, 'purchase_invoices'));

        self::assertSame([
            ['Kancelářská židle', 'DM-001', 'tangible', '2025-03-10', '2025-03-10', '2.000', '4500.000000', '9000.00', 'Kancelář 1', 'Jan Zkušební', 'in_use', null],
            ['Notebook do terénu', 'DH-001', 'tangible', '2025-02-01', '2025-02-03', '1.000', '25000.000000', '25000.00', 'Sklad', 'Eva Vzorová', 'in_use', null],
            ['Skartovačka', 'DM-002', 'tangible', '2024-05-01', '2024-05-01', '1.000', '3000.000000', '3000.00', null, null, 'disposed', '2025-06-30'],
        ], $this->fetch('SELECT name, inventory_number, asset_kind, acquisition_date, put_into_use_date, quantity, unit_price, price, location,
                                responsible_person, status, disposed_at
                           FROM small_assets WHERE supplier_id = ? ORDER BY name', $supplierId), $this->explain($first));
        $counts = self::stepCounts($first, 'small_assets');
        self::assertSame([2, 1, 1], [$counts['created'] ?? 0, $counts['created_disposed'] ?? 0, $counts['later_years'] ?? 0], $this->explain($first));
        self::assertSame(1, self::stepCounts($first, 'assets')['small_register'] ?? 0, $this->explain($first));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM assets WHERE supplier_id = ?', $supplierId), 'Karta řady DH není dlouhodobý majetek.');

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(3, self::stepCounts($again, 'small_assets')['existing'] ?? 0, $this->explain($again));
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM small_assets WHERE supplier_id = ?', $supplierId));

        // Rok 2026: tablet zařazený v únoru 2026 přibude, ostatní zůstanou.
        $next = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR2, false);
        self::assertFalse($next->hasErrors(), $this->explain($next));
        self::assertSame(1, self::stepCounts($next, 'small_assets')['created'] ?? 0, $this->explain($next));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM small_assets WHERE supplier_id = ? AND name = 'Tablet' AND price = 8000.00", $supplierId));
    }

    public function testOtherRegisterCardIsReportedNotImported(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['other_register' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM assets WHERE supplier_id = ?', $supplierId));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM small_assets WHERE supplier_id = ?', $supplierId));
        self::assertSame(1, self::stepCounts($protocol, 'assets')['other_register'] ?? 0, $this->explain($protocol));
        $messages = $this->messages($protocol, 'assets', 'other_register');
        self::assertCount(1, $messages, $this->explain($protocol));
        self::assertSame(['info', 'LEA'], [$messages[0]['level'], $messages[0]['context']['series'] ?? null]);
    }

    /** Dlouhodobá karta registru `MAJ_H`: odpisy a pohyby navázané přes ID karty. */
    public function testMajHCardImportsAsLongTermAssetWithConfirmedTaxDepreciation(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(['maj_h' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertSame([['HM-001', 'Server', 'tangible', 'in_use', '120000.00', 'straight', '2', '0', '0.00', '022.100']],
            $this->fetch('SELECT inventory_number, name, kind, status, input_price, tax_method, tax_group, opening_tax_years, opening_tax_amount, asset_account_code
                            FROM assets WHERE supplier_id = ?', $supplierId), $this->explain($protocol));
        self::assertSame([
            ['accounting', '20000.00', 'posted'],
            ['tax', '13200.00', 'confirmed'],
        ], $this->fetch("SELECT e.kind, e.amount, e.status FROM depreciation_entries e JOIN assets a ON a.id = e.asset_id
                          WHERE a.supplier_id = ? AND e.fiscal_year = 2025 ORDER BY CAST(e.kind AS CHAR)", $supplierId), $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'assets')['tax_depreciation_confirmed'] ?? 0);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM premier_import_map WHERE supplier_id = ? AND kind = 'asset' AND premier_key LIKE 'asset|H|%'", $supplierId));

        $again = $this->importer->run($supplierId, $this->userId, $this->backup(['maj_h' => true]), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(1, self::stepCounts($again, 'assets')['existing'] ?? 0, $this->explain($again));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM depreciation_entries e JOIN assets a ON a.id = e.asset_id WHERE a.supplier_id = ?', $supplierId));
    }

    /**
     * Karta vyřazená v převáděném roce: vyřazení zaúčtoval převedený deník (ZC 541/082),
     * karta se proto vyřadí BEZ zaúčtování a naváže na ten zápis. Dřív zůstala konceptem
     * s pokynem „vyřaďte v MyÚčtu" a vyřazení v modulu by ZC zaúčtovalo podruhé.
     */
    public function testMajHCardDisposedInYearIsDisposedWithoutSecondPosting(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->backup(['maj_h' => true, 'maj_h_disposed' => true]);
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertSame([['HM-001', 'disposed', '2025-12-31', 'liquidated', '1']],
            $this->fetch("SELECT a.inventory_number, a.status, a.disposal_date, a.disposal_type,
                                 (SELECT COUNT(*) FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id = l.account_id
                                   WHERE l.entry_id = a.disposal_entry_id AND c.account_code = '541.000' AND l.side = 'debit') AS linked
                            FROM assets a WHERE a.supplier_id = ?", $supplierId), $this->explain($protocol));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'asset_disposal'", $supplierId),
            'Vyřazení se neúčtuje znovu.');
        self::assertSame(100000, $this->scalar("SELECT SUM(l.amount) FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id = l.account_id
            WHERE l.supplier_id = ? AND c.account_code LIKE '541%' AND l.side = 'debit'", $supplierId), 'ZC je v deníku jen jednou.');
        $counts = self::stepCounts($protocol, 'assets');
        self::assertSame([1, 1], [$counts['disposed_from_journal'] ?? 0, $counts['disposal_entry_linked'] ?? 0], $this->explain($protocol));
        self::assertNotContains('asset_review', $this->messageCodes($protocol), $this->explain($protocol));

        $residuals = (new DisposalResiduals($this->db))->forPeriod($supplierId, '2025-01-01', '2025-12-31');
        self::assertSame([100000.0, DisposalResiduals::BOOK_SOURCE_LINKED_ENTRY],
            [$residuals['rows'][0]['book_residual_value'], $residuals['rows'][0]['book_residual_source']]);

        $again = $this->importer->run($supplierId, $this->userId, $backup, SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertArrayNotHasKey('disposed_from_journal', self::stepCounts($again, 'assets'), $this->explain($again));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM assets WHERE supplier_id = ? AND status = 'disposed'", $supplierId));
    }

    private function assertReconciled(ImportProtocol $protocol): void
    {
        $reconciliation = $protocol->get('reconciliation');
        self::assertCount(1, $reconciliation, $this->explain($protocol));
        self::assertTrue($reconciliation[0]['ok'], json_encode($reconciliation[0], JSON_UNESCAPED_UNICODE));
    }

    /** @return list<list<mixed>> */
    private function cards(int $supplierId): array
    {
        return $this->fetch('SELECT s.name, s.asset_kind, s.acquisition_date, s.quantity, s.unit_price, s.price, s.status, s.disposed_at, pi.varsymbol
                               FROM small_assets s LEFT JOIN purchase_invoices pi ON pi.id = s.purchase_invoice_id
                              WHERE s.supplier_id = ? ORDER BY s.name', $supplierId);
    }

    /** @param array<string,bool> $flags */
    private function backup(array $flags): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup_' . md5((string) json_encode($flags));
        if (!is_dir($dir)) {
            SyntheticPremierBackup::writeDir($dir, false, $flags);
        }
        return PremierBackup::open($dir);
    }

    private function supplier(): int
    {
        $pdo = $this->db->pdo();
        $ico = SyntheticPremierBackup::ICO;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, vat_period, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 1", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, "monthly", ?, ?, "tax_evidence")'
        )->execute([SyntheticPremierBackup::NAME, $this->czId, $ico, 'CZ' . $ico, $this->anyCurrencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function scalar(string $sql, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<list<mixed>> */
    private function fetch(string $sql, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $row): array => array_map(static fn (mixed $v): mixed => is_int($v) ? (string) $v : $v, $row), $stmt->fetchAll(\PDO::FETCH_NUM));
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

    /** @return list<array<string,mixed>> */
    private function messages(ImportProtocol $protocol, string $key, string $code): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return array_values(array_filter($step['messages'] ?? [], static fn (array $m): bool => $m['code'] === $code));
            }
        }
        return [];
    }

    /** @return list<string> */
    private function messageCodes(ImportProtocol $protocol): array
    {
        $codes = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                $codes[] = $m['code'];
            }
        }
        return $codes;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
