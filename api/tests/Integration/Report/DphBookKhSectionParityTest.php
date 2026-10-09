<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sloupec „KH" Knihy DPH = zařazení dokladu v kontrolním hlášení. Kniha nesmí mít
 * vlastní kopii pravidla A.4/A.5 a B.2/B.3: limit 10 000 Kč na doklad, chybějící DIČ,
 * příznak opravy nedobytné pohledávky (zdph_44 = P jde do A.4/B.2 i pod limitem),
 * vyřazené řádky (rozdílné atributy, přenesená daň bez DIČ) a opravy § 74b.
 */
#[Group('integration')]
final class DphBookKhSectionParityTest extends TestCase
{
    private const YEAR = 2097;
    private const MONTH = 10;

    private Connection $db;
    private KontrolniHlaseniBuilder $kh;
    private DphBookBuilder $book;

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
    /** @var int[] */
    private array $classificationIds = [];
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
            $this->book = $container->get(DphBookBuilder::class);
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
            $pdo->prepare('DELETE FROM vat_s74b_corrections WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        foreach ($this->classificationIds as $id) {
            $pdo->prepare('DELETE FROM vat_classifications WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testBookKhColumnEqualsControlStatementPlacement(): void
    {
        $this->classification('T46P', 'sale', '1', 'A.4', 21.0, 'P');
        $cust = $this->client('Testovací odběratel', 'CZ11111118', customer: true);
        $custNoDic = $this->client('Odběratel bez DIČ', null, customer: true);
        $vend = $this->client('Testovací dodavatel', 'CZ22222220', vendor: true);
        $vendNoDic = $this->client('Dodavatel bez DIČ', null, vendor: true);
        $d = static fn (int $day): string => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);

        $over = $this->sale('2097100001', $cust, $d(3), [[20000.00, 4200.00, '1']]);
        $composed = $this->sale('2097100002', $cust, $d(4), [[6000.00, 1260.00, '1'], [6000.00, 1260.00, '1']]);
        $exact = $this->sale('2097100003', $cust, $d(5), [[8264.46, 1735.54, '1']]);
        $noDic = $this->sale('2097100004', $custNoDic, $d(6), [[20000.00, 4200.00, '1']]);
        $badDebt = $this->sale('2097100005', $cust, $d(7), [[1000.00, 210.00, 'T46P']]);
        $conflict = $this->sale('2097100006', $cust, $d(8), [[10000.00, 2100.00, '1'], [10000.00, 2100.00, '1c']]);
        $draft = $this->sale('2097100007', $cust, $d(9), [[20000.00, 4200.00, '1']], status: 'draft');

        $purchaseOver = $this->purchase('P-2097-001', $vend, $d(10), [[12000.00, 2520.00, '40']]);
        $rcNoDic = $this->purchase('P-2097-002', $vendNoDic, $d(11), [[50000.00, 0.0, '5c']], reverseCharge: true);
        // § 74b: doklad z dřívějšího období, oprava odpočtu zaevidovaná v tomto období.
        // Dodavatel bez DIČ → KH řádek opravy neuvede, Kniha nesmí tvrdit B.2.
        $old74b = $this->purchase('P-2096-074', $vendNoDic, '2096-01-15', [[10000.00, 2100.00, '40']]);
        $this->db->pdo()->prepare(
            "INSERT INTO vat_s74b_corrections
                (supplier_id, purchase_invoice_id, period_year, period_month, movement, vat_amount,
                 claimed_deduction_vat, unpaid_ratio, state)
             VALUES (?, ?, ?, ?, 'reduction', 2100.00, 2100.00, 1.0, 'corrected')"
        )->execute([$this->supplierId, $old74b, self::YEAR, self::MONTH]);

        $book = $this->bookPlacement();
        $kh = $this->khPlacement();

        $expected = [
            'sale:' . $over        => 'A.4',
            'sale:' . $composed    => 'A.4',
            'sale:' . $exact       => 'A.5',
            'sale:' . $noDic       => 'A.5',
            'sale:' . $badDebt     => 'A.4',
            'sale:' . $conflict    => null,
            'purchase:' . $purchaseOver => 'B.2',
            'purchase:' . $rcNoDic => null,
        ];
        foreach ($expected as $doc => $section) {
            $this->assertArrayHasKey($doc, $book, "Doklad {$doc} chybí v Knize DPH.");
            $this->assertSame($section, $kh[$doc] ?? null, "KH zařazení dokladu {$doc}.");
            $this->assertSame($kh[$doc] ?? null, $book[$doc], "Kniha DPH musí ukázat stejný oddíl KH jako výkaz u dokladu {$doc}.");
        }

        // Obecně: každý doklad Knihy (mimo koncepty a opravy § 74b) má oddíl z KH.
        foreach ($book as $doc => $section) {
            if (str_starts_with($doc, 's74b:') || $doc === 'sale:' . $draft) {
                continue;
            }
            $this->assertSame($kh[$doc] ?? null, $section, "Kniha DPH se rozchází s KH u dokladu {$doc}.");
        }

        // Koncept do podaného KH nevstupuje, Kniha ho ale ukazuje se zařazením, jaké dostane po vystavení.
        $this->assertSame('A.4', $book['sale:' . $draft]);
        $this->assertArrayNotHasKey('sale:' . $draft, $kh);

        // Oprava § 74b bez DIČ dodavatele v KH B.2 není, Kniha ji tak nesmí označit.
        $this->assertArrayHasKey('s74b:' . $old74b, $book, 'Řádek opravy § 74b v Knize DPH.');
        $this->assertNull($book['s74b:' . $old74b]);
    }

    /** @return array<string,?string> doklad → oddíl KH ve sloupci Knihy (primární řádky) */
    private function bookPlacement(): array
    {
        $out = [];
        $r = $this->book->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        foreach ($r['sections'] as $s) {
            if (!empty($s['is_secondary'])) {
                continue;
            }
            foreach ($s['rows'] as $row) {
                $source = ($row['direction'] ?? '') === 'issued' ? 'sale' : 'purchase';
                $key = str_contains((string) ($row['description'] ?? ''), '§74b')
                    ? 's74b:' . (int) $row['invoice_id']
                    : $source . ':' . (int) $row['invoice_id'];
                if (!in_array((int) $row['invoice_id'], [...$this->invoiceIds, ...$this->purchaseIds], true)) {
                    continue;
                }
                $out[$key] = $row['kh_section'] ?? null;
            }
        }
        return $out;
    }

    /** @return array<string,string> doklad → oddíl podle soupisu KH (issue #142) */
    private function khPlacement(): array
    {
        $out = [];
        $docs = $this->kh->sectionDocuments($this->supplierId, self::YEAR, self::MONTH);
        foreach ($docs['sections'] as $section => $data) {
            if ($section === 'none') {
                continue;
            }
            foreach ($data['rows'] as $row) {
                if ($row['invoice_id'] !== null) {
                    $out[$row['source'] . ':' . $row['invoice_id']] = $section;
                }
            }
        }
        return $out;
    }

    private function classification(string $code, string $direction, string $line, string $kh, float $rate, ?string $badDebt): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO vat_classifications
                (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, kh_bad_debt)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)'
        )->execute([$this->supplierId, $code, 'Test ' . $code, $direction, $line, $kh, $rate, $badDebt]);
        $this->classificationIds[] = (int) $this->db->pdo()->lastInsertId();
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

    /** @param list<array{0:float,1:float,2:string}> $items */
    private function sale(string $varsymbol, int $clientId, string $date, array $items, string $status = 'issued'): int
    {
        [$base, $vat] = $this->sum($items);
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, NULL, 0, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $varsymbol, $clientId, $date, $date, $date, $this->currencyId,
                    $base, $vat, $base + $vat, $status, $items[0][2], $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invoiceIds[] = $id;
        $this->items('invoice_items', 'invoice_id', $id, $items);
        return $id;
    }

    /** @param list<array{0:float,1:float,2:string}> $items */
    private function purchase(string $number, int $vendorId, string $date, array $items, bool $reverseCharge = false): int
    {
        [$base, $vat] = $this->sum($items);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 is_fixed_asset, vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, NULL, ?, "{}", ?, ?, ?, "received", ?, 0, "full", 100, ?)'
        )->execute([$this->supplierId, $vendorId, $number, $date, $date, $date, $date, $this->currencyId,
                    $reverseCharge ? 1 : 0, $base, $vat, $base + $vat, $items[0][2], $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;
        $this->items('purchase_invoice_items', 'purchase_invoice_id', $id, $items);
        return $id;
    }

    /** @param list<array{0:float,1:float,2:string}> $items */
    private function items(string $table, string $fk, int $id, array $items): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, vat_classification_code, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, 21, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $i => [$base, $vat, $code]) {
            $stmt->execute([$id, $base, $this->vatRateId, $base, $vat, $base + $vat, $code, $i]);
        }
    }

    /** @param list<array{0:float,1:float,2:string}> $items @return array{0:float,1:float} */
    private function sum(array $items): array
    {
        $b = 0.0; $v = 0.0;
        foreach ($items as [$base, $vat]) { $b += $base; $v += $vat; }
        return [round($b, 2), round($v, 2)];
    }
}
