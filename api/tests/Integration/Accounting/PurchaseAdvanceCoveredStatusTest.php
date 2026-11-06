<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\PurchaseInvoice\AdvanceCoveredPaidStatus;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Stav dvojice záloha → konečná přijatá faktura po změně úhrady zálohy.
 *
 * Průběh z praxe (syntetická data): záloha ručně „uhrazená" 23. 6., skutečná platba
 * bankou 24. 6. spárovaná nejdřív na konečnou fakturu, pak odpárovaná a spárovaná na
 * zálohu. Konečná faktura krytá zálohou CELÁ (k úhradě 0) zůstala `received` se
 * splatností a záloha si nechala ruční datum úhrady místo data platby.
 */
#[Group('integration')]
final class PurchaseAdvanceCoveredStatusTest extends BankPostingTestCase
{
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vatRateId = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
    }

    /** Odpárování z konečné faktury a spárování na zálohu → faktura uhrazená k datu platby. */
    public function testRematchFromFinalToAdvanceMarksFinalPaidAtPaymentDate(): void
    {
        $vendor  = $this->client('Dodavatel přepárování zálohy');
        $advance = $this->purchaseInvoice('ZPF-STAV-1', $vendor, 1210.00, 'advance');
        $this->markPaid($advance, self::YEAR . '-06-23');
        $final = $this->finalPurchase('PF-STAV-1', $vendor, $advance);

        // Stav z doby před strážcem AdvanceFinalMatchGuard: platba zálohy spárovaná na fakturu.
        $tx = $this->transaction($this->statement(), -1210.00, ['posted_at' => self::YEAR . '-06-24', 'match_status' => 'manual']);
        $this->paymentMatch($tx, $final, 1210.00);
        $this->markPaid($final, self::YEAR . '-06-24');

        $this->unmatch($tx);
        $this->match($tx, $advance);

        self::assertSame(['paid', self::YEAR . '-06-24'], $this->state($advance), 'Datum úhrady zálohy = datum platby, ne ruční 23. 6.');
        self::assertSame(['paid', self::YEAR . '-06-24'], $this->state($final), 'Faktura krytá zaplacenou zálohou je uhrazená k datu platby.');
    }

    /** Odpárování platby ze zálohy → konečná faktura se vrací mezi neuhrazené. */
    public function testUnmatchFromAdvanceReturnsFinalToReceived(): void
    {
        $vendor  = $this->client('Dodavatel odpárování zálohy');
        $advance = $this->purchaseInvoice('ZPF-STAV-2', $vendor, 1210.00, 'advance');
        $final   = $this->finalPurchase('PF-STAV-2', $vendor, $advance);
        $tx = $this->transaction($this->statement(), -1210.00, ['posted_at' => self::YEAR . '-06-24']);

        $this->match($tx, $advance);
        self::assertSame(['paid', self::YEAR . '-06-24'], $this->state($final), 'Spárovaná záloha kryje fakturu.');

        $this->unmatch($tx);
        self::assertNotSame('paid', $this->state($advance)[0], 'Záloha bez platby není uhrazená.');
        self::assertSame(['received', null], $this->state($final), 'Bez úhrady zálohy je faktura zase k úhradě.');
    }

    /** Záloha ručně „uhrazená" 23. 6. + platba 24. 6. → datum úhrady 24. 6. */
    public function testManuallyPaidAdvanceTakesDateOfMatchedPayment(): void
    {
        $vendor  = $this->client('Dodavatel ruční úhrada');
        $advance = $this->purchaseInvoice('ZPF-STAV-3', $vendor, 1210.00, 'advance');
        $this->markPaid($advance, self::YEAR . '-06-23');
        $tx = $this->transaction($this->statement(), -1210.00, ['posted_at' => self::YEAR . '-06-24']);

        $this->match($tx, $advance);

        self::assertSame(['paid', self::YEAR . '-06-24'], $this->state($advance));
    }

    /** Běžná ručně uhrazená faktura: spárování ani odpárování nemění stav ani ruční datum. */
    public function testRegularManuallyPaidInvoiceKeepsManualDateThroughMatchAndUnmatch(): void
    {
        $vendor  = $this->client('Dodavatel běžná faktura');
        $invoice = $this->purchaseInvoice('PF-STAV-7', $vendor, 1210.00);
        $this->markPaid($invoice, self::YEAR . '-06-23');
        $tx = $this->transaction($this->statement(), -1210.00, ['posted_at' => self::YEAR . '-06-24']);

        $this->match($tx, $invoice);
        self::assertSame(['paid', self::YEAR . '-06-23'], $this->state($invoice), 'Ruční datum úhrady zůstává.');

        $this->unmatch($tx);
        self::assertSame(['paid', self::YEAR . '-06-23'], $this->state($invoice), 'Odpárování ruční úhradu nevrací.');
    }

    /** Pokladní úhrada zálohy a její storno (zrcadlo bankovní větve). */
    public function testCashPaymentOfAdvanceAndReversalDriveFinalStatus(): void
    {
        $register = $this->container->get(CashRegisterService::class)->create(
            $this->supplierId,
            ['name' => 'Pokladna stav zálohy', 'account_code' => '211', 'is_default' => false],
        );
        $vendor  = $this->client('Dodavatel záloha hotově stav');
        $advance = $this->purchaseInvoice('ZPF-STAV-4', $vendor, 1210.00, 'advance');
        $final   = $this->finalPurchase('PF-STAV-4', $vendor, $advance);

        $cash = $this->container->get(CashDocumentService::class);
        $doc = $cash->create($this->supplierId, [
            'register_id' => $register, 'issue_date' => self::YEAR . '-06-24', 'description' => 'Úhrada zálohy',
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 1210.00,
            'purchase_invoice_id' => $advance, 'post' => true,
        ], $this->userId);
        self::assertSame(['paid', self::YEAR . '-06-24'], $this->state($final));

        $cash->reverse($this->supplierId, (int) $doc['id'], ['reason' => 'Chybná úhrada'], $this->userId);
        self::assertSame(['received', null], $this->state($final));
    }

    /** Záloha krytá zápočtem dobropisu: datum úhrady = datum zápočtu, i na konečné faktuře. */
    public function testAdvanceCoveredByCreditNoteOffsetTakesOffsetDate(): void
    {
        $vendor  = $this->client('Dodavatel zápočet dobropisu');
        $advance = $this->purchaseInvoice('ZPF-STAV-8', $vendor, 1210.00, 'advance');
        $this->markPaid($advance, self::YEAR . '-06-23');
        $final  = $this->finalPurchase('PF-STAV-8', $vendor, $advance);
        $credit = $this->purchaseInvoice('DPF-STAV-8', $vendor, -1210.00, 'credit_note');
        $this->db->pdo()->prepare("INSERT INTO credit_note_offsets
            (supplier_id, doc_type, invoice_id, credit_note_id, amount, offset_on, created_by)
            VALUES (?, 'purchase_invoice', ?, ?, 1210.00, ?, ?)")
            ->execute([$this->supplierId, $advance, $credit, self::YEAR . '-06-25', $this->userId]);

        $status = $this->container->get(AdvanceCoveredPaidStatus::class);
        self::assertSame(self::YEAR . '-06-25', $status->lastPaymentDate($this->supplierId, $advance));
        $status->afterPaymentsChanged($this->supplierId, [$advance]);

        self::assertSame(['paid', self::YEAR . '-06-25'], $this->state($advance));
        self::assertSame(['paid', self::YEAR . '-06-25'], $this->state($final));
    }

    /** Faktura s doplatkem nad zálohu se zálohou samotnou neuzavírá. */
    public function testFinalWithRemainderIsNotClosedByAdvance(): void
    {
        $vendor  = $this->client('Dodavatel doplatek');
        $advance = $this->purchaseInvoice('ZPF-STAV-5', $vendor, 1000.00, 'advance');
        $final   = $this->finalPurchase('PF-STAV-5', $vendor, $advance, 1000.00);
        $tx = $this->transaction($this->statement(), -1000.00, ['posted_at' => self::YEAR . '-06-24']);

        $this->match($tx, $advance);

        self::assertSame(['received', null], $this->state($final), 'Zbývá doplatit 210 Kč.');
    }

    /**
     * Issue #313: faktura propojená se zálohou dřív, než se záloha spárovala s platbou,
     * odečítala nulovou zálohu a chtěla doplatit i ji. Spárování zálohy ji doplní.
     */
    public function testAdvancePaidAfterLinkingIsDeductedFromFinal(): void
    {
        $vendor  = $this->client('Dodavatel záloha po propojení');
        $advance = $this->purchaseInvoice('ZPF-STAV-9', $vendor, 1210.00, 'advance');
        $final   = $this->finalPurchase('PF-STAV-9', $vendor, $advance, 0.00);
        self::assertSame(1210.0, $this->amountToPay($final), 'Neuhrazená záloha se při propojení neodečte.');

        $this->match($this->transaction($this->statement(), -1210.00, ['posted_at' => self::YEAR . '-06-24']), $advance);

        self::assertSame(0.0, $this->amountToPay($final), 'Spárovaná záloha se odečte.');
        self::assertSame(['paid', self::YEAR . '-06-24'], $this->state($final), 'Faktura krytá zálohou je uhrazená.');
    }

    public function testPartlyCoveredFinalKeepsRemainderAfterAdvanceIsPaid(): void
    {
        $vendor  = $this->client('Dodavatel záloha s doplatkem po propojení');
        $advance = $this->purchaseInvoice('ZPF-STAV-10', $vendor, 1000.00, 'advance');
        $final   = $this->finalPurchase('PF-STAV-10', $vendor, $advance, 0.00);

        $this->match($this->transaction($this->statement(), -1000.00, ['posted_at' => self::YEAR . '-06-24']), $advance);

        self::assertSame(210.0, $this->amountToPay($final), 'Zbývá doplatit jen rozdíl nad zálohu.');
        self::assertSame(['received', null], $this->state($final));
    }

    /** Ručně zadaná záloha na konečné faktuře se spárováním zálohy nepřepisuje. */
    public function testManualAdvanceAmountOnFinalIsKept(): void
    {
        $vendor  = $this->client('Dodavatel ruční záloha na faktuře');
        $advance = $this->purchaseInvoice('ZPF-STAV-11', $vendor, 1210.00, 'advance');
        $final   = $this->finalPurchase('PF-STAV-11', $vendor, $advance, 500.00);

        $this->match($this->transaction($this->statement(), -1210.00, ['posted_at' => self::YEAR . '-06-24']), $advance);

        self::assertSame(710.0, $this->amountToPay($final));
    }

    /**
     * Issue #313, existující data: záloha spárovaná dřív, než dopočet při změně úhrady
     * existoval (tady přímý zápis párování bez háčku). Backfill při aktualizaci uhrazenou
     * zálohu doplní, dry-run nic nezapíše a druhý běh nemá co dělat.
     */
    public function testLinkedAdvanceBackfillFillsExistingFinals(): void
    {
        $vendor  = $this->client('Dodavatel backfill zálohy po propojení');
        $advance = $this->purchaseInvoice('ZPF-STAV-12', $vendor, 1000.00, 'advance');
        $final   = $this->finalPurchase('PF-STAV-12', $vendor, $advance, 0.00);
        $tx = $this->transaction($this->statement(), -1000.00, ['posted_at' => self::YEAR . '-06-24', 'match_status' => 'manual']);
        $this->paymentMatch($tx, $advance, 1000.00);
        $this->markPaid($advance, self::YEAR . '-06-24');

        $backfill = new \MyInvoice\Service\PurchaseInvoice\LinkedAdvanceAmount($this->db);
        $dry = array_values(array_filter($backfill->backfill($this->supplierId, false), static fn (array $r): bool => $r['final_id'] === $final));
        self::assertSame([['supplier_id' => $this->supplierId, 'final_id' => $final, 'advance_id' => $advance, 'amount' => 1000.0]], $dry);
        self::assertSame(1210.0, $this->amountToPay($final), 'Dry-run nic nezapíše.');

        $backfill->backfill($this->supplierId, true);
        self::assertSame(210.0, $this->amountToPay($final), 'Odečte se uhrazená záloha, zbývá doplatek.');
        self::assertSame([], array_values(array_filter($backfill->backfill($this->supplierId, false), static fn (array $r): bool => $r['final_id'] === $final)));
    }

    /** Srovnání existujících dat: dry-run nic nemění, --apply srovná, druhý běh nemá co dělat. */
    public function testBackfillIsDryRunByDefaultAndIdempotent(): void
    {
        $vendor  = $this->client('Dodavatel backfill');
        $advance = $this->purchaseInvoice('ZPF-STAV-6', $vendor, 1210.00, 'advance');
        $this->markPaid($advance, self::YEAR . '-06-23');
        $final = $this->finalPurchase('PF-STAV-6', $vendor, $advance);
        $tx = $this->transaction($this->statement(), -1210.00, ['posted_at' => self::YEAR . '-06-24', 'match_status' => 'manual']);
        $this->paymentMatch($tx, $advance, 1210.00);

        $status = $this->container->get(AdvanceCoveredPaidStatus::class);
        $dry = $this->rowsFor($status->backfill($this->supplierId, false), [$advance, $final]);
        self::assertSame(['advance_paid_at', 'final_paid'], array_column($dry, 'kind'));
        self::assertSame(self::YEAR . '-06-24', $dry[1]['to_paid_at'], 'Dry-run ukáže datum, které by faktura dostala.');
        self::assertSame(['paid', self::YEAR . '-06-23'], $this->state($advance), 'Dry-run nic nezapíše.');
        self::assertSame(['received', null], $this->state($final));

        $status->backfill($this->supplierId, true);
        self::assertSame(['paid', self::YEAR . '-06-24'], $this->state($advance));
        self::assertSame(['paid', self::YEAR . '-06-24'], $this->state($final));
        self::assertSame([], $this->rowsFor($status->backfill($this->supplierId, true), [$advance, $final]), 'Druhý běh nemá co měnit.');
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    private function finalPurchase(string $number, int $vendorId, int $advanceId, float $advancePaid = 1210.00): int
    {
        $issue = self::YEAR . '-06-20';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, advance_purchase_invoice_id,
                 advance_paid_amount, vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "invoice", ?, ?, "full", ?, ?, ?, ?, ?, 0, 0, 1000.00, 210.00, 1210.00, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $advanceId, $advancePaid, $issue, $issue, $issue, $issue,
            $this->currencyId, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Služba', 1, 'ks', 1000.00, ?, 21.00, 1000.00, 210.00, 1210.00, 0)"
        )->execute([$id, $this->vatRateId]);
        return $id;
    }

    private function amountToPay(int $id): float
    {
        return (float) $this->db->pdo()->query("SELECT amount_to_pay FROM purchase_invoices WHERE id = {$id}")->fetchColumn();
    }

    private function markPaid(int $id, string $date): void
    {
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")->execute([$date, $id]);
    }

    /** @return array{0:string, 1:?string} */
    private function state(int $id): array
    {
        $row = $this->db->pdo()->query("SELECT status, paid_at FROM purchase_invoices WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        return [(string) $row['status'], $row['paid_at'] !== null ? (string) $row['paid_at'] : null];
    }

    private function match(int $txId, int $purchaseInvoiceId): void
    {
        $res = $this->callAction($this->container->get(BankStatementAction::class), 'manualMatch', 'POST', 'admin',
            ['purchase_invoice_id' => $purchaseInvoiceId], ['id' => (string) $txId]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function unmatch(int $txId): void
    {
        $res = $this->callAction($this->container->get(BankStatementAction::class), 'unmatch', 'POST', 'admin',
            [], ['id' => (string) $txId]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function rowsFor(array $rows, array $ids): array
    {
        return array_values(array_filter($rows, static fn (array $r): bool => in_array((int) $r['id'], $ids, true)));
    }
}
