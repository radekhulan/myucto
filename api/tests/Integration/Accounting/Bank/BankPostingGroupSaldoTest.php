<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\DocumentPostingGroup;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Úhrada dokladu se skupinou zaúčtování: předpis i saldokonto se čtou ze skupiny,
 * ne jen z přímého zápisu. Převzatý doklad nese základ a DPH ve zvláštních zápisech
 * a účetní může přímý člen ručně stornovat, doklad přitom zůstává zaúčtovaný.
 */
#[Group('integration')]
final class BankPostingGroupSaldoTest extends BankPostingTestCase
{
    public function testOutgoingPaymentUsesRemainingMembersAndTheirSaldoAnalytic(): void
    {
        $this->analytic('321.001');
        $pf = $this->purchase(1210.00);
        $this->groupWithReversedDirect('purchase_invoice', $pf, ['518', '321.001', 1000.00], [
            [['343', 'debit', 210.00], ['321.001', 'credit', 210.00]],
            [['518', 'debit', 1000.00], ['321.001', 'credit', 1000.00]],
        ]);

        $tx = $this->transaction($this->statement(), -1210.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 1210.00);
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], 'Zbylí členové skupiny drží předpis: ' . json_encode($res));
        self::assertSame(['221:credit:1210.00', '321.001:debit:1210.00'], $this->postedLines((int) $res['entry_id']));
    }

    public function testIncomingPaymentUsesRemainingMembersAndTheirSaldoAnalytic(): void
    {
        $this->analytic('311.001');
        $inv = $this->saleInvoice('FV-SKUPINA-1', $this->client('Odběratel skupina'), 1210.00);
        $this->groupWithReversedDirect('invoice', $inv, ['311.001', '602', 1000.00], [
            [['311.001', 'debit', 210.00], ['343', 'credit', 210.00]],
            [['311.001', 'debit', 1000.00], ['602', 'credit', 1000.00]],
        ]);

        $tx = $this->transaction($this->statement(), 1210.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 1210.00);
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        self::assertSame(['221:debit:1210.00', '311.001:credit:1210.00'], $this->postedLines((int) $res['entry_id']));
    }

    /** Přímý člen saldo nenese, nese ho až vlastněný zápis: úhrada nesmí spadnout na syntetiku. */
    public function testSaldoAnalyticComesFromTheMemberThatCarriesIt(): void
    {
        $this->analytic('321.001');
        $pf = $this->purchase(1210.00);
        $direct = $this->entry('purchase_invoice', $pf, [['518', 'debit', 1000.00], ['395', 'credit', 1000.00]]);
        $owned = $this->entry('manual', null, [
            ['343', 'debit', 210.00], ['395', 'debit', 1000.00], ['321.001', 'credit', 1210.00],
        ]);
        (new DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $pf, [$direct, $owned]);

        $tx = $this->transaction($this->statement(), -1210.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 1210.00);
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        self::assertSame(['221:credit:1210.00', '321.001:debit:1210.00'], $this->postedLines((int) $res['entry_id']));
    }

    public function testSaldoOnDifferentAccountsInGroupIsNotGuessed(): void
    {
        $this->analytic('321.001');
        $this->analytic('321.002');
        $pf = $this->purchase(1210.00);
        $this->groupWithReversedDirect('purchase_invoice', $pf, ['518', '321.001', 1000.00], [
            [['343', 'debit', 210.00], ['321.001', 'credit', 210.00]],
            [['518', 'debit', 1000.00], ['321.002', 'credit', 1000.00]],
        ]);

        $tx = $this->transaction($this->statement(), -1210.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 1210.00);
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('skipped', $res['action']);
        self::assertSame('posting_group_saldo_ambiguous', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testGroupWithoutSaldoLineIsNotGuessed(): void
    {
        $pf = $this->purchase(1000.00);
        $this->groupWithReversedDirect('purchase_invoice', $pf, ['518', '321', 1000.00], [
            [['518', 'debit', 1000.00], ['395', 'credit', 1000.00]],
        ]);

        $tx = $this->transaction($this->statement(), -1000.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 1000.00);
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('skipped', $res['action']);
        self::assertSame('posting_group_saldo_ambiguous', $res['reason']);
    }

    /** Kurz předpisu se u skupiny bere ze saldokontního řádku zbylého člena. */
    public function testFxRateComesFromSaldoCarrierOfGroup(): void
    {
        $eur = (int) $this->db->pdo()->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn();
        if ($eur === 0) {
            $eur = $this->currencyRow($this->supplierId, 'EUR');
        }
        $inv = $this->saleInvoice('FV-SKUPINA-EUR', $this->client('Odběratel EUR'), 50.00);
        $this->db->pdo()->prepare('UPDATE invoices SET currency_id = ?, exchange_rate = 25.000 WHERE supplier_id = ? AND id = ?')
            ->execute([$eur, $this->supplierId, $inv]);
        $this->groupWithReversedDirect('invoice', $inv, ['311', '602', 1250.00], [
            [['311', 'debit', 1225.00, 24.5, 50.00], ['602', 'credit', 1225.00]],
        ]);

        $method = new \ReflectionMethod($this->service, 'incomingFxSaldo');
        [$account, $rate] = $method->invoke($this->service, $this->supplierId, $inv, '311', 'EUR');

        self::assertSame('311', $account);
        self::assertSame(24.5, $rate);
    }

    /** Nativní doklady (jen přímý zápis): stejné řádky i stejné odmítnutí jako před skupinami. */
    public function testNativeDocumentsKeepPaymentPostingUnchanged(): void
    {
        $this->analytic('321.001');
        $pf = $this->purchase(1210.00);
        $this->entry('purchase_invoice', $pf, [['518', 'debit', 1210.00], ['321.001', 'credit', 1210.00]]);
        $tx = $this->transaction($this->statement(), -1210.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 1210.00);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        self::assertSame(['221:credit:1210.00', '321.001:debit:1210.00'], $this->postedLines((int) $res['entry_id']));

        $inv = $this->saleInvoice('FV-NATIVNI-1', $this->client('Odběratel nativní'), 500.00);
        $this->postPredpis('invoice', $inv, '311', '602', 500.00);
        $tx = $this->transaction($this->statement(), 500.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 500.00);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        self::assertSame(['221:debit:500.00', '311:credit:500.00'], $this->postedLines((int) $res['entry_id']));

        $reversed = $this->purchase(800.00);
        $entry = $this->postPredpis('purchase_invoice', $reversed, '518', '321', 800.00);
        $this->posting->reverse($this->supplierId, $entry);
        $tx = $this->transaction($this->statement(), -800.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $reversed, 800.00);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('document_not_posted', $res['reason']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function purchase(float $total): int
    {
        return $this->purchaseInvoice('PF-SKUPINA-' . uniqid(), $this->client('Dodavatel skupina'), $total);
    }

    /**
     * Přímý zápis + vlastněné zápisy ve skupině, přímý pak ručně stornovaný.
     *
     * @param array{string,string,float} $direct [MD, D, částka]
     * @param list<list<array{0:string,1:string,2:float,3?:float,4?:float}>> $owned
     */
    private function groupWithReversedDirect(string $type, int $docId, array $direct, array $owned): void
    {
        $directId = $this->entry($type, $docId, [[$direct[0], 'debit', $direct[2]], [$direct[1], 'credit', $direct[2]]]);
        $ownedIds = array_map(fn (array $lines): int => $this->entry('manual', null, $lines), $owned);
        (new DocumentPostingGroup($this->db))->attach($this->supplierId, $type, $docId, [$directId, ...$ownedIds]);
        $this->posting->reverse($this->supplierId, $directId);
    }

    /** @param list<array{0:string,1:string,2:float,3?:float,4?:float}> $lines [účet, strana, částka, kurz, cizí částka] */
    private function entry(string $sourceType, ?int $sourceId, array $lines): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        return $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => self::YEAR . '-06-10',
            'document_no' => 'SKUPINA-' . uniqid(),
            'description' => 'Předpis skupiny',
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], array_map(static fn (array $line): array => [
            'account_id'     => $map[$line[0]]['id'],
            'side'           => $line[1],
            'amount'         => $line[2],
            'currency_code'  => isset($line[3]) ? 'EUR' : null,
            'fx_rate'        => $line[3] ?? null,
            'amount_foreign' => $line[4] ?? null,
        ], $lines));
    }

    private function analytic(string $code): void
    {
        $parent = $this->db->pdo()->prepare('SELECT id, account_type, normal_side FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $parent->execute([$this->supplierId, substr($code, 0, 3)]);
        $row = $parent->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Syntetika analytiky musí v osnově existovat.');
        $this->accounts->insert($this->supplierId, [
            'account_code' => $code,
            'name'         => 'Analytika ' . $code,
            'account_type' => (string) $row['account_type'],
            'normal_side'  => $row['normal_side'],
            'parent_id'    => (int) $row['id'],
        ]);
    }

    /** @return list<string> řádky zápisu jako „účet:strana:částka", bankovní analytika 221xxx jako 221 */
    private function postedLines(int $entryId): array
    {
        $out = [];
        foreach ($this->journal->find($entryId, $this->supplierId)['lines'] as $line) {
            $code = $this->accountCode((int) $line['account_id']);
            $out[] = (str_starts_with($code, '221') ? '221' : $code) . ':' . $line['side'] . ':' . number_format((float) $line['amount'], 2, '.', '');
        }
        sort($out);
        return $out;
    }
}
