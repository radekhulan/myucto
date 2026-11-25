<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\AdvancePartialDrawdown;
use MyInvoice\Service\Invoice\IssuedInvoiceAdvanceDrawdown;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Odpočet zálohy na už vystavené a zaúčtované konečné faktuře.
 *
 * Konečná faktura nahraná z PDF a spárovaná se zálohou jen v hlavičce (`advance_paid_amount`,
 * K úhradě 0 Kč) nese plnou DPH, kterou už přiznal daňový doklad k záloze. Oprava dřív šla
 * jen smazáním faktury. {@see IssuedInvoiceAdvanceDrawdown} doplní odpočtové řádky přímo,
 * odpočet v hlavičce nahradí, stav úhrady srovná a zápis v deníku přepíše.
 * Odpárování navíc vrací odpočet v hlavičce, který doplnilo spárování.
 */
#[Group('integration')]
final class IssuedInvoiceAdvanceDrawdownTest extends BankPostingTestCase
{
    private int $rate21 = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rate21 = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates WHERE rate_percent = 21 AND valid_to IS NULL AND is_reverse_charge = 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->rate21 === 0) {
            self::markTestSkipped('Chybí sazba DPH 21 %.');
        }
    }

    public function testIssuedFinalWithHeaderOnlyAdvanceGetsDeductionAndIsReposted(): void
    {
        $client = $this->client('Odběratel vystavené konečné faktury');
        $proforma = $this->document('proforma', 'PF-IS-1', $client, 1000.00, 210.00);
        $this->payProformaViaBank($proforma, 1210.00);
        // DDKP převzatý bez vazby na zálohu (převod z Pohody): „Spárovat se zálohou“ ho nevidělo
        // a odečetlo zálohu jen v hlavičce. Faktura se tak zaúčtovala, vazba DDKP přišla až potom.
        $ddkp = $this->document('tax_document', 'DD-IS-1', $client, 1000.00, 210.00, null, 1);
        $this->postInvoiceEntry($ddkp);
        $final = $this->document('invoice', 'FV-IS-1', $client, 1000.00, 210.00);
        $this->db->pdo()->prepare(
            "UPDATE invoices SET parent_invoice_id = ?, advance_paid_amount = 1210.00, status = 'paid', paid_at = issue_date WHERE id = ?"
        )->execute([$proforma, $final]);
        $oldEntry = $this->postInvoiceEntry($final);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')->execute([$proforma, $ddkp]);

        try {
            $this->container->get(AdvancePartialDrawdown::class)->apply($this->supplierId, $final, $proforma, 1210.00);
            self::fail('Čerpání konceptu na vystavené faktuře má selhat, vystavenou řeší IssuedInvoiceAdvanceDrawdown.');
        } catch (\DomainException) {
        }

        $res = $this->container->get(IssuedInvoiceAdvanceDrawdown::class)
            ->apply($this->supplierId, $final, $proforma, 1210.00, null, AdvancePartialDrawdown::BASIS_GROSS, ['user_id' => $this->userId]);
        self::assertEqualsWithDelta(0.0, $res['remaining'], 0.001, 'Záloha je vyčerpaná.');

        $inv = $this->container->get(InvoiceRepository::class)->find($final);
        self::assertEqualsWithDelta(0.0, (float) $inv['total_vat'], 0.001, 'DPH zálohy se odečetla, daň se nezdvojí.');
        self::assertEqualsWithDelta(0.0, (float) $inv['advance_paid_amount'], 0.001, 'Odpočet v hlavičce nahradily odpočtové řádky.');
        self::assertEqualsWithDelta(0.0, (float) $inv['amount_to_pay'], 0.001);
        self::assertSame('paid', $inv['status']);
        self::assertSame($proforma, (int) $inv['parent_invoice_id']);
        $lines = array_values(array_filter($inv['items'], static fn (array $it): bool => (float) $it['total_with_vat'] < 0));
        self::assertCount(1, $lines);
        self::assertEqualsWithDelta(-210.00, (float) $lines[0]['total_vat'], 0.001);
        self::assertStringContainsString('DD-IS-1', (string) $lines[0]['description']);

        self::assertNotNull($res['reposted_entry_id'], 'Zaúčtovaná faktura se přeúčtovala.');
        $active = $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND source_type = 'invoice'
                AND source_id = {$final} AND reversed_by IS NULL"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(1, $active, 'Faktura má jediný aktivní zápis.');
        self::assertSame($oldEntry, (int) $active[0], 'Zápis v otevřeném období se přepsal na místě.');
        $byAcc = $this->linesByAccountCode((int) $active[0]);
        self::assertEqualsWithDelta(1000.00, $byAcc['324']['debit'] ?? 0.0, 0.001, 'Zúčtování zálohy 324 MD základem DDKP.');
        self::assertEqualsWithDelta(0.0, ($byAcc['343.200']['credit'] ?? 0.0) - ($byAcc['343.200']['debit'] ?? 0.0), 0.001,
            'Daň z plnění a odpočet DPH zálohy se v zápisu faktury vyruší.');
    }

    public function testUnlinkRevertsHeaderAdvanceAndPaidStatus(): void
    {
        $client = $this->client('Odběratel odpárování');
        $proforma = $this->document('proforma', 'PF-IS-2', $client, 500.00, 105.00);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_total = 605.00 WHERE id = ?")->execute([$proforma]);
        $final = $this->document('invoice', 'FV-IS-2', $client, 500.00, 105.00);

        $repo = $this->container->get(InvoiceRepository::class);
        $repo->linkAdvance($final, $proforma, $this->supplierId);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_at = issue_date WHERE id = ?")->execute([$final]);
        self::assertEqualsWithDelta(605.00, (float) $repo->find($final)['advance_paid_amount'], 0.001);


        $repo->unlinkAdvance($final, $this->supplierId);
        $this->container->get(IssuedInvoiceAdvanceDrawdown::class)->resync($this->supplierId, $final, ['user_id' => $this->userId]);


        $inv = $repo->find($final);
        self::assertNull($inv['parent_invoice_id']);
        self::assertEqualsWithDelta(0.0, (float) $inv['advance_paid_amount'], 0.001, 'Odpárování vrátí odpočet, který doplnilo spárování.');
        self::assertEqualsWithDelta(605.00, (float) $inv['amount_to_pay'], 0.001);
        self::assertSame('issued', $inv['status'], 'Faktura bez zálohy už není uhrazená.');
    }

    public function testUnlinkKeepsManualAdvanceAmount(): void
    {
        $client = $this->client('Odběratel ruční korekce');
        $proforma = $this->document('proforma', 'PF-IS-3', $client, 500.00, 105.00);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_total = 605.00 WHERE id = ?")->execute([$proforma]);
        $final = $this->document('invoice', 'FV-IS-3', $client, 500.00, 105.00);
        $this->db->pdo()->prepare('UPDATE invoices SET advance_paid_amount = 300.00 WHERE id = ?')->execute([$final]);

        $repo = $this->container->get(InvoiceRepository::class);
        $repo->linkAdvance($final, $proforma, $this->supplierId);
        $repo->unlinkAdvance($final, $this->supplierId);

        self::assertEqualsWithDelta(300.00, (float) $repo->find($final)['advance_paid_amount'], 0.001, 'Ruční korekce odpočtu zůstává.');
    }

    private function document(string $type, string $vs, int $clientId, float $base, float $vat, ?int $parentId = null, int $pricesIncludeVat = 0): int
    {
        $issue = self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 0, "issued", "1", ?)'
        )->execute([$this->supplierId, $vs, $type, $parentId, $clientId, $issue, $issue, $issue,
            $this->currencyId, $pricesIncludeVat, $base, $vat, $base + $vat, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, 'Položka', 1, 'ks', ?, ?, 21, ?, ?, ?, 0, '1')"
        )->execute([$id, $base, $this->rate21, $base, $vat, $base + $vat]);
        return $id;
    }

    private function postInvoiceEntry(int $invoiceId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId),
            ['entry_date' => self::YEAR . '-06-16'],
        );
    }

    private function payProformaViaBank(int $proformaId, float $amount): void
    {
        $tx = $this->transaction($this->statement(), $amount, ['match_status' => 'auto_exact', 'matched_invoice_id' => $proformaId]);
        $this->invoicePayment($proformaId, $tx, $amount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Inkaso zálohy se má zaúčtovat: ' . ($res['reason'] ?? ''));
    }
}
