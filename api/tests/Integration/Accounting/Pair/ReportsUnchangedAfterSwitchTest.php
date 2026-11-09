<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\SouhrnneHlaseniBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rozvaha, výsledovka, předvaha, hlavní kniha, přiznání k DPH, kontrolní a souhrnné
 * hlášení vyjdou po přepnutí na jednoduchý zápis bajtově stejně jako před ním
 * (kritérium g). Dvojice vznikají rozdělením řádků (součty po účtech a stranách
 * se nemění), DPH výkazy se počítají z dokladů přes VatLedgerService.
 *
 * Data jednoduché firmy se do deníku vkládají přímo s `pair_no` (rozdělení
 * {@see PairReportFixture::pairEntryInPlace()}), nezávisle na zaúčtování a převodu.
 */
#[Group('integration')]
final class ReportsUnchangedAfterSwitchTest extends TestCase
{
    use PairReportFixture;

    protected function setUp(): void
    {
        $this->setUpFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    public function testReportsAreIdenticalAfterSwitchFromStartOfYear(): void
    {
        $entries = $this->postScenario() + $this->postDocuments();
        $before = $this->reports();
        // Výkazy nejsou prázdné: DPH a KH nesou doklady, předvaha zápisy deníku.
        self::assertStringContainsString('10000', $before['dph']);
        self::assertStringContainsString('VetaB', $before['kontrolni_hlaseni']);
        self::assertStringContainsString('"turnover_md":27529', $before['predvaha']);

        $this->switchToSingle(self::YEAR . '-01-01');
        $this->pairAllInPlace($entries);
        self::assertGreaterThan(20, $this->pairedLines(), 'Deník je po přepnutí rozdělený na dvojice.');
        $after = $this->reports();

        foreach ($before as $name => $report) {
            self::assertSame($report, $after[$name], 'Výkaz ' . $name . ' se po přepnutí změnil.');
        }
    }

    public function testReportsAreIdenticalAfterSwitchDuringYear(): void
    {
        $entries = $this->postScenario() + $this->postDocuments();
        $before = $this->reports();

        $this->switchToSingle(self::YEAR . '-03-01');
        $dates = $this->db->pdo()->prepare('SELECT id, entry_date FROM journal_entries WHERE supplier_id = ?');
        $dates->execute([$this->supplierId]);
        $from = [];
        foreach ($dates->fetchAll(\PDO::FETCH_KEY_PAIR) as $id => $date) {
            if ($date >= self::YEAR . '-03-01') {
                $from[] = (int) $id;
            }
        }
        self::assertNotSame([], $from);
        self::assertLessThan(count($entries), count($from));
        $this->pairAllInPlace($from);
        $after = $this->reports();

        foreach ($before as $name => $report) {
            self::assertSame($report, $after[$name], 'Výkaz ' . $name . ' se po přepnutí v průběhu roku změnil.');
        }
    }

    /** @return array<string,string> výkaz => JSON / XML */
    private function reports(): array
    {
        $this->entryForms->forget();
        $c = $this->container;
        $fs = $c->get(FinancialStatementService::class);
        $json = static fn (mixed $v): string => json_encode($v, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $vatMonth = 6;
        return [
            'predvaha'        => $json($c->get(TrialBalanceService::class)->build($this->supplierId, $this->periodId, null, null)),
            'predvaha_analytika' => $json($c->get(TrialBalanceService::class)->build($this->supplierId, $this->periodId, null, null, true)),
            'hlavni_kniha'    => $json($c->get(GeneralLedgerService::class)->build($this->supplierId, $this->periodId, null, null)),
            'rozvaha'         => $json($fs->balanceSheet($this->supplierId, $this->periodId, null, 'full')),
            'vysledovka'      => $json($fs->incomeStatement($this->supplierId, $this->periodId, null, 'full')),
            'dph'             => $json($c->get(DphPriznaniBuilder::class)->build($this->supplierId, self::YEAR, $vatMonth, 'monthly')),
            'kontrolni_hlaseni' => $json($c->get(KontrolniHlaseniBuilder::class)->build($this->supplierId, self::YEAR, $vatMonth)),
            'souhrnne_hlaseni'  => $json($c->get(SouhrnneHlaseniBuilder::class)->build($this->supplierId, self::YEAR, $vatMonth)),
        ];
    }

    /**
     * Doklady pro DPH výkazy (vydaná faktura dvě sazby, přijatá faktura) zaúčtované builderem.
     *
     * @return array<string,int>
     */
    private function postDocuments(): array
    {
        $pdo = $this->db->pdo();
        $czk = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $rate21 = (int) ($pdo->query('SELECT id FROM vat_rates WHERE code = "CZ-21" LIMIT 1')->fetchColumn() ?: 0);
        $rate12 = (int) ($pdo->query('SELECT id FROM vat_rates WHERE code = "CZ-12" LIMIT 1')->fetchColumn() ?: $rate21);
        if ($czk === 0 || $country === 0 || $rate21 === 0) {
            $this->markTestSkipped('Chybí měna, země nebo sazba DPH.');
        }
        $client = $this->client('Odběratel dvojic s.r.o.', $country, $czk, true);
        $vendor = $this->client('Dodavatel dvojic s.r.o.', $country, $czk, false);
        $date = self::YEAR . '-06-15';

        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, 11000, 2220, 13220, "issued", "1", ?)'
        )->execute([$this->supplierId, '2099061501', $client, $date, $date, $date, $czk, $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();
        $item = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, "ks", ?, ?, ?, ?, ?, ?, ?)'
        );
        $item->execute([$invoiceId, 'Služba', 10000, $rate21, 21, 10000, 2100, 12100, 0]);
        $item->execute([$invoiceId, 'Kniha', 1000, $rate12, 12, 1000, 120, 1120, 1]);

        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, "FP-2099-77", "invoice", ?, ?, ?, ?, ?, 0, "{}", 200, 42, 242, "received", "40", "full", ?)'
        )->execute([$this->supplierId, $vendor, $date, $date, $date, $date, $czk, $this->userId]);
        $purchaseId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Materiál", 1, "ks", 200, ?, 21, 200, 42, 242, 0)'
        )->execute([$purchaseId, $rate21]);

        $meta = ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId];
        return [
            'doc_invoice'  => $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $this->posting->buildFromInvoice($this->supplierId, $invoiceId), $meta + ['document_no' => '2099061501']),
            'doc_purchase' => $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId), $meta + ['document_no' => 'FP-2099-77']),
        ];
    }

    private function client(string $name, int $country, int $currency, bool $customer): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, ?, ?)'
        )->execute([$this->supplierId, $name, $country, $currency, $customer ? 1 : 0, $customer ? 0 : 1]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function pairedLines(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = ? AND pair_no IS NOT NULL');
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }
}
