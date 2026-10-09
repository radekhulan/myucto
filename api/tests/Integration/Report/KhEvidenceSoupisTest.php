<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KhEvidenceService;
use MyInvoice\Service\Report\KhEvidenceXlsxExporter;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Soupis dokladů oddílu KH (issue #142) musí na haléř sedět s tím, co odchází v XML
 * kontrolního hlášení, a na celé koruny s přiznáním k DPH. Zařazení dokladu (limit
 * 10 000 Kč na DOKLAD, ne na řádek) se nesmí lišit od KH.
 */
#[Group('integration')]
final class KhEvidenceSoupisTest extends TestCase
{
    private const YEAR = 2098;
    private const MONTH = 11;

    private Connection $db;
    private KontrolniHlaseniBuilder $kh;
    private DphPriznaniBuilder $dph;
    private KhEvidenceService $evidence;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    /** @var int[] */
    private array $clientIds = [];
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $purchaseIds = [];
    private ?array $origVatFlags = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->kh = $container->get(KontrolniHlaseniBuilder::class);
            $this->dph = $container->get(DphPriznaniBuilder::class);
            $this->evidence = $container->get(KhEvidenceService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->origVatFlags = $pdo->query(
            "SELECT is_vat_payer, is_identified, dic FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(\PDO::FETCH_ASSOC) ?: [];
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678' WHERE id = ?")
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->origVatFlags !== null && $this->supplierId > 0) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ?, dic = ? WHERE id = ?')
                ->execute([
                    (int) ($this->origVatFlags['is_vat_payer'] ?? 1),
                    (int) ($this->origVatFlags['is_identified'] ?? 0),
                    $this->origVatFlags['dic'] ?? null,
                    $this->supplierId,
                ]);
        }
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->purchaseIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testSoupisMatchesControlStatementToTheHalerAndVatReturnToTheCrown(): void
    {
        $this->seedPeriod();

        $xml = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        $root = $xml->DPHKH1;
        $docs = $this->kh->sectionDocuments($this->supplierId, self::YEAR, self::MONTH);
        $s = $docs['sections'];

        // A.4 — věty jednotlivě; součet soupisu = součet vět XML, čísla dokladů stejná.
        $this->assertSame(
            $this->sumAttr($root->VetaA4, 'zakl_dane1'),
            $this->cents($s['A.4']['totals']['base21']),
            'A.4 základ: soupis = KH',
        );
        $this->assertSame($this->sumAttr($root->VetaA4, 'dan1'), $this->cents($s['A.4']['totals']['vat21']));
        $this->assertSame($this->sumAttr($root->VetaA4, 'zakl_dane2'), $this->cents($s['A.4']['totals']['base12']));
        $this->assertSame($this->sumAttr($root->VetaA4, 'dan2'), $this->cents($s['A.4']['totals']['vat12']));
        $xmlA4 = [];
        foreach ($root->VetaA4 as $v) {
            $xmlA4[] = (string) $v['c_evid_dd'];
        }
        $soupisA4 = array_column($s['A.4']['rows'], 'doc_number');
        sort($xmlA4);
        sort($soupisA4);
        $this->assertSame($xmlA4, $soupisA4);

        // Limit 10 000 Kč se posuzuje na DOKLAD: dvě položky po 7 260 Kč = A.4,
        // doklad za 6 050 Kč i doklad přesně za 10 000 Kč do A.4 nepatří.
        $this->assertContains('2098110003', $soupisA4, 'Doklad nad limit složený z položek pod limitem patří do A.4.');
        $this->assertNotContains('2098110004', $soupisA4, 'Doklad pod 10 000 Kč nesmí být v A.4.');
        $this->assertNotContains('2098110005', $soupisA4, 'Doklad přesně za 10 000 Kč patří do A.5.');
        $a5Docs = array_column($s['A.5']['rows'], 'doc_number');
        $this->assertContains('2098110004', $a5Docs);
        $this->assertContains('2098110005', $a5Docs);

        // A.5 a B.3 — souhrnná věta = součet soupisu.
        $this->assertSame($this->cents((float) $root->VetaA5['zakl_dane1']), $this->cents($s['A.5']['totals']['base21']));
        $this->assertSame($this->cents((float) $root->VetaA5['dan1']), $this->cents($s['A.5']['totals']['vat21']));
        $this->assertSame($this->cents((float) $root->VetaB3['zakl_dane1']), $this->cents($s['B.3']['totals']['base21']));
        $this->assertSame($this->cents((float) $root->VetaB3['dan1']), $this->cents($s['B.3']['totals']['vat21']));

        // B.2
        $this->assertSame($this->sumAttr($root->VetaB2, 'zakl_dane1'), $this->cents($s['B.2']['totals']['base21']));
        $this->assertSame($this->sumAttr($root->VetaB2, 'dan1'), $this->cents($s['B.2']['totals']['vat21']));

        // Haléřové součty dokladů (ručně): A.4 = 20 000,37 + 12 000,00 + 15 000,11.
        $this->assertSame(4700048, $this->cents($s['A.4']['totals']['base21']));
        $this->assertSame(987010,$this->cents($s['A.4']['totals']['vat21']));

        // DPHDP3 ř.1 v celých Kč = součet A.4 + A.5 zaokrouhlený AŽ na konci.
        $dp = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']);
        $base21 = ($this->cents($s['A.4']['totals']['base21']) + $this->cents($s['A.5']['totals']['base21'])) / 100;
        $vat21 = ($this->cents($s['A.4']['totals']['vat21']) + $this->cents($s['A.5']['totals']['vat21'])) / 100;
        $this->assertSame((string) round($base21), (string) $dp->DPHDP3->Veta1['obrat23']);
        $this->assertSame((string) round($vat21), (string) $dp->DPHDP3->Veta1['dan23']);
        $pBase = ($this->cents($s['B.2']['totals']['base21']) + $this->cents($s['B.3']['totals']['base21'])) / 100;
        $this->assertSame((string) round($pBase), (string) $dp->DPHDP3->Veta4['pln23']);
    }

    public function testReportFilterAndXlsxCarryTheSameNumericTotals(): void
    {
        $this->seedPeriod();
        $report = $this->evidence->current($this->supplierId, self::YEAR, self::MONTH, 'monthly', 'A.4');
        $this->assertSame(['A.4'], array_keys($report['sections']));
        $this->assertSame('current', $report['source']);

        $pdf = (new \MyInvoice\Service\Pdf\KhEvidencePdfRenderer())->render($report);
        $this->assertStringStartsWith('%PDF', $pdf);

        $out = (new KhEvidenceXlsxExporter())->export($report, 'soupis.xlsx');
        $tmp = tempnam(sys_get_temp_dir(), 'khtest_') . '.xlsx';
        file_put_contents($tmp, $out['bytes']);
        try {
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
            $found = null;
            foreach ($sheet->getRowIterator() as $row) {
                $r = $row->getRowIndex();
                if (str_starts_with((string) $sheet->getCell([1, $r])->getValue(), 'Celkem ')) {
                    $found = $r;
                }
            }
            $this->assertNotNull($found, 'XLSX nemá řádek součtu.');
            $cell = $sheet->getCell([6, $found]);
            $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC, $cell->getDataType());
            $this->assertSame(4700048, $this->cents((float) $cell->getValue()));
        } finally {
            @unlink($tmp);
        }
    }

    private function seedPeriod(): void
    {
        $cust = $this->client('Testovací odběratel', 'CZ11111118', customer: true);
        $vend = $this->client('Testovací dodavatel', 'CZ22222220', vendor: true);
        $d = static fn (int $day): string => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);

        $this->sale('2098110001', $cust, $d(3), [[20000.37, 4200.08]]);
        $this->sale('2098110002', $cust, $d(4), [[15000.11, 3150.02]]);
        // Dvě položky pod limitem, doklad nad limit.
        $this->sale('2098110003', $cust, $d(5), [[6000.00, 1260.00], [6000.00, 1260.00]]);
        $this->sale('2098110004', $cust, $d(6), [[5000.33, 1050.07]]);
        // Přesně 10 000 Kč včetně DPH.
        $this->sale('2098110005', $cust, $d(7), [[8264.46, 1735.54]]);

        $this->purchase('P-2098-001', $vend, $d(8), [[12000.55, 2520.12]]);
        $this->purchase('P-2098-002', $vend, $d(9), [[1999.99, 420.00]]);
        $this->purchase('P-2098-003', $vend, $d(10), [[3000.01, 630.00]]);
    }

    private function sumAttr(\SimpleXMLElement $nodes, string $attr): int
    {
        $sum = 0;
        foreach ($nodes as $n) {
            $sum += $this->cents((float) $n[$attr]);
        }
        return $sum;
    }

    private function cents(float|int|null $v): int
    {
        return (int) round(((float) $v) * 100);
    }

    private function client(string $name, ?string $dic, bool $customer = false, bool $vendor = false): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, ?, ?)'
        )->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    /** @param list<array{0:float,1:float}> $items */
    private function sale(string $varsymbol, int $clientId, string $date, array $items): void
    {
        [$base, $vat] = $this->sum($items);
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, NULL, 0, ?, ?, ?, "issued", "1", ?)'
        )->execute([$this->supplierId, $varsymbol, $clientId, $date, $date, $date, $this->currencyId, $base, $vat, $base + $vat, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invoiceIds[] = $id;
        $this->items('invoice_items', 'invoice_id', $id, $items);
    }

    /** @param list<array{0:float,1:float}> $items */
    private function purchase(string $number, int $vendorId, string $date, array $items): int
    {
        [$base, $vat] = $this->sum($items);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 is_fixed_asset, vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, NULL, 0, "{}", ?, ?, ?, "received", "40", 0, "full", 100, ?)'
        )->execute([$this->supplierId, $vendorId, $number, $date, $date, $date, $date, $this->currencyId, $base, $vat, $base + $vat, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;
        $this->items('purchase_invoice_items', 'purchase_invoice_id', $id, $items);
        return $id;
    }

    /** @param list<array{0:float,1:float}> $items */
    private function items(string $table, string $fk, int $id, array $items): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, 21, ?, ?, ?, ?)"
        );
        foreach ($items as $i => [$base, $vat]) {
            $stmt->execute([$id, $base, $this->vatRateId, $base, $vat, $base + $vat, $i]);
        }
    }

    /** @param list<array{0:float,1:float}> $items @return array{0:float,1:float} */
    private function sum(array $items): array
    {
        $b = 0.0; $v = 0.0;
        foreach ($items as [$base, $vat]) { $b += $base; $v += $vat; }
        return [round($b, 2), round($v, 2)];
    }
}
