<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Úhrada dokladu z počátečních stavů: faktura minulého roku převzatá do otevíracího
 * zápisu vlastní zápis nemá, jejím předpisem je řádek saldokonta v počátečním stavu.
 * Banka ji musí odúčtovat stejně jako fakturu s vlastním zápisem, jinak úhrady starých
 * faktur zůstanou nezaúčtované s důvodem „nemá zaúčtovaný předpis".
 */
#[Group('integration')]
final class BankPostingOpeningDocumentTest extends BankPostingTestCase
{
    public function testIncomingPaymentOfOpeningInvoiceCreditsSaldoAnalyticFromOpening(): void
    {
        $this->analytic('311.001');
        $inv = $this->saleInvoice('FV-PS-1', $this->client('Odběratel počáteční stav'), 1210.00);
        $opening = $this->opening([['311.001', 'debit', 1210.00], ['395', 'credit', 1210.00]]);
        $this->linkToOpening($opening, 'invoice', $inv);

        $tx = $this->transaction($this->statement(), 1210.00, ['match_status' => 'auto_partial', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 1210.00);
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        self::assertSame(['221:debit:1210.00', '311.001:credit:1210.00'], $this->postedLines((int) $res['entry_id']));
    }

    public function testOutgoingPaymentOfOpeningPurchaseDebitsSaldoFromOpening(): void
    {
        $this->analytic('321.001');
        $pf = $this->purchaseInvoice('PF-PS-1', $this->client('Dodavatel počáteční stav'), 800.00);
        $opening = $this->opening([['395', 'debit', 800.00], ['321.001', 'credit', 800.00]]);
        $this->linkToOpening($opening, 'purchase_invoice', $pf);

        $tx = $this->transaction($this->statement(), -800.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 800.00);
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        self::assertSame(['221:credit:800.00', '321.001:debit:800.00'], $this->postedLines((int) $res['entry_id']));
    }

    /** Saldokonto vedené v počátečním stavu na dvou analytikách: účet nejde určit, nehádá se. */
    public function testOpeningWithSeveralSaldoAnalyticsIsNotGuessed(): void
    {
        $this->analytic('311.001');
        $this->analytic('311.002');
        $inv = $this->saleInvoice('FV-PS-2', $this->client('Odběratel dvě analytiky'), 500.00);
        $opening = $this->opening([
            ['311.001', 'debit', 300.00], ['311.002', 'debit', 200.00], ['395', 'credit', 500.00],
        ]);
        $this->linkToOpening($opening, 'invoice', $inv);

        $tx = $this->transaction($this->statement(), 500.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 500.00);
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('skipped', $res['action']);
        self::assertSame('opening_saldo_ambiguous', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    /** Stornovaný otevírací zápis předpis nenese, doklad bez vazby na počáteční stav taky ne. */
    public function testReversedOpeningOrMissingLinkStaysUnposted(): void
    {
        $inv = $this->saleInvoice('FV-PS-3', $this->client('Odběratel storno PS'), 400.00);
        $opening = $this->opening([['311', 'debit', 400.00], ['395', 'credit', 400.00]]);
        $this->linkToOpening($opening, 'invoice', $inv);
        $this->posting->reverse($this->supplierId, $opening);

        $tx = $this->transaction($this->statement(), 400.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 400.00);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('document_not_posted', $res['reason']);

        $unlinked = $this->saleInvoice('FV-PS-4', $this->client('Odběratel bez vazby'), 300.00);
        $tx = $this->transaction($this->statement(), 300.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $unlinked]);
        $this->invoicePayment($unlinked, $tx, 300.00);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('document_not_posted', $res['reason']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @param list<array{0:string,1:string,2:float}> $lines */
    private function opening(array $lines): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        return $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => self::YEAR . '-01-01',
            'document_no' => 'PS-' . uniqid(),
            'description' => 'Počáteční stavy',
            'source_type' => 'opening',
            'source_id'   => null,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], array_map(static fn (array $line): array => [
            'account_id' => $map[$line[0]]['id'],
            'side'       => $line[1],
            'amount'     => $line[2],
        ], $lines));
    }

    private function linkToOpening(int $entryId, string $docType, int $docId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $entryId, $docType, $docId, OpeningBalanceDocuments::NOTE, $this->userId]);
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
