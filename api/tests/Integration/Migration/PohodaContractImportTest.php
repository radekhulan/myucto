<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\ContractImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zakázky POHODY jako dimenze Zakázka (Firma → Dimenze): hodnota pro každou zakázku,
 * na řádcích deníku podle jejich zakázky a na fakturách a bankovním pohybu. Zakázka MyÚčta
 * u klienta vzniká jen u zakázky s vlastním partnerem. Opakovaný převod exportu se zakázkami
 * je doplní firmě převedené bez nich. Izolovaná firma, rollback v tearDown.
 */
#[Group('integration')]
final class PohodaContractImportTest extends TestCase
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
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_contracts_' . bin2hex(random_bytes(5));
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
     * Každá zakázka je hodnota dimenze, i nevyužitá. Zakázka s partnerem má zakázku MyÚčta
     * u jeho klienta a faktura i řádky deníku na ni ukazují; zakázka bez partnera je jen
     * hodnotou, přestože ji nese přijatá faktura (klient se z dokladů neodvozuje).
     */
    public function testContractsBecomeDimensionValuesAndProjectsOnlyWithPartner(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withContracts($dir);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->assertContractsImported($supplierId);
        $counts = $this->counts($protocol);
        self::assertSame(3, $counts['contracts'] ?? null, $this->explain($protocol));
        self::assertSame(3, $counts['contract_values_created'] ?? null);
        self::assertSame(1, $counts['contract_projects_created'] ?? null);
        self::assertSame(2, $counts['contract_dimension_only'] ?? null);
        self::assertSame(3, $counts['contract_documents'] ?? null, 'vydaná, přijatá faktura a bankovní pohyb');
        self::assertStringContainsString(SyntheticPohodaExport::CONTRACT_UNUSED, $this->message($protocol, 'contract_dimension_only'));
    }

    /**
     * Firma převedená exportem bez zakázek: dimenze se nezapnou a nic nevznikne. Opakovaný
     * převod exportu se zakázkami (nový převodník) je doplní k už převedeným dokladům
     * a řádkům, další opakování nic nezdvojí.
     */
    public function testRepeatImportFillsContractsIntoConvertedCompany(): void
    {
        $supplierId = $this->supplier();
        $plainDir = $this->tmp . DIRECTORY_SEPARATOR . 'plain';
        mkdir($plainDir);
        $plain = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($plainDir)), false);
        self::assertFalse($plain->hasErrors(), $this->explain($plain));
        self::assertSame(0, $this->rows('dimension_types', $supplierId, "code = '" . ContractImporter::TYPE_CODE . "'"));
        self::assertSame('0', (string) $this->db->pdo()->query("SELECT dimensions_enabled FROM supplier WHERE id = {$supplierId}")->fetchColumn());
        self::assertArrayNotHasKey('contracts', $this->counts($plain));
        $documents = [$this->rows('invoices', $supplierId), $this->rows('purchase_invoices', $supplierId), $this->rows('journal_entries', $supplierId)];

        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withContracts($dir);
        $filled = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($filled->hasErrors(), $this->explain($filled));
        $this->assertContractsImported($supplierId);
        self::assertSame($documents, [$this->rows('invoices', $supplierId), $this->rows('purchase_invoices', $supplierId), $this->rows('journal_entries', $supplierId)]);
        $tagged = $this->tagged($supplierId);

        $again = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = $this->counts($again);
        self::assertSame(3, $counts['contract_values_existing'] ?? null, $this->explain($again));
        self::assertSame(1, $counts['contract_projects_existing'] ?? null);
        self::assertArrayNotHasKey('contract_values_created', $counts);
        self::assertArrayNotHasKey('contract_lines', $counts);
        self::assertSame($tagged, $this->tagged($supplierId));
        $this->assertContractsImported($supplierId);
    }

    /** Zakázka se nedoplní do uzavřeného období; protokol to řekne a hodnoty dimenze vzniknou. */
    public function testRepeatImportLeavesClosedPeriodUntouched(): void
    {
        $supplierId = $this->supplier();
        $plainDir = $this->tmp . DIRECTORY_SEPARATOR . 'plain';
        mkdir($plainDir);
        $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($plainDir)), false);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ?")->execute([$supplierId]);

        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withContracts($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);

        self::assertSame(3, $this->rows('dimension_values', $supplierId), $this->explain($protocol));
        self::assertSame(0, $this->rows('journal_entry_line_dimensions', $supplierId));
        self::assertSame(0, $this->rows('document_dimensions', $supplierId));
        self::assertSame(0, $this->rows('invoices', $supplierId, 'project_id IS NOT NULL'));
        self::assertStringContainsString('uzavřeném', $this->message($protocol, 'contract_period_not_open'), $this->explain($protocol));
    }

    private function assertContractsImported(int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $type = $pdo->query("SELECT id, name, kind, supplier_id FROM dimension_types WHERE supplier_id = {$supplierId} AND code = '" . ContractImporter::TYPE_CODE . "'")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($type);
        self::assertSame(['Zakázka', 'project'], [$type['name'], $type['kind']]);
        $typeId = (int) $type['id'];
        $values = $pdo->query("SELECT code, name, project_id FROM dimension_values WHERE type_id = {$typeId} ORDER BY code")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([SyntheticPohodaExport::CONTRACT_OWN, SyntheticPohodaExport::CONTRACT_PARTNER, SyntheticPohodaExport::CONTRACT_UNUSED], array_column($values, 'code'));
        self::assertSame(['Vlastní vývoj', 'Stavba haly', 'Nevyužitá zakázka'], array_column($values, 'name'));

        $projects = $pdo->query("SELECT p.id, p.project_number, p.name, c.ic FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.supplier_id = {$supplierId}")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $projects, 'zakázka MyÚčta jen u zakázky s partnerem');
        self::assertSame([SyntheticPohodaExport::CONTRACT_PARTNER, 'Stavba haly', SyntheticPohodaExport::CUSTOMER_ICO], [$projects[0]['project_number'], $projects[0]['name'], $projects[0]['ic']]);
        $projectId = (int) $projects[0]['id'];
        self::assertEquals([SyntheticPohodaExport::CONTRACT_OWN => null, SyntheticPohodaExport::CONTRACT_PARTNER => $projectId, SyntheticPohodaExport::CONTRACT_UNUSED => null],
            array_column($values, 'project_id', 'code'));

        self::assertSame((string) $projectId, (string) $pdo->query("SELECT project_id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = '26FV0001'")->fetchColumn());
        self::assertNull($pdo->query("SELECT project_id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND varsymbol = '26PF0001'")->fetchColumn() ?: null);

        $headers = $pdo->query("SELECT dd.doc_type, v.code FROM document_dimensions dd JOIN dimension_values v ON v.id = dd.dimension_value_id
             WHERE dd.supplier_id = {$supplierId} AND dd.dimension_type_id = {$typeId} AND dd.item_no = 0 ORDER BY dd.doc_type")->fetchAll(\PDO::FETCH_NUM);
        self::assertSame([
            ['purchase_invoice', SyntheticPohodaExport::CONTRACT_OWN],
            ['invoice', SyntheticPohodaExport::CONTRACT_PARTNER],
            ['bank_transaction', SyntheticPohodaExport::CONTRACT_OWN],
        ], $headers);

        $items = $pdo->query("SELECT dd.doc_type, dd.item_no, v.code FROM document_dimensions dd JOIN dimension_values v ON v.id = dd.dimension_value_id
             WHERE dd.supplier_id = {$supplierId} AND dd.dimension_type_id = {$typeId} AND dd.item_no > 0")->fetchAll(\PDO::FETCH_NUM);
        self::assertSame([['invoice', 1, SyntheticPohodaExport::CONTRACT_OWN]], $items, 'položka s jinou zakázkou než hlavička');

        $lines = $pdo->query("SELECT e.document_no, v.code, l.project_id FROM journal_entry_lines l
              JOIN journal_entries e ON e.id = l.entry_id
              JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = {$typeId}
              JOIN dimension_values v ON v.id = d.dimension_value_id
             WHERE l.supplier_id = {$supplierId} ORDER BY e.document_no, l.line_no")->fetchAll(\PDO::FETCH_NUM);
        $byDocument = [];
        foreach ($lines as [$number, $code, $project]) {
            $byDocument[$number][] = [$code, $project !== null ? (int) $project : null];
        }
        self::assertSame(array_fill(0, 4, [SyntheticPohodaExport::CONTRACT_PARTNER, $projectId]), $byDocument['26FV0001'] ?? null);
        self::assertSame(array_fill(0, 4, [SyntheticPohodaExport::CONTRACT_OWN, null]), $byDocument['26PF0001'] ?? null);
        self::assertSame(array_fill(0, 2, [SyntheticPohodaExport::CONTRACT_OWN, null]), $byDocument['BAN0010003'] ?? null);
        self::assertSame(['26FV0001', '26PF0001', 'BAN0010003'], array_keys($byDocument), 'úhrady faktur zakázku v deníku nenesou');
    }

    /** @return array{lines:int,documents:int,projects:int} */
    private function tagged(int $supplierId): array
    {
        return [
            'lines' => $this->rows('journal_entry_line_dimensions', $supplierId),
            'documents' => $this->rows('document_dimensions', $supplierId),
            'projects' => (int) $this->db->pdo()->query("SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.supplier_id = {$supplierId}")->fetchColumn(),
        ];
    }

    /** @return array<string,int|float> */
    private function counts(ImportProtocol $protocol): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if (($step['key'] ?? '') === ContractImporter::STEP) {
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
