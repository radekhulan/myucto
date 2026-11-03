<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalSettlementAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\JournalEntryDocumentLinkRepository;
use MyInvoice\Service\Accounting\JournalSettlementException;
use MyInvoice\Service\Accounting\JournalSettlementService;
use MyInvoice\Service\Accounting\JournalSettlementUseGuard;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Vyrovnání položky saldokonta rozpadem řádku zápisu na doklady (migrace 2192).
 *
 * Firma testu má v deníku i jiná data, proto se konfrontace s hlavní knihou měří
 * rozdílem před a po (zůstatek účtu se rozpadem nemění, mění se jen součet položek).
 */
#[Group('integration')]
final class JournalSettlementTest extends BankPostingTestCase
{
    private JournalSettlementService $settlements;
    private SaldoService $saldo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settlements = $this->container->get(JournalSettlementService::class);
        $this->saldo = $this->container->get(SaldoService::class);
    }

    /** @return array<string,array{string,string,string}> */
    public static function documents(): array
    {
        return [
            'payable' => ['purchase_invoice', '321', 'debit'],
            'receivable' => ['invoice', '311', 'credit'],
        ];
    }

    #[DataProvider('documents')]
    public function testOneEntrySettlingTwoDocumentsReducesSaldoAndDifference(string $type, string $account, string $settleSide): void
    {
        $partner = $this->client('Syntetický partner rozpadu');
        $a = $this->document($type, 'ROZ-A', $partner, 1000.0, $account);
        $b = $this->document($type, 'ROZ-B', $partner, 500.0, $account);
        $entry = $this->manual($account, $settleSide, 1500.0, self::YEAR . '-07-01', 'Zápočet ROZ-A a ROZ-B');
        $links = $this->container->get(JournalEntryDocumentLinkRepository::class);
        $links->add($entry, $this->supplierId, $type, $a, null, $this->userId);
        $links->add($entry, $this->supplierId, $type, $b, null, $this->userId);
        $asOf = self::YEAR . '-12-31';

        $before = $this->block($account, $asOf);
        self::assertEqualsWithDelta(1000.0, $this->remaining($before, $type, $a), 0.001, 'Vazba na dva doklady bez částky se nepočítá.');
        self::assertEqualsWithDelta(500.0, $this->remaining($before, $type, $b), 0.001);

        $line = $this->lineId($entry, $account);
        $this->settlements->allocate($this->supplierId, [
            ['line_id' => $line, 'doc_type' => $type, 'doc_id' => $a, 'amount' => 1000.0],
            ['line_id' => $line, 'doc_type' => $type, 'doc_id' => $b, 'amount' => 500.0],
        ], $this->userId);

        $after = $this->block($account, $asOf);
        self::assertNull($this->remaining($after, $type, $a));
        self::assertNull($this->remaining($after, $type, $b));
        self::assertEqualsWithDelta($before['gl_balance'], $after['gl_balance'], 0.001, 'Rozpad nemění hlavní knihu.');
        self::assertEqualsWithDelta(1500.0, $before['open_items_total'] - $after['open_items_total'], 0.001);
        self::assertEqualsWithDelta(1500.0, $after['difference'] - $before['difference'], 0.001, 'Rozdíl k hlavní knize klesne o vyrovnanou částku.');

        $beforeEntry = $this->block($account, self::YEAR . '-06-30');
        self::assertEqualsWithDelta(1000.0, $this->remaining($beforeEntry, $type, $a), 0.001, 'Před datem zápisu je doklad otevřený.');
    }

    #[DataProvider('documents')]
    public function testClosingCheckCountsAllocationLikeSaldo(string $type, string $account, string $settleSide): void
    {
        $partner = $this->client('Syntetický partner kontroly');
        $doc = $this->document($type, 'ROZ-K3', $partner, 800.0, $account);
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        $this->db->pdo()->prepare("UPDATE {$table} SET status = 'paid', paid_at = ? WHERE id = ? AND supplier_id = ?")
            ->execute([self::YEAR . '-07-01 00:00:00', $doc, $this->supplierId]);
        $entry = $this->manual($account, $settleSide, 800.0, self::YEAR . '-07-01', 'Zápočet ROZ-K3 a jiného dokladu');
        $asOf = self::YEAR . '-12-31';
        self::assertContains($doc, $this->closingIssues($type, $asOf));

        $this->settlements->allocate($this->supplierId, [
            ['line_id' => $this->lineId($entry, $account), 'doc_type' => $type, 'doc_id' => $doc, 'amount' => 800.0],
        ], $this->userId);

        self::assertNotContains($doc, $this->closingIssues($type, $asOf), 'Kontrola uzávěrky počítá rozpad stejně jako saldokonto.');
    }

    public function testSingleLinkIsNotCountedTwiceWithAllocation(): void
    {
        $partner = $this->client('Syntetický partner dvojí vazby');
        $doc = $this->document('purchase_invoice', 'ROZ-DUP', $partner, 1000.0, '321');
        $entry = $this->manual('321', 'debit', 1000.0, self::YEAR . '-07-01', 'Částečný zápočet ROZ-DUP');
        $this->settlements->allocate($this->supplierId, [
            ['line_id' => $this->lineId($entry, '321'), 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 600.0],
        ], $this->userId);
        // Vazba přidaná až po rozpadu: zápis je na doklad navázaný jako jediný, stará cesta
        // by mu přiznala celý řádek. Rozpad má přednost, vazba se nepočítá.
        $this->container->get(JournalEntryDocumentLinkRepository::class)->add($entry, $this->supplierId, 'purchase_invoice', $doc, null, $this->userId);

        $block = $this->block('321', self::YEAR . '-12-31');
        self::assertEqualsWithDelta(400.0, $this->remaining($block, 'purchase_invoice', $doc), 0.001);
    }

    public function testCountedLinkBlocksAllocation(): void
    {
        $partner = $this->client('Syntetický partner vazby');
        $doc = $this->document('purchase_invoice', 'ROZ-LNK', $partner, 1000.0, '321');
        $entry = $this->manual('321', 'debit', 1000.0, self::YEAR . '-07-01', 'Vyrovnání ROZ-LNK');
        $this->container->get(JournalEntryDocumentLinkRepository::class)->add($entry, $this->supplierId, 'purchase_invoice', $doc, null, $this->userId);
        $this->assertSettlementError('entry_linked', fn () => $this->settlements->allocate($this->supplierId, [
            ['line_id' => $this->lineId($entry, '321'), 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 100.0],
        ], $this->userId));
        $candidates = $this->settlements->candidates($this->supplierId, 'purchase_invoice', $doc, '321', self::YEAR . '-12-31');
        self::assertNotContains($this->lineId($entry, '321'), array_column($candidates['items'], 'line_id'), 'Zápis použitý vazbou není kandidát.');
    }

    public function testReversedEntryStopsSettlingFromReversalDate(): void
    {
        $partner = $this->client('Syntetický partner storna');
        $doc = $this->document('purchase_invoice', 'ROZ-STO', $partner, 300.0, '321');
        $entry = $this->manual('321', 'debit', 300.0, self::YEAR . '-07-01', 'Zápočet ROZ-STO');
        $this->settlements->allocate($this->supplierId, [
            ['line_id' => $this->lineId($entry, '321'), 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 300.0],
        ], $this->userId);
        $this->posting->reverse($this->supplierId, $entry, ['entry_date' => self::YEAR . '-09-01', 'description' => 'Storno', 'posted_by' => $this->userId]);

        self::assertNull($this->remaining($this->block('321', self::YEAR . '-08-31'), 'purchase_invoice', $doc));
        self::assertEqualsWithDelta(300.0, $this->remaining($this->block('321', self::YEAR . '-09-30'), 'purchase_invoice', $doc), 0.001);
    }

    public function testValidationRejectsOverallocationWrongSidePostingLinesAndForeignTenant(): void
    {
        $partner = $this->client('Syntetický partner validace');
        $doc = $this->document('purchase_invoice', 'ROZ-VAL', $partner, 1000.0, '321');
        $small = $this->document('purchase_invoice', 'ROZ-VAL2', $partner, 100.0, '321');
        $entry = $this->manual('321', 'debit', 500.0, self::YEAR . '-07-01', 'Zápočet ROZ-VAL');
        $line = $this->lineId($entry, '321');
        $alloc = fn (array $rows) => $this->settlements->allocate($this->supplierId, $rows, $this->userId);

        $this->assertSettlementError('line_overallocated', fn () => $alloc([['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 500.01]]));
        $this->assertSettlementError('document_overallocated', fn () => $alloc([['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $small, 'amount' => 150.0]]));
        $this->assertSettlementError('line_side', fn () => $alloc([['line_id' => $line, 'doc_type' => 'invoice', 'doc_id' => $this->document('invoice', 'ROZ-VAL3', $partner, 100.0, '311'), 'amount' => 50.0]]));
        $predpis = $this->lineId($this->predpisEntry('purchase_invoice', $doc), '321');
        $this->assertSettlementError('line_source', fn () => $alloc([['line_id' => $predpis, 'doc_type' => 'purchase_invoice', 'doc_id' => $small, 'amount' => 10.0]]));
        $this->assertSettlementError('invalid_amount', fn () => $alloc([['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 0.001]]));

        $other = $this->otherSupplierId();
        $this->assertSettlementError('not_found', fn () => $this->settlements->allocate($other, [
            ['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 10.0],
        ], $this->userId));
        $foreignDoc = (int) $this->db->pdo()->query("SELECT id FROM purchase_invoices WHERE supplier_id <> {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn();
        if ($foreignDoc > 0) {
            $this->assertSettlementError('not_found', fn () => $alloc([['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $foreignDoc, 'amount' => 10.0]]));
        }

        // Dva rozpady téhož řádku v jednom požadavku se sčítají do volné částky.
        $this->assertSettlementError('line_overallocated', fn () => $alloc([
            ['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 450.0],
            ['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $small, 'amount' => 100.0],
        ]));
        self::assertSame(0, $this->allocationCount(), 'Odmítnutý požadavek nic nezapíše.');
    }

    public function testCandidatesAndSuggestionsFindCertainMatch(): void
    {
        $partner = $this->client('Syntetický partner návrhu');
        $doc = $this->document('purchase_invoice', 'ROZSUG77', $partner, 4321.0, '321');
        $noise = $this->manual('321', 'debit', 4321.0, self::YEAR . '-03-01', 'Jiný zápis bez čísla');
        $entry = $this->manual('321', 'debit', 4321.0, self::YEAR . '-07-01', 'zápočet PH990001 x ROZSUG77');
        $line = $this->lineId($entry, '321');

        $candidates = $this->settlements->candidates($this->supplierId, 'purchase_invoice', $doc, '321', self::YEAR . '-12-31');
        self::assertEqualsWithDelta(4321.0, $candidates['document']['remaining'], 0.001);
        self::assertSame($line, $candidates['items'][0]['line_id'], 'Číslo dokladu v textu řadí zápis před stejnou částku bez čísla.');
        self::assertTrue($candidates['items'][0]['amount_match']);
        self::assertTrue($candidates['items'][0]['doc_match']);
        self::assertContains($this->lineId($noise, '321'), array_column($candidates['items'], 'line_id'));

        $suggestions = $this->settlements->suggestions($this->supplierId, $this->periodId, self::YEAR . '-12-31', '321');
        $mine = array_values(array_filter($suggestions['items'], static fn (array $s): bool => $s['allocations'][0]['doc_id'] === $doc));
        self::assertCount(1, $mine);
        self::assertSame('doc_no_amount', $mine[0]['reason']);
        self::assertSame($line, $mine[0]['line']['line_id']);

        $this->settlements->allocate($this->supplierId, [
            ['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => $mine[0]['allocations'][0]['amount']],
        ], $this->userId);
        $again = $this->settlements->suggestions($this->supplierId, $this->periodId, self::YEAR . '-12-31', '321');
        self::assertSame([], array_values(array_filter($again['items'], static fn (array $s): bool => $s['allocations'][0]['doc_id'] === $doc)));
        self::assertNull($this->remaining($this->block('321', self::YEAR . '-12-31'), 'purchase_invoice', $doc));
    }

    public function testSuggestionSplitsOneEntryIntoNamedDocumentsOfPartner(): void
    {
        $partner = $this->client('Syntetický partner součtu');
        $a = $this->document('invoice', 'ROZSUM01', $partner, 700.0, '311');
        $b = $this->document('invoice', 'ROZSUM02', $partner, 300.0, '311');
        $entry = $this->manual('311', 'credit', 1000.0, self::YEAR . '-07-01', 'odpis ROZSUM01 a ROZSUM02');

        $suggestions = $this->settlements->suggestions($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311');
        $mine = array_values(array_filter($suggestions['items'], static fn (array $s): bool => $s['line']['entry_id'] === $entry));
        self::assertCount(1, $mine);
        self::assertSame('doc_no_sum', $mine[0]['reason']);
        self::assertEqualsCanonicalizing([$a, $b], array_column($mine[0]['allocations'], 'doc_id'));
    }

    public function testOtherItemFromResidualCoversDifferenceWithoutPosting(): void
    {
        $entry = $this->manual('311', 'debit', 700.0, self::YEAR . '-07-01', 'Záloha bez dokladu');
        $asOf = self::YEAR . '-12-31';
        $before = $this->block('311', $asOf);
        $entries = $this->journalCount();

        $residual = $this->settlements->residualLines($this->supplierId, '311', $asOf, 'Záloha bez dokladu');
        self::assertContains($this->lineId($entry, '311'), array_column($residual, 'line_id'));

        $result = $this->settlements->createOtherItem($this->supplierId, [
            'line_id' => $this->lineId($entry, '311'), 'amount' => 700.0, 'title' => 'Syntetická záloha',
        ], $this->userId);
        self::assertSame('confirmed', $result['item']['status']);
        self::assertSame('receivable', $result['item']['side']);
        self::assertNull($result['item']['journal_entry_id']);
        self::assertSame($entries, $this->journalCount(), 'Položka ze zůstatku se znovu neúčtuje.');

        $after = $this->block('311', $asOf);
        self::assertEqualsWithDelta($before['gl_balance'], $after['gl_balance'], 0.001);
        self::assertEqualsWithDelta(700.0, $after['open_items_total'] - $before['open_items_total'], 0.001);
        self::assertEqualsWithDelta(-700.0, $after['difference'] - $before['difference'], 0.001);
        self::assertEqualsWithDelta(700.0, $this->remaining($after, 'other_item', (int) $result['item']['id']), 0.001);
        self::assertSame([], $this->settlements->residualLines($this->supplierId, '311', $asOf, 'Záloha bez dokladu'), 'Celý řádek je použitý.');

        $this->assertSettlementError('origin_allocation', fn () => $this->settlements->delete($this->supplierId, $result['allocation_id']));

        $this->container->get(OtherItemService::class)->reverse($this->supplierId, (int) $result['item']['id'], 'Omyl při založení', $this->userId, self::YEAR . '-12-31');
        $cancelled = $this->block('311', $asOf);
        self::assertNull($this->remaining($cancelled, 'other_item', (int) $result['item']['id']));
        self::assertEqualsWithDelta($before['difference'], $cancelled['difference'], 0.001);
        self::assertSame($entries, $this->journalCount(), 'Storno položky ze zůstatku nevytváří protizápis.');
    }

    public function testActionGuardsClosedPeriodAndDeletesAllocation(): void
    {
        $partner = $this->client('Syntetický partner akce');
        $doc = $this->document('purchase_invoice', 'ROZ-ACT', $partner, 250.0, '321');
        $entry = $this->manual('321', 'debit', 250.0, self::YEAR . '-07-01', 'Zápočet ROZ-ACT');
        $body = ['allocations' => [['line_id' => $this->lineId($entry, '321'), 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 250.0]]];
        $action = $this->container->get(JournalSettlementAction::class);

        $readonly = $this->callAction($action, 'create', 'POST', 'readonly', $body);
        self::assertSame(403, $readonly['status']);

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ? AND supplier_id = ?")
            ->execute([$this->periodId, $this->supplierId]);
        $closed = $this->callAction($action, 'create', 'POST', 'accountant', $body);
        self::assertSame(409, $closed['status']);
        self::assertSame('period_closed', $closed['body']['error']['code'] ?? null);

        $forced = $this->forcedCreate($action, $body);
        self::assertSame(201, $forced['status'], json_encode($forced['body']));
        $allocationId = (int) $forced['body']['created'][0]['id'];
        self::assertNull($this->remaining($this->block('321', self::YEAR . '-12-31'), 'purchase_invoice', $doc));
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'accounting.saldo_allocation_created' AND supplier_id = {$this->supplierId}"
        )->fetchColumn());

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ? AND supplier_id = ?")
            ->execute([$this->periodId, $this->supplierId]);
        $deleted = $this->callAction($action, 'delete', 'DELETE', 'accountant', [], ['id' => (string) $allocationId]);
        self::assertSame(200, $deleted['status']);
        self::assertEqualsWithDelta(250.0, $this->remaining($this->block('321', self::YEAR . '-12-31'), 'purchase_invoice', $doc), 0.001);
    }


    public function testRepostOrDeleteCannotMoveOrShrinkAllocatedLine(): void
    {
        $partner = $this->client('Syntetický partner přeúčtování');
        $doc = $this->document('purchase_invoice', 'ROZ-REP', $partner, 1000.0, '321');
        $entryId = $this->manual('321', 'debit', 1000.0, self::YEAR . '-07-01', 'Zápočet ROZ-REP');
        $this->settlements->allocate($this->supplierId, [
            ['line_id' => $this->lineId($entryId, '321'), 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 600.0],
        ], $this->userId);
        $entry = $this->journal->find($entryId, $this->supplierId);
        $header = array_intersect_key($entry, array_flip(['supplier_id', 'period_id', 'entry_date', 'document_date', 'document_no', 'description', 'source_type', 'source_id', 'posted_at', 'posted_by']));
        $lines = array_map(static fn (array $l): array => [
            'account_id' => (int) $l['account_id'], 'side' => $l['side'], 'amount' => (float) $l['amount'], 'line_no' => (int) $l['line_no'],
        ], $entry['lines']);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $variants = [
            'menší částka' => array_map(static fn (array $l): array => [...$l, 'amount' => 500.0], $lines),
            'prohozené strany' => array_map(static fn (array $l): array => [...$l, 'side' => $l['side'] === 'debit' ? 'credit' : 'debit'], $lines),
            'jiný účet na pořadí' => array_map(static fn (array $l): array => $l['account_id'] === (int) $map['321']['id']
                ? [...$l, 'account_id' => (int) $map['325']['id']] : $l, $lines),
            'přečíslované řádky' => array_map(static fn (array $l): array => [...$l, 'line_no' => $l['line_no'] + 10], $lines),
        ];
        foreach ($variants as $label => $changed) {
            try {
                $this->journal->replace($entryId, $header, $changed);
                self::fail('Přeúčtování prošlo: ' . $label);
            } catch (PostingException $e) {
                self::assertSame('allocation_saldo_in_use', $e->errorCode, $label);
            }
        }
        // Beze změny řádku s rozpadem projde (např. jiný popis).
        $this->journal->replace($entryId, [...$header, 'description' => 'Zápočet ROZ-REP opravený popis'], $lines);

        $line321 = array_values(array_filter($this->journal->find($entryId, $this->supplierId)['lines'], static fn (array $l): bool => (int) $l['account_id'] === (int) $map['321']['id']))[0];
        try {
            $this->journal->replaceSomeLines($entryId, $this->supplierId, (int) $this->journal->find($entryId, $this->supplierId)['row_version'], [(int) $line321['id']], []);
            self::fail('Odebrání rozepsaného řádku prošlo.');
        } catch (PostingException $e) {
            self::assertSame('allocation_saldo_in_use', $e->errorCode);
        }
        try {
            (new JournalSettlementUseGuard($this->db))->assertEntriesFree($this->supplierId, [$entryId]);
            self::fail('Smazání zápisu s rozpadem prošlo.');
        } catch (PostingException $e) {
            self::assertSame('allocation_saldo_in_use', $e->errorCode);
        }
        self::assertEqualsWithDelta(400.0, $this->remaining($this->block('321', self::YEAR . '-12-31'), 'purchase_invoice', $doc), 0.001);
    }

    public function testDuplicateLineNumberDoesNotCountAllocationTwice(): void
    {
        $partner = $this->client('Syntetický partner pořadí');
        $doc = $this->document('purchase_invoice', 'ROZ-DUPNO', $partner, 1000.0, '321');
        $entryId = $this->manual('321', 'debit', 1000.0, self::YEAR . '-07-01', 'Zápočet ROZ-DUPNO');
        $line = $this->lineId($entryId, '321');
        $this->settlements->allocate($this->supplierId, [
            ['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 600.0],
        ], $this->userId);
        // Převod může vložit dva řádky se stejným pořadím (jedinečnost DB nevynucuje).
        $this->db->pdo()->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             SELECT entry_id, supplier_id, account_id, side, amount, line_no FROM journal_entry_lines WHERE id = ?'
        )->execute([$line]);

        self::assertEqualsWithDelta(400.0, $this->remaining($this->block('321', self::YEAR . '-12-31'), 'purchase_invoice', $doc), 0.001);
        $this->assertSettlementError('line_ambiguous', fn () => $this->settlements->allocate($this->supplierId, [
            ['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 100.0],
        ], $this->userId));
    }

    public function testEntryWithImportedSettlementCannotBeAllocated(): void
    {
        $partner = $this->client('Syntetický partner převzatého zápočtu');
        $doc = $this->document('purchase_invoice', 'ROZ-IMP', $partner, 1000.0, '321');
        $entryId = $this->manual('321', 'debit', 1000.0, self::YEAR . '-07-01', 'Interní doklad ROZ-IMP');
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id, status, journal_entry_id)
             VALUES (?, 'purchase_invoice', ?, ?, 1000, ?, 'confirmed', ?)"
        )->execute([$this->supplierId, $doc, self::YEAR . '-07-01', (int) $map['395']['id'], $entryId]);

        $this->assertSettlementError('entry_settled', fn () => $this->settlements->allocate($this->supplierId, [
            ['line_id' => $this->lineId($entryId, '321'), 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 1000.0],
        ], $this->userId));
        $other = $this->document('purchase_invoice', 'ROZ-IMP2', $partner, 1000.0, '321');
        $candidates = $this->settlements->candidates($this->supplierId, 'purchase_invoice', $other, '321', self::YEAR . '-12-31');
        self::assertNotContains($this->lineId($entryId, '321'), array_column($candidates['items'], 'line_id'));
    }

    public function testOrphanAllocationNeitherAbsorbsNewOneNorDisablesLink(): void
    {
        $partner = $this->client('Syntetický partner osiřelého rozpadu');
        $doc = $this->document('purchase_invoice', 'ROZ-ORF', $partner, 1000.0, '321');
        $entryId = $this->manual('321', 'debit', 1000.0, self::YEAR . '-07-01', 'Zápočet ROZ-ORF');
        $line = $this->lineId($entryId, '321');
        $this->settlements->allocate($this->supplierId, [
            ['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 300.0],
        ], $this->userId);
        // Rozpad, kterému řádek přestal odpovídat (účet mimo hlídané cesty změněný).
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $this->db->pdo()->prepare('UPDATE journal_settlement_allocations SET account_id = ? WHERE supplier_id = ? AND entry_id = ?')
            ->execute([(int) $map['325']['id'], $this->supplierId, $entryId]);

        $this->settlements->allocate($this->supplierId, [
            ['line_id' => $line, 'doc_type' => 'purchase_invoice', 'doc_id' => $doc, 'amount' => 300.0],
        ], $this->userId);
        self::assertEqualsWithDelta(700.0, $this->remaining($this->block('321', self::YEAR . '-12-31'), 'purchase_invoice', $doc), 0.001,
            'Nový rozpad se nepřičte k osiřelému.');

        // Osiřelý rozpad nevypíná starou vazbu zápisu na jediný doklad.
        $linked = $this->document('purchase_invoice', 'ROZ-ORF2', $partner, 500.0, '321');
        $entry2 = $this->manual('321', 'debit', 500.0, self::YEAR . '-07-02', 'Vyrovnání ROZ-ORF2');
        $this->db->pdo()->prepare(
            "INSERT INTO journal_settlement_allocations (supplier_id, entry_id, line_no, account_id, side, kind, doc_type, doc_id, amount)
             VALUES (?, ?, 0, ?, 'debit', 'settlement', 'purchase_invoice', ?, 100)"
        )->execute([$this->supplierId, $entry2, (int) $map['325']['id'], $linked]);
        $this->container->get(JournalEntryDocumentLinkRepository::class)->add($entry2, $this->supplierId, 'purchase_invoice', $linked, null, $this->userId);
        self::assertNull($this->remaining($this->block('321', self::YEAR . '-12-31'), 'purchase_invoice', $linked));
    }

    public function testResidualItemCountsAmountSettledOutside(): void
    {
        $entry = $this->manual('311', 'debit', 700.0, self::YEAR . '-04-01', 'Převzatý zůstatek');
        $id = (int) $this->settlements->createOtherItem($this->supplierId, [
            'line_id' => $this->lineId($entry, '311'), 'amount' => 700.0, 'title' => 'Syntetický převzatý zůstatek',
        ], $this->userId)['item']['id'];
        $outside = $this->db->pdo()->prepare('UPDATE other_items SET settled_outside_amount = ? WHERE id = ? AND supplier_id = ?');
        $asOf = self::YEAR . '-12-31';

        $outside->execute([300, $id, $this->supplierId]);
        self::assertEqualsWithDelta(400.0, $this->remaining($this->block('311', $asOf), 'other_item', $id), 0.001);

        $outside->execute([700, $id, $this->supplierId]);
        self::assertNull($this->remaining($this->block('311', $asOf), 'other_item', $id));
    }

    public function testCancelledResidualItemDisappearsOnlyFromCancellationDayInOpenPeriod(): void
    {
        $entry = $this->manual('311', 'debit', 400.0, self::YEAR . '-03-01', 'Přeplatek bez dokladu');
        $result = $this->settlements->createOtherItem($this->supplierId, [
            'line_id' => $this->lineId($entry, '311'), 'amount' => 400.0, 'title' => 'Syntetický přeplatek',
        ], $this->userId);
        $id = (int) $result['item']['id'];
        $items = $this->container->get(OtherItemService::class);

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ? AND supplier_id = ?")
            ->execute([$this->periodId, $this->supplierId]);
        try {
            $items->reverse($this->supplierId, $id, 'Omyl při založení', $this->userId, self::YEAR . '-09-01');
            self::fail('Storno v uzavřeném období prošlo.');
        } catch (OtherItemException $e) {
            self::assertSame('period_closed', $e->errorCode);
        }
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ? AND supplier_id = ?")
            ->execute([$this->periodId, $this->supplierId]);

        $items->reverse($this->supplierId, $id, 'Omyl při založení', $this->userId, self::YEAR . '-09-01');
        self::assertEqualsWithDelta(400.0, $this->remaining($this->block('311', self::YEAR . '-08-31'), 'other_item', $id), 0.001,
            'K datu před stornem položka v saldokontu zůstává.');
        self::assertNull($this->remaining($this->block('311', self::YEAR . '-09-30'), 'other_item', $id));
    }

    public function testResidualItemsRespectLimitAndDueOrder(): void
    {
        $ids = [];
        // Partner podle abecedy první má pozdější splatnost: řazení podle splatnosti a podle
        // partnera dají různé pořadí.
        foreach ([['Syntetický Alfa limitu', '2099-10-01'], ['Syntetický Zeta limitu', '2099-08-01']] as $i => [$name, $due]) {
            $entry = $this->manual('311', 'debit', 100.0 + $i, self::YEAR . '-03-0' . ($i + 1), 'Záloha ' . $i);
            $ids[] = (int) $this->settlements->createOtherItem($this->supplierId, [
                'line_id' => $this->lineId($entry, '311'), 'amount' => 100.0 + $i, 'partner_id' => $this->client($name), 'due_on' => $due,
            ], $this->userId)['item']['id'];
        }
        $saldo = $this->container->get(\MyInvoice\Repository\SaldoRepository::class);
        $account = $saldo->resolveAccount($this->supplierId, '311');
        $method = new \ReflectionMethod($saldo, 'fetchOpenOtherItems');
        $rows = $method->invoke($saldo, $this->supplierId, (int) $account['id'], self::YEAR . '-12-31', 1, null, null, true);
        self::assertCount(1, $rows);
        self::assertSame($ids[1], $rows[0]['doc_id'], 'Řazení podle splatnosti vrátí dřívější splatnost.');
    }

    public function testSuggestionIgnoresShortDocumentNumbers(): void
    {
        $partner = $this->client('Syntetický partner krátkého čísla');
        $doc = $this->document('purchase_invoice', '7711', $partner, 2468.0, '321');
        $this->manual('321', 'debit', 2468.0, self::YEAR . '-07-01', 'úhrada 7711 bez dalšího');
        $suggestions = $this->settlements->suggestions($this->supplierId, $this->periodId, self::YEAR . '-12-31', '321');
        self::assertSame([], array_values(array_filter($suggestions['items'], static fn (array $s): bool => $s['allocations'][0]['doc_id'] === $doc)));
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    private function document(string $type, string $number, int $partner, float $amount, string $account): int
    {
        $id = $type === 'invoice' ? $this->saleInvoice($number, $partner, $amount) : $this->purchaseInvoice($number, $partner, $amount);
        if ($type === 'purchase_invoice') {
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ?')->execute([$number, $id]);
        }
        $type === 'invoice'
            ? $this->postPredpis('invoice', $id, $account, '602', $amount)
            : $this->postPredpis('purchase_invoice', $id, '518', $account, $amount);
        return $id;
    }

    private function predpisEntry(string $type, int $docId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = ? AND source_id = ?');
        $stmt->execute([$this->supplierId, $type, $docId]);
        return (int) $stmt->fetchColumn();
    }

    private function manual(string $account, string $side, float $amount, string $date, string $description): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $account, 'side' => $side, 'amount' => $amount],
            ['account_code' => '395', 'side' => $side === 'debit' ? 'credit' : 'debit', 'amount' => $amount],
        ], ['entry_date' => $date, 'description' => $description, 'posted_by' => $this->userId]);
    }

    private function lineId(int $entryId, string $accountCode): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND ca.account_code = ? ORDER BY l.line_no LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $entryId, $accountCode]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id);
        return $id;
    }

    /** @return array<string,mixed> */
    private function block(string $account, string $asOf): array
    {
        $report = $this->saldo->build($this->supplierId, $this->periodId, $asOf, $account);
        self::assertCount(1, $report['accounts']);
        return $report['accounts'][0];
    }

    /** @param array<string,mixed> $block */
    private function remaining(array $block, string $type, int $docId): ?float
    {
        foreach ($block['partners'] as $partner) {
            foreach ($partner['items'] as $item) {
                if ($item['doc_type'] === $type && $item['doc_id'] === $docId) {
                    return (float) $item['remaining_czk'];
                }
            }
        }
        return null;
    }

    /** @return list<int> */
    private function closingIssues(string $type, string $asOf): array
    {
        $closing = $this->container->get(ClosingRepository::class);
        $rows = $type === 'invoice'
            ? $closing->paidInvoicesOpenSaldo($this->supplierId, $asOf)
            : $closing->paidPurchasesOpenSaldo($this->supplierId, $asOf);
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    private function allocationCount(): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM journal_settlement_allocations WHERE supplier_id = {$this->supplierId}")->fetchColumn();
    }

    private function journalCount(): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}")->fetchColumn();
    }

    private function assertSettlementError(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('Očekávaná chyba ' . $code);
        } catch (JournalSettlementException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    /** @param array<string,mixed> $body */
    private function forcedCreate(JournalSettlementAction $action, array $body): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounting/saldo-settlements')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withQueryParams(['force' => '1'])
            ->withParsedBody($body);
        $resp = $action->create($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
