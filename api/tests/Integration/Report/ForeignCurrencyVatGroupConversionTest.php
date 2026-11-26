<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Invoice\CzkRecap;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doklad v cizí měně: Kč základu a daně sazby v evidenci DPH jsou přesně částky
 * rekapitulace DPH v Kč na daňovém dokladu ({@see CzkRecap::build()}).
 *
 * Rekapitulace přepočítává SOUČET sazby, evidence dřív přepočítávala každou položku
 * zvlášť a sčítala. Položky 467,35 + 8,35 + 0,34 EUR po 21 % kurzem 24,36 dávají po
 * položkách 11 384,65 + 203,41 + 8,28 = 11 596,34 Kč, doklad uvádí 476,04 × 24,36
 * = 11 596,33 Kč. Přiznání, KH, kniha DPH i zápis 343/6xx se tak o haléř rozcházely
 * s dokladem. Druhý doklad (1 218,75 + 21,79 + 0,78 EUR) se rozchází opačně
 * (30 238,55 po položkách, 30 238,56 za sazbu).
 *
 * Izolovaný rok 2098 pod existujícím supplierem, úklid v tearDown.
 */
#[Group('integration')]
final class ForeignCurrencyVatGroupConversionTest extends TestCase
{
    private const YEAR = 2098;
    private const MONTH = 3;
    private const RATE = 24.36;

    private Connection $db;
    private \Psr\Container\ContainerInterface $container;

    private int $supplierId = 0;
    private int $czkId = 0;
    private int $eurId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $deId = 0;
    private bool $createdEur = false;
    private ?array $origVatFlags = null;

