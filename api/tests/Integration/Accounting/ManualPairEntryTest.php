<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\ManualJournalEntryException;
use MyInvoice\Service\Accounting\ManualJournalEntryService;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ruční zápis jednoduchou formou (kritérium b) a storno vybraných dvojic (kritérium f):
 * dvojice MD/Dal se zapíše jako dva řádky se stejným číslem dvojice, server odmítne
 * dvojici bez účtu, se stejnými účty nebo s nekladnou částkou, složená firma dvojice
 * nedostane a storno dvojice nechá originál nestornovaný.
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne.
 */
#[Group('integration')]
final class ManualPairEntryTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private ManualJournalEntryService $manual;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private EntryFormService $entryForms;
    private int $supplierId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->manual = $container->get(ManualJournalEntryService::class);
        $this->posting = $container->get(PostingService::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $this->entryForms = $container->get(EntryFormService::class);
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode, legal_form)
             SELECT 'Dvojice test s.r.o.', 'Testovací', 'Praha', '11000', country_id, 'dvojice@example.com',
                    default_currency_id, default_vat_rate_id, 'double_entry', '112'
               FROM supplier WHERE id = ?"
        )->execute([$source]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $container->get(AccountingPeriodRepository::class)->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    /** @return iterable<string,array{array<string,mixed>, string}> */
    public static function invalidPairs(): iterable
    {
        yield 'bez účtu MD' => [['debit_account_code' => '', 'credit_account_code' => '221', 'amount' => 10], JournalPairInvariant::MISSING_DEBIT_ACCOUNT];
        yield 'bez účtu Dal' => [['debit_account_code' => '568', 'amount' => 10], JournalPairInvariant::MISSING_CREDIT_ACCOUNT];
        yield 'stejné účty' => [['debit_account_code' => '221', 'credit_account_code' => '221', 'amount' => 10], JournalPairInvariant::SAME_ACCOUNT];
        yield 'nulová částka' => [['debit_account_code' => '568', 'credit_account_code' => '221', 'amount' => 0], JournalPairInvariant::AMOUNT_NOT_POSITIVE];
        yield 'záporná částka' => [['debit_account_code' => '568', 'credit_account_code' => '221', 'amount' => -5], JournalPairInvariant::AMOUNT_NOT_POSITIVE];
    }

    /** @param array<string,mixed> $pair */
    #[DataProvider('invalidPairs')]
    public function testInvalidPairIsRejected(array $pair, string $code): void
    {
        $this->switchToSingle();
        try {
            $this->manual->normalizePairs($this->supplierId, [$pair], true);
            self::fail('Neplatná dvojice prošla.');
        } catch (ManualJournalEntryException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testValidPairsBecomeTwoLinesWithSamePairNumber(): void
    {
        $this->switchToSingle();
        $entryId = $this->postPairs([
            ['debit_account_code' => '568', 'credit_account_code' => '221', 'amount' => 15, 'line_text' => 'Poplatek'],
            ['debit_account_code' => '518', 'credit_account_code' => '221', 'amount' => '100.50'],
        ]);

        $rows = $this->lineRows($entryId);
        self::assertSame([
            ['568', 'debit', '15.00', 1, 'Poplatek'],
            ['221', 'credit', '15.00', 1, 'Poplatek'],
            ['518', 'debit', '100.50', 2, null],
            ['221', 'credit', '100.50', 2, null],
        ], $rows);
        $view = $this->journal->pairView($this->supplierId, $entryId, $this->journal->find($entryId, $this->supplierId)['lines']);
        self::assertCount(2, $view['pairs']);
    }

    public function testCompositeSupplierGetsLinesWithoutPairNumbers(): void
    {
        $lines = $this->manual->normalizePairs($this->supplierId, [
            ['debit_account_code' => '568', 'credit_account_code' => '221', 'amount' => 15],
        ], $this->manual->singleAt($this->supplierId, self::YEAR . '-03-10'));
        $entryId = $this->manual->post($this->supplierId, $lines, ['entry_date' => self::YEAR . '-03-10', 'user_id' => $this->userId]);

        self::assertSame([[ '568', 'debit', '15.00', null, null], ['221', 'credit', '15.00', null, null]], $this->lineRows($entryId));
        self::assertArrayNotHasKey('pair_no', $this->journal->find($entryId, $this->supplierId)['lines'][0]);
        self::assertSame([], $this->journal->pairView($this->supplierId, $entryId, []));
    }

    public function testCompositeSupplierRejectsLinesCarryingPairNumbers(): void
    {
        $lines = $this->manual->normalizePairs($this->supplierId, [
            ['debit_account_code' => '568', 'credit_account_code' => '221', 'amount' => 15],
        ], true);

        $this->expectException(PostingException::class);
        $this->manual->post($this->supplierId, $lines, ['entry_date' => self::YEAR . '-03-10', 'user_id' => $this->userId]);
    }

    public function testManualLinesInSingleFormArePairedOrRejected(): void
    {
        $this->switchToSingle();
        $oneToMany = $this->manual->normalizeLines($this->supplierId, [
            ['account_code' => '221', 'side' => 'credit', 'amount' => 30],
            ['account_code' => '568', 'side' => 'debit', 'amount' => 10],
            ['account_code' => '518', 'side' => 'debit', 'amount' => 20],
        ]);
        $entryId = $this->manual->post($this->supplierId, $oneToMany, ['entry_date' => self::YEAR . '-03-10', 'user_id' => $this->userId]);
        self::assertSame([
            ['568', 'debit', '10.00', 1, null],
            ['221', 'credit', '10.00', 1, null],
            ['518', 'debit', '20.00', 2, null],
            ['221', 'credit', '20.00', 2, null],
        ], $this->lineRows($entryId));

        $manyToMany = $this->manual->normalizeLines($this->supplierId, [
            ['account_code' => '221', 'side' => 'credit', 'amount' => 30],
            ['account_code' => '211', 'side' => 'credit', 'amount' => 10],
            ['account_code' => '568', 'side' => 'debit', 'amount' => 15],
            ['account_code' => '518', 'side' => 'debit', 'amount' => 25],
        ]);
        try {
            $this->manual->post($this->supplierId, $manyToMany, ['entry_date' => self::YEAR . '-03-10', 'user_id' => $this->userId]);
            self::fail('Ruční M : N zápis v jednoduché formě prošel.');
        } catch (PostingException $e) {
            self::assertSame('pairing_required', $e->errorCode);
        }
    }

    public function testDateBeforeSwitchStaysComposite(): void
    {
        $this->switchToSingle();
        $lines = $this->manual->normalizeLines($this->supplierId, [
            ['account_code' => '221', 'side' => 'credit', 'amount' => 30],
            ['account_code' => '568', 'side' => 'debit', 'amount' => 30],
        ]);
        $entryId = $this->manual->post($this->supplierId, $lines, ['entry_date' => self::YEAR . '-01-15', 'user_id' => $this->userId]);

        self::assertSame([['221', 'credit', '30.00', null, null], ['568', 'debit', '30.00', null, null]], $this->lineRows($entryId));
    }

    public function testReverseSelectedPairLeavesOriginalAndFullReversalMirrorsRest(): void
    {
        $this->switchToSingle();
        $entryId = $this->postPairs([
            ['debit_account_code' => '568', 'credit_account_code' => '221', 'amount' => 15],
            ['debit_account_code' => '518', 'credit_account_code' => '221', 'amount' => 100],
        ]);

        $pairReversal = $this->posting->reversePairs($this->supplierId, $entryId, [2], ['user_id' => $this->userId]);

        self::assertSame([['518', 'credit', '100.00', 1, null], ['221', 'debit', '100.00', 1, null]], $this->lineRows($pairReversal));
        self::assertNull($this->journal->find($entryId, $this->supplierId)['reversed_by']);
        self::assertSame([2], $this->journal->reversedPairNos($this->supplierId, $entryId));

        try {
            $this->posting->reversePairs($this->supplierId, $entryId, [2], ['user_id' => $this->userId]);
            self::fail('Dvojice šla stornovat dvakrát.');
        } catch (PostingException $e) {
            self::assertSame('pair_already_reversed', $e->errorCode);
        }

        $full = $this->posting->reverse($this->supplierId, $entryId, ['user_id' => $this->userId]);
        self::assertSame([['568', 'credit', '15.00', 1, null], ['221', 'debit', '15.00', 1, null]], $this->lineRows($full));
        self::assertSame($full, $this->journal->find($entryId, $this->supplierId)['reversed_by']);
    }

    public function testRepostPairThroughCorrectionEntryCarriesReplacementPair(): void
    {
        $this->switchToSingle();
        $entryId = $this->postPairs([
            ['debit_account_code' => '568', 'credit_account_code' => '221', 'amount' => 15],
        ]);

        $correction = $this->posting->reversePairs($this->supplierId, $entryId, [1], ['user_id' => $this->userId],
            ['debit_account_code' => '549', 'credit_account_code' => '221']);

        self::assertSame([
            ['568', 'credit', '15.00', 1, null],
            ['221', 'debit', '15.00', 1, null],
            ['549', 'debit', '15.00', 2, null],
            ['221', 'credit', '15.00', 2, null],
        ], $this->lineRows($correction));
        $stmt = $this->db->pdo()->prepare('SELECT replacement_pair_no FROM journal_pair_reversals WHERE supplier_id = ? AND entry_id = ?');
        $stmt->execute([$this->supplierId, $entryId]);
        self::assertSame(2, (int) $stmt->fetchColumn());
    }

    public function testAutomaticManyToManyEntryIsPostedCompositeAndMarked(): void
    {
        $this->switchToSingle();
        $entryId = $this->posting->postDocument($this->supplierId, 'opening', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 30],
            ['account_code' => '211', 'side' => 'debit', 'amount' => 10],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 15],
            ['account_code' => '568', 'side' => 'credit', 'amount' => 25],
        ], ['entry_date' => self::YEAR . '-03-10', 'user_id' => $this->userId]);

        self::assertSame([null, null, null, null], array_column($this->lineRows($entryId), 3));
        self::assertSame(['pair_status' => ['status' => 'composite_mn', 'reason' => 'many_to_many']],
            $this->journal->pairView($this->supplierId, $entryId, []));
    }

    private function switchToSingle(): void
    {
        $this->entryForms->schedule($this->supplierId, self::YEAR . '-02-01', EntryForm::Single, $this->userId);
    }

    /** @param list<array<string,mixed>> $pairs */
    private function postPairs(array $pairs): int
    {
        $lines = $this->manual->normalizePairs($this->supplierId, $pairs, true);
        return $this->manual->post($this->supplierId, $lines, ['entry_date' => self::YEAR . '-03-10', 'user_id' => $this->userId]);
    }

    /** @return list<array{string,string,string,?int,?string}> */
    private function lineRows(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount, l.pair_no, l.line_text
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$this->supplierId, $entryId]);
        return array_map(static fn (array $r): array => [
            (string) $r['account_code'], (string) $r['side'], (string) $r['amount'],
            $r['pair_no'] === null ? null : (int) $r['pair_no'], $r['line_text'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
}
