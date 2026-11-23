<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\NewInvoiceVatClassification;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\SouhrnneHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dodání knih podle § 71i: osvobozené plnění s nárokem na odpočet. Vestavěný kód `71i` míří
 * na ř. 26 (pln_ost) bez oddílu KH a v koeficientu § 76 je v čitateli i jmenovateli. Kód `3`
 * (osvobozené bez nároku, ř. 50) by koeficient snížil.
 */
#[Group('integration')]
final class DomesticExemptWithCreditClassificationTest extends TestCase
{
    private const YEAR = 2093;

    private Connection $db;
    private DphPriznaniBuilder $dph;
    private KontrolniHlaseniBuilder $kh;
    private SouhrnneHlaseniBuilder $sh;
    private VatLedgerService $ledger;
    private NewInvoiceVatClassification $newClassification;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $rate21Id = 0;
    private int $rate0Id = 0;
    private int $userId = 0;
    /** @var int[] */
    private array $clientIds = [];
    private int $clientId = 0;
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var array<string,mixed> */
    private array $origSupplier = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db                = $c->get(Connection::class);
            $this->dph               = $c->get(DphPriznaniBuilder::class);
            $this->kh                = $c->get(KontrolniHlaseniBuilder::class);
            $this->sh                = $c->get(SouhrnneHlaseniBuilder::class);
            $this->ledger            = $c->get(VatLedgerService::class);
            $this->newClassification = $c->get(NewInvoiceVatClassification::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->rate21Id   = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 AND valid_to IS NULL ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rate0Id    = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 0 AND is_reverse_charge = 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->rate21Id === 0
            || $this->rate0Id === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $this->origSupplier = $pdo->query(
            "SELECT is_vat_payer, is_identified, dic FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->clientId = $this->client('CZ', 'Knihkupectví Test s.r.o.', 'CZ11111118');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->origSupplier !== []) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ?, dic = ? WHERE id = ?')
                ->execute([$this->origSupplier['is_vat_payer'], $this->origSupplier['is_identified'], $this->origSupplier['dic'], $this->supplierId]);
        }
        foreach (array_reverse($this->invoiceIds) as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testBuiltInCodeMapsToLine26WithoutControlStatementSection(): void
    {
        $rows = $this->db->pdo()->query(
            "SELECT dphdp3_line, dphdp3_line_secondary, kh_section, kh_regime_code, vat_rate, is_reverse_charge, direction, archived, display_order
               FROM vat_classifications WHERE supplier_id IS NULL AND code = '71i'"
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(1, $rows, 'Vestavěný kód 71i musí existovat právě jednou.');
        $row = $rows[0];
        self::assertSame('26', $row['dphdp3_line']);
        self::assertNull($row['dphdp3_line_secondary']);
        self::assertNull($row['kh_section'], 'Do KH plnění nepatří.');
        self::assertNull($row['kh_regime_code']);
        self::assertEqualsWithDelta(0.0, (float) $row['vat_rate'], 0.001);
        self::assertSame(0, (int) $row['is_reverse_charge']);
        self::assertSame('sale', $row['direction']);
        self::assertSame(0, (int) $row['archived']);
        $three = (int) $this->db->pdo()->query("SELECT display_order FROM vat_classifications WHERE supplier_id IS NULL AND code = '3'")->fetchColumn();
        self::assertSame($three, (int) $row['display_order'], 'V číselníku hned za kódem 3.');
    }

    /** Faktura s položkou 71i (1 000) a 21% položkou (500 + 105): ř. 26 a ř. 1, KH jen 21 %. */
    public function testReturnPutsItemOnLine26AndControlStatementIgnoresIt(): void
    {
        $mixed = $this->sale('2093010001', '2093-01-15', [[1000.00, 0.0, '71i', $this->rate0Id, 0.0], [500.00, 105.0, '1', $this->rate21Id, 21.0]]);
        $booksOnly = $this->sale('2093010002', '2093-01-16', [[300.00, 0.0, '71i', $this->rate0Id, 0.0]]);

        $line26 = array_values(array_filter(
            $this->ledger->rows($this->supplierId, '2093-01-01', '2093-01-31'),
            static fn (array $r): bool => ($r['code'] ?? null) === '71i',
        ));
        self::assertCount(2, $line26);
        foreach ($line26 as $r) {
            self::assertSame('26', $r['dphdp3_line'], 'Tuzemský 71i nespadne do zahraniční větve.');
            self::assertEqualsWithDelta(0.0, (float) $r['vat_czk'], 0.001);
        }
        self::assertEqualsWithDelta(1300.0, array_sum(array_map(static fn (array $r): float => (float) $r['base_czk'], $line26)), 0.001);

        $dp = $this->return(1);
        self::assertSame('1300', (string) $dp->Veta2['pln_ost'], 'ř. 26 = základ položek 71i');
        self::assertSame('500', (string) $dp->Veta1['obrat23'], 'ř. 1 = 21% položka');
        self::assertSame('105', (string) $dp->Veta1['dan23']);
        self::assertSame('', (string) ($dp->Veta4['pln_osvob'] ?? ''), 'Na ř. 50 nic.');

        $kh = $this->kh->sectionDocuments($this->supplierId, self::YEAR, 1);
        $found = [];
        foreach ($kh['sections'] as $section => $data) {
            if ($section === 'none') {
                continue; // soupis dokladů mimo KH
            }
            foreach ($data['rows'] as $row) {
                $found[(int) ($row['invoice_id'] ?? 0)][] = [$section, (float) ($row['base21'] ?? 0)];
            }
        }
        self::assertSame([['A.5', 500.0]], $found[$mixed] ?? null, 'V KH jen 21% položka.');
        self::assertArrayNotHasKey($booksOnly, $found, 'Doklad jen s 71i do KH nepatří.');
        self::assertSame([$booksOnly], array_map('intval', array_column($kh['sections']['none']['rows'], 'invoice_id')));
        $khXml = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, 1)['xml']);
        self::assertEqualsWithDelta(500.0, (float) $khXml->DPHKH1->VetaA5['zakl_dane1'], 0.001, 'A.5 jen 21% položka.');

        $sh = $this->sh->build($this->supplierId, self::YEAR, 1);
        self::assertStringNotContainsString('VetaR', $sh['xml'], 'Souhrnné hlášení je prázdné.');
    }

    /** Koeficient § 76: 71i v čitateli i jmenovateli. 21 % 500 + 71i 1 000 + osvobozené bez nároku 1 000 = 1 500 / 2 500 = 60 %. */
    public function testCoefficientCountsItemInNumerator(): void
    {
        $this->sale('2093020001', '2093-02-15', [[1000.00, 0.0, '71i', $this->rate0Id, 0.0], [500.00, 105.0, '1', $this->rate21Id, 21.0]]);
        $this->sale('2093020002', '2093-02-16', [[1000.00, 0.0, '3', $this->rate0Id, 0.0]]);

        $coef = $this->dph->computeAnnualCoefficient($this->supplierId, self::YEAR);
        self::assertSame(1500, $coef['numerator']);
        self::assertSame(2500, $coef['denominator']);
        self::assertSame(60, $coef['final_percent']);
    }

    /** Výchozí klasifikace nové faktury se nemění: tuzemská 0% položka zůstane bez kódu, 71i jen výslovně. */
    public function testNewInvoiceDefaultsAreUnchanged(): void
    {
        $domestic = $this->body($this->clientId, [
            ['vat_rate_id' => $this->rate0Id, 'quantity' => 1, 'unit_price_without_vat' => 100, 'unit' => 'ks'],
            ['vat_rate_id' => $this->rate21Id, 'quantity' => 1, 'unit_price_without_vat' => 100, 'unit' => 'ks'],
            ['vat_rate_id' => $this->rate0Id, 'quantity' => 1, 'unit_price_without_vat' => 100, 'unit' => 'ks', 'vat_classification_code' => '71i'],
        ]);
        $this->newClassification->apply($domestic, $this->supplierId);
        self::assertSame([null, '1', '71i'], array_column($domestic['items'], 'vat_classification_code'));
        self::assertNotSame('71i', $domestic['vat_classification_code'] ?? null);

        $zeroOnly = $this->body($this->clientId, [['vat_rate_id' => $this->rate0Id, 'quantity' => 1, 'unit_price_without_vat' => 100, 'unit' => 'ks']]);
        $this->newClassification->apply($zeroOnly, $this->supplierId);
        self::assertNull($zeroOnly['items'][0]['vat_classification_code']);
        self::assertNotSame('71i', $zeroOnly['vat_classification_code'] ?? null);
        self::assertNotSame('3', $zeroOnly['vat_classification_code'] ?? null);
    }

    private function return(int $month): \SimpleXMLElement
    {
        $xml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, $month, 'monthly')['xml']);
        return $xml->DPHDP3;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function body(int $clientId, array $items): array
    {
        return ['client_id' => $clientId, 'issue_date' => '2093-03-01', 'tax_date' => '2093-03-01', 'reverse_charge' => false, 'items' => $items];
    }

    private function client(string $iso2, string $name, ?string $dic): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = " . $pdo->quote($iso2))->fetchColumn() ?: 0);
        if ($countryId === 0) {
            $this->markTestSkipped("Chybí země {$iso2}.");
        }
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "knihy@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $name, $countryId, $dic, $this->currencyId]);
        $id = (int) $pdo->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    /** @param list<array{0:float,1:float,2:string,3:int,4:float}> $items [základ, DPH, kód, id sazby, sazba] */
    private function sale(string $varsymbol, string $date, array $items): int
    {
        $pdo = $this->db->pdo();
        $base = array_sum(array_column($items, 0));
        $vat = array_sum(array_column($items, 1));
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?, ?)'
        )->execute([
            $this->supplierId, $varsymbol, $this->clientId, $date, $date, $date, $this->currencyId,
            round($base, 2), round($vat, 2), round($base + $vat, 2), $items[0][2], $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, vat_classification_code, order_index)
             VALUES (?, "Kniha", 1, "ks", ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $i => [$itemBase, $itemVat, $code, $rateId, $rate]) {
            $stmt->execute([$id, $itemBase, $rateId, $rate, $itemBase, $itemVat, $itemBase + $itemVat, $code, $i]);
        }
        return $id;
    }
}
