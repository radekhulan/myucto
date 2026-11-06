<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Service\Accounting\AdvancePaymentRelink;
use MyInvoice\Service\Accounting\AdvanceSettlementSync;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\PostingOriginService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Stavové a automatické cesty po ručním stornu přímého člena skupiny. */
#[Group('integration')]
final class PostingGroupFollowupTest extends BankPostingTestCase
{
    public function testPostingOriginUsesRemainingOwnedEntriesAndAllTheirAccounts(): void
    {
        foreach (['invoice', 'purchase_invoice'] as $type) {
            [$doc, , $owned] = $this->groupedDocument($type, true);
            $result = $this->container->get(PostingOriginService::class)->describe($this->supplierId, $type, $doc);

            self::assertTrue($result['posted'], $type . ': vlastněný člen stále drží předpis.');
            self::assertSame($owned[0], $result['entry_id']);
            self::assertTrue($result['presets'][0]['used'], 'Účty předkontace se hledají ve všech živých členech, ne jen v prvním DPH.');
        }
    }

    public function testPostingOriginKeepsNativeDraftAndBankSemantics(): void
    {
        $origin = $this->container->get(PostingOriginService::class);
        foreach (['invoice', 'purchase_invoice', 'bank'] as $type) {
            $doc = $type === 'bank' ? $this->transaction($this->statement(), 1000) : $this->document($type);
            $entry = $this->postPredpis($type, $doc, $type === 'invoice' ? '311' : '518', $type === 'invoice' ? '602' : '321', 1000);
            $this->db->pdo()->prepare('UPDATE journal_entries SET posted_at = NULL WHERE supplier_id = ? AND id = ?')
                ->execute([$this->supplierId, $entry]);
            self::assertSame($entry, $origin->describe($this->supplierId, $type, $doc)['entry_id'], 'Dosavadní stav zahrnuje i přímý koncept.');
            $this->db->pdo()->prepare('UPDATE journal_entries SET posted_at = NOW() WHERE supplier_id = ? AND id = ?')
                ->execute([$this->supplierId, $entry]);
            $this->posting->reverse($this->supplierId, $entry);
            self::assertFalse($origin->describe($this->supplierId, $type, $doc)['posted']);
        }
    }

    public function testAdvanceSyncReportsOwnedOnlyFinalInsteadOfUnposted(): void
    {
        foreach (['invoice' => 'sale', 'purchase_invoice' => 'purchase'] as $type => $side) {
            [$final, , $owned] = $this->groupedDocument($type, true);
            $result = $this->syncFinal($side, $final);
            self::assertSame('skipped', $result['action']);
            self::assertSame('posting_group_requires_repost', $result['reason']);
            self::assertSame($owned[0], $result['entry_id']);
        }
    }

    public function testAdvanceSyncSkipsImportedOwnedOnlyFinal(): void
    {
        [$final, , $owned] = $this->groupedDocument('purchase_invoice', true);
        $this->importedEntry($owned[0]);
        self::assertSame('taken_over', $this->syncFinal('purchase', $final)['reason']);
    }

    public function testAdvanceRelinkBlocksOwnedOnlyFinalBeforeChangingPayment(): void
    {
        [$final] = $this->groupedDocument('purchase_invoice', true);
        $result = $this->relinkBlocker($final);
        self::assertNotNull($result);
        self::assertSame('posting_group_requires_repost', $result['status']);
    }

    public function testAdvanceRelinkBlocksImportedFinalEvenWithDirectMember(): void
    {
        [$final, $direct] = $this->groupedDocument('purchase_invoice', false);
        $this->importedEntry($direct);
        $result = $this->relinkBlocker($final);
        self::assertNotNull($result);
        self::assertSame('taken_over', $result['status']);
    }

    public function testAdvanceRelinkBalanceCheckCannotSilentlySkipOwnedOnlyFinal(): void
    {
        [$final] = $this->groupedDocument('purchase_invoice', true);
        $tx = $this->transaction($this->statement(), -1000);
        $bank = $this->postPredpis('bank', $tx, '314', '221', 1000);
        $service = $this->container->get(AdvancePaymentRelink::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('skupin');
        (new \ReflectionMethod($service, 'assertAdvanceBalanced'))->invoke($service, $this->supplierId, $bank, $final);
    }

    /** @return array{int,int,list<int>} */
    private function groupedDocument(string $type, bool $reverseDirect): array
    {
        $doc = $this->document($type);
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        $this->db->pdo()->prepare("UPDATE {$table} SET total_without_vat = 1000, total_vat = 210, total_with_vat = 1210 WHERE supplier_id = ? AND id = ?")
            ->execute([$this->supplierId, $doc]);
        [$debit, $credit] = $type === 'invoice' ? ['311', '602'] : ['518', '321'];
        $direct = $this->postPredpis($type, $doc, $debit, $credit, 1000);
        $owned = [];
        $pieces = [[$type === 'invoice' ? '311' : '343', $type === 'invoice' ? '343' : '321', 210]];
        if ($reverseDirect) $pieces[] = [$debit, $credit, 1000];
        foreach ($pieces as [$d, $c, $amount]) {
            $owned[] = $this->posting->postDocument($this->supplierId, 'manual', null, [
                ['account_code' => $d, 'side' => 'debit', 'amount' => $amount],
                ['account_code' => $c, 'side' => 'credit', 'amount' => $amount],
            ], ['entry_date' => self::YEAR . '-06-10']);
        }
        (new DocumentPostingGroup($this->db))->attach($this->supplierId, $type, $doc, [$direct, ...$owned]);
        if ($reverseDirect) $this->posting->reverse($this->supplierId, $direct);
        return [$doc, $direct, $owned];
    }

    private function document(string $type): int
    {
        $vendor = $this->client('TEST skupina ' . $type);
        return $type === 'invoice'
            ? $this->saleInvoice('TEST-SKUPINA-' . $vendor, $vendor, 1000)
            : $this->purchaseInvoice('TEST-SKUPINA-' . $vendor, $vendor, 1000);
    }

    private function importedEntry(int $entry): void
    {
        $this->db->pdo()->prepare("INSERT INTO money_s3_import_map (supplier_id, kind, money_key, target_id) VALUES (?, 'journal_entry', ?, ?)")
            ->execute([$this->supplierId, 'synth|followup-' . $entry, $entry]);
    }

    private function syncFinal(string $side, int $final): array
    {
        $service = $this->container->get(AdvanceSettlementSync::class);
        return (new \ReflectionMethod($service, 'syncFinal'))->invoke($service, $this->supplierId, $side, $final, 0, $this->userId);
    }

    private function relinkBlocker(int $final): ?array
    {
        $advance = $this->purchaseInvoice('TEST-ZALOHA-' . $final, $this->client('TEST záloha'), 1210, 'advance');
        $tx = $this->transaction($this->statement(), -1210);
        $this->postPredpis('bank', $tx, '321', '221', 1210);
        $service = $this->container->get(AdvancePaymentRelink::class);
        return (new \ReflectionMethod($service, 'blocker'))->invoke($service, [
            'supplier_id' => $this->supplierId, 'tx_matches' => 1, 'currency' => 'CZK',
            'amount' => 1210, 'advance_total' => 1210, 'final_total' => 1210, 'final_to_pay' => 0,
            'advance_id' => $advance, 'final_id' => $final, 'tx_id' => $tx,
        ]);
    }
}
