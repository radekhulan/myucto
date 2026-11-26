<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemOffsetService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\Pair\JournalPairCorrectionService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Storno a přeúčtování dvojice opravným zápisem nesmí zrušit řádek, který je rozepsaný
 * na doklad saldokonta (`journal_settlement_allocations`): zápočet ostatní položky ani
 * rozpad na fakturu. Rozpad by vyrovnání dál počítal, ač hlavní kniha řádek zrušila.
 */
#[Group('integration')]
final class PairSettlementGuardTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private JournalPairCorrectionService $corrections;
    private OtherItemService $items;
    private OtherItemOffsetService $offsets;
    private AccountingPeriodRepository $periods;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
        $this->corrections = $container->get(JournalPairCorrectionService::class);
        $this->items = $container->get(OtherItemService::class);
        $this->offsets = $container->get(OtherItemOffsetService::class);
        $this->periods = $container->get(AccountingPeriodRepository::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testPairWithOtherItemOffsetCannotBeReversedOrRepostedByCorrectionEntry(): void
    {
        $itemId = $this->receivable((self::YEAR - 1) . '-02-01');
        $entryId = $this->pairedEntry((self::YEAR - 1) . '-03-10');
        $line = $this->offsets->lineCandidates($this->supplierId, $itemId)[0];
        self::assertSame($entryId, $line['entry_id']);
        $this->offsets->attach($this->supplierId, $itemId, ['line_id' => $line['line_id'], 'amount' => 300], null);

        $this->assertInUse(fn () => $this->corrections->reversePairs($this->supplierId, $entryId, [1], []), true);
        $this->assertInUse(fn () => $this->posting->reversePairs($this->supplierId, $entryId, [1], []), true);
        // Přeúčtování na místě (otevřené období) by řádek odebralo: hlídá ho JournalEntryRepository.
        $this->assertInUse(fn () => $this->corrections->repostPair($this->supplierId, $entryId, 1,
            ['debit_account_code' => '379', 'credit_account_code' => '315'], []), true);

        // V uzavřeném období jde přeúčtování opravným zápisem, i ten musí rozpad odmítnout.
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ? AND fiscal_year = ?")
            ->execute([$this->supplierId, self::YEAR - 1]);
        $this->assertInUse(fn () => $this->corrections->repostPair($this->supplierId, $entryId, 1,
            ['debit_account_code' => '379', 'credit_account_code' => '315'], ['entry_date' => self::YEAR . '-01-05']), true);

        // Dvojice bez rozpadu se dál opravuje normálně.
        $result = $this->corrections->repostPair($this->supplierId, $entryId, 3,
            ['debit_account_code' => '549', 'credit_account_code' => '221'], ['entry_date' => self::YEAR . '-01-05']);
        self::assertSame('correction_entry', $result['mode']);
        self::assertSame(70000, (int) round((float) $this->items->get($this->supplierId, $itemId)['remaining_amount'] * 100));
    }

    public function testPairWithInvoiceSettlementCannotBeReversed(): void
    {
        $entryId = $this->pairedEntry(self::YEAR . '-03-10');
        $line = $this->db->pdo()->prepare(
            "SELECT l.line_no, l.account_id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND a.account_code = '321'"
        );
        $line->execute([$entryId]);
        $row = $line->fetch(\PDO::FETCH_ASSOC);
        $this->db->pdo()->prepare(
            "INSERT INTO journal_settlement_allocations (supplier_id, entry_id, line_no, account_id, side, kind, doc_type, doc_id, amount)
             VALUES (?, ?, ?, ?, 'credit', 'settlement', 'purchase_invoice', 1, 100)"
        )->execute([$this->supplierId, $entryId, $row['line_no'], $row['account_id']]);

        $this->assertInUse(fn () => $this->corrections->reversePairs($this->supplierId, $entryId, [2], []), false);
        $this->assertInUse(fn () => $this->posting->reversePairs($this->supplierId, $entryId, [2], []), false);
        $reversal = $this->corrections->reversePairs($this->supplierId, $entryId, [3], []);
        self::assertGreaterThan(0, $reversal);
    }

    public function testWholeReversalCascadeNamesCorrectionEntryWithAllocation(): void
    {
        // Dvojice přeúčtovaná opravným zápisem: storno celého zápisu stornuje i náhradní
        // dvojici v opravném zápisu. Je-li ta rozepsaná na zápočet, hláška řekne, kde.
        $itemId = $this->receivable((self::YEAR - 1) . '-02-01');
        $entryId = $this->pairedEntry((self::YEAR - 1) . '-03-10');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ? AND fiscal_year = ?")
            ->execute([$this->supplierId, self::YEAR - 1]);
        $result = $this->corrections->repostPair($this->supplierId, $entryId, 1,
            ['debit_account_code' => '379', 'credit_account_code' => '315'], ['entry_date' => self::YEAR . '-01-05']);
        self::assertSame('correction_entry', $result['mode']);
        $correctionId = $result['entry_id'];
        $line = $this->db->pdo()->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE l.entry_id = ? AND a.account_code = '315' AND l.side = 'credit'");
        $line->execute([$correctionId]);
        $this->offsets->attach($this->supplierId, $itemId, ['line_id' => (int) $line->fetchColumn(), 'amount' => 300], null);

        $this->db->pdo()->exec('SAVEPOINT cascade_guard');
        try {
            $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => self::YEAR . '-02-01']);
            self::fail('Storno celého zápisu nesmí zrušit rozepsanou náhradní dvojici.');
        } catch (PostingException $e) {
            self::assertSame('allocation_saldo_in_use', $e->errorCode);
            self::assertStringContainsString('opravném zápisu #' . $correctionId, $e->getMessage());
            self::assertStringContainsString('v detailu položky', $e->getMessage());
            self::assertSame($correctionId, $e->context['correction_entry_id']);
        } finally {
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT cascade_guard');
        }
        self::assertSame(70000, (int) round((float) $this->items->get($this->supplierId, $itemId)['remaining_amount'] * 100));
    }

    public function testWholeEntryWithOffsetStillReversesAndReopensItem(): void
    {
        $itemId = $this->receivable(self::YEAR . '-02-01');
        $entryId = $this->pairedEntry(self::YEAR . '-03-10');
        $line = $this->offsets->lineCandidates($this->supplierId, $itemId)[0];
        $this->offsets->attach($this->supplierId, $itemId, ['line_id' => $line['line_id'], 'amount' => 300], null);

        // Výběr všech dvojic je storno celého zápisu: hlásí se to dřív než rozpad.
        $this->db->pdo()->exec('SAVEPOINT whole_pairs');
        try {
            $this->corrections->reversePairs($this->supplierId, $entryId, [1, 2, 3], []);
            self::fail('Všechny dvojice se stornují jako celý zápis.');
        } catch (\MyInvoice\Service\Accounting\Pair\JournalPairException $e) {
            self::assertSame('pair_reversal_whole_entry', $e->errorCode);
        } finally {
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT whole_pairs');
        }

        $reversalId = $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => self::YEAR . '-04-01']);
        self::assertGreaterThan(0, $reversalId);
        self::assertSame(self::YEAR . '-04-01', $this->items->allocations($this->supplierId, $itemId)[0]['reversed_on']);
        self::assertSame(100000, (int) round((float) $this->items->get($this->supplierId, $itemId)['remaining_amount'] * 100));
    }

    private function receivable(string $date): int
    {
        $draft = $this->items->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'insurance', 'title' => 'Syntetické pojistné plnění',
            'issued_on' => $date, 'accounting_on' => $date, 'due_on' => $date, 'currency' => 'CZK', 'amount' => 1000,
            'account_code' => '315', 'counter_account_code' => '648',
        ], null);
        $this->items->post($this->supplierId, (int) $draft['id'], null);
        return (int) $draft['id'];
    }

    /** Ruční zápis o třech dvojicích: 395/315 300 (zápočet), 518/321 100, 568/221 15. */
    private function pairedEntry(string $date): int
    {
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '395', 'side' => 'debit', 'amount' => 300.00],
            ['account_code' => '315', 'side' => 'credit', 'amount' => 300.00],
            ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
            ['account_code' => '568', 'side' => 'debit', 'amount' => 15.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 15.00],
        ], ['entry_date' => $date, 'description' => 'Synteticky zapocet a dvojice', 'posted' => true]);
        $this->db->pdo()->prepare('UPDATE journal_entry_lines SET pair_no = FLOOR(line_no / 2) + 1 WHERE supplier_id = ? AND entry_id = ?')
            ->execute([$this->supplierId, $entryId]);
        return $entryId;
    }

    private function assertInUse(\Closure $call, bool $otherItem): void
    {
        $this->db->pdo()->exec('SAVEPOINT settlement_guard');
        try {
            $call();
            self::fail('Rozepsaný řádek se nesmí stornovat ani přeúčtovat opravným zápisem.');
        } catch (PostingException $e) {
            self::assertSame('allocation_saldo_in_use', $e->errorCode, $e->getMessage());
            self::assertSame($otherItem, str_contains($e->getMessage(), 'v detailu položky'), $e->getMessage());
        } finally {
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT settlement_guard');
        }
    }
}
