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
 * Agenda POHODY s hospodářským rokem (1. 8. 2025 - 31. 7. 2026, složka `<IČO>_2026`):
 * firma s hospodářským rokem od srpna ji převede do období 1. 8. - 31. 7. podle data
 * zápisů, firma s kalendářním rokem převod zastaví s návrhem nastavení.
 * Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class PohodaFiscalYearTest extends TestCase
{
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
        if (!$this->db->hasColumn('pohoda_import_map', 'pohoda_key') || !$this->db->hasColumn('supplier', 'fiscal_year_start_month')) {
            $this->markTestSkipped('Chybí migrace (pohoda_import_map / fiscal_year_start_month).');
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_fiscal_' . bin2hex(random_bytes(5));
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
     * Firma s hospodářským rokem od srpna: období 1. 8. 2025 - 31. 7. 2026 (label 2025),
     * otevírací zápis k 1. 8. 2025, zápis z března 2026 ve stejném období se skutečným
     * datem (žádné posunutí k hranici roku), doklad ze září 2026 v dalším období.
     */
    public function testFiscalCompanyImportsFiscalAgendaIntoFiscalPeriods(): void
    {
        $export = PohodaExport::open($this->fiscalAgenda());
        $supplierId = $this->supplier(8);

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertSame([
            '2025|2025-08-01|2026-07-31',
            '2026|2026-08-01|2027-07-31',
        ], $this->periods($supplierId));
        self::assertSame(['2025-08-01|2025'], $this->entries($supplierId, "e.source_type = 'opening'"));
        self::assertSame(['2026-03-15|2025'], $this->entries($supplierId, "e.document_no = 'FI0001'"));
        self::assertSame(['2026-09-10|2026'], $this->entries($supplierId, "e.document_no = 'FI0002'"));
        self::assertSame([], $this->entries($supplierId, "e.entry_date < '2025-08-01'"));
        self::assertSame(0, $this->entryCount($supplierId, "e.document_date IS NOT NULL AND e.document_date <> e.entry_date AND e.source_type <> 'opening'"));
        $codes = array_column($this->messages($protocol), 'code');
        self::assertNotContains('entry_date_outside_year', $codes, $this->explain($protocol));
        self::assertNotContains('fiscal_year_mismatch', $codes);
        self::assertContains('later_period', $codes);
    }

    /** Firma s kalendářním rokem: agenda hospodářského roku převod zastaví s návrhem „srpen". */
    public function testCalendarCompanyStopsOnFiscalAgenda(): void
    {
        $export = PohodaExport::open($this->fiscalAgenda());
        $supplierId = $this->supplier(1);

        $preflight = $this->importer->preflight($supplierId, $export);
        $mismatch = array_values(array_filter($preflight, static fn (array $m): bool => $m['code'] === 'fiscal_year_mismatch'));
        self::assertCount(1, $mismatch, json_encode($preflight, JSON_UNESCAPED_UNICODE));
        self::assertSame('error', $mismatch[0]['level']);
        self::assertSame(8, $mismatch[0]['context']['suggested_start_month']);
        self::assertStringContainsString('srpen', $mismatch[0]['message']);

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertTrue($protocol->hasErrors());
        self::assertContains('fiscal_year_mismatch', array_column($this->messages($protocol), 'code'), $this->explain($protocol));
        self::assertSame([], $this->periods($supplierId));
        self::assertSame(0, $this->entryCount($supplierId));
    }

    /** Firma s hospodářským rokem od srpna: kalendářní agenda (leden až listopad) převod zastaví s návrhem „leden". */
    public function testFiscalCompanyStopsOnCalendarAgenda(): void
    {
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, unbooked: true));
        $supplierId = $this->supplier(8);

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertTrue($protocol->hasErrors());
        $mismatch = array_values(array_filter((array) $protocol->get('preflight'), static fn (array $m): bool => $m['code'] === 'fiscal_year_mismatch'));
        self::assertCount(1, $mismatch, $this->explain($protocol));
        self::assertSame(1, $mismatch[0]['context']['suggested_start_month']);
        self::assertStringContainsString('leden', $mismatch[0]['message']);
        self::assertSame([], $this->periods($supplierId));
    }

    /**
     * Agenda v daňové evidenci: POHODA účetní deník nevydá (agendu nepoužívá). Průvodce
     * má říct, že daňovou evidenci převod nepodporuje a čím převést doklady, ne hlásit
     * chybu souboru a přepnutí firmy do podvojného účetnictví.
     */
    public function testTaxEvidenceAgendaIsReportedAsUnsupported(): void
    {
        $dir = SyntheticPohodaExport::write($this->tmp);
        file_put_contents($dir . '/01_ucetni_denik.xml', (string) iconv('UTF-8', 'Windows-1250',
            '<?xml version="1.0" encoding="Windows-1250"?>'
            . '<rsp:responsePack version="2.0" id="001" state="ok" programVersion="SYNTETICKY 1.0" ico="' . SyntheticPohodaExport::ICO . '"'
            . ' xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd">'
            . '<rsp:responsePackItem version="2.0" id="001-1" state="error" note="Agenda Účetní deník není v této účetní jednotce dostupná."></rsp:responsePackItem>'
            . '</rsp:responsePack>'));
        $export = PohodaExport::open($dir);
        $supplierId = $this->supplier(1);

        $preflight = $this->importer->preflight($supplierId, $export);
        $codes = array_column($preflight, 'code');
        self::assertContains('tax_evidence_agenda_unsupported', $codes, json_encode($preflight, JSON_UNESCAPED_UNICODE));
        self::assertNotContains('switch_to_double_entry', $codes);
        self::assertNotContains('file_state', $codes);
        $message = $preflight[array_search('tax_evidence_agenda_unsupported', $codes, true)];
        self::assertSame('error', $message['level']);
        self::assertStringContainsString('Pohoda XML', $message['message']);

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertTrue($protocol->hasErrors());
        self::assertSame(0, $this->entryCount($supplierId));
    }

    /**
     * Agenda ze {@see SyntheticPohodaExport::write()} posunutá na hospodářský rok od srpna:
     * leden 2026 → srpen 2025 (počáteční stavy 1. 8. 2025), minulý rok prosinec → červenec.
     * Navíc zápis 15. 3. 2026 (stejný hospodářský rok, jiný kalendářní) a 10. 9. 2026
     * (doklad dalšího roku, který agenda vede). Složka zůstává `<IČO>_2026`.
     */
    private function fiscalAgenda(): string
    {
        $dir = SyntheticPohodaExport::write($this->tmp);
        foreach (glob($dir . '/*.xml') ?: [] as $file) {
            $xml = (string) file_get_contents($file);
            file_put_contents($file, str_replace(['2026-01-', '2025-12-'], ['2025-08-', '2025-07-'], $xml));
        }
        $journal = $dir . '/01_ucetni_denik.xml';
        $entries = self::entry('FI0001', 'Služba vložená společníkem', 300, '518000', '411000', '2026-03-15')
            . self::entry('FI0002', 'Služba vložená společníkem', 200, '518000', '411000', '2026-09-10');
        $xml = (string) file_get_contents($journal);
        self::assertSame(1, substr_count($xml, '</lst:accountancy>'));
        file_put_contents($journal, str_replace('</lst:accountancy>', (string) iconv('UTF-8', 'Windows-1250', $entries) . '</lst:accountancy>', $xml));
        return $dir;
    }

    private static function entry(string $number, string $text, float $amount, string $md, string $d, string $date): string
    {
        return '<act:accountingItem><act:source>Interní doklady</act:source><act:number><typ:numberRequested>' . $number . '</typ:numberRequested></act:number>'
            . '<act:text>' . $text . '</act:text><act:homeCurrency><typ:priceSum>' . $amount . '</typ:priceSum></act:homeCurrency>'
            . '<act:accounting><act:credit>' . $md . '</act:credit><act:debit>' . $d . '</act:debit></act:accounting><act:date>' . $date . '</act:date><act:dateTax>' . $date . '</act:dateTax></act:accountingItem>';
    }

    private function supplier(int $startMonth): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode, fiscal_year_start_month)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence", ?)'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, SyntheticPohodaExport::ICO, 'CZ' . SyntheticPohodaExport::ICO, $this->currencyId, $this->vatRateId, $startMonth]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    /** @return list<string> „label|začátek|konec" */
    private function periods(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT CONCAT(fiscal_year, "|", starts_on, "|", ends_on) FROM accounting_periods WHERE supplier_id = ? ORDER BY starts_on');
        $stmt->execute([$supplierId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<string> „datum zápisu|label období" */
    private function entries(int $supplierId, string $where): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT CONCAT(e.entry_date, '|', p.fiscal_year) FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND {$where} ORDER BY e.entry_date, e.id"
        );
        $stmt->execute([$supplierId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function entryCount(int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM journal_entries e WHERE e.supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
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

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
