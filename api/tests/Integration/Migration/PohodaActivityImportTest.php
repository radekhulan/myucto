<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\ActivityImporter;
use MyInvoice\Service\Migration\Pohoda\ContractImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Činnosti POHODY jako dimenze Činnost (Firma → Dimenze): hodnota pro každou činnost, na
 * řádcích deníku podle jejich činnosti a na fakturách, položce a bankovním pohybu. Opakovaný
 * převod exportu s činnostmi je doplní firmě převedené bez nich, uzavřené období nechá být.
 * Nezisková organizace (504) dostane činnosti POHODY do samostatné dimenze, systémová
 * dimenze Činnost zůstává. Izolovaná firma, rollback v tearDown.
 */
#[Group('integration')]
final class PohodaActivityImportTest extends TestCase
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
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_activities_' . bin2hex(random_bytes(5));
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
     * Každá činnost je hodnota dimenze Činnost, i nevyužitá. Řádky deníku nesou činnost svého
     * řádku, faktury a bankovní pohyb činnost hlavičky a položka s jinou činností vlastní.
     */
    public function testActivitiesBecomeDimensionValuesOnJournalAndDocuments(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withActivities($dir);
        $export = PohodaExport::open($dir);
        self::assertSame(3, $export->counts()['activities']);

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->assertActivitiesImported($supplierId, 'Činnost');
        $counts = $this->counts($protocol);
        self::assertSame(3, $counts['activities'] ?? null, $this->explain($protocol));
        self::assertSame(3, $counts['activity_values_created'] ?? null);
        self::assertSame(10, $counts['activity_lines'] ?? null);
        self::assertSame(3, $counts['activity_documents'] ?? null, 'vydaná, přijatá faktura a bankovní pohyb');
        self::assertSame(1, $counts['activity_items'] ?? null);
        self::assertStringContainsString(SyntheticPohodaExport::ACTIVITY_UNUSED, $this->message($protocol, 'activities_summary'));
        self::assertSame('', $this->message($protocol, 'activity_nonprofit_separate'));
    }

    /**
     * Firma převedená exportem bez činností: dimenze se nezapnou a nic nevznikne. Opakovaný
     * převod exportu s činnostmi (nový převodník) je doplní k už převedeným dokladům
     * a řádkům, další opakování nic nezdvojí.
     */
    public function testRepeatImportFillsActivitiesIntoConvertedCompany(): void
    {
        $supplierId = $this->supplier();
        $plainDir = $this->tmp . DIRECTORY_SEPARATOR . 'plain';
        mkdir($plainDir);
        $plain = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($plainDir)), false);
        self::assertFalse($plain->hasErrors(), $this->explain($plain));
        self::assertSame(0, $this->rows('dimension_types', $supplierId, "code = '" . ActivityImporter::TYPE_CODE . "'"));
        self::assertSame('0', (string) $this->db->pdo()->query("SELECT dimensions_enabled FROM supplier WHERE id = {$supplierId}")->fetchColumn());
        self::assertSame([], $this->counts($plain));
        $documents = [$this->rows('invoices', $supplierId), $this->rows('purchase_invoices', $supplierId), $this->rows('journal_entries', $supplierId)];

        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withActivities($dir);
        $filled = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($filled->hasErrors(), $this->explain($filled));
        $this->assertActivitiesImported($supplierId, 'Činnost');
        self::assertSame($documents, [$this->rows('invoices', $supplierId), $this->rows('purchase_invoices', $supplierId), $this->rows('journal_entries', $supplierId)]);
        self::assertSame(10, $this->counts($filled)['activity_lines'] ?? null, $this->explain($filled));
        $tagged = $this->tagged($supplierId);

        $again = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = $this->counts($again);
        self::assertSame(3, $counts['activity_values_existing'] ?? null, $this->explain($again));
        self::assertArrayNotHasKey('activity_values_created', $counts);
        self::assertArrayNotHasKey('activity_lines', $counts);
        self::assertArrayNotHasKey('activity_documents', $counts);
        self::assertSame($tagged, $this->tagged($supplierId));
        $this->assertActivitiesImported($supplierId, 'Činnost');
    }

    /** Činnost se nedoplní do uzavřeného období; protokol to řekne a hodnoty dimenze vzniknou. */
    public function testRepeatImportLeavesClosedPeriodUntouched(): void
    {
        $supplierId = $this->supplier();
        $plainDir = $this->tmp . DIRECTORY_SEPARATOR . 'plain';
        mkdir($plainDir);
        $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($plainDir)), false);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ?")->execute([$supplierId]);

        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withActivities($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);

        self::assertSame(3, $this->rows('dimension_values', $supplierId), $this->explain($protocol));
        self::assertSame(0, $this->rows('journal_entry_line_dimensions', $supplierId));
        self::assertSame(0, $this->rows('document_dimensions', $supplierId));
        self::assertStringContainsString('uzavřeném', $this->message($protocol, 'activity_period_not_open'), $this->explain($protocol));
    }

    /**
     * Zakázky a činnosti v jednom exportu: každé členění ve své dimenzi, jedno druhé nepřepíše.
     */
    public function testActivitiesAndContractsAreSeparateDimensions(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withContracts($dir);
        SyntheticPohodaExport::withActivities($dir);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->assertActivitiesImported($supplierId, 'Činnost');
        $pdo = $this->db->pdo();
        $contractType = (int) $pdo->query("SELECT id FROM dimension_types WHERE supplier_id = {$supplierId} AND code = '" . ContractImporter::TYPE_CODE . "'")->fetchColumn();
        self::assertSame(10, (int) $pdo->query("SELECT COUNT(*) FROM journal_entry_line_dimensions WHERE supplier_id = {$supplierId} AND dimension_type_id = {$contractType}")->fetchColumn());
        self::assertSame(4, (int) $pdo->query("SELECT COUNT(*) FROM document_dimensions WHERE supplier_id = {$supplierId} AND dimension_type_id = {$contractType}")->fetchColumn());
    }

    /**
     * Firma 504: činnosti POHODY jsou v samostatné dimenzi Činnost POHODY. Systémová dimenze
     * Činnost má dál jen hlavní a hospodářskou činnost a náklady a výnosy nesou hlavní činnost.
     */
    public function testNonprofitCompanyKeepsSystemActivityDimension(): void
    {
        $supplierId = $this->supplier();
        $this->db->pdo()->exec("UPDATE supplier SET legal_form = '706' WHERE id = {$supplierId}");
        self::assertTrue((new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db)))->adoptCz504BaselineForNewSupplier($supplierId, $this->userId));
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withNonprofitChart($dir);
        SyntheticPohodaExport::withActivities($dir);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->assertActivitiesImported($supplierId, 'Činnost POHODY');
        $pdo = $this->db->pdo();
        $system = $pdo->query("SELECT id, code, name FROM dimension_types WHERE supplier_id = {$supplierId} AND system_key = 'activity'")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($system, $this->explain($protocol));
        self::assertSame(['activity', 'Činnost'], [$system['code'], $system['name']]);
        self::assertSame(['economic', 'main'], $pdo->query("SELECT code FROM dimension_values WHERE type_id = {$system['id']} ORDER BY code")->fetchAll(\PDO::FETCH_COLUMN));
        $systemLines = $pdo->query("SELECT DISTINCT v.semantic_key FROM journal_entry_line_dimensions d JOIN dimension_values v ON v.id = d.dimension_value_id
             WHERE d.supplier_id = {$supplierId} AND d.dimension_type_id = {$system['id']}")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['main'], $systemLines);
        self::assertStringContainsString('Činnost POHODY', $this->message($protocol, 'activity_nonprofit_separate'), $this->explain($protocol));
    }

    private function assertActivitiesImported(int $supplierId, string $typeName): void
    {
        $pdo = $this->db->pdo();
        $type = $pdo->query("SELECT id, name, kind, system_key FROM dimension_types WHERE supplier_id = {$supplierId} AND code = '" . ActivityImporter::TYPE_CODE . "'")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($type);
        self::assertSame([$typeName, 'custom', null], [$type['name'], $type['kind'], $type['system_key']]);
        $typeId = (int) $type['id'];
        $values = $pdo->query("SELECT code, name FROM dimension_values WHERE type_id = {$typeId} ORDER BY code")->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame([
            SyntheticPohodaExport::ACTIVITY_SALES => 'Prodej služeb',
            SyntheticPohodaExport::ACTIVITY_UNUSED => 'Správní režie',
            SyntheticPohodaExport::ACTIVITY_SERVICE => 'Servis a údržba',
        ], $values);

        $headers = $pdo->query("SELECT dd.doc_type, v.code FROM document_dimensions dd JOIN dimension_values v ON v.id = dd.dimension_value_id
             WHERE dd.supplier_id = {$supplierId} AND dd.dimension_type_id = {$typeId} AND dd.item_no = 0 ORDER BY dd.doc_type")->fetchAll(\PDO::FETCH_NUM);
        self::assertSame([
            ['purchase_invoice', SyntheticPohodaExport::ACTIVITY_SERVICE],
            ['invoice', SyntheticPohodaExport::ACTIVITY_SALES],
            ['bank_transaction', SyntheticPohodaExport::ACTIVITY_SERVICE],
        ], $headers);
        $items = $pdo->query("SELECT dd.doc_type, dd.item_no, v.code FROM document_dimensions dd JOIN dimension_values v ON v.id = dd.dimension_value_id
             WHERE dd.supplier_id = {$supplierId} AND dd.dimension_type_id = {$typeId} AND dd.item_no > 0")->fetchAll(\PDO::FETCH_NUM);
        self::assertSame([['invoice', 1, SyntheticPohodaExport::ACTIVITY_SERVICE]], $items, 'položka s jinou činností než hlavička');

        $lines = $pdo->query("SELECT e.document_no, v.code FROM journal_entry_lines l
              JOIN journal_entries e ON e.id = l.entry_id
              JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = {$typeId}
              JOIN dimension_values v ON v.id = d.dimension_value_id
             WHERE l.supplier_id = {$supplierId} ORDER BY e.document_no, l.line_no")->fetchAll(\PDO::FETCH_NUM);
        $byDocument = [];
        foreach ($lines as [$number, $code]) {
            $byDocument[$number][] = $code;
        }
        self::assertSame([
            '26FV0001' => array_fill(0, 4, SyntheticPohodaExport::ACTIVITY_SALES),
            '26PF0001' => array_fill(0, 4, SyntheticPohodaExport::ACTIVITY_SERVICE),
            'BAN0010003' => array_fill(0, 2, SyntheticPohodaExport::ACTIVITY_SERVICE),
        ], $byDocument, 'úhrady faktur činnost v deníku nenesou');
    }

    /** @return array{lines:int,documents:int,values:int} */
    private function tagged(int $supplierId): array
    {
        return [
            'lines' => $this->rows('journal_entry_line_dimensions', $supplierId),
            'documents' => $this->rows('document_dimensions', $supplierId),
            'values' => $this->rows('dimension_values', $supplierId),
        ];
    }

    /** @return array<string,int|float> */
    private function counts(ImportProtocol $protocol): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if (($step['key'] ?? '') === ActivityImporter::STEP) {
                return (array) ($step['counts'] ?? []);
            }
        }
        return [];
    }

    private function message(ImportProtocol $protocol, string $code): string
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                if (($m['code'] ?? '') === $code) {
                    return (string) ($m['text'] ?? '');
                }
            }
        }
        return '';
    }

    private function rows(string $table, int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function supplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence")'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, SyntheticPohodaExport::ICO, 'CZ' . SyntheticPohodaExport::ICO, $this->currencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
