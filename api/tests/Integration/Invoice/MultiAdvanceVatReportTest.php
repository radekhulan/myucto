<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\AdvancePartialDrawdown;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Invoice\ProformaPaymentDocuments;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Konečná faktura, která odečítá dvě zálohy s daňovými doklady k platbě v různých sazbách:
 * v kontrolním hlášení (A.4) i v přiznání k DPH vyjde rozdíl plnění a odpočtů § 37a
 * po sazbách, záloha se nezdaní podruhé a plnění nevypadne.
 */
#[Group('integration')]
final class MultiAdvanceVatReportTest extends TestCase
{
    private const YEAR = 2083;

    private Connection $db;
    private InvoicePaymentService $payments;
    private PaymentTaxDocumentCreator $taxDocCreator;
    private AdvancePartialDrawdown $drawdown;
    private DphPriznaniBuilder $dph;
    private KontrolniHlaseniBuilder $kh;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $rate21Id = 0;
    private int $rate12Id = 0;
    private int $userId = 0;
    private int $clientId = 0;
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var array<string,mixed> */
    private array $origSupplier = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db            = $c->get(Connection::class);
            $this->payments      = $c->get(InvoicePaymentService::class);
            $this->taxDocCreator = $c->get(PaymentTaxDocumentCreator::class);
            $this->drawdown      = $c->get(AdvancePartialDrawdown::class);
            $this->dph           = $c->get(DphPriznaniBuilder::class);
            $this->kh            = $c->get(KontrolniHlaseniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->rate21Id   = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 AND valid_to IS NULL AND is_reverse_charge = 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rate12Id   = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 12 AND valid_to IS NULL AND is_reverse_charge = 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->rate21Id === 0
            || $this->rate12Id === 0 || $this->userId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $this->origSupplier = $pdo->query(
            "SELECT is_vat_payer, is_identified, dic, proforma_payment_document, accounting_mode FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        // Výkazy DPH na formě účetnictví nezávisí. Zálohy tu hradí ruční úhrada, kterou podvojné
        // účetnictví čerpat nedovolí (advance_receipt_not_posted), proto daňová evidence.
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678', proforma_payment_document = ?, accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([ProformaPaymentDocuments::MODE_MANUAL, $this->supplierId]);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer)
             VALUES (?, "Odběratel více záloh", "Test 1", "Praha", "11000", ?, "CZ11111118",
                     "zalohy@example.com", "cs", ?, 1)'
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
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ?, dic = ?, proforma_payment_document = ?, accounting_mode = ? WHERE id = ?')
                ->execute([
                    $this->origSupplier['is_vat_payer'],
                    $this->origSupplier['is_identified'],
                    $this->origSupplier['dic'],
                    $this->origSupplier['proforma_payment_document'],
                    $this->origSupplier['accounting_mode'],
                    $this->supplierId,
                ]);
        }
        foreach (array_reverse($this->invoiceIds) as $id) {
            $pdo->prepare('DELETE FROM invoice_payments WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE invoice_items SET advance_invoice_id = NULL WHERE advance_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    public function testControlStatementAndReturnCarrySupplyMinusDeductionsPerRate(): void
    {
        // Zálohy v březnu: 21 % (10 000 + 2 100) a 12 % (5 000 + 600), každá s DDKP.
        $pfA = $this->document('proforma', '2083030001', '2083-03-01', [[10000.00, 2100.00, '1', $this->rate21Id, 21.0]], 'issued', null);
        $pfB = $this->document('proforma', '2083030002', '2083-03-01', [[5000.00, 600.00, '2', $this->rate12Id, 12.0]], 'issued', null);
        $this->advanceTaxDocument($pfA, 12100.00, '2083-03-10', '2083030101');
        $this->advanceTaxDocument($pfB, 5600.00, '2083-03-10', '2083030102');

        $before = $this->returnTotals(4);

        // Konečná faktura v dubnu: plnění 30 000 v 21 % a 20 000 v 12 %, odečte obě zálohy celé.
        $finalId = $this->document('invoice', null, '2083-04-20', [
            [30000.00, 6300.00, '1', $this->rate21Id, 21.0],
            [20000.00, 2400.00, '2', $this->rate12Id, 12.0],
        ], 'draft', '2083-04-20');
        $this->drawdown->apply($this->supplierId, $finalId, $pfA, 12100.00);
        $this->drawdown->apply($this->supplierId, $finalId, $pfB, 5600.00);
        self::assertSame(['1', '2', '1', '2'], $this->itemCodes($finalId), 'Odpočty nesou klasifikaci DDKP své zálohy.');
        $this->db->pdo()->prepare("UPDATE invoices SET varsymbol = '2083040001', status = 'issued' WHERE id = ?")->execute([$finalId]);

        $row = $this->khRow(4, $finalId);
        self::assertSame('A.4', $row['section'], 'Doklad s DIČ nad 10 000 Kč (doplatek 41 000 Kč).');
        self::assertEqualsWithDelta(20000.00, $row['base21'], 0.001, 'Základ 21 %: plnění 30 000 − odpočet zálohy A 10 000.');
        self::assertEqualsWithDelta(4200.00, $row['vat21'], 0.001);
        self::assertEqualsWithDelta(15000.00, $row['base12'], 0.001, 'Základ 12 %: plnění 20 000 − odpočet zálohy B 5 000.');
        self::assertEqualsWithDelta(1800.00, $row['vat12'], 0.001);

        $after = $this->returnTotals(4);
        self::assertSame(20000, $after['obrat23'] - $before['obrat23'], 'ř. 1 přiznání: rozdíl plnění a odpočtu v 21 %.');
        self::assertSame(4200, $after['dan23'] - $before['dan23']);
        self::assertSame(15000, $after['obrat5'] - $before['obrat5'], 'ř. 2 přiznání: rozdíl plnění a odpočtu v 12 %.');
        self::assertSame(1800, $after['dan5'] - $before['dan5']);
    }

    /** @return array{obrat23:int, dan23:int, obrat5:int, dan5:int} */
    private function returnTotals(int $month): array
    {
        $xml = new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, $month, 'monthly')['xml']);
        $veta = $xml->DPHDP3->Veta1;
        return [
            'obrat23' => (int) ($veta['obrat23'] ?? 0),
            'dan23'   => (int) ($veta['dan23'] ?? 0),
            'obrat5'  => (int) ($veta['obrat5'] ?? 0),
            'dan5'    => (int) ($veta['dan5'] ?? 0),
        ];
    }

    private function advanceTaxDocument(int $proformaId, float $amount, string $date, string $varsymbol): void
    {
        $paid = $this->payments->recordPayment($proformaId, $amount, $date, ['source' => 'manual']);
        $taxDocId = $this->taxDocCreator->createForPayment($paid['payment_id'], $this->userId);
        $this->invoiceIds[] = $taxDocId;
        $this->db->pdo()->prepare("UPDATE invoices SET varsymbol = ?, status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([$varsymbol, $date, $taxDocId]);
    }

    /** @return array<string,mixed> */
    private function khRow(int $month, int $invoiceId): array
    {
        foreach ($this->kh->sectionDocuments($this->supplierId, self::YEAR, $month)['sections'] as $section => $data) {
            foreach ($data['rows'] as $row) {
                if ((int) ($row['invoice_id'] ?? 0) === $invoiceId) {
                    return $row + ['section' => $section];
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

    /** @param list<array{0:float,1:float,2:string,3:int,4:float}> $items [základ, DPH, kód, id sazby, sazba] */
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
             VALUES (?, "Dílo", 1, "ks", ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $i => [$itemBase, $itemVat, $code, $rateId, $rate]) {
            $stmt->execute([$id, $itemBase, $rateId, $rate, $itemBase, $itemVat, $itemBase + $itemVat, $code, $i]);
        }
        return $id;
    }
}
