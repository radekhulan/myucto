<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatCrossCheckService;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Smíšený doklad s příznakem přenesení daňové povinnosti v hlavičce: řádek § 92a
 * (kód 5 / 25s) a vedle něj tuzemský řádek (kód 40 / 1) s daní dodavatele. Přiznání
 * vykazuje tuzemský řádek na ř. 40/41 (resp. ř. 1/2), takže v KH musí být v B.2/B.3
 * (resp. A.4/A.5). Zda je řádek tuzemský, rozhoduje klasifikace řádku, ne hlavička.
 */
#[Group('integration')]
final class KhMixedReverseChargeDocumentTest extends TestCase
{
    private const YEAR = 2097;
    private const MONTH = 11;

    private Connection $db;
    private KontrolniHlaseniBuilder $kh;
    private DphPriznaniBuilder $dph;
    private DphBookBuilder $book;
    private VatCrossCheckService $crossCheck;
    private VatLedgerService $ledger;

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
            $this->book = $container->get(DphBookBuilder::class);
            $this->crossCheck = $container->get(VatCrossCheckService::class);
            $this->ledger = $container->get(VatLedgerService::class);
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

    public function testDomesticLineOfMixedReverseChargeDocumentIsReportedLikeInTheReturn(): void
    {
        $cust = $this->client('Testovací odběratel', 'CZ11111118', customer: true);
        $vend = $this->client('Testovací dodavatel', 'CZ22222220', vendor: true);
        $d = static fn (int $day): string => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);

        // Přijaté: řádek § 92a (kód 5) + tuzemský řádek s daní dodavatele (kód 40).
        // Doklad nad limitem → tuzemská část do B.2, pod limitem → B.3.
        $pOver = $this->purchase('P-2097-101', $vend, $d(3), [[50000.00, 0.0, '5', 21.0], [20000.00, 4200.00, '40', 21.0]]);
        $pUnder = $this->purchase('P-2097-102', $vend, $d(4), [[3000.00, 0.0, '5', 21.0], [2000.00, 420.00, '40', 21.0]]);
        // Kód 40 se sníženou sazbou: přiznání ho podle sazby řádku přesune na ř. 41.
        $pReduced = $this->purchase('P-2097-103', $vend, $d(5), [[4000.00, 0.0, '5', 21.0], [1000.00, 120.00, '40', 12.0]]);

        // Vystavené: řádek § 92a (kód 25s) + tuzemský řádek s daní (kód 1).
        $sOver = $this->sale('2097110001', $cust, $d(6), [[30000.00, 0.0, '25s', 21.0], [15000.00, 3150.00, '1', 21.0]]);
        $sUnder = $this->sale('2097110002', $cust, $d(7), [[2000.00, 0.0, '25s', 21.0], [1000.00, 210.00, '1', 21.0]]);

