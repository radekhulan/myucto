<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\PurchaseInvoice\LinkedAdvanceAmount;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceDeduction;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Vyúčtování poskytnuté zálohy s daňovým dokladem k platbě (DDKP) přijatou konečnou
 * fakturou, která zálohu odečítá označeným odpočtovým řádkem ({@see PurchaseAdvanceDeduction}).
 *
 * Syntetický případ: záloha 890,00 (735,54 + 154,46 DPH 21 %) zaplacená v září, DDKP
 * v září, konečná faktura v říjnu: spotřeba 665,66 + 139,79, odpočet zálohy −735,54
 * −154,46, celkem −84,55 (zaokrouhlení dokladu 0,17 → přeplatek 84,38).
 *
 * Cíl: DPH říjen základ −69,88 / daň −14,67 (ř. 40, KH B.3), deník náklad 665,66,
 * 343 MD 139,79 a 343 D 154,46, zúčtování 321 MD / 314 D 735,54, 314 po celém cyklu 0.
 */
#[Group('integration')]
final class PurchaseAdvanceDeductionTest extends BankPostingTestCase
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
        // Výkazy DPH počítají s plátcem (typ P). Transakce testu změnu vrátí.
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678' WHERE id = ?")
            ->execute([$this->supplierId]);
    }

    public function testFinalWithAdvanceDeductionSettlesDdkpAdvance(): void
    {
        [$advance, $txEntry, $ddkpEntry] = $this->paidAdvanceWithDdkp('ZPF-DED');
        $final = $this->finalInvoice('PF-DED', $advance, true);

        $entryId = $this->postFinal($final);
        $byAcc = $this->linesByAccountCode($entryId);

        $expense = $this->expenseAccount($byAcc);
        self::assertSame(66566, self::cents($byAcc[$expense]['debit']), 'Náklad = spotřeba bez odpočtu zálohy.');
        self::assertSame(0, self::cents($byAcc[$expense]['credit']), 'Odpočet zálohy na náklad nesmí jít.');
        self::assertSame(13979, self::cents($byAcc['343.100']['debit']), '343 MD = DPH konečné faktury.');
        self::assertSame(15446, self::cents($byAcc['343.100']['credit']), '343 D = vrácení odpočtu z DDKP.');
        self::assertSame(65099, self::cents($byAcc['321']['credit']), '321 D = závazek bez odpočtu zálohy.');
        self::assertSame(73554, self::cents($byAcc['321']['debit']), '321 MD = zúčtování zbytku zálohy.');
        self::assertSame(73554, self::cents($byAcc['314']['credit']), '314 D = zbytek zálohy po DDKP.');
        self::assertArrayNotHasKey('548', $byAcc, 'Bez haléřového dorovnání.');
        self::assertArrayNotHasKey('648', $byAcc, 'Bez haléřového dorovnání.');
        $this->assertBalanced($entryId);

        $sum = $this->accountSums([$txEntry, $ddkpEntry, $entryId]);
        self::assertSame(0, self::cents($sum['314'] ?? 0), 'Poskytnutá záloha 314 po vyúčtování na nule.');
        self::assertSame(8455, self::cents($sum['321'] ?? 0), '321 MD = přeplatek (bez zaokrouhlení dokladu).');
        self::assertSame(13979, self::cents($sum['343.100'] ?? 0), '343 = DDKP 154,46 − 14,67.');

        // DPH říjen: jen rozdíl konečné faktury a DDKP.
        $kh = new \SimpleXMLElement($this->container->get(KontrolniHlaseniBuilder::class)->build($this->supplierId, self::YEAR, 10)['xml']);
        self::assertSame('-69.88', (string) $kh->DPHKH1->VetaB3['zakl_dane1'], 'KH B.3 základ.');
        self::assertSame('-14.67', (string) $kh->DPHKH1->VetaB3['dan1'], 'KH B.3 daň.');
        self::assertCount(0, $kh->DPHKH1->VetaB2, 'Konečná faktura pod limitem nepatří do B.2.');
        $dp = (new \SimpleXMLElement($this->container->get(DphPriznaniBuilder::class)->build($this->supplierId, self::YEAR, 10, 'monthly')['xml']))->DPHDP3;
        self::assertSame('-70', (string) $dp->Veta4['pln23'], 'ř. 40 základ −69,88.');
        self::assertSame('-15', (string) $dp->Veta4['odp_tuz23_nar'], 'ř. 40 odpočet −14,67.');
        $september = (new \SimpleXMLElement($this->container->get(DphPriznaniBuilder::class)->build($this->supplierId, self::YEAR, 9, 'monthly')['xml']))->DPHDP3;
        self::assertSame('154', (string) $september->Veta4['odp_tuz23_nar'], 'Září nese odpočet z DDKP.');

        // K úhradě: záloha se v hlavičce neodečítá podruhé.
        $row = $this->piRow($final);
        self::assertSame(0, self::cents($row['advance_paid_amount']));
        self::assertSame(-8455, self::cents($row['amount_to_pay']));
        self::assertSame([], (new PurchaseAdvanceDeduction($this->db))->finalsMissingDeduction($this->supplierId, self::YEAR . '-12-31'));
    }

    /**
     * Přehledy nákladů sčítané z hlavičky (CRM, hlavičky měsíců seznamu, daňový profil,
     * seznam dodavatelů): náklad konečné faktury = plnění 665,66 (s DPH 805,45), ne hlavička
     * po odpočtu zálohy (−69,88). DDKP se z nákladů vyřazuje, takže bez odečtu odpočtového
     * řádku by se základ zálohy ztratil úplně ({@see \MyInvoice\Support\Sql\PurchaseCostSql}).
     */
    public function testCostOverviewsCountFinalWithoutAdvanceDeduction(): void
    {
        [$advance] = $this->paidAdvanceWithDdkp('ZPF-CST');
        $final = $this->finalInvoice('PF-CST', $advance, true);
        $vendor = (int) $this->piRow($final)['vendor_id'];

        $crm = $this->container->get(\MyInvoice\Service\Crm\CrmAggregationService::class)
            ->documentRange($this->supplierId, '2099-10-01', '2099-11-01');
        $czk = array_values(array_filter($crm, static fn (array $r): bool => $r['currency'] === 'CZK'))[0] ?? null;
        self::assertNotNull($czk, 'CRM musí mít korunový řádek za říjen.');
        self::assertSame(66566, self::cents($czk['costs']), 'CRM náklad za říjen.');
        self::assertSame(66566, self::cents($czk['costs_czk']), 'CRM náklad za říjen v Kč.');

        $vendors = $this->container->get(\MyInvoice\Service\Crm\CrmAggregationService::class)->topVendors($this->supplierId, 1200, 50);
        $row = array_values(array_filter($vendors, static fn (array $v): bool => (int) $v['vendor_id'] === $vendor))[0] ?? null;
        self::assertNotNull($row);
        self::assertSame(66566, self::cents($row['costs']), 'Top dodavatelé: náklad dodavatele.');

        $repo = $this->container->get(PurchaseInvoiceRepository::class);
        $list = $repo->listGroupedByMonth(['supplier_id' => $this->supplierId, 'vendor_id' => $vendor], 1, 0);
        $october = array_values(array_filter($list['data'], static fn (array $g): bool => ($g['month'] ?? '') === '2099-10'))[0] ?? null;
        self::assertNotNull($october, 'Seznam musí mít skupinu 2099-10.');
        $totals = $october['totals_per_currency'][0] ?? [];
        self::assertSame(66566, self::cents($totals['without_vat'] ?? null), 'Hlavička měsíce: základ nákladu.');
        self::assertSame(80545, self::cents($totals['with_vat'] ?? null), 'Hlavička měsíce: náklad s DPH.');

        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = '2099-10-20' WHERE id = ?")->execute([$final]);
        $tax = $this->container->get(\MyInvoice\Repository\TaxProfileRepository::class);
        self::assertSame(66566, self::cents($tax->monthExpenses($this->supplierId, '2099-10', true)), 'Daňový profil plátce: výdaj bez DPH.');
        self::assertSame(80545, self::cents($tax->monthExpenses($this->supplierId, '2099-10', false)), 'Daňový profil neplátce: výdaj s DPH.');

        $clients = $this->container->get(\MyInvoice\Repository\ClientRepository::class)
            ->list(['supplier_id' => $this->supplierId, 'q' => 'Dodavatel ZPF-CST'], 1, 20);
        $client = array_values(array_filter($clients['data'], static fn (array $c): bool => (int) $c['id'] === $vendor))[0] ?? null;
        self::assertNotNull($client);
        self::assertSame(80545, self::cents($client['costs']), 'Seznam dodavatelů: náklady s DPH.');
    }

    public function testLinkingFinalWithDeductionKeepsHeaderAdvanceZero(): void
    {
        [$advance] = $this->paidAdvanceWithDdkp('ZPF-LNK');
        $final = $this->finalInvoice('PF-LNK', null, true, $advance);
        $repo = $this->container->get(PurchaseInvoiceRepository::class);

        $repo->linkAdvance($final, $advance, $this->supplierId);
        self::assertSame(0, self::cents($this->piRow($final)['advance_paid_amount']), 'Odpočet na řádku: hlavička zálohu neodečítá.');
        self::assertSame([], (new LinkedAdvanceAmount($this->db))->fillFinalsOfAdvance($this->supplierId, $advance));
        self::assertFalse($repo->find($final, $this->supplierId)['has_advance_candidates'] ?? false);

        // Ruční korekce v hlavičce se s odpočtem nepotká: uložení ji vynuluje.
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_paid_amount = 890 WHERE id = ?')->execute([$final]);
        self::assertTrue((new PurchaseAdvanceDeduction($this->db))->clearHeaderAdvanceWhenDeducted($this->supplierId, $final));
        self::assertSame(0, self::cents($this->piRow($final)['advance_paid_amount']));
    }

    public function testFinalWithoutDeductionIsNotPostedAndWarns(): void
    {
        [$advance] = $this->paidAdvanceWithDdkp('ZPF-NOD');
        $final = $this->finalInvoice('PF-NOD', $advance, false);

        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $final);
            self::fail('Konečná faktura bez odpočtu zálohy s DDKP se nesmí zaúčtovat.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
            self::assertStringContainsString('Doplnit odpočet zálohy', $e->getMessage());
            self::assertStringContainsString('154,46', $e->getMessage());
            self::assertStringContainsString('-14,67', $e->getMessage(), 'Doúčtovat zbývá 139,79 − 154,46.');
        }

        $missing = (new PurchaseAdvanceDeduction($this->db))->finalsMissingDeduction($this->supplierId, self::YEAR . '-12-31');
        self::assertSame([$final], array_column($missing, 'id'), 'Měsíční kontrola najde dvojí odpočet.');
        self::assertSame(15446, self::cents($missing[0]['amount']));
        self::assertTrue(CreatePurchaseInvoiceAction::finalRisksDoubleVatDeduction($this->db, $this->piRow($final), $this->supplierId));
    }

    public function testMismatchedDeductionThrowsWithNumbers(): void
    {
        [$advance] = $this->paidAdvanceWithDdkp('ZPF-MIS');
        $final = $this->finalInvoice('PF-MIS', $advance, true, null, -700.00, -147.00);

        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $final);
            self::fail('Odpočet nesedící s DDKP má vyhodit advance_settlement_ambiguous.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
            self::assertStringContainsString('700.00', $e->getMessage());
            self::assertStringContainsString('735.54', $e->getMessage());
        }
    }

    public function testUnpostedTaxDocumentBlocksSettlement(): void
    {
        $vendor = $this->client('Dodavatel nezaúčtovaný DDKP');
        $advance = $this->purchase('ZPF-UNP', $vendor, 'advance', null, null, '2099-09-05', [[890.00, 0.00]]);
        $this->payAdvance($advance, 890.00, '2099-09-05');
        $this->purchase('DDKP-UNP', $vendor, 'tax_document', null, $advance, '2099-09-10', [[735.54, 154.46]]);
        $final = $this->finalInvoice('PF-UNP', $advance, true);

        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $final);
            self::fail('Nezaúčtovaný DDKP má vyhodit advance_tax_document_unposted.');
        } catch (PostingException $e) {
            self::assertSame('advance_tax_document_unposted', $e->errorCode);
        }
    }

    public function testDeductionOfOtherAdvanceThrows(): void
    {
        [$advance] = $this->paidAdvanceWithDdkp('ZPF-OTH');
        [$other] = $this->paidAdvanceWithDdkp('ZPF-OTH2');
        $final = $this->finalInvoice('PF-OTH', $advance, true, $other);

        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $final);
            self::fail('Odpočet cizí zálohy má vyhodit advance_settlement_ambiguous.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
            self::assertStringContainsString('#' . $other, $e->getMessage());
        }
    }

    public function testDeductionLinesFromTaxDocument(): void
    {
        [$advance] = $this->paidAdvanceWithDdkp('ZPF-LIN');
        $lines = (new PurchaseAdvanceDeduction($this->db))->linesFromTaxDocuments($this->supplierId, $advance);

        self::assertCount(1, $lines);
        self::assertSame(-73554, self::cents($lines[0]['base']));
        self::assertSame(-15446, self::cents($lines[0]['vat']));
        self::assertSame(-89000, self::cents($lines[0]['gross']));
        self::assertSame($this->vatRateId, $lines[0]['vat_rate_id']);
        self::assertSame([], (new PurchaseAdvanceDeduction($this->db))->linesFromTaxDocuments($this->otherSupplierId(), $advance), 'Cizí firma řádky nedostane.');
    }

    /** Záloha bez DDKP se zúčtuje jako dosud a řádkem se odečítat nesmí. */
    public function testAdvanceWithoutTaxDocumentKeepsSettlementAndRejectsDeductionLine(): void
    {
        $vendor = $this->client('Dodavatel bez DDKP');
        $advance = $this->purchase('ZPF-PLAIN', $vendor, 'advance', null, null, '2099-09-05', [[890.00, 0.00]]);
        $this->payAdvance($advance, 890.00, '2099-09-05');

        $plain = $this->purchase('PF-PLAIN', $vendor, 'invoice', $advance, null, '2099-10-15', [[1000.00, 210.00]]);
        $byAcc = $this->linesByAccountCode($this->postFinal($plain));
        self::assertSame(121000, self::cents($byAcc['321']['credit']));
        self::assertSame(89000, self::cents($byAcc['321']['debit']), 'Zúčtování celé zaplacené zálohy beze změny.');
        self::assertSame(89000, self::cents($byAcc['314']['credit']));
        self::assertSame(21000, self::cents($byAcc['343.100']['debit']));

        $vendor2 = $this->client('Dodavatel bez DDKP 2');
        $advance2 = $this->purchase('ZPF-PLAIN2', $vendor2, 'advance', null, null, '2099-09-05', [[890.00, 0.00]]);
        $this->payAdvance($advance2, 890.00, '2099-09-05');
        $marked = $this->purchase('PF-PLAIN2', $vendor2, 'invoice', $advance2, null, '2099-10-15', [[1000.00, 210.00], [-735.54, -154.46, $advance2]]);
        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $marked);
            self::fail('Odpočet zálohy bez DDKP řádkem má vyhodit advance_settlement_ambiguous.');
        } catch (PostingException $e) {
            self::assertSame('advance_settlement_ambiguous', $e->errorCode);
        }
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    /**
     * Záloha 890 zaplacená v září, DDKP 735,54 + 154,46 v září, obojí zaúčtované.
     *
     * @return array{0:int, 1:int, 2:int} záloha, zápis úhrady, zápis DDKP
     */
    private function paidAdvanceWithDdkp(string $number): array
    {
        $vendor = $this->client('Dodavatel ' . $number);
        $advance = $this->purchase($number, $vendor, 'advance', null, null, '2099-09-05', [[890.00, 0.00]]);
        $txEntry = $this->payAdvance($advance, 890.00, '2099-09-05');
        $ddkp = $this->purchase('DDKP-' . $number, $vendor, 'tax_document', null, $advance, '2099-09-10', [[735.54, 154.46]]);
        $ddkpEntry = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $ddkp,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $ddkp),
            ['entry_date' => '2099-09-10'],
        );
        return [$advance, $txEntry, $ddkpEntry];
    }

    /**
     * Konečná faktura případu: spotřeba 665,66 + 139,79 a (volitelně) odpočet zálohy.
     * `$deductedAdvance` = záloha vyznačená na řádku (výchozí = $advance z hlavičky).
     */
    private function finalInvoice(string $number, ?int $advance, bool $withDeduction, ?int $deductedAdvance = null, float $base = -735.54, float $vat = -154.46): int
    {
        $vendor = (int) $this->db->pdo()->query('SELECT vendor_id FROM purchase_invoices WHERE id = ' . (int) ($advance ?? $deductedAdvance))->fetchColumn();
        $items = [[665.66, 139.79]];
        if ($withDeduction) {
            $items[] = [$base, $vat, $deductedAdvance ?? $advance];
        }
        $id = $this->purchase($number, $vendor, 'invoice', $advance, null, '2099-10-15', $items);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET rounding = 0.17 WHERE id = ?')->execute([$id]);
        return $id;
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
            ['entry_date' => '2099-10-15'],
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
            foreach ($this->linesByAccountCode($entryId) as $code => $sides) {
                if ((string) $code === '221' && $this->hasBankAnalytic($entryId)) {
                    continue; // alias analytiky banky, sčítala by se dvakrát
                }
                $sum[(string) $code] = round(($sum[(string) $code] ?? 0.0) + $sides['debit'] - $sides['credit'], 2);
            }
        }
        return $sum;
    }

    private function hasBankAnalytic(int $entryId): bool
    {
        foreach (array_keys($this->linesByAccountCode($entryId)) as $code) {
            if ((string) $code !== '221' && str_starts_with((string) $code, '221')) {
                return true;
            }
        }
        return false;
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
