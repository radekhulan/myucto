<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\AdvancePartialDrawdown;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Invoice\ProformaPaymentDocuments;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Cestovní služba § 89: přirážka na ř. 1 a KH s kódem režimu 1, zbytek ceny na ř. 26 mimo KH
 * (metodika GFŘ č. j. 67743/21/7100-20116-050701), a to i přes celý zálohový cyklus.
 *
 * Odvozené doklady (daňový doklad k platbě, vyúčtování, postupné čerpání) dřív klasifikaci
 * z proformy nepřebíraly: přirážka se vykázala jako běžné plnění bez režimu 1 a zbytek ceny
 * z přiznání vypadl. Záloha na vývoz naopak na ř. 22 nepatří, povinnost vzniká až dodáním.
 */
#[Group('integration')]
final class TravelServiceAdvanceClassificationTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private InvoicePaymentService $payments;
    private PaymentTaxDocumentCreator $taxDocCreator;
    private FinalFromProformaCreator $finalCreator;
    private AdvancePartialDrawdown $drawdown;
    private DphPriznaniBuilder $dph;
    private KontrolniHlaseniBuilder $kh;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $rate21Id = 0;
    private int $rate0Id = 0;
    private int $userId = 0;
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
            $this->db            = $c->get(Connection::class);
            $this->payments      = $c->get(InvoicePaymentService::class);
            $this->taxDocCreator = $c->get(PaymentTaxDocumentCreator::class);
            $this->finalCreator  = $c->get(FinalFromProformaCreator::class);
            $this->drawdown      = $c->get(AdvancePartialDrawdown::class);
            $this->dph           = $c->get(DphPriznaniBuilder::class);
            $this->kh            = $c->get(KontrolniHlaseniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->rate21Id   = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 AND valid_to IS NULL ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rate0Id    = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 0 AND is_reverse_charge = 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->rate21Id === 0
            || $this->rate0Id === 0 || $this->userId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $this->origSupplier = $pdo->query(
            "SELECT is_vat_payer, is_identified, dic, proforma_payment_document FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678', proforma_payment_document = ? WHERE id = ?")
            ->execute([ProformaPaymentDocuments::MODE_MANUAL, $this->supplierId]);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer)
             VALUES (?, "Cestovní odběratel", "Test 1", "Praha", "11000", ?, "CZ11111118",
                     "cestovka@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->origSupplier !== []) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ?, dic = ?, proforma_payment_document = ? WHERE id = ?')
                ->execute([
                    $this->origSupplier['is_vat_payer'],
                    $this->origSupplier['is_identified'],
                    $this->origSupplier['dic'],
                    $this->origSupplier['proforma_payment_document'],
                    $this->supplierId,
                ]);
        }
        foreach (array_reverse($this->invoiceIds) as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    public function testBuiltInCodeMapsToLine26WithoutControlStatementSection(): void
    {
        $row = $this->db->pdo()->query(
            "SELECT dphdp3_line, kh_section, vat_rate, kh_regime_code, direction
               FROM vat_classifications WHERE supplier_id IS NULL AND code = '26c'"
        )->fetch(PDO::FETCH_ASSOC);

        self::assertNotFalse($row, 'Vestavěný kód 26c musí existovat.');
        self::assertSame('26', $row['dphdp3_line']);
        self::assertNull($row['kh_section'], 'Zbytek ceny do KH nepatří.');
        self::assertEqualsWithDelta(0.0, (float) $row['vat_rate'], 0.001);
        self::assertSame('1', $row['kh_regime_code']);
        self::assertSame('sale', $row['direction']);
    }

    /** Příklad z metodiky: cena 140, náklady 120, přirážka 20 = základ 16,53 + daň 3,47. */
    public function testDocumentReportsMarkupOnLine1AndRemainderOnLine26(): void
    {
        $id = $this->sale('2098010001', '2098-01-15', [[16.53, 3.47, '1c', $this->rate21Id, 21.0], [120.00, 0.0, '26c', $this->rate0Id, 0.0]]);

        $dp = $this->return(1);
        self::assertSame('17', (string) $dp->Veta1['obrat23'], 'ř. 1 základ na celé Kč');
        self::assertSame('3', (string) $dp->Veta1['dan23'], 'ř. 1 daň na celé Kč');
        self::assertSame('120', (string) $dp->Veta2['pln_ost'], 'ř. 26');

        $row = $this->khRow(1, $id);
        self::assertSame('A.5', $row['section'], 'Pod limitem 10 000 Kč včetně daně.');
        self::assertEqualsWithDelta(16.53, $row['base21'], 0.001, 'KH nese jen přirážku.');
    }

    /** Limit KH se posuzuje podle celého dokladu, ne podle přirážky; 26c nezpůsobí konflikt režimů. */
    public function testControlStatementLimitUsesWholeDocumentAndKeepsRegime(): void
    {
        $this->sale('2098020001', '2098-02-15', [[165.29, 34.71, '1c', $this->rate21Id, 21.0], [20000.00, 0.0, '26c', $this->rate0Id, 0.0]]);

        $xml = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, 2)['xml']);
        self::assertCount(1, $xml->DPHKH1->VetaA4, 'Doklad 20 200 Kč s DIČ patří do A.4.');
        self::assertSame('1', (string) $xml->DPHKH1->VetaA4[0]['kod_rezim_pl']);
        self::assertSame('165.29', (string) $xml->DPHKH1->VetaA4[0]['zakl_dane1']);
    }

    /** Celý cyklus: záloha v březnu, vyúčtování v dubnu. Součet období = celé plnění. */
    public function testAdvanceCycleKeepsRegimeAndLine26(): void
    {
        $proformaId = $this->proforma('2098030001', '2098-03-01', [
            [16.53, 3.47, '1c', $this->rate21Id, 21.0],
            [120.00, 0.0, '26c', $this->rate0Id, 0.0],
        ]);
        $paid = $this->payments->recordPayment($proformaId, 70.00, '2098-03-10', ['source' => 'manual']);
        $taxDocId = $this->taxDocCreator->createForPayment($paid['payment_id'], $this->userId);
        $this->invoiceIds[] = $taxDocId;

        self::assertSame(['1c', '26c'], $this->itemCodes($taxDocId), 'Daňový doklad přebírá kódy z proformy.');
        $this->markIssued($taxDocId, '2098030002', '2098-03-10');

        self::assertSame('60', (string) $this->return(3)->Veta2['pln_ost'], 'ř. 26 zálohy: 70 − přirážka 10.');
        self::assertSame('1', (string) $this->khRow(3, $taxDocId)['kh_regime_code'], 'Přirážka ze zálohy je ve zvláštním režimu.');

        $this->payments->recordPayment($proformaId, 70.00, '2098-04-02', ['source' => 'manual']);
        $finalId = $this->finalCreator->create($proformaId, $this->userId, '2098-04-20', '2098-04-20');
        $this->invoiceIds[] = $finalId;
        $this->markIssued($finalId, '2098040001', '2098-04-20');

        self::assertSame(['1c', '26c', '1c', '26c'], $this->itemCodes($finalId), 'Položky i odpočty § 37a nesou kód.');
        self::assertSame('60', (string) $this->return(4)->Veta2['pln_ost'], 'ř. 26 vyúčtování: 120 − 60.');
        self::assertSame('1', (string) $this->khRow(4, $finalId)['kh_regime_code']);
    }

    public function testPartialDrawdownDeductionCarriesClassification(): void
    {
        $proformaId = $this->proforma('2098050001', '2098-05-01', [
            [16.53, 3.47, '1c', $this->rate21Id, 21.0],
            [120.00, 0.0, '26c', $this->rate0Id, 0.0],
        ]);
        $paid = $this->payments->recordPayment($proformaId, 140.00, '2098-05-10', ['source' => 'manual']);
        $taxDocId = $this->taxDocCreator->createForPayment($paid['payment_id'], $this->userId);
        $this->invoiceIds[] = $taxDocId;
        $this->markIssued($taxDocId, '2098050002', '2098-05-10');

        $invoiceId = $this->sale('', '2098-05-20', [[16.53, 3.47, '1c', $this->rate21Id, 21.0], [120.00, 0.0, '26c', $this->rate0Id, 0.0]], 'draft');
        $this->drawdown->apply($this->supplierId, $invoiceId, $proformaId, 70.00);

        self::assertSame(['1c', '26c', '1c', '26c'], $this->itemCodes($invoiceId));
    }

    /** Záloha na vývoz se nevykazuje: povinnost vzniká až dodáním, doklad zůstává bez ř. 22. */
    public function testZeroRateCodeOutsideSpecialRegimeIsNotCarriedToAdvance(): void
    {
        $proformaId = $this->proforma('2098060001', '2098-06-01', [[1000.00, 0.0, '26', $this->rate0Id, 0.0]]);
        $paid = $this->payments->recordPayment($proformaId, 500.00, '2098-06-10', ['source' => 'manual']);
        $taxDocId = $this->taxDocCreator->createForPayment($paid['payment_id'], $this->userId);
        $this->invoiceIds[] = $taxDocId;

        self::assertSame([null], $this->itemCodes($taxDocId));
    }

    /** Vyúčtování je samo plněním, kód z proformy proto přebírá vždy. */
    public function testFinalKeepsClassificationOfExport(): void
    {
        $proformaId = $this->proforma('2098070001', '2098-07-01', [[1000.00, 0.0, '26', $this->rate0Id, 0.0]]);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid' WHERE id = ?")->execute([$proformaId]);
        $finalId = $this->finalCreator->create($proformaId, $this->userId, '2098-07-20', '2098-07-20');
        $this->invoiceIds[] = $finalId;

        self::assertSame(['26'], $this->itemCodes($finalId));
    }

    private function return(int $month): \SimpleXMLElement
    {
        $xml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, $month, 'monthly')['xml']);
        return $xml->DPHDP3;
    }

    /** @return array<string,mixed> */
    private function khRow(int $month, int $invoiceId): array
    {
        foreach ($this->kh->sectionDocuments($this->supplierId, self::YEAR, $month)['sections'] as $section => $data) {
            foreach ($data['rows'] as $row) {
                if ((int) ($row['invoice_id'] ?? 0) === $invoiceId) {
                    return $row + ['section' => $section, 'kh_regime_code' => null];
                }
            }
        }
        self::fail("Doklad #{$invoiceId} chybí v soupisu KH.");
    }

    /** @return list<?string> */
    private function itemCodes(int $invoiceId): array
    {
        return array_map(
            static fn (mixed $code): ?string => $code === null ? null : (string) $code,
            $this->db->pdo()->query(
                "SELECT vat_classification_code FROM invoice_items WHERE invoice_id = {$invoiceId} ORDER BY order_index, id"
            )->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    private function markIssued(int $invoiceId, string $varsymbol, string $date): void
    {
        $this->db->pdo()->prepare("UPDATE invoices SET varsymbol = ?, status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([$varsymbol, $date, $invoiceId]);
    }

    /** @param list<array{0:float,1:float,2:string,3:int,4:float}> $items [základ, DPH, kód, id sazby, sazba] */
    private function proforma(string $varsymbol, string $date, array $items): int
    {
        return $this->document('proforma', $varsymbol, $date, $items, 'issued', null);
    }

    /** @param list<array{0:float,1:float,2:string,3:int,4:float}> $items [základ, DPH, kód, id sazby, sazba] */
    private function sale(string $varsymbol, string $date, array $items, string $status = 'issued'): int
    {
        return $this->document('invoice', $varsymbol !== '' ? $varsymbol : null, $date, $items, $status, $date);
    }

    /** @param list<array{0:float,1:float,2:string,3:int,4:float}> $items */
    private function document(string $type, ?string $varsymbol, string $date, array $items, string $status, ?string $taxDate): int
    {
        $pdo = $this->db->pdo();
        $base = array_sum(array_column($items, 0));
        $vat = array_sum(array_column($items, 1));
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $varsymbol, $type, $this->clientId, $date, $taxDate, $date, $this->currencyId,
            round($base, 2), round($vat, 2), round($base + $vat, 2), $status, $items[0][2], $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, vat_classification_code, order_index)
             VALUES (?, "Zájezd", 1, "ks", ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $i => [$itemBase, $itemVat, $code, $rateId, $rate]) {
            $stmt->execute([$id, $itemBase, $rateId, $rate, $itemBase, $itemVat, $itemBase + $itemVat, $code, $i]);
        }
        return $id;
    }
}
