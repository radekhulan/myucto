<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\AdvanceDeductionRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Invoice\AdvancePartialDrawdown;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Support\Sql\AdvanceSettledSql;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Konečná faktura čerpající víc záloh (proforem s daňovým dokladem k platbě) najednou:
 * odpočtové řádky § 37a po zálohách a sazbách s vyznačenou zálohou, zaúčtování každé zálohy
 * jejím základem (324 MD / 311 D), hlídání přečerpání po zálohách, saldokonto 324, příznak
 * „vyúčtováno" a kombinace s postupným čerpáním jedné zálohy víc fakturami.
 */
#[Group('integration')]
final class MultiAdvanceDrawdownTest extends BankPostingTestCase
{
    private int $rate21 = 0;
    private int $rate12 = 0;
    private SaldoService $saldo;
    private AdvancePartialDrawdown $drawdown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saldo = $this->container->get(SaldoService::class);
        $this->drawdown = $this->container->get(AdvancePartialDrawdown::class);
        $pdo = $this->db->pdo();
        $this->rate21 = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 AND valid_to IS NULL AND is_reverse_charge = 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rate12 = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 12 AND valid_to IS NULL AND is_reverse_charge = 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->rate21 === 0 || $this->rate12 === 0) {
            self::markTestSkipped('Chybí sazby DPH 21 % a 12 %.');
        }
    }

    public function testInvoiceDrawsTwoAdvancesAtDifferentRatesAndSettlesEach(): void
    {
        $client = $this->client('Odběratel více záloh');
        [$pfA, $txA, $tdA] = $this->paidAdvance('PF-MA-A', 'DD-MA-A', $client, 1000.00, 210.00, $this->rate21, 21.0);
        [$pfB, $txB, $tdB] = $this->paidAdvance('PF-MA-B', 'DD-MA-B', $client, 1000.00, 120.00, $this->rate12, 12.0);

        $invoice = $this->draft($client, [[3000.00, 630.00, $this->rate21, 21.0], [2000.00, 240.00, $this->rate12, 12.0]]);
        $resA = $this->drawdown->apply($this->supplierId, $invoice, $pfA, 605.00);
        $resB = $this->drawdown->apply($this->supplierId, $invoice, $pfB, 1120.00);
        self::assertEqualsWithDelta(605.00, $resA['remaining'], 0.001, 'Ze zálohy A zbývá polovina.');
        self::assertEqualsWithDelta(0.00, $resB['remaining'], 0.001, 'Záloha B je vyčerpaná.');

        $lines = $this->deductionLines($invoice);
        self::assertCount(2, $lines, 'Odpočet po zálohách: každá záloha svůj řádek ve své sazbě.');
        self::assertSame($pfA, $lines[0]['advance_invoice_id']);
        self::assertEqualsWithDelta(-500.00, $lines[0]['total_without_vat'], 0.001);
        self::assertEqualsWithDelta(-105.00, $lines[0]['total_vat'], 0.001);
        self::assertStringContainsString('PF-MA-A', $lines[0]['description']);
        self::assertStringContainsString('DD-MA-A', $lines[0]['description'], 'Popis nese číslo DDKP zálohy.');
        self::assertSame($pfB, $lines[1]['advance_invoice_id']);
        self::assertSame($this->rate12, $lines[1]['vat_rate_id']);
        self::assertEqualsWithDelta(-1000.00, $lines[1]['total_without_vat'], 0.001);
        self::assertEqualsWithDelta(-120.00, $lines[1]['total_vat'], 0.001);
        self::assertNull($this->parentOf($invoice), 'Faktura čerpající víc záloh nemá jednu vazbu dokladu.');

        $detail = $this->container->get(InvoiceRepository::class)->find($invoice);
        self::assertEqualsWithDelta(5870.00, $detail['advance_drawdown']['supply']['gross'], 0.001, 'Plnění faktury bez odpočtů.');
        self::assertSame([$pfA, $pfB], array_column($detail['advance_drawdown']['drawdowns'], 'proforma_id'));
        self::assertSame('PF-MA-B', $detail['advance_drawdown']['drawdowns'][1]['varsymbol']);

        $this->issue($invoice, 'FV-MA-1');
        $entry = $this->postInvoiceEntry($invoice, self::YEAR . '-06-20');
        $this->assertBalancedEntry($entry);
        $byAcc = $this->linesByAccountCode($entry);
        self::assertEqualsWithDelta(1500.00, $byAcc['324']['debit'] ?? 0.0, 0.001, '324 MD = základ odečtený z obou záloh.');
        self::assertEqualsWithDelta(1500.00, $byAcc['311']['credit'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(5000.00, $byAcc['602']['credit'] ?? 0.0, 0.001, 'Výnos za celé plnění.');

        $sum = $this->accountSums([$this->bankEntry($txA), $this->bankEntry($txB), $tdA, $tdB, $entry]);
        self::assertSame(-50000, self::cents($sum['324'] ?? 0), 'Na 324 zbývá jen nevyčerpaný základ zálohy A.');
        self::assertSame(-97500, self::cents($sum['343.200'] ?? 0),
            'Daň z plnění 630 + 240 právě jednou a daň nevyčerpané poloviny zálohy A (105) přiznaná jejím DDKP.');

        $acc = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '324')['accounts'][0];
        $openA = $this->saldoItem($acc, 'invoice', $pfA);
        self::assertNotNull($openA, 'Záloha A je v saldokontu otevřená.');
        self::assertEqualsWithDelta(500.00, (float) $openA['remaining_czk'], 0.001);
        self::assertNull($this->saldoItem($acc, 'invoice', $pfB), 'Záloha B je vyčerpaná fakturou bez vazby dokladu.');

        $deductions = $this->container->get(AdvanceDeductionRepository::class);
        self::assertSame([$invoice => 1500.00], $deductions->deductedByFinal($this->supplierId, 'invoice', [$invoice]),
            'Saldokonto ví, kolik faktura čerpá, a nenabídne ho k ručnímu vyrovnání podruhé.');

        $settled = $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoices p WHERE p.id IN ({$pfA}, {$pfB}) AND " . AdvanceSettledSql::proforma('p')
        )->fetchColumn();
        self::assertSame(2, (int) $settled, 'Obě zálohy jsou vyúčtované.');
        self::assertSame($invoice, $this->container->get(FinalFromProformaCreator::class)->create($pfB, $this->userId),
            'Vyúčtování ze zálohy B vrátí fakturu, která ji čerpá, a nevystaví druhou.');
    }

    public function testMilestoneDrawdownCombinesWithSecondAdvance(): void
    {
        $client = $this->client('Odběratel milníky a víc záloh');
        [$pfA, $txA, $tdA] = $this->paidAdvance('PF-MB-A', 'DD-MB-A', $client, 1000.00, 210.00, $this->rate21, 21.0);
        [$pfB, $txB, $tdB] = $this->paidAdvance('PF-MB-B', 'DD-MB-B', $client, 500.00, 105.00, $this->rate21, 21.0);

        $first = $this->draft($client, [[1000.00, 210.00, $this->rate21, 21.0]]);
        $this->drawdown->apply($this->supplierId, $first, $pfA, 363.00);
        self::assertSame($pfA, $this->parentOf($first), 'Čerpání jediné zálohy drží vazbu dokladu jako dosud.');
        $this->issue($first, 'FV-MB-1');
        $firstEntry = $this->postInvoiceEntry($first, self::YEAR . '-06-20');

        $second = $this->draft($client, [[2000.00, 420.00, $this->rate21, 21.0]]);
        $candidates = array_column($this->drawdown->candidates($this->supplierId, $second), null, 'id');
        self::assertEqualsWithDelta(847.00, $candidates[$pfA]['available'], 0.001, 'Zbytek zálohy A po prvním milníku.');
        try {
            $this->drawdown->apply($this->supplierId, $second, $pfA, 900.00);
            self::fail('Čerpání nad zbytek zálohy A má selhat.');
        } catch (\DomainException) {
        }
        $this->drawdown->apply($this->supplierId, $second, $pfA, 847.00);
        $this->drawdown->apply($this->supplierId, $second, $pfB, 605.00);
        $this->issue($second, 'FV-MB-2');
        $secondEntry = $this->postInvoiceEntry($second, self::YEAR . '-07-20');
        $this->assertBalancedEntry($secondEntry);

        $sum = $this->accountSums([$this->bankEntry($txA), $this->bankEntry($txB), $tdA, $tdB, $firstEntry, $secondEntry]);
        self::assertSame(0, self::cents($sum['324'] ?? 0), 'Obě zálohy vyčerpané: 324 na nule.');
        self::assertSame(-63000, self::cents($sum['343.200'] ?? 0), 'Daň 21 % z 3000 právě jednou.');

        $acc = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '324')['accounts'][0];
        self::assertNull($this->saldoItem($acc, 'invoice', $pfA));
        self::assertNull($this->saldoItem($acc, 'invoice', $pfB));
    }

    public function testRemovingOneAdvanceKeepsTheOtherAndRestoresParent(): void
    {
        $client = $this->client('Odběratel odebrání zálohy');
        [$pfA] = $this->paidAdvance('PF-MC-A', 'DD-MC-A', $client, 1000.00, 210.00, $this->rate21, 21.0);
        [$pfB] = $this->paidAdvance('PF-MC-B', 'DD-MC-B', $client, 1000.00, 120.00, $this->rate12, 12.0);
        $invoice = $this->draft($client, [[3000.00, 630.00, $this->rate21, 21.0]]);
        $this->drawdown->apply($this->supplierId, $invoice, $pfA, 1210.00);
        $this->drawdown->apply($this->supplierId, $invoice, $pfB, 560.00);

        // Opakované čerpání téže zálohy ho nahradí, ostatní zálohy nechá.
        $this->drawdown->apply($this->supplierId, $invoice, $pfB, 1120.00);
        self::assertCount(2, $this->deductionLines($invoice));

        self::assertSame([$pfB], $this->drawdown->remove($this->supplierId, $invoice, $pfB));
        $lines = $this->deductionLines($invoice);
        self::assertCount(1, $lines);
        self::assertSame($pfA, $lines[0]['advance_invoice_id']);
        self::assertSame($pfA, $this->parentOf($invoice), 'Zbyla jediná záloha, faktura ji má zase v hlavičce.');

        try {
            $this->drawdown->remove($this->supplierId, $invoice, $pfB);
            self::fail('Odebrat zálohu, kterou faktura nečerpá, nejde.');
        } catch (\DomainException) {
        }

        $this->drawdown->apply($this->supplierId, $invoice, $pfB, 1120.00);
        self::assertSame([$pfA, $pfB], $this->drawdown->remove($this->supplierId, $invoice));
        self::assertSame([], $this->deductionLines($invoice));
        self::assertNull($this->parentOf($invoice));
    }

    public function testPercentOfSupplyAndAmountWithoutVat(): void
    {
        $client = $this->client('Odběratel procento');
        [$pfA] = $this->paidAdvance('PF-MD-A', 'DD-MD-A', $client, 5000.00, 1050.00, $this->rate21, 21.0);
        [$pfB] = $this->paidAdvance('PF-MD-B', 'DD-MD-B', $client, 5000.00, 1050.00, $this->rate21, 21.0);
        $invoice = $this->draft($client, [[10000.00, 2100.00, $this->rate21, 21.0]]);

        $res = $this->drawdown->applyPercent($this->supplierId, $invoice, $pfA, 30.0);
        self::assertEqualsWithDelta(3630.00, $res['amount'], 0.001, '30 % z plnění 12 100 s DPH.');
        $res = $this->drawdown->apply($this->supplierId, $invoice, $pfB, 2000.00, AdvancePartialDrawdown::BASIS_NET);
        self::assertEqualsWithDelta(2000.00, $res['base'], 0.001, 'Částka zadaná bez DPH je základ odpočtu.');
        self::assertEqualsWithDelta(2420.00, $res['amount'], 0.001);
        $lines = $this->deductionLines($invoice);
        self::assertEqualsWithDelta(-3000.00, $lines[0]['total_without_vat'], 0.001);
        self::assertEqualsWithDelta(-2000.00, $lines[1]['total_without_vat'], 0.001);
    }

    public function testMultiDrawdownGuards(): void
    {
        $client = $this->client('Odběratel pojistky');
        [$pfA] = $this->paidAdvance('PF-ME-A', 'DD-ME-A', $client, 1000.00, 210.00, $this->rate21, 21.0);
        [$pfB] = $this->paidAdvance('PF-ME-B', 'DD-ME-B', $client, 1000.00, 210.00, $this->rate21, 21.0);
        $withoutDdkp = $this->document('proforma', 'PF-ME-C', $client, 1000.00, 210.00, $this->rate21, 21.0);

        $invoice = $this->draft($client, [[1500.00, 315.00, $this->rate21, 21.0]]);
        $this->drawdown->apply($this->supplierId, $invoice, $pfA, 1210.00);
        try {
            $this->drawdown->apply($this->supplierId, $invoice, $pfB, 1210.00);
            self::fail('Odpočty víc záloh nad plnění faktury mají selhat.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('plnění faktury', $e->getMessage());
        }
        $this->drawdown->apply($this->supplierId, $invoice, $pfB, 605.00);

        try {
            $this->drawdown->apply($this->supplierId, $invoice, $withoutDdkp, 100.00);
            self::fail('Zálohu bez daňového dokladu k platbě odpočtem § 37a čerpat nejde.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('daňový doklad', $e->getMessage());
        }

        [$pfD] = $this->paidAdvance('PF-ME-D', 'DD-ME-D', $client, 1000.00, 210.00, $this->rate21, 21.0);
        $paidOff = $this->draft($client, [[3000.00, 630.00, $this->rate21, 21.0]]);
        $this->drawdown->apply($this->supplierId, $paidOff, $pfB, 100.00);
        $this->db->pdo()->prepare('UPDATE invoices SET advance_paid_amount = 100 WHERE id = ?')->execute([$paidOff]);
        try {
            $this->drawdown->apply($this->supplierId, $paidOff, $pfD, 100.00);
            self::fail('Odečet úhrady zálohy bez DDKP v hlavičce se s čerpáním víc záloh nesnese.');
        } catch (\DomainException) {
        }
    }

    public function testSavingItemsKeepsAdvanceOfDeductionLines(): void
    {
        $client = $this->client('Odběratel uložení položek');
        [$pfA] = $this->paidAdvance('PF-MF-A', 'DD-MF-A', $client, 1000.00, 210.00, $this->rate21, 21.0);
        [$pfB] = $this->paidAdvance('PF-MF-B', 'DD-MF-B', $client, 1000.00, 210.00, $this->rate21, 21.0);
        $foreign = $this->document('proforma', 'PF-MF-X', $client, 1000.00, 210.00, $this->rate21, 21.0);
        $invoice = $this->draft($client, [[3000.00, 630.00, $this->rate21, 21.0]]);
        $this->drawdown->apply($this->supplierId, $invoice, $pfA, 605.00);
        $this->drawdown->apply($this->supplierId, $invoice, $pfB, 605.00);

        $repo = $this->container->get(InvoiceRepository::class);
        $calc = $this->container->get(InvoiceCalculator::class);
        $items = $repo->find($invoice)['items'];
        // Klient bez zálohy řádku (starší API): záloha se dohledá podle popisu a sazby.
        $withoutLinks = array_map(static function (array $it): array {
            unset($it['advance_invoice_id'], $it['id']);
            return $it;
        }, $items);
        $repo->replaceItems($invoice, $withoutLinks);
        $calc->recompute($invoice);
        self::assertSame([$pfA, $pfB], array_column($this->deductionLines($invoice), 'advance_invoice_id'));

        // Podstrčená záloha, kterou faktura nečerpala, se nepřevezme.
        $forged = $repo->find($invoice)['items'];
        foreach ($forged as &$it) {
            if (($it['advance_invoice_id'] ?? null) === $pfB) {
                $it['advance_invoice_id'] = $foreign;
            }
        }
        unset($it);
        $repo->replaceItems($invoice, $forged);
        $calc->recompute($invoice);
        self::assertSame([$pfA, null], array_column($this->deductionLines($invoice), 'advance_invoice_id'));
    }

    public function testPostingRejectsOverdrawnAdvanceOnMultiInvoice(): void
    {
        $client = $this->client('Odběratel přečerpání');
        [$pfA] = $this->paidAdvance('PF-MG-A', 'DD-MG-A', $client, 1000.00, 210.00, $this->rate21, 21.0);
        [$pfB] = $this->paidAdvance('PF-MG-B', 'DD-MG-B', $client, 1000.00, 210.00, $this->rate21, 21.0);
        $invoice = $this->draft($client, [[5000.00, 1050.00, $this->rate21, 21.0]]);
        $this->drawdown->apply($this->supplierId, $invoice, $pfA, 605.00);
        $this->drawdown->apply($this->supplierId, $invoice, $pfB, 605.00);
        // Ruční úprava řádku zálohy A nad její daňový doklad.
        $this->db->pdo()->prepare(
            'UPDATE invoice_items SET unit_price_without_vat = -1500, total_without_vat = -1500, total_vat = -315, total_with_vat = -1815
              WHERE invoice_id = ? AND advance_invoice_id = ?'
        )->execute([$invoice, $pfA]);
        $this->issue($invoice, 'FV-MG-1');

        try {
            $this->posting->buildFromInvoice($this->supplierId, $invoice);
            self::fail('Odpočet zálohy A nad její DDKP má vyhodit advance_settlement_ambiguous.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
            self::assertStringContainsString('#' . $pfA, $e->getMessage());
        }
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    /**
     * Proforma zaplacená bankou a zaúčtovaný daňový doklad k platbě na celou částku.
     *
     * @return array{0:int, 1:int, 2:int} [proforma, bankovní transakce, zápis DDKP]
     */
    private function paidAdvance(string $vs, string $ddkpVs, int $client, float $base, float $vat, int $rateId, float $rate): array
    {
        $proforma = $this->document('proforma', $vs, $client, $base, $vat, $rateId, $rate);
        $tx = $this->payProformaViaBank($proforma, $base + $vat);
        $ddkp = $this->document('tax_document', $ddkpVs, $client, $base, $vat, $rateId, $rate, $proforma, 1);
        return [$proforma, $tx, $this->postInvoiceEntry($ddkp, self::YEAR . '-06-16')];
    }

    /** @param list<array{0:float,1:float,2:int,3:float}> $items [základ, DPH, id sazby, sazba] */
    private function draft(int $client, array $items): int
    {
        $id = $this->document('invoice', '', $client, $items[0][0], $items[0][1], $items[0][2], $items[0][3]);
        foreach (array_slice($items, 1) as $i => [$base, $vat, $rateId, $rate]) {
            $this->item($id, 'Položka ' . ($i + 2), $base, $vat, $rateId, $rate, $i + 1);
        }
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'draft', varsymbol = NULL WHERE id = ?")->execute([$id]);
        return $id;
    }

    private function issue(int $invoiceId, string $vs): void
    {
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = ? WHERE id = ?")->execute([$vs, $invoiceId]);
    }

    private function document(string $type, string $vs, int $clientId, float $base, float $vat, int $rateId, float $rate, ?int $parentId = null, int $pricesIncludeVat = 0): int
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
        $this->item($id, 'Položka', $base, $vat, $rateId, $rate, 0);
        return $id;
    }

    private function item(int $invoiceId, string $description, float $base, float $vat, int $rateId, float $rate, int $order): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, ?, 1, 'ks', ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$invoiceId, $description, $base, $rateId, $rate, $base, $vat, $base + $vat, $order, $rate === 21.0 ? '1' : '2']);
    }

    /** @return list<array{advance_invoice_id:?int, description:string, vat_rate_id:int, total_without_vat:float, total_vat:float}> */
    private function deductionLines(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT advance_invoice_id, description, vat_rate_id, total_without_vat, total_vat FROM invoice_items
              WHERE invoice_id = ? AND total_with_vat < 0 AND item_kind <> 'discount' ORDER BY order_index, id"
        );
        $stmt->execute([$invoiceId]);
        return array_map(static fn (array $r): array => [
            'advance_invoice_id' => $r['advance_invoice_id'] !== null ? (int) $r['advance_invoice_id'] : null,
            'description'        => (string) $r['description'],
            'vat_rate_id'        => (int) $r['vat_rate_id'],
            'total_without_vat'  => (float) $r['total_without_vat'],
            'total_vat'          => (float) $r['total_vat'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function parentOf(int $invoiceId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT parent_invoice_id FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $parent = $stmt->fetchColumn();
        return $parent === null || $parent === false ? null : (int) $parent;
    }

    /** @param array<string,mixed> $account */
    private function saldoItem(array $account, string $docType, int $docId): ?array
    {
        foreach ($account['partners'] as $partner) {
            foreach ($partner['items'] as $item) {
                if ((string) $item['doc_type'] === $docType && (int) $item['doc_id'] === $docId) {
                    return $item;
                }
            }
        }
        return null;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100.0);
    }

    private function postInvoiceEntry(int $invoiceId, string $date): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId),
            ['entry_date' => $date],
        );
    }

    /**
     * @param list<int> $entryIds
     * @return array<string,float>
     */
    private function accountSums(array $entryIds): array
    {
        $sum = [];
        foreach ($entryIds as $entryId) {
            foreach ($this->linesByAccountCode($entryId) as $code => $sides) {
                $sum[$code] = round(($sum[$code] ?? 0.0) + ($sides['debit'] ?? 0.0) - ($sides['credit'] ?? 0.0), 2);
            }
        }
        return $sum;
    }

    private function payProformaViaBank(int $proformaId, float $amount): int
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, $amount, ['match_status' => 'auto_exact', 'matched_invoice_id' => $proformaId]);
        $this->invoicePayment($proformaId, $tx, $amount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Inkaso zálohy se má zaúčtovat: ' . ($res['reason'] ?? ''));
        return $tx;
    }

    private function bankEntry(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id={$this->supplierId}
              AND source_type='bank' AND source_id={$txId} AND reversed_by IS NULL LIMIT 1"
        )->fetchColumn();
    }

    private function assertBalancedEntry(int $entryId): void
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        $debit = 0;
        $credit = 0;
        foreach ($entry['lines'] as $l) {
            $cents = (int) round((float) $l['amount'] * 100);
            if ($l['side'] === 'debit') {
                $debit += $cents;
            } else {
                $credit += $cents;
            }
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D (v haléřích).');
    }
}
