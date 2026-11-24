<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\AdvanceDeductionRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceDeduction;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceLinkException;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceLinks;
use MyInvoice\Support\Sql\PurchaseCostSql;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use MyInvoice\Tests\Support\SavepointStatus;
use PHPUnit\Framework\Attributes\Group;

/**
 * Přijatá konečná faktura, která vyúčtovává víc poskytnutých záloh ({@see PurchaseAdvanceLinks}).
 *
 * Syntetický případ (vyúčtování energie za rok):
 *   - zálohy s DDKP: A1 1 210 (1 000 + 210), A2 605 (500 + 105), A3 2 420 (2 000 + 420),
 *     všechny zaplacené, DDKP zaúčtované,
 *   - záloha bez DDKP: A4 800 zaplacená (DDKP v systému nemá),
 *   - konečná faktura: spotřeba 4 000 + 840, sleva −200 − 42, odpočty záloh A1–A3 podle
 *     DDKP (−3 500 − 735), celkem 363; zálohu A4 odečítá hlavička (800), takže
 *     k úhradě −437 (přeplatek).
 *
 * Cíl v deníku: náklad 3 800, 343 MD 798 a 343 D 735, 321 D 3 863, zúčtování záloh
 * 321 MD / 314 D 1 000 + 500 + 2 000 + 800, 314 po celém cyklu 0 a 321 MD 437.
 */