    /** @var int[] */
    private array $clientIds = [];
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $purchaseIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query("SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->deId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'DE' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0 || $this->czId === 0 || $this->deId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user/vat_rate/country) v DB.');
        }
        $currency = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY id LIMIT 1');
        $currency->execute([$this->supplierId, 'CZK']);
        $this->czkId = (int) ($currency->fetchColumn() ?: 0);
        $currency->execute([$this->supplierId, 'EUR']);
        $this->eurId = (int) ($currency->fetchColumn() ?: 0);
        if ($this->czkId === 0) {
            $this->markTestSkipped('Firma nemá měnu CZK.');
        }
        if ($this->eurId === 0) {
            $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active)
                           VALUES (?, 'EUR', 'EUR', '€', 'euro', 'euro', 2, 1)")->execute([$this->supplierId]);
            $this->eurId = (int) $pdo->lastInsertId();
            $this->createdEur = true;
        }
        $this->origVatFlags = $pdo->query("SELECT is_vat_payer, is_identified, dic FROM supplier WHERE id = {$this->supplierId}")
            ->fetch(\PDO::FETCH_ASSOC) ?: null;
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678' WHERE id = ?")
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->origVatFlags !== null) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ?, dic = ? WHERE id = ?')->execute([
                (int) $this->origVatFlags['is_vat_payer'], (int) $this->origVatFlags['is_identified'], $this->origVatFlags['dic'], $this->supplierId,
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
        if ($this->createdEur) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$this->eurId]);
        }
        $this->db->close();
    }

    public function testIssuedForeignCurrencyReturnKhBookAndPostingEqualDocumentRecap(): void
    {
        $customer = $this->client('Odběratel EUR', $this->czId, 'CZ27082440', customer: true);
        $first = $this->sale('FX-GROUP-1', $customer, [[467.35, 98.14], [8.35, 1.75], [0.34, 0.07]]);
        $second = $this->sale('FX-GROUP-2', $customer, [[1218.75, 255.94], [21.79, 4.58], [0.78, 0.16]]);

        $recaps = [];
        foreach ([$first, $second] as $id) {
            $recap = $this->container->get(InvoiceRepository::class)->find($id)['czk_recap'] ?? null;
            self::assertIsArray($recap);
            self::assertCount(1, $recap['breakdown']);
            $recaps[$id] = $recap['breakdown'][0];
        }
        self::assertSame(11596.33, $recaps[$first]['base_czk'], 'Rekapitulace: 476,04 EUR × 24,36.');
        self::assertSame(30238.56, $recaps[$second]['base_czk'], 'Rekapitulace: 1 241,32 EUR × 24,36.');
        self::assertSame(6350.16, $recaps[$second]['vat_czk'], 'Rekapitulace: 260,68 EUR × 24,36.');

        $ledger = $this->container->get(VatLedgerService::class);
        foreach ($recaps as $id => $recap) {
            $rows = $ledger->documentRows($this->supplierId, 'sale', $id, $this->start(), $this->end());
            self::assertCount(3, $rows);
            self::assertSame($recap['base_czk'], round(array_sum(array_column($rows, 'base_czk')), 2), 'Základ sazby v evidenci = rekapitulace dokladu.');
            self::assertSame($recap['vat_czk'], round(array_sum(array_column($rows, 'vat_czk')), 2), 'Daň sazby v evidenci = rekapitulace dokladu.');
        }
        $periodRows = array_values(array_filter(
            $ledger->rows($this->supplierId, $this->start(), $this->end()),
            fn (array $r): bool => $r['source'] === 'sale' && in_array($r['invoice_id'], $this->invoiceIds, true),
        ));
        self::assertSame(
            [...$ledger->documentRows($this->supplierId, 'sale', $first, $this->start(), $this->end()),
             ...$ledger->documentRows($this->supplierId, 'sale', $second, $this->start(), $this->end())],
            $periodRows,
            'Evidence za období a řádky jednoho dokladu (zaúčtování) dávají totéž.',
        );

        $base = round($recaps[$first]['base_czk'] + $recaps[$second]['base_czk'], 2);
        $vat = round($recaps[$first]['vat_czk'] + $recaps[$second]['vat_czk'], 2);

        $line1 = $this->container->get(DphPriznaniBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['summary']['lines']['1'];
        self::assertSame($base, round((float) $line1['base'], 2), 'DPHDP3 ř. 1 základ = součet rekapitulací.');
        self::assertSame($vat, round((float) $line1['vat'], 2), 'DPHDP3 ř. 1 daň = součet rekapitulací.');

        $kh = simplexml_load_string($this->container->get(KontrolniHlaseniBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH)['xml']);
        self::assertNotFalse($kh);
        $a4 = [];
        foreach ($kh->DPHKH1->VetaA4 as $veta) {
            $a4[(string) $veta['c_evid_dd']] = [(float) $veta['zakl_dane1'], (float) $veta['dan1']];
        }
        self::assertSame([$recaps[$first]['base_czk'], $recaps[$first]['vat_czk']], $a4['FX-GROUP-1'] ?? null, 'KH A.4 = rekapitulace dokladu.');
        self::assertSame([$recaps[$second]['base_czk'], $recaps[$second]['vat_czk']], $a4['FX-GROUP-2'] ?? null, 'KH A.4 = rekapitulace dokladu.');

        $book = $this->container->get(DphBookBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertSame($base, round((float) $book['totals']['issued']['base'], 2), 'Kniha DPH = součet rekapitulací.');
        self::assertSame($vat, round((float) $book['totals']['issued']['vat'], 2));

        foreach ($recaps as $id => $recap) {
            $lines = $this->container->get(PostingService::class)->buildFromInvoice($this->supplierId, $id);
            $sum = static fn (string $prefix): float => round(array_sum(array_map(
                static fn (array $l): float => str_starts_with($l['account_code'], $prefix) ? (float) $l['amount'] : 0.0,
                $lines,
            )), 2);
            self::assertSame($recap['vat_czk'], $sum('343'), 'Zápis: 343 = daň z rekapitulace dokladu.');
            self::assertSame($recap['base_czk'], $sum('60'), 'Zápis: výnos = základ z rekapitulace dokladu.');
        }
    }

    /**
     * Přijatý doklad v cizí měně se přepočítává stejným pravidlem: tuzemský odpočet
     * i samovyměření z EU (ř. 5). Samovyměřená daň se dál počítá ze
     * základu řádku v Kč.
     */
    public function testPurchaseForeignCurrencyRatesConvertPerRateGroup(): void
    {
        $vendor = $this->client('Dodavatel EUR', $this->czId, 'CZ27082440', vendor: true);
        $euVendor = $this->client('EU dodavatel EUR', $this->deId, 'DE123456789', vendor: true);
        $domestic = $this->purchase('FX-PURCH-1', $vendor, '40', false, [[467.35, 98.14], [8.35, 1.75], [0.34, 0.07]]);
        $selfAssessed = $this->purchase('FX-PURCH-2', $euVendor, '24e', true, [[467.35, 0.0], [8.35, 0.0], [0.34, 0.0]]);

        $ledger = $this->container->get(VatLedgerService::class);
        $rows = $ledger->documentRows($this->supplierId, 'purchase', $domestic, $this->start(), $this->end());
        self::assertSame(11596.33, round(array_sum(array_column($rows, 'base_czk')), 2));
        self::assertSame(CzkRecap::multiplyHalfUp(99.96, self::RATE), round(array_sum(array_column($rows, 'vat_czk')), 2));

        $rows = $ledger->documentRows($this->supplierId, 'purchase', $selfAssessed, $this->start(), $this->end());
        self::assertSame(11596.33, round(array_sum(array_column($rows, 'base_czk')), 2), 'Základ samovyměření = přepočet součtu sazby.');
        foreach ($rows as $row) {
            self::assertSame(round($row['base_czk'] * 0.21, 2), $row['vat_czk'], 'Samovyměřená daň zůstává ze základu řádku v Kč.');
        }

        $lines = $this->container->get(DphPriznaniBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['summary']['lines'];
        self::assertSame(11596.33, round((float) $lines['40']['base'], 2), 'DPHDP3 ř. 40 = přepočet součtu sazby.');
        self::assertSame(11596.33, round((float) $lines['5']['base'], 2), 'DPHDP3 ř. 5 = přepočet součtu sazby.');
    }

    private function start(): string
    {
        return sprintf('%04d-%02d-01', self::YEAR, self::MONTH);
    }

    private function end(): string
    {
        return (new \DateTimeImmutable($this->start()))->modify('last day of this month')->format('Y-m-d');
    }

    private function date(): string
    {
        return sprintf('%04d-%02d-10', self::YEAR, self::MONTH);
    }

    private function client(string $name, int $countryId, string $dic, bool $customer = false, bool $vendor = false): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "fx-group@example.test", "cs", ?, ?, ?)'
        )->execute([$this->supplierId, $name, $countryId, $dic, $this->czkId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    /** @param list<array{0:float,1:float}> $items základ a daň položky v EUR (21 %) */
    private function sale(string $number, int $clientId, array $items): int
    {
        [$base, $vat] = self::totals($items);
        $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                                   currency_id, exchange_rate, exchange_rate_date, reverse_charge,
                                   total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", "1", ?)'
        )->execute([
            $this->supplierId, $number, $clientId, $this->date(), $this->date(), $this->date(),
            $this->eurId, self::RATE, $this->date(), $base, $vat, round($base + $vat, 2), $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invoiceIds[] = $id;
        $this->items('invoice_items', 'invoice_id', $id, $items, '1');
        return $id;
    }

    /** @param list<array{0:float,1:float}> $items */
    private function purchase(string $number, int $vendorId, string $code, bool $reverseCharge, array $items): int
    {
        [$base, $vat] = self::totals($items);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                                            due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                                            total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                                            vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, "{}", ?, ?, ?, "received", ?, "full", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, $this->date(), $this->date(), $this->date(), $this->date(),
            $this->eurId, self::RATE, $reverseCharge ? 1 : 0, $base, $vat, round($base + $vat, 2), $code, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;
        $this->items('purchase_invoice_items', 'purchase_invoice_id', $id, $items, $code);
        return $id;
    }

    /** @param list<array{0:float,1:float}> $items */
    private function items(string $table, string $fk, int $id, array $items, string $code): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$table} ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                                   total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, 'Položka', 1, 'ks', ?, ?, 21, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $i => [$base, $vat]) {
            $stmt->execute([$id, $base, $this->vatRateId, $base, $vat, round($base + $vat, 2), $i, $code]);
        }
    }

    /**
     * @param list<array{0:float,1:float}> $items
     * @return array{0:float,1:float}
     */
    private static function totals(array $items): array
    {
        return [round(array_sum(array_column($items, 0)), 2), round(array_sum(array_column($items, 1)), 2)];
    }
}
