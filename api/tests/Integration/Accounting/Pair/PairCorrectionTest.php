<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Pair\JournalPairCorrectionService;
use MyInvoice\Service\Accounting\Pair\JournalPairException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Storno a přeúčtování vybraných dvojic (kritérium f, kap. 4.2 návrhu).
 *
 * Zápis s dvojicemi se tu připraví zaúčtováním a doplněním čísel dvojic přímo
 * v řádcích, protože zapisovač čísla dvojic ještě neukládá. Testy ve skupině
 * `pairs-e2e` ověřují čísla dvojic ve výsledných zápisech a projdou až se
 * zapisovačem, který `pair_no` ukládá.
 */
#[Group('integration')]
final class PairCorrectionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private JournalEntryRepository $journal;
    private PostingService $posting;
    private JournalPairCorrectionService $corrections;
    private AccountingPeriodRepository $periods;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $this->posting = $container->get(PostingService::class);
        $this->corrections = $container->get(JournalPairCorrectionService::class);
        $this->periods = $container->get(AccountingPeriodRepository::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
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

    public function testReverseSelectedPairOfManualEntry(): void
    {
        $entryId = $this->pairedManualEntry(self::YEAR . '-03-10');

        $reversalId = $this->corrections->reversePairs($this->supplierId, $entryId, [2], ['user_id' => $this->userId]);

        $reversal = $this->lines($reversalId);
        self::assertSame([['221', 'debit', '15.00'], ['568', 'credit', '15.00']], self::shape($reversal));
        self::assertSame(self::YEAR . '-03-10', $this->entryDate($reversalId));
        $original = $this->journal->findForUpdate($entryId, $this->supplierId);
        self::assertNull($original['reversed_by'], 'Originál jako celek zůstává nestornovaný.');
        self::assertSame(
            [['entry_id' => $entryId, 'pair_no' => 2, 'reversal_entry_id' => $reversalId, 'reversal_pair_no' => 1, 'replacement_pair_no' => null]],
            $this->reversals($entryId),
        );
        self::assertSame(1, $this->activityCount('accounting.pairs_reversed', $reversalId));
    }

    public function testReversedPairCannotBeReversedTwice(): void
    {
        $entryId = $this->pairedManualEntry(self::YEAR . '-03-10');
        $this->corrections->reversePairs($this->supplierId, $entryId, [2], []);

        $this->assertPairError('pair_already_reversed', 409, fn () => $this->corrections->reversePairs($this->supplierId, $entryId, [2], []));
        $this->assertPairError('pair_already_reversed', 409, fn () => $this->corrections->repostPair(
            $this->supplierId, $entryId, 2, ['debit_account_code' => '549', 'credit_account_code' => '221'], [],
        ));
    }

    public function testAllRemainingPairsMustBeReversedAsWholeEntry(): void
    {
        $entryId = $this->pairedManualEntry(self::YEAR . '-03-10');

        $this->assertPairError('pair_reversal_whole_entry', 409, fn () => $this->corrections->reversePairs($this->supplierId, $entryId, [1, 2, 3], []));
    }

    public function testDocumentEntryAllowsOnlyRepost(): void
    {
        $entryId = $this->pairedManualEntry(self::YEAR . '-03-10');
        $this->db->pdo()->prepare("UPDATE journal_entries SET source_type = 'other_item', source_id = 999999 WHERE id = ?")->execute([$entryId]);

        $this->assertPairError('pair_reversal_document_forbidden', 409, fn () => $this->corrections->reversePairs($this->supplierId, $entryId, [2], []));
    }

    public function testRejectsUnknownPairUnpairedEntryAndInvalidAccounts(): void
    {
        $entryId = $this->pairedManualEntry(self::YEAR . '-03-10');
        $this->assertPairError('pair_not_found', 404, fn () => $this->corrections->reversePairs($this->supplierId, $entryId, [7], []));
        $this->assertPairError('pair_same_account', 422, fn () => $this->corrections->repostPair(
            $this->supplierId, $entryId, 2, ['debit_account_code' => '221', 'credit_account_code' => '221'], [],
        ));
        $this->assertPairError('unknown_account', 422, fn () => $this->corrections->repostPair(
            $this->supplierId, $entryId, 2, ['debit_account_code' => '999.999', 'credit_account_code' => '221'], [],
        ));
        $this->assertPairError('pair_unchanged', 422, fn () => $this->corrections->repostPair(
            $this->supplierId, $entryId, 2, ['debit_account_code' => '568', 'credit_account_code' => '221'], [],
        ));

        $plain = $this->manualEntry(self::YEAR . '-03-11');
        $this->assertPairError('entry_not_paired', 409, fn () => $this->corrections->reversePairs($this->supplierId, $plain, [1], []));
    }

    public function testPayrollEntryIsRejected(): void
    {
        $entryId = $this->pairedManualEntry(self::YEAR . '-03-10');
        $this->db->pdo()->prepare("UPDATE journal_entries SET source_type = 'payroll' WHERE id = ?")->execute([$entryId]);

        $this->assertPairError('payroll_reversal_forbidden', 409, fn () => $this->corrections->reversePairs($this->supplierId, $entryId, [2], []));
        $this->assertPairError('payroll_reversal_forbidden', 409, fn () => $this->corrections->repostPair(
            $this->supplierId, $entryId, 2, ['debit_account_code' => '549', 'credit_account_code' => '221'], [],
        ));
    }

    /** Přeúčtování 568 → 549 v otevřeném nezamčeném období na místě, ostatní dvojice beze změny. */
    public function testRepostPairInPlaceInOpenPeriod(): void
    {
        $entryId = $this->pairedManualEntry(self::YEAR . '-03-10');

        $result = $this->corrections->repostPair(
            $this->supplierId, $entryId, 2, ['debit_account_code' => '549', 'credit_account_code' => '221'], ['user_id' => $this->userId],
        );

        self::assertSame(['entry_id' => $entryId, 'mode' => 'in_place', 'pair_no' => 2], $result);
        self::assertSame(
            [['518', 'debit', '100.00'], ['321', 'credit', '100.00'], ['549', 'debit', '15.00'], ['221', 'credit', '15.00'], ['501', 'debit', '50.00'], ['211', 'credit', '50.00']],
            self::shape($this->lines($entryId)),
        );
        self::assertSame([], $this->reversals($entryId));
    }

    /** Zamčené datum a daňově neutrální přesun ve třídě 5: na místě, bez opravného zápisu. */
    public function testRepostInLockedDateIsTaxNeutralInPlace(): void
    {
        $entryId = $this->pairedManualEntry(self::YEAR . '-03-10');
        $this->lockUntil(self::YEAR . '-03-31');

        $result = $this->corrections->repostPair(
            $this->supplierId, $entryId, 2, ['debit_account_code' => '518', 'credit_account_code' => '221'], [],
        );

        self::assertSame('tax_neutral', $result['mode']);
        self::assertSame($entryId, $result['entry_id']);
    }

    /** Zamčené datum a přesun na účet daně (568 → 343): opravný zápis = storno dvojice + nová dvojice. */
    public function testRepostInLockedDateWithTaxAccountUsesCorrectionEntry(): void
    {
        $entryId = $this->pairedManualEntry(self::YEAR . '-03-10');
        $this->lockUntil(self::YEAR . '-03-31');

        $result = $this->corrections->repostPair(
            $this->supplierId, $entryId, 2, ['debit_account_code' => '343.100', 'credit_account_code' => '221'],
            ['entry_date' => self::YEAR . '-04-02', 'user_id' => $this->userId],
        );

        self::assertSame('correction_entry', $result['mode']);
        self::assertNotSame($entryId, $result['entry_id']);
        self::assertSame(
            [['221', 'debit', '15.00'], ['568', 'credit', '15.00'], ['343', 'debit', '15.00'], ['221', 'credit', '15.00']],
            self::shape($this->lines($result['entry_id'])),
        );
        self::assertSame(self::YEAR . '-04-02', $this->entryDate($result['entry_id']));
        self::assertSame(
            [['entry_id' => $entryId, 'pair_no' => 2, 'reversal_entry_id' => $result['entry_id'], 'reversal_pair_no' => 1, 'replacement_pair_no' => 2]],
            $this->reversals($entryId),
        );
        self::assertCount(6, $this->lines($entryId), 'Originál v zamčeném datu se nemění.');
    }

    /** Uzavřené období: vždy opravným zápisem do otevřeného data. */
    public function testRepostInClosedPeriodUsesCorrectionEntry(): void
    {
        $this->periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $entryId = $this->pairedManualEntry((self::YEAR - 1) . '-11-10');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ? AND fiscal_year = ?")
            ->execute([$this->supplierId, self::YEAR - 1]);

        $result = $this->corrections->repostPair(
            $this->supplierId, $entryId, 2, ['debit_account_code' => '549', 'credit_account_code' => '221'],
            ['entry_date' => self::YEAR . '-01-05'],
        );

        self::assertSame('correction_entry', $result['mode']);
        self::assertSame(self::YEAR . '-01-05', $this->entryDate($result['entry_id']));

        $this->assertPairError('period_not_open', 422, fn () => $this->corrections->reversePairs(
            $this->supplierId, $entryId, [3], ['entry_date' => (self::YEAR - 1) . '-12-01'],
        ));
    }

    /** Čísla dvojic ve výsledných zápisech: zapisovač je musí ukládat (druhá část jádra). */
    #[Group('pairs-e2e')]
    public function testCorrectionEntriesCarryPairNumbers(): void
    {
        $entryId = $this->pairedManualEntry(self::YEAR . '-03-10');
        $reversalId = $this->corrections->reversePairs($this->supplierId, $entryId, [1, 3], []);
        self::assertSame([1, 1, 2, 2], array_column($this->lines($reversalId), 'pair_no'));

        $result = $this->corrections->repostPair(
            $this->supplierId, $entryId, 2, ['debit_account_code' => '549', 'credit_account_code' => '221'], [],
        );
        self::assertSame('in_place', $result['mode']);
        self::assertSame([1, 1, 2, 2, 3, 3], array_column($this->lines($entryId), 'pair_no'));
        $history = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entry_lines FOR SYSTEM_TIME ALL WHERE entry_id = ? AND pair_no = 2'
        );
        $history->execute([$entryId]);
        self::assertGreaterThanOrEqual(4, (int) $history->fetchColumn(), 'Původní dvojice zůstává v historii zápisu.');
    }

    /** Storno celého zápisu po stornu dvojice zrcadlí jen zbývající dvojice (PostingService::reverseOnce). */
    #[Group('pairs-e2e')]
    public function testWholeReversalAfterPairReversalMirrorsRemainingPairs(): void
    {
        $entryId = $this->pairedManualEntry(self::YEAR . '-03-10');
        $this->corrections->reversePairs($this->supplierId, $entryId, [2], []);

        $reversalId = $this->posting->reverse($this->supplierId, $entryId, []);

        self::assertSame(
            [['518', 'credit', '100.00'], ['321', 'debit', '100.00'], ['501', 'credit', '50.00'], ['211', 'debit', '50.00']],
            self::shape($this->lines($reversalId)),
        );
    }

    private function manualEntry(string $date): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
            ['account_code' => '568', 'side' => 'debit', 'amount' => 15.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 15.00],
            ['account_code' => '501', 'side' => 'debit', 'amount' => 50.00],
            ['account_code' => '211', 'side' => 'credit', 'amount' => 50.00],
        ], ['entry_date' => $date, 'posted_by' => $this->userId, 'description' => 'Dvojice']);
    }

    /** Ruční zápis o třech dvojicích: 518/321 100, 568/221 15, 501/211 50. */
    private function pairedManualEntry(string $date): int
    {
        $entryId = $this->manualEntry($date);
        $this->db->pdo()->prepare('UPDATE journal_entry_lines SET pair_no = FLOOR(line_no / 2) + 1 WHERE supplier_id = ? AND entry_id = ?')
            ->execute([$this->supplierId, $entryId]);
        return $entryId;
    }

    /** @return list<array<string,mixed>> */
    private function lines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount, l.pair_no
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$this->supplierId, $entryId]);
        return array_map(static fn (array $r): array => [
            'account_code' => (string) $r['account_code'],
            'side' => (string) $r['side'],
            'amount' => (string) $r['amount'],
            'pair_no' => $r['pair_no'] === null ? null : (int) $r['pair_no'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @param list<array<string,mixed>> $lines @return list<array{string,string,string}> */
    private static function shape(array $lines): array
    {
        return array_map(static fn (array $l): array => [
            preg_replace('/\..*$/', '', $l['account_code']),
            $l['side'],
            number_format((float) $l['amount'], 2, '.', ''),
        ], $lines);
    }

    /** @return list<array<string,mixed>> */
    private function reversals(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT entry_id, pair_no, reversal_entry_id, reversal_pair_no, replacement_pair_no
               FROM journal_pair_reversals WHERE supplier_id = ? AND entry_id = ? ORDER BY pair_no'
        );
        $stmt->execute([$this->supplierId, $entryId]);
        return array_map(static fn (array $r): array => [
            'entry_id' => (int) $r['entry_id'],
            'pair_no' => (int) $r['pair_no'],
            'reversal_entry_id' => (int) $r['reversal_entry_id'],
            'reversal_pair_no' => (int) $r['reversal_pair_no'],
            'replacement_pair_no' => $r['replacement_pair_no'] === null ? null : (int) $r['replacement_pair_no'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function entryDate(int $entryId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT entry_date FROM journal_entries WHERE id = ?');
        $stmt->execute([$entryId]);
        return (string) $stmt->fetchColumn();
    }

    private function activityCount(string $action, int $entityId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM activity_log WHERE action = ? AND entity_id = ? AND supplier_id = ?');
        $stmt->execute([$action, $entityId, $this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function lockUntil(string $date): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, $date]);
    }

    private function assertPairError(string $code, int $status, \Closure $call): void
    {
        $this->db->pdo()->exec('SAVEPOINT pair_error');
        try {
            $call();
            self::fail('Očekávaná chyba ' . $code . '.');
        } catch (JournalPairException $e) {
            self::assertSame([$code, $status], [$e->errorCode, $e->httpStatus], $e->getMessage());
        } finally {
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT pair_error');
        }
    }
}
