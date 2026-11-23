<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výběr roků u agendy POHODY, která vede i doklady následujícího roku: nevybraný pozdější
 * rok se nepřevede (deník, banka, doklady ani úhrady) a opakovaný převod s ním ho jen doplní.
 * Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class PohodaYearSelectionTest extends TestCase
{
    private const TABLES = ['journal_entries', 'journal_entry_lines', 'accounting_periods', 'invoices', 'purchase_invoices', 'payment_matches', 'cash_documents'];

    private Connection $db;
    private PohodaImporter $importer;
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
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if (!$this->db->hasColumn('pohoda_import_map', 'pohoda_key')) {
            $this->markTestSkipped('Chybí migrace 1844 (pohoda_import_map).');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_years_' . bin2hex(random_bytes(5));
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

    public function testOverviewListsLaterYearsOfTheAgenda(): void
    {
        SyntheticPohodaExport::write($this->tmp, unbooked: true);
        $agendas = PohodaExport::overview($this->tmp);
        self::assertSame([SyntheticPohodaExport::NEXT_YEAR], $agendas[0]['counts']['later_years']);

        $plain = $this->tmp . '/plain';
        SyntheticPohodaExport::write($plain);
        self::assertSame([], PohodaExport::overview($plain)[0]['counts']['later_years']);
    }

    public function testLaterUnpostedInvoiceIsOfferedAndSkipped(): void
    {
        $dir = SyntheticPohodaExport::write($this->tmp);
        $journal = $dir . '/01_ucetni_denik.xml';
        $original = file_get_contents($journal);
        $next = SyntheticPohodaExport::NEXT_YEAR;
        SyntheticPohodaExport::withIssuedInvoice($dir, 'FV2027TEST', '270099', $next . '-01-08', 1000);
        file_put_contents($journal, $original);

        $supplierId = $this->supplier();
        $result = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false, skipYears: [$next]);
        self::assertFalse($result->hasErrors(), $this->explain($result));
        self::assertSame(0, $this->rows('invoices', $supplierId, "issue_date >= '{$next}-01-01'"));
        self::assertSame([$next], PohodaExport::overview($this->tmp)[0]['counts']['later_years']);
    }

    public function testRepeatImportOfClosedPeriodAddsNoDocuments(): void
    {
        $dir = SyntheticPohodaExport::write($this->tmp);
        $supplierId = $this->supplier();
        $initial = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($initial->hasErrors(), $this->explain($initial));
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ?")->execute([$supplierId]);

        SyntheticPohodaExport::withIssuedInvoice($dir, 'FVCLOSED', '260098', '2026-11-08', 1000);
        $before = $this->snapshot($supplierId);
        $result = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertSame($before, $this->snapshot($supplierId));
        self::assertContains('period_not_open', array_column((array) $result->get('preflight'), 'code'));
    }

    public function testRepeatImportRespectsCompanyDateLock(): void
    {
        $dir = SyntheticPohodaExport::write($this->tmp);
        $supplierId = $this->supplier();
        $initial = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($initial->hasErrors(), $this->explain($initial));
        $this->db->pdo()->prepare("INSERT INTO accounting_supplier_settings(supplier_id, locked_until) VALUES (?, '2027-01-31') ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)")->execute([$supplierId]);

        SyntheticPohodaExport::withIssuedInvoice($dir, 'FVDATELOCK', '260097', '2026-11-08', 1000);
        $before = $this->snapshot($supplierId);
        $result = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertSame($before, $this->snapshot($supplierId));
        self::assertContains('date_locked', array_column((array) $result->get('preflight'), 'code'));
    }

    public function testFinishingAgendaYearLeavesNextYearDocumentsAndJournalUntouched(): void
    {
        $dir = SyntheticPohodaExport::write($this->tmp, unbooked: true);
        $supplierId = $this->supplier();
        $next = SyntheticPohodaExport::NEXT_YEAR;
        $initial = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($initial->hasErrors(), $this->explain($initial));
        $pdo = $this->db->pdo();
        $nextPeriod = $pdo->prepare('SELECT id FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?');
        $nextPeriod->execute([$supplierId, $next]);
        $periodId = (int) $nextPeriod->fetchColumn();
        self::assertGreaterThan(0, $periodId);
        $pdo->prepare("DELETE m FROM pohoda_import_map m JOIN journal_entries e ON e.id = m.target_id AND e.supplier_id = m.supplier_id
                      WHERE m.kind = 'journal_entry' AND e.period_id = ? AND e.supplier_id = ?")
            ->execute([$periodId, $supplierId]);
        $snapshot = static function () use ($pdo, $supplierId, $next, $periodId): array {
            $invoices = $pdo->prepare('SELECT id, status, paid_total, paid_at, parent_invoice_id FROM invoices WHERE supplier_id = ? AND issue_date >= ? ORDER BY id');
            $invoices->execute([$supplierId, $next . '-01-01']);
            $journal = $pdo->prepare('SELECT id, entry_date, description, source_type, source_id FROM journal_entries WHERE supplier_id = ? AND period_id = ? ORDER BY id');
            $journal->execute([$supplierId, $periodId]);
            return [$invoices->fetchAll(), $journal->fetchAll()];
        };
        $before = $snapshot();
        SyntheticPohodaExport::withIssuedInvoice($dir, 'FVFINAL', '260096', '2026-12-30', 1000);
        $export = PohodaExport::open($dir);

        $result = $this->importer->run($supplierId, $this->userId, $export, false, selectedYears: [SyntheticPohodaExport::YEAR]);
        self::assertFalse($result->hasErrors(), $this->explain($result));
        self::assertSame(1, $this->rows('invoices', $supplierId, "varsymbol = 'FVFINAL'"));
        self::assertSame($before, $snapshot());
        $withNext = $this->importer->preflight($supplierId, $export);
        self::assertContains('journal_not_empty', array_column($withNext, 'code'));
    }

    /**
     * Jen rok agendy: deník, banka, pokladna, doklady ani úhrady následujícího roku se
     * nepřevedou, rekonciliace proti deníku POHODY bez nich sedí a faktura uhrazená až
     * v nevybraném roce zůstává neuhrazená. Převod s následujícím rokem je doplní a stav
     * je stejný jako po převodu obou roků najednou; další běh už nic nepřidá.
     */
    public function testOnlyAgendaYearAndLaterYearAddedByRepeatedImport(): void
    {
        $dir = SyntheticPohodaExport::write($this->tmp, unbooked: true);
        $this->payFirstInvoiceInNextYear($dir);
        $export = PohodaExport::open($dir);
        $next = SyntheticPohodaExport::NEXT_YEAR;
        $supplierId = $this->supplier();

        $only = $this->importer->run($supplierId, $this->userId, $export, false, skipYears: [$next]);
        self::assertFalse($only->hasErrors(), $this->explain($only));
        self::assertContains('later_years_skipped', array_column((array) $only->get('preflight'), 'code'));
        self::assertNotContains('later_periods', array_column((array) $only->get('preflight'), 'code'));
        self::assertTrue($only->get('reconciliation')[0]['ok'], json_encode($only->get('reconciliation')[0], JSON_UNESCAPED_UNICODE));
        self::assertSame(2, self::stepCounts($only, 'journal')['later_year_skipped'] ?? 0, $this->explain($only));
        self::assertSame(1, self::stepCounts($only, 'issued_invoices')['later_year_skipped'] ?? 0);
        self::assertSame(2, self::stepCounts($only, 'bank')['later_year_skipped'] ?? 0);
        self::assertSame(1, self::stepCounts($only, 'payments')['later_year_skipped'] ?? 0);
        self::assertSame(0, $this->rows('journal_entries', $supplierId, "entry_date >= '{$next}-01-01'"));
        self::assertSame(0, $this->rows('accounting_periods', $supplierId, "fiscal_year = {$next}"));
        self::assertSame(0, $this->rows('invoices', $supplierId, "issue_date >= '{$next}-01-01'"));
        self::assertSame(0, $this->bankRows($supplierId, "t.posted_at >= '{$next}-01-01'"));
        self::assertSame(1, $this->rows('invoices', $supplierId, "varsymbol = '26FV0001' AND status = 'sent' AND paid_total = 0"));

        $added = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($added->hasErrors(), $this->explain($added));
        self::assertTrue($added->get('reconciliation')[0]['ok'], json_encode($added->get('reconciliation')[0], JSON_UNESCAPED_UNICODE));
        self::assertSame(2, self::stepCounts($added, 'journal')['entries'] ?? 0, $this->explain($added));
        self::assertSame(1, $this->rows('accounting_periods', $supplierId, "fiscal_year = {$next}"));
        self::assertSame(1, $this->rows('invoices', $supplierId, "varsymbol = '26FV0001' AND status = 'paid'"));

        $full = $this->supplier('87650002');
        $this->db->pdo()->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute(['00000000', $supplierId]);
        $this->db->pdo()->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([SyntheticPohodaExport::ICO, $full]);
        $both = $this->importer->run($full, $this->userId, $export, false);
        self::assertFalse($both->hasErrors(), $this->explain($both));
        self::assertSame($this->snapshot($full), $this->snapshot($supplierId));

        $before = $this->snapshot($supplierId);
        $this->db->pdo()->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute(['00000000', $full]);
        $this->db->pdo()->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([SyntheticPohodaExport::ICO, $supplierId]);
        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $this->snapshot($supplierId));
    }

    /** Zkouška nanečisto jen roku agendy nic nezapíše a období následujícího roku nezaloží. */
    public function testDryRunOfAgendaYearOnlyLeavesNothingBehind(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, unbooked: true));
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $export, true, skipYears: [SyntheticPohodaExport::NEXT_YEAR]);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(2, self::stepCounts($protocol, 'journal')['later_year_skipped'] ?? 0);
        self::assertSame(0, $this->rows('journal_entries', $supplierId));
        self::assertSame(0, $this->rows('accounting_periods', $supplierId));
    }

    /** Úhrada 26FV0001 s datem v následujícím roce (pohyb zůstává v roce agendy). */
    private function payFirstInvoiceInNextYear(string $dir): void
    {
        $from = '<typ:liquidation><typ:id>1</typ:id><typ:date>2026-01-15</typ:date>';
        $to = '<typ:liquidation><typ:id>1</typ:id><typ:date>' . SyntheticPohodaExport::NEXT_YEAR . '-01-15</typ:date>';
        $patched = 0;
        foreach (glob($dir . '/*.xml') ?: [] as $file) {
            $xml = (string) file_get_contents($file);
            if (str_contains($xml, $from)) {
                file_put_contents($file, str_replace($from, $to, $xml));
                $patched++;
            }
        }
        self::assertSame(1, $patched);
    }

    private function supplier(string $ico = SyntheticPohodaExport::ICO): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence")'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, $ico, 'CZ' . $ico, $this->currencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function rows(string $table, int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function bankRows(int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> počty řádků a součty, které nesmí záviset na pořadí převodů */
    private function snapshot(int $supplierId): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $out[$table] = $this->rows($table, $supplierId);
        }
        $out['bank_transactions'] = $this->bankRows($supplierId);
        $lines = $this->db->pdo()->prepare(
            "SELECT p.fiscal_year, l.side, SUM(l.amount) FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id JOIN accounting_periods p ON p.id = e.period_id
              WHERE l.supplier_id = ? GROUP BY p.fiscal_year, l.side ORDER BY p.fiscal_year, l.side"
        );
        $lines->execute([$supplierId]);
        $out['turnover'] = array_map(static fn (array $r): string => implode('|', $r), $lines->fetchAll(\PDO::FETCH_NUM));
        $invoices = $this->db->pdo()->prepare('SELECT varsymbol, status, paid_total FROM invoices WHERE supplier_id = ? ORDER BY varsymbol');
        $invoices->execute([$supplierId]);
        $out['invoices_state'] = array_map(static fn (array $r): string => implode('|', $r), $invoices->fetchAll(\PDO::FETCH_NUM));
        return $out;
    }

    /** @return array<string,int> */
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