        $kh = (new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, self::MONTH)['xml']))->DPHKH1;
        $dp = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['xml']))->DPHDP3;

        // ── přijatá strana ──────────────────────────────────────────────────
        $b1 = 0.0;
        foreach ($kh->VetaB1 as $v) {
            $b1 += (float) $v['zakl_dane1'];
        }
        $this->assertSame(57000.0, $b1, 'B.1 = řádky § 92a všech tří dokladů (50 000 + 3 000 + 4 000).');
        $this->assertSame('57000', (string) $dp->Veta1['rez_pren23'], 'ř. 10 = tytéž řádky § 92a.');

        $this->assertCount(1, $kh->VetaB2, 'B.2: tuzemský řádek dokladu nad limitem.');
        $this->assertSame('P-2097-101', (string) $kh->VetaB2[0]['c_evid_dd']);
        $this->assertSame('20000.00', (string) $kh->VetaB2[0]['zakl_dane1']);
        $this->assertSame('4200.00', (string) $kh->VetaB2[0]['dan1']);

        $this->assertSame('2000.00', (string) $kh->VetaB3['zakl_dane1'], 'B.3 21 %: tuzemský řádek dokladu pod limitem.');
        $this->assertSame('420.00', (string) $kh->VetaB3['dan1']);
        $this->assertSame('1000.00', (string) $kh->VetaB3['zakl_dane2'], 'B.3 12 %: kód 40 se sníženou sazbou.');
        $this->assertSame('120.00', (string) $kh->VetaB3['dan2']);

        // B.2 + B.3 = ř. 40 / ř. 41.
        $this->assertSame('22000', (string) $dp->Veta4['pln23'], 'ř. 40 základ = B.2 + B.3 (21 %).');
        $this->assertSame('4620', (string) $dp->Veta4['odp_tuz23_nar'], 'ř. 40 odpočet = B.2 + B.3 (21 %).');
        $this->assertSame('1000', (string) $dp->Veta4['pln5'], 'ř. 41 základ = B.3 (12 %).');
        $this->assertSame('120', (string) $dp->Veta4['odp_tuz5_nar'], 'ř. 41 odpočet = B.3 (12 %).');

        // ── vydaná strana ───────────────────────────────────────────────────
        $a1 = 0.0;
        foreach ($kh->VetaA1 as $v) {
            $a1 += (float) $v['zakl_dane1'];
        }
        $this->assertSame(32000.0, $a1, 'A.1 = řádky § 92a (30 000 + 2 000).');
        $this->assertCount(1, $kh->VetaA4, 'A.4: tuzemský řádek dokladu nad limitem.');
        $this->assertSame('15000.00', (string) $kh->VetaA4[0]['zakl_dane1']);
        $this->assertSame('3150.00', (string) $kh->VetaA4[0]['dan1']);
        $this->assertSame('1000.00', (string) $kh->VetaA5['zakl_dane1'], 'A.5: tuzemský řádek dokladu pod limitem.');
        $this->assertSame('210.00', (string) $kh->VetaA5['dan1']);
        $this->assertSame('16000', (string) $dp->Veta1['obrat23'], 'ř. 1 = A.4 + A.5.');
        $this->assertSame('3360', (string) $dp->Veta1['dan23']);

        // Sloupec KH Knihy DPH čte totéž zařazení: smíšený doklad je v obou oddílech.
        $sections = $this->kh->documentSections($this->supplierId, self::YEAR, self::MONTH);
        $placement = static function (array $sections, string $source, int $id): array {
            $s = [];
            foreach ($sections as $key => $docSections) {
                if (str_starts_with((string) $key, $source . ':') && str_ends_with((string) $key, ':' . $id)) {
                    $s = [...$s, ...array_keys($docSections)];
                }
            }
            sort($s);
            return $s;
        };
        $this->assertSame(['B.1', 'B.2'], $placement($sections, 'purchase', $pOver));
        $this->assertSame(['B.1', 'B.3'], $placement($sections, 'purchase', $pUnder));
        $this->assertSame(['B.1', 'B.3'], $placement($sections, 'purchase', $pReduced));
        $this->assertSame(['A.1', 'A.4'], $placement($sections, 'sale', $sOver));
        $this->assertSame(['A.1', 'A.5'], $placement($sections, 'sale', $sUnder));

        $book = $this->book->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $domesticBookRows = 0;
        foreach ($book['sections'] as $s) {
            foreach ($s['rows'] as $row) {
                if (!empty($s['is_secondary'])
                    || !in_array((int) $row['invoice_id'], [...$this->invoiceIds, ...$this->purchaseIds], true)
                    || !in_array((string) ($s['dphdp3_line'] ?? ''), ['1', '40', '41'], true)) {
                    continue;
                }
                $domesticBookRows++;
                $this->assertNotNull($row['kh_section'] ?? null, 'Tuzemský řádek smíšeného dokladu má v Knize DPH oddíl KH.');
            }
        }
        $this->assertSame(5, $domesticBookRows, 'Kniha DPH vede tuzemský řádek každého z pěti dokladů na ř. 1/40/41.');

        // Křížová kontrola DPHDP3 ř. 1+2 ↔ KH A.4 + A.5 nesmí u smíšených dokladů nic hlásit.
        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $this->assertSame([], array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['check'] === 'dphdp3_vs_kh_domestic',
        )), 'DPHDP3 ř. 1+2 a KH A.4 + A.5 se u smíšeného dokladu shodují.');

        // Příspěvek dokladu k dani z přiznání (kontrola účtu 343): řádky § 92a se vyruší,
        // tuzemský řádek je odpočet ř. 40/41.
        $rows = $this->ledger->rows($this->supplierId, $d(1), $d(30));
        [$declared] = (new \ReflectionMethod($this->crossCheck, 'declared343ByDoc'))->invoke($this->crossCheck, $rows);
        $this->assertSame(-4200.0, $declared['purchase_invoice:' . $pOver]);
        $this->assertSame(-420.0, $declared['purchase_invoice:' . $pUnder]);
        $this->assertSame(-120.0, $declared['purchase_invoice:' . $pReduced]);
    }

    public function testMixedReverseChargePurchasePostsDomesticVatAsDeduction(): void
    {
        $vend = $this->client('Testovací dodavatel', 'CZ22222220', vendor: true);
        $date = sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, 3);
        $id = $this->purchase('P-2097-111', $vend, $date, [[50000.00, 0.0, '5', 21.0], [20000.00, 4200.00, '40', 21.0]]);

        $lines = Bootstrap::buildContainer()->get(PostingService::class)->buildFromPurchaseInvoice($this->supplierId, $id);

        $sum = static function (array $lines, string $prefix, string $side): float {
            $s = 0.0;
            foreach ($lines as $l) {
                if (str_starts_with($l['account_code'], $prefix) && $l['side'] === $side) {
                    $s += (float) $l['amount'];
                }
            }
            return round($s, 2);
        };
        $this->assertSame(70000.0, $sum($lines, '5', 'debit'), 'Náklad = základ obou řádků.');
        $this->assertSame(14700.0, $sum($lines, '343', 'debit'), 'Odpočet = samovyměření § 92a 10 500 + daň dodavatele 4 200.');
        $this->assertSame(10500.0, $sum($lines, '343', 'credit'), 'Výstupní daň jen z řádku § 92a.');
        $this->assertSame(74200.0, $sum($lines, '321', 'credit'), 'Závazek včetně daně dodavatele.');
        $this->assertSame($sum($lines, '', 'debit'), $sum($lines, '', 'credit'), 'Zápis je vyvážený.');
        $this->assertSame([], array_values(array_filter(
            $lines,
            static fn (array $l): bool => in_array($l['account_code'], ['548', '648'], true),
        )), 'Žádné zaokrouhlovací dorovnání.');
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

    /** @param list<array{0:float,1:float,2:string,3:float}> $items */
    private function sale(string $varsymbol, int $clientId, string $date, array $items): int
    {
        [$base, $vat] = $this->sum($items);
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, NULL, 1, ?, ?, ?, "issued", ?, ?)'
        )->execute([$this->supplierId, $varsymbol, $clientId, $date, $date, $date, $this->currencyId,
                    $base, $vat, $base + $vat, $items[0][2], $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invoiceIds[] = $id;
        $this->items('invoice_items', 'invoice_id', $id, $items);
        return $id;
    }

    /** @param list<array{0:float,1:float,2:string,3:float}> $items */
    private function purchase(string $number, int $vendorId, string $date, array $items): int
    {
        [$base, $vat] = $this->sum($items);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 is_fixed_asset, vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, NULL, 1, "{}", ?, ?, ?, "received", ?, 0, "full", 100, ?)'
        )->execute([$this->supplierId, $vendorId, $number, $date, $date, $date, $date, $this->currencyId,
                    $base, $vat, $base + $vat, $items[0][2], $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;
        $this->items('purchase_invoice_items', 'purchase_invoice_id', $id, $items);
        return $id;
    }

    /** @param list<array{0:float,1:float,2:string,3:float}> $items */
    private function items(string $table, string $fk, int $id, array $items): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, vat_classification_code, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $i => [$base, $vat, $code, $rate]) {
            $stmt->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $base + $vat, $code, $i]);
        }
    }

    /** @param list<array{0:float,1:float,2:string,3:float}> $items @return array{0:float,1:float} */
    private function sum(array $items): array
    {
        $b = 0.0; $v = 0.0;
        foreach ($items as [$base, $vat]) { $b += $base; $v += $vat; }
        return [round($b, 2), round($v, 2)];
    }
}