#[Group('integration')]
final class PurchaseMultiAdvanceSettlementTest extends BankPostingTestCase
{
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí sazba 21 % v DB.');
        }
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678' WHERE id = ?")
            ->execute([$this->supplierId]);
    }

    public function testFinalSettlesSeveralAdvancesWithAndWithoutTaxDocument(): void
    {
        $vendor = $this->client('Dodavatel energie MULTI');
        [$a1, $e1, $d1] = $this->paidAdvanceWithDdkp('ZAL-M1', $vendor, 1000.00, 210.00);
        [$a2, $e2, $d2] = $this->paidAdvanceWithDdkp('ZAL-M2', $vendor, 500.00, 105.00);
        [$a3, $e3, $d3] = $this->paidAdvanceWithDdkp('ZAL-M3', $vendor, 2000.00, 420.00);
        $a4 = $this->purchase('ZAL-M4', $vendor, 'advance', null, null, '2099-03-05', [[800.00, 0.00]]);
        $e4 = $this->payAdvance($a4, 800.00, '2099-03-05');

        $final = $this->purchase('VYU-M', $vendor, 'invoice', null, null, '2099-12-15', [
            [4000.00, 840.00],
            [-200.00, -42.00],
            [-1000.00, -210.00, $a1],
            [-500.00, -105.00, $a2],
            [-2000.00, -420.00, $a3],
        ]);
        $this->linkAll($final, [$a1, $a2, $a3, $a4]);

        $row = $this->piRow($final);
        self::assertNull($row['advance_purchase_invoice_id'], 'Faktura s víc zálohami vazbu hlavičkou nemá.');
        self::assertSame(80000, self::cents($row['advance_paid_amount']), 'Hlavička odečítá jen zálohu bez DDKP.');
        self::assertSame(-43700, self::cents($row['amount_to_pay']), 'Přeplatek záloh.');
        self::assertSame(
            [$a1 => 100000, $a2 => 50000, $a3 => 200000, $a4 => 80000],
            $this->documentRows($final),
            'Saldokonto čte čerpání každé zálohy v Kč.',
        );

        $entryId = $this->postFinal($final);
        $byAcc = $this->linesByAccountCode($entryId);
        $expense = $this->expenseAccount($byAcc);
        self::assertSame(380000, self::cents($byAcc[$expense]['debit']), 'Náklad = spotřeba po slevě, bez odpočtů záloh.');
        self::assertSame(79800, self::cents($byAcc['343.100']['debit']), '343 MD = DPH plnění po slevě.');
        self::assertSame(73500, self::cents($byAcc['343.100']['credit']), '343 D = vrácení odpočtů ze tří DDKP.');
        self::assertSame(386300, self::cents($byAcc['321']['credit']), '321 D = závazek bez odpočtů záloh.');
        self::assertSame(430000, self::cents($byAcc['321']['debit']), '321 MD = zúčtování všech čtyř záloh.');
        self::assertSame(430000, self::cents($byAcc['314']['credit']), '314 D = zbytky záloh s DDKP + záloha bez DDKP.');
        self::assertSame(
            [100000, 50000, 200000, 80000],
            $this->pairAmounts($entryId, '314'),
            'Každá záloha má vlastní pár zúčtování.',
        );
        $this->assertBalanced($entryId);

        $sum = $this->accountSums([$e1, $d1, $e2, $d2, $e3, $d3, $e4, $entryId]);
        self::assertSame(0, self::cents($sum['314'] ?? 0), 'Poskytnuté zálohy 314 po vyúčtování na nule.');
        self::assertSame(43700, self::cents($sum['321'] ?? 0), '321 MD = přeplatek = −k úhradě.');

        // Měsíční kontrola dvojího odpočtu i varování editoru: vše odečteno.
        $deduction = new PurchaseAdvanceDeduction($this->db);
        self::assertSame([], $deduction->finalsMissingDeduction($this->supplierId, self::YEAR . '-12-31'));
        self::assertFalse($deduction->finalMissingDeduction($this->supplierId, $final));

        // Saldokonto 314: strana čerpání faktury je celá přiřazená zálohám.
        $repo = new AdvanceDeductionRepository($this->db);
        $family = $repo->accountFamily($this->supplierId, (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '314' ORDER BY id LIMIT 1"
        )->fetchColumn());
        $finals = $repo->finals($this->supplierId, 'purchase_invoice', $family, null, AdvanceDeductionRepository::ANY_DATE, [$final]);
        self::assertSame(430000, self::cents($finals[$final]['deductible'] ?? null));
        self::assertSame(0, self::cents($finals[$final]['unassigned'] ?? null), 'Saldokonto nehlásí nepřiřazený odpočet.');

        // Vyúčtované zálohy se už jinde nenabízejí a náklad se nezapočte dvakrát.
        $pir = $this->container->get(PurchaseInvoiceRepository::class);
        $offered = array_column($pir->advanceCandidatesFor($vendor, $this->currencyId, 100.0, $this->supplierId), 'id');
        self::assertSame([], array_values(array_intersect($offered, [$a1, $a2, $a3, $a4])));
        $cost = $this->db->pdo()->query(
            'SELECT ' . PurchaseCostSql::net('pi') . ' FROM purchase_invoices pi'
            . PurchaseCostSql::deductionJoin('pi', $this->supplierId) . ' WHERE pi.id = ' . $final
        )->fetchColumn();
        self::assertSame(380000, self::cents($cost), 'Náklad v přehledech = plnění bez odpočtů záloh.');

        $detail = $pir->find($final, $this->supplierId);
        self::assertSame([$a1, $a2, $a3, $a4], array_column($detail['linked_advances'], 'id'));
        self::assertSame($a1, $detail['linked_advance']['id'] ?? null);
        self::assertSame($final, $pir->find($a3, $this->supplierId)['settled_by']['id'] ?? null);
    }

    /** Chybějící odpočet jedné zálohy s DDKP najde kontrola i vedle odpočtů ostatních. */
    public function testMissingDeductionOfOneAdvanceIsReported(): void
    {
        $vendor = $this->client('Dodavatel energie CHYBI');
        [$a1] = $this->paidAdvanceWithDdkp('ZAL-C1', $vendor, 1000.00, 210.00);
        [$a2] = $this->paidAdvanceWithDdkp('ZAL-C2', $vendor, 500.00, 105.00);
        $final = $this->purchase('VYU-C', $vendor, 'invoice', null, null, '2099-12-15', [
            [4000.00, 840.00],
            [-1000.00, -210.00, $a1],
        ]);
        $this->linkAll($final, [$a1, $a2]);

        $missing = (new PurchaseAdvanceDeduction($this->db))->finalsMissingDeduction($this->supplierId, self::YEAR . '-12-31');
        self::assertSame([[$final, $a2]], array_map(static fn (array $m): array => [$m['id'], $m['advance_id']], $missing));
        self::assertSame(10500, self::cents($missing[0]['amount']));
        self::assertTrue((new PurchaseAdvanceDeduction($this->db))->finalMissingDeduction($this->supplierId, $final));
    }

    /** Odpojení: zbude-li jediná záloha, vrátí se vazba do hlavičky. */
    public function testUnlinkBackToSingleHeaderLink(): void
    {
        $vendor = $this->client('Dodavatel energie ODPOJ');
        [$a1] = $this->paidAdvanceWithDdkp('ZAL-O1', $vendor, 1000.00, 210.00);
        $a2 = $this->purchase('ZAL-O2', $vendor, 'advance', null, null, '2099-03-05', [[800.00, 0.00]]);
        $this->payAdvance($a2, 800.00, '2099-03-05');
        $final = $this->purchase('VYU-O', $vendor, 'invoice', null, null, '2099-12-15', [
            [4000.00, 840.00],
            [-1000.00, -210.00, $a1],
        ]);
        $this->linkAll($final, [$a1, $a2]);
        self::assertSame(80000, self::cents($this->piRow($final)['advance_paid_amount']));

        $this->container->get(PurchaseInvoiceRepository::class)->unlinkAdvance($final, $this->supplierId, $a2);
        $row = $this->piRow($final);
        self::assertSame($a1, (int) $row['advance_purchase_invoice_id'], 'Zbylá záloha je zase v hlavičce.');
        self::assertSame(0, self::cents($row['advance_paid_amount']), 'Odpočet řádkem: hlavička zálohu neodečítá.');
        self::assertSame([], $this->documentRows($final));
    }

    public function testRejectsForeignVendorDraftCancelledAndAlreadySettledAdvance(): void
    {
        $vendor = $this->client('Dodavatel energie KONTROLY');
        $a1 = $this->purchase('ZAL-K1', $vendor, 'advance', null, null, '2099-03-05', [[800.00, 0.00]]);
        $a2 = $this->purchase('ZAL-K2', $vendor, 'advance', null, null, '2099-04-05', [[800.00, 0.00]]);
        $final = $this->purchase('VYU-K', $vendor, 'invoice', null, null, '2099-12-15', [[4000.00, 840.00]]);
        $this->linkAll($final, [$a1, $a2]);
        $links = new PurchaseAdvanceLinks($this->db);

        $other = $this->purchase('ZAL-K3', $this->client('Cizí dodavatel'), 'advance', null, null, '2099-04-05', [[800.00, 0.00]]);
        $this->assertRejected('vendor_mismatch', fn () => $links->append($this->supplierId, $final, $other));

        $draft = $this->purchase('ZAL-K4', $vendor, 'advance', null, null, '2099-04-05', [[800.00, 0.00]]);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'draft' WHERE id = ?")->execute([$draft]);
        $this->assertRejected('advance_draft', fn () => $links->append($this->supplierId, $final, $draft));

        $cancelled = $this->purchase('ZAL-K5', $vendor, 'advance', null, null, '2099-04-05', [[800.00, 0.00]]);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([$cancelled]);
        $this->assertRejected('advance_cancelled', fn () => $links->append($this->supplierId, $final, $cancelled));

        $foreignCurrency = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code <> 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($foreignCurrency > 0) {
            $eur = $this->purchase('ZAL-K6', $vendor, 'advance', null, null, '2099-04-05', [[800.00, 0.00]]);
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET currency_id = ? WHERE id = ?')->execute([$foreignCurrency, $eur]);
            $this->assertRejected('currency_mismatch', fn () => $links->append($this->supplierId, $final, $eur));
        }

        // Záloha vyúčtovaná jinou fakturou (hlavičkou i jako jedna z víc záloh) podruhé nejde.
        $second = $this->purchase('VYU-K2', $vendor, 'invoice', null, null, '2099-12-16', [[100.00, 21.00]]);
        $a3 = $this->purchase('ZAL-K7', $vendor, 'advance', null, null, '2099-04-05', [[800.00, 0.00]]);
        $this->container->get(PurchaseInvoiceRepository::class)->linkAdvance($second, $a3, $this->supplierId);
        $this->assertRejected('advance_settled', fn () => $links->append($this->supplierId, $second, $a2));
        $this->assertRejected('advance_settled', fn () => $links->append($this->supplierId, $final, $a3));
        $this->assertRejected('already_linked', fn () => $links->append($this->supplierId, $final, $a1));

        // Odpočet zálohy nad její částku.
        $big = $this->purchase('ZAL-K8', $vendor, 'tax_document', null, null, '2099-04-05', [[1000.00, 210.00]]);
        $links->append($this->supplierId, $final, $big);
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, advance_purchase_invoice_id)
             VALUES (?, 'Odpočet', 1, 'ks', -2000, ?, 21, -2000, -420, -2420, 9, ?)"
        )->execute([$final, $this->vatRateId, $big]);
        $this->assertRejected('advance_overdeducted', fn () => $links->sync($this->supplierId, $final));
    }

    /**
     * Regrese: faktura s jedinou zálohou v hlavičce se zaúčtuje přesně jako dosud (řádky
     * zápisu bajtově stejně, žádný řádek čerpání `document`).
     */
    public function testSingleHeaderAdvancePostingIsUnchanged(): void
    {
        $vendor = $this->client('Dodavatel jedna záloha');
        [$a1] = $this->paidAdvanceWithDdkp('ZAL-S1', $vendor, 735.54, 154.46);
        $final = $this->purchase('VYU-S', $vendor, 'invoice', $a1, null, '2099-12-15', [
            [665.66, 139.79],
            [-735.54, -154.46, $a1],
        ]);
        $plainAdvance = $this->purchase('ZAL-S2', $vendor, 'advance', null, null, '2099-03-05', [[890.00, 0.00]]);
        $this->payAdvance($plainAdvance, 890.00, '2099-03-05');
        $plain = $this->purchase('VYU-S2', $vendor, 'invoice', $plainAdvance, null, '2099-12-15', [[1000.00, 210.00]]);

        $lines = static fn (array $built): string => json_encode(array_map(
            static fn (array $l): array => [$l['account_code'], $l['side'], round((float) $l['amount'], 2)],
            $built,
        ), JSON_THROW_ON_ERROR);
        $expense = (string) $this->posting->buildFromPurchaseInvoice($this->supplierId, $final)[0]['account_code'];
        self::assertSame(
            json_encode([[$expense, 'debit', 665.66], ['343.100', 'debit', 139.79], ['343.100', 'credit', 154.46],
                ['321', 'credit', 650.99], ['321', 'debit', 735.54], ['314', 'credit', 735.54]], JSON_THROW_ON_ERROR),
            $lines($this->posting->buildFromPurchaseInvoice($this->supplierId, $final)),
        );
        self::assertSame(
            json_encode([[$expense, 'debit', 1000.0], ['343.100', 'debit', 210.0], ['321', 'credit', 1210.0],
                ['321', 'debit', 890.0], ['314', 'credit', 890.0]], JSON_THROW_ON_ERROR),
            $lines($this->posting->buildFromPurchaseInvoice($this->supplierId, $plain)),
        );
        self::assertSame([], $this->documentRows($final));
        self::assertSame([], $this->documentRows($plain));
    }

    /**
     * Záloha z počátečních stavů (DDKP i úhrada v předchozím programu, na 314 jen základ)
     * vedle zálohy s DDKP: odečte ji řádek s DPH dopočtenou z částky zálohy, daň řádku
     * vrátí odpočet (343 D) a základ zúčtuje 314 zálohy na nulu.
     */
    public function testOpeningBalanceAdvanceIsDeductedByLineWithVat(): void
    {
        $vendor = $this->client('Dodavatel energie POCSTAV');
        [$a1, $e1, $d1] = $this->paidAdvanceWithDdkp('ZAL-P1', $vendor, 1000.00, 210.00);
        [$opening, $openingEntry] = $this->openingAdvance('ZAL-P0', $vendor, 1210.00, 1000.00);

        $deduction = new PurchaseAdvanceDeduction($this->db);
        self::assertTrue($deduction->isOpeningAdvance($this->supplierId, $opening));
        self::assertFalse($deduction->isOpeningAdvance($this->supplierId, $a1), 'Záloha s DDKP není z počátečních stavů.');
        $proposal = $deduction->linesFromTaxDocuments($this->supplierId, $opening);
        self::assertCount(1, $proposal);
        self::assertTrue($proposal[0]['opening_balance']);
        self::assertNull($proposal[0]['tax_document_id']);
        self::assertSame([-100000, -21000, -121000], [self::cents($proposal[0]['base']), self::cents($proposal[0]['vat']), self::cents($proposal[0]['gross'])]);
        self::assertSame($this->vatRateId, $proposal[0]['vat_rate_id']);

        $final = $this->purchase('VYU-P', $vendor, 'invoice', null, null, '2099-12-15', [
            [4000.00, 840.00],
            [-1000.00, -210.00, $a1],
            [$proposal[0]['base'], $proposal[0]['vat'], $opening],
        ]);
        $this->linkAll($final, [$a1, $opening]);
        self::assertSame([$a1 => 100000, $opening => 100000], $this->documentRows($final), 'Čerpání zálohy z počátečních stavů = základ řádku.');
        self::assertSame(0, self::cents($this->piRow($final)['advance_paid_amount']), 'Hlavička zálohu z počátečních stavů neodečítá.');
        self::assertSame([], $deduction->finalsMissingDeduction($this->supplierId, self::YEAR . '-12-31'));

        $entryId = $this->postFinal($final);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertSame(400000, self::cents($byAcc[$this->expenseAccount($byAcc)]['debit']));
        self::assertSame(84000, self::cents($byAcc['343.100']['debit']));
        self::assertSame(42000, self::cents($byAcc['343.100']['credit']), '343 D = vrácení odpočtu z DDKP i ze zálohy z počátečních stavů.');
        self::assertSame(442000, self::cents($byAcc['321']['credit']));
        self::assertSame([100000, 100000], $this->pairAmounts($entryId, '314'), '314 D = základ každé zálohy.');
        $this->assertBalanced($entryId);
        self::assertSame(0, self::cents($this->accountSums([$e1, $d1, $openingEntry, $entryId])['314'] ?? 0),
            '314 obou záloh (i zálohy z počátečních stavů) po vyúčtování na nule.');
    }

    /** Bez odpočtového řádku se faktura se zálohou z počátečních stavů nezaúčtuje a kontrola ji najde. */
    public function testOpeningBalanceAdvanceWithoutDeductionLineIsRejected(): void
    {
        $vendor = $this->client('Dodavatel energie POCSTAV2');
        [$a1] = $this->paidAdvanceWithDdkp('ZAL-Q1', $vendor, 1000.00, 210.00);
        [$opening] = $this->openingAdvance('ZAL-Q0', $vendor, 1210.00, 1000.00);
        $final = $this->purchase('VYU-Q', $vendor, 'invoice', null, null, '2099-12-15', [
            [4000.00, 840.00],
            [-1000.00, -210.00, $a1],
        ]);
        $this->linkAll($final, [$a1, $opening]);

        $missing = (new PurchaseAdvanceDeduction($this->db))->finalsMissingDeduction($this->supplierId, self::YEAR . '-12-31');
        self::assertSame([[$final, $opening, true]], array_map(static fn (array $m): array => [$m['id'], $m['advance_id'], $m['opening_balance']], $missing));
        self::assertSame(21000, self::cents($missing[0]['amount']), 'Daň, kterou by faktura uplatnila podruhé.');
        $this->assertPostingRejected($final, 'počátečních stavech');

        // Jediná záloha v hlavičce: stejné pravidlo.
        [$single] = $this->openingAdvance('ZAL-Q2', $vendor, 605.00, 500.00);
        $headerOnly = $this->purchase('VYU-Q2', $vendor, 'invoice', $single, null, '2099-12-15', [[1000.00, 210.00]]);
        $this->assertPostingRejected($headerOnly, 'počátečních stavech');
        $withLine = $this->purchase('VYU-Q3', $vendor, 'invoice', null, null, '2099-12-15', [[1000.00, 210.00], [-500.00, -105.00, $single]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = NULL WHERE id = ?')->execute([$headerOnly]);
        $this->container->get(PurchaseInvoiceRepository::class)->linkAdvance($withLine, $single, $this->supplierId);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_paid_amount = 0 WHERE id = ?')->execute([$withLine]);
        $byAcc = $this->linesByAccountCode($this->postFinal($withLine));
        self::assertSame(10500, self::cents($byAcc['343.100']['credit']));
        self::assertSame(50000, self::cents($byAcc['314']['credit']), 'Jediná záloha z počátečních stavů: 314 D = základ řádku.');
    }

    /** `PUT …/items`: odpočet nad částku zálohy řádky neuloží (422, doklad beze změny). */
    #[Group('fulltext-transactions')]
    public function testSetItemsRollsBackOnOverdeduction(): void
    {
        $vendor = $this->client('Dodavatel energie RADKY');
        [$a1] = $this->paidAdvanceWithDdkp('ZAL-R1', $vendor, 1000.00, 210.00);
        $a2 = $this->purchase('ZAL-R2', $vendor, 'advance', null, null, '2099-03-05', [[800.00, 0.00]]);
        $final = $this->purchase('VYU-R', $vendor, 'invoice', null, null, '2099-12-15', [
            [4000.00, 840.00],
            [-1000.00, -210.00, $a1],
        ]);
        $this->linkAll($final, [$a1, $a2]);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'draft' WHERE id = ?")->execute([$final]);
        $before = $this->itemsSnapshot($final);
        $rowsBefore = $this->documentRows($final);

        $action = $this->container->get(\MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction::class);
        $save = fn (): array => $this->callAction($action, '__invoke', 'PUT', 'admin', ['items' => [
            ['description' => 'Spotřeba', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 4000, 'vat_rate_id' => $this->vatRateId],
            ['description' => 'Odpočet', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => -2000, 'vat_rate_id' => $this->vatRateId,
                'advance_purchase_invoice_id' => $a1],
        ]], ['id' => (string) $final]);

        $pdo = $this->db->pdo();
        $counters = SavepointStatus::read($pdo);
        $res = $save();
        self::assertSame(422, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('advance_overdeducted', $res['body']['error']['code'] ?? $res['body']['code'] ?? null);
        self::assertSame($before, $this->itemsSnapshot($final), 'Řádky se neuložily.');
        self::assertSame($rowsBefore, $this->documentRows($final), 'Čerpání záloh beze změny.');
        self::assertFalse($pdo->inTransaction());
        self::assertSame($counters, SavepointStatus::read($pdo));

        $pdo->beginTransaction();
        try {
            self::assertSame($before, $this->itemsSnapshot($final));
            try {
                $save();
                self::fail('Chybu vnořeného zápisu musí dostat vlastník transakce.');
            } catch (PurchaseAdvanceLinkException $e) {
                self::assertSame('advance_overdeducted', $e->errorCode);
            }
            self::assertTrue($pdo->inTransaction());
            self::assertSame($counters, SavepointStatus::read($pdo));
        } finally {
            $pdo->rollBack();
        }
        self::assertSame($before, $this->itemsSnapshot($final));
        self::assertSame($rowsBefore, $this->documentRows($final));
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    /**
     * Záloha převzatá z předchozího programu: stav uhrazeno, v MyÚčtu bez úhrady a bez DDKP,
     * zastoupená v otevíracím zápisu (314 MD jen základ) vazbou na doklad.
     *
     * @return array{0:int, 1:int} záloha, otevírací zápis
     */
    private function openingAdvance(string $number, int $vendor, float $gross, float $base): array
    {
        $advance = $this->purchase($number, $vendor, 'advance', null, null, '2098-11-05', [[$gross, 0.00]]);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = '2098-11-05' WHERE id = ?")->execute([$advance]);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $entry = $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => self::YEAR . '-01-01',
            'document_no' => 'PS-' . $number,
            'description' => 'Počáteční stav zálohy',
            'source_type' => 'opening',
            'source_id'   => null,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['314']['id'], 'side' => 'debit', 'amount' => $base],
            ['account_id' => $map['701']['id'], 'side' => 'credit', 'amount' => $base],
        ]);
        $this->db->pdo()->prepare(
            "INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id) VALUES (?, ?, 'purchase_invoice', ?)"
        )->execute([$this->supplierId, $entry, $advance]);
        return [$advance, $entry];
    }

    private function assertPostingRejected(int $finalId, string $message): void
    {
        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $finalId);
            self::fail('Faktura se zálohou z počátečních stavů bez odpočtu se nesmí zaúčtovat.');
        } catch (\MyInvoice\Service\Accounting\PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /** @return list<array{0:string, 1:int, 2:?int}> */
    private function itemsSnapshot(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT description, total_with_vat, advance_purchase_invoice_id FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY id'
        );
        $stmt->execute([$id]);
        return array_map(
            static fn (array $r): array => [(string) $r['description'], self::cents($r['total_with_vat']), $r['advance_purchase_invoice_id'] !== null ? (int) $r['advance_purchase_invoice_id'] : null],
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /** @param list<int> $advanceIds první jde do hlavičky, další se přidají */
    private function linkAll(int $finalId, array $advanceIds): void
    {
        $links = new PurchaseAdvanceLinks($this->db);
        foreach ($advanceIds as $i => $advanceId) {
            if ($i === 0) {
                $this->container->get(PurchaseInvoiceRepository::class)->linkAdvance($finalId, $advanceId, $this->supplierId);
            } else {
                $links->append($this->supplierId, $finalId, $advanceId, $this->userId);
            }
        }
    }

    private function assertRejected(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('Očekávané odmítnutí ' . $code . '.');
        } catch (PurchaseAdvanceLinkException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
            self::assertGreaterThanOrEqual(400, $e->status);
            self::assertLessThan(500, $e->status);
        }
    }

    /** @return array<int,int> záloha => částka čerpání v haléřích */
    private function documentRows(int $finalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT advance_invoice_id, amount FROM purchase_advance_deductions
              WHERE supplier_id = ? AND final_invoice_id = ? AND source = 'document' ORDER BY id"
        );
        $stmt->execute([$this->supplierId, $finalId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$advance, $amount]) {
            $out[(int) $advance] = self::cents($amount);
        }
        return $out;
    }

    /** @return list<int> částky řádků účtu v pořadí zápisu (haléře) */
    private function pairAmounts(int $entryId, string $code): array
    {
        $out = [];
        foreach ($this->journal->find($entryId, $this->supplierId)['lines'] as $l) {
            if ($this->accountCode((int) $l['account_id']) === $code) {
                $out[] = self::cents($l['amount']);
            }
        }
        return $out;
    }

    /** @return array{0:int, 1:int, 2:int} záloha, zápis úhrady, zápis DDKP */
    private function paidAdvanceWithDdkp(string $number, int $vendor, float $base, float $vat): array
    {
        $gross = round($base + $vat, 2);
        $advance = $this->purchase($number, $vendor, 'advance', null, null, '2099-03-05', [[$gross, 0.00]]);
        $txEntry = $this->payAdvance($advance, $gross, '2099-03-05');
        $ddkp = $this->purchase('DDKP-' . $number, $vendor, 'tax_document', null, $advance, '2099-03-10', [[$base, $vat]]);
        $ddkpEntry = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $ddkp,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $ddkp),
            ['entry_date' => '2099-03-10'],
        );
        return [$advance, $txEntry, $ddkpEntry];
    }

    /** @param list<array{0:float, 1:float, 2?:?int}> $items základ, daň, záloha odpočtu */
    private function purchase(string $number, int $vendorId, string $kind, ?int $advanceId, ?int $parentId, string $date, array $items): int
    {
        $base = 0.0;
        $vat = 0.0;
        foreach ($items as $it) {
            $base += $it[0];
            $vat += $it[1];
        }
        $base = round($base, 2);
        $vat = round($vat, 2);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, advance_purchase_invoice_id,
                 parent_purchase_invoice_id, vat_deduction, issue_date, tax_date, due_date, received_at, currency_id,
                 reverse_charge, is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", ?, ?, ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", ?, ?)'
        )->execute([$this->supplierId, $vendorId, $number, $kind, $advanceId, $parentId, $date, $date, $date, $date,
            $this->currencyId, $base, $vat, round($base + $vat, 2), $kind === 'advance' ? null : '40', $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, advance_purchase_invoice_id)
             VALUES (?, ?, 1, 'ks', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $i => $it) {
            $rate = abs($it[1]) > 0.0 ? 21.00 : 0.00;
            $insert->execute([$id, isset($it[2]) ? 'Odpočet zálohy' : 'Položka', $it[0], $this->vatRateId, $rate,
                $it[0], $it[1], round($it[0] + $it[1], 2), $i, $it[2] ?? null]);
        }
        return $id;
    }

    /** Úhrada zálohy z banky (314 MD / 221 D). Vrací id zápisu. */
    private function payAdvance(int $advanceId, float $amount, string $date): int
    {
        $tx = $this->transaction($this->statement(), -$amount, ['match_status' => 'manual', 'posted_at' => $date]);
        $this->paymentMatch($tx, $advanceId, $amount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Úhrada zálohy se má zaúčtovat: ' . ($res['reason'] ?? ''));
        return (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id={$this->supplierId} AND source_type='bank' AND source_id={$tx} AND reversed_by IS NULL LIMIT 1"
        )->fetchColumn();
    }

    private function postFinal(int $finalId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $finalId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $finalId),
            ['entry_date' => '2099-12-15'],
        );
    }

    /** @return array<string,mixed> */
    private function piRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,array{debit:float,credit:float}> $byAcc */
    private function expenseAccount(array $byAcc): string
    {
        $codes = array_values(array_filter(
            array_map('strval', array_keys($byAcc)),
            static fn (string $c): bool => str_starts_with($c, '5'),
        ));
        self::assertCount(1, $codes, 'Náklad má být na jednom účtu: ' . implode(', ', $codes));
        return $codes[0];
    }

    /**
     * @param list<int> $entryIds
     * @return array<string,float> Σ (MD − D) po účtech
     */
    private function accountSums(array $entryIds): array
    {
        $sum = [];
        foreach ($entryIds as $entryId) {
            $byAcc = $this->linesByAccountCode($entryId);
            $hasBankAnalytic = array_filter(array_keys($byAcc), static fn ($c): bool => (string) $c !== '221' && str_starts_with((string) $c, '221')) !== [];
            foreach ($byAcc as $code => $sides) {
                if ((string) $code === '221' && $hasBankAnalytic) {
                    continue;
                }
                $sum[(string) $code] = round(($sum[(string) $code] ?? 0.0) + $sides['debit'] - $sides['credit'], 2);
            }
        }
        return $sum;
    }

    private function assertBalanced(int $entryId): void
    {
        $debit = 0;
        $credit = 0;
        foreach ($this->journal->find($entryId, $this->supplierId)['lines'] as $l) {
            $cents = self::cents($l['amount']);
            $l['side'] === 'debit' ? $debit += $cents : $credit += $cents;
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D (v haléřích).');
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100.0);
    }
}
