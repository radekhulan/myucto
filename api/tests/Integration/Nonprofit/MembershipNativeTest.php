<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalLinePairingRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipMemberService;
use MyInvoice\Service\Nonprofit\Membership\MembershipPublicCatalog;
use MyInvoice\Service\Nonprofit\Membership\MembershipReceivablePaymentAdapter;
use MyInvoice\Service\Nonprofit\Membership\MembershipReconciliationService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MembershipNativeTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private PostingService $posting;
    private MembershipMemberService $members;
    private MembershipChargeService $charges;
    private MembershipBalanceRepository $balances;
    private int $supplier;
    private int $period;
    private int $actor;
    private array $classification;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic membership association','Synthetic street','Synthetic city','10000','membership-association@example.test',203,1,?,'double_entry')")->execute([$vat]);
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")->execute([$this->supplier]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currency, $this->supplier]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(? ,?,'Synthetic membership actor','admin','cs',1)")
            ->execute(['membership-actor@example.test', password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->actor = (int) $this->pdo->lastInsertId();
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $axes = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2026-01-01', $this->actor);
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'MEMBERSHIP','Synthetic membership activity')")
            ->execute([$axes['activity_kind']['id'], $this->supplier]);
        $this->classification = ['activity_value_id' => $axes['activity']['values']['main'],
            'activity_kind_value_id' => (int) $this->pdo->lastInsertId(),
            'tax_regime_value_id' => $axes['tax_regime']['values']['subject']];
        foreach (['221' => 'asset', '315.100' => 'asset', '684.100' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')
                ->execute([$this->supplier, $code, 'Synthetic ' . $code, $type]);
        }
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $this->period, [
            'expected_revision' => 0,
            'idempotency_key' => 'membership-legal',
            'reason' => 'Synthetic reviewed association statutes',
            'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association', 'main_activity_nonbusiness' => true,
                'legal_basis' => 'zdp_17a', 'evidence' => 'Synthetic signed association statutes'],
        ], $this->actor);
        $repository = new MembershipRepository($this->db);
        $this->members = new MembershipMemberService($this->db, $repository);
        $this->posting = $container->get(PostingService::class);
        $this->charges = new MembershipChargeService($this->db, $repository, new MembershipChargeRepository($this->db), $this->posting);
        $this->balances = new MembershipBalanceRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testReal504SystemAxesAcceptExplicitMembershipClassification(): void
    {
        $member = $this->member('M-DIM-RED', 'dimension-red');
        $request = $this->request($member['id']);
        $preview = $this->charges->previewIssue($this->supplier, $request);
        $issued = $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'dimension-red', $this->actor);

        self::assertSame('issue', $issued['operation']['operation']);
        self::assertSame($this->classification, [
            'activity_value_id' => $issued['operation']['proof']['classification']['activity']['value_id'],
            'activity_kind_value_id' => $issued['operation']['proof']['classification']['activity_kind']['value_id'],
            'tax_regime_value_id' => $issued['operation']['proof']['classification']['tax_regime']['value_id'],
        ]);
        foreach ($issued['operation']['receipt']['lines'] as $line) {
            self::assertCount(3, $line['dimensions']);
            self::assertSame(array_fill_keys(array_keys($line['dimensions']), ['origin' => 'explicit', 'ref' => null]), $line['dimension_origins']);
        }
    }
    public function testReplayUsesCapturedClassificationAfterValueRetirement(): void
    {
        $member = $this->member('M-RETIRED-DIM', 'retired-dimension');
        $issued = $this->issue($member['id'], 'retired-dimension');
        $kind = $issued['operation']['proof']['classification']['activity_kind']['value_id'];
        $this->pdo->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$this->supplier, $kind]);

        $replayed = $this->charges->confirmIssue($this->supplier, $this->request($member['id']), 0,
            $issued['preview_hash'], 'issue-retired-dimension', $this->actor);
        self::assertTrue($replayed['idempotent_replay']);
        self::assertSame($issued['operation']['id'], $replayed['operation']['id']);

        $new = $this->request($member['id']);
        $new['title_key'] = 'retired-dimension-new';
        try {
            $this->charges->previewIssue($this->supplier, $new);
            self::fail('A retired classification value was accepted for a new charge.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $error) {
            self::assertSame('nonprofit_membership_classification_invalid', $error->errorCode);
        }
    }
    #[DataProvider('dimensionProofMutations')]
    public function testReplayRejectsChangedDimensionAssignmentOrOrigin(string $mutation): void
    {
        $member = $this->member('M-PROOF-' . $mutation, 'proof-' . $mutation);
        $issued = $this->issue($member['id'], 'proof-' . $mutation);
        $line = (int) $issued['operation']['receivable_line_id'];
        $activity = $issued['operation']['proof']['classification']['activity'];
        if ($mutation === 'origin') {
            $this->pdo->prepare("UPDATE journal_entry_line_dimensions SET assignment_origin='unknown' WHERE supplier_id=? AND line_id=? AND dimension_type_id=?")
                ->execute([$this->supplier, $line, $activity['type_id']]);
        } else {
            $q = $this->pdo->prepare("SELECT id FROM dimension_values WHERE supplier_id=? AND type_id=? AND semantic_key='economic'");
            $q->execute([$this->supplier, $activity['type_id']]);
            $this->pdo->prepare('UPDATE journal_entry_line_dimensions SET dimension_value_id=? WHERE supplier_id=? AND line_id=? AND dimension_type_id=?')
                ->execute([(int) $q->fetchColumn(), $this->supplier, $line, $activity['type_id']]);
        }

        try {
            $this->charges->confirmIssue($this->supplier, $this->request($member['id']), 0, $issued['preview_hash'], 'issue-proof-' . $mutation, $this->actor);
            self::fail('Changed dimension proof was accepted: ' . $mutation);
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $error) {
            self::assertSame('nonprofit_membership_ledger_mismatch', $error->errorCode, $mutation);
        }
    }

    public static function dimensionProofMutations(): iterable
    {
        yield 'assignment value' => ['value'];
        yield 'assignment origin' => ['origin'];
    }
    #[DataProvider('genericDimensionMutationPaths')]
    public function testGenericDimensionMutationCannotRewriteStoredFinancialProof(string $path): void
    {
        $member = $this->member('M-GUARD-' . $path, 'guard-' . $path);
        $issued = $this->issueTitle($member['id'], 'guard-' . $path, 'guard-' . $path);
        $line = (int) $issued['operation']['receivable_line_id'];
        $this->pdo->prepare('INSERT INTO dimension_types(supplier_id,code,name,kind) VALUES(?,?,?,?)')
            ->execute([$this->supplier, 'guard_' . $path, 'Synthetic guard ' . $path, 'custom']);
        $type = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,?,?)')
            ->execute([$type, $this->supplier, 'VALUE', 'Synthetic guard value']);
        $value = (int) $this->pdo->lastInsertId();
        $repo = new DimensionAssignmentRepository($this->db);
        $origin = [$type => ['origin' => 'explicit', 'ref' => null]];

        try {
            if ($path === 'replace') {
                $current = $repo->lineDimensions($this->supplier, [$line])[$line];
                $repo->replaceLineDimensions($this->supplier, $line, $current + [$type => $value], $origin);
            } elseif ($path === 'insert') {
                $repo->insertLineDimensions($this->supplier, $line, [$type => $value], $origin);
            } elseif ($path === 'batch') {
                $repo->insertLineAssignments($this->supplier, [$line => ['dimensions' => [$type => $value], 'dimension_origins' => $origin]]);
            } else {
                $repo->insertLineSplits($this->supplier, $line, [$type => [$value => 1]], [$type => [$value => ['origin' => 'explicit', 'ref' => null]]]);
            }
            self::fail('Generic dimension mutation changed a Membership-owned financial line: ' . $path);
        } catch (\MyInvoice\Service\Accounting\PostingException $error) {
            self::assertSame('nonprofit_membership_source_immutable', $error->errorCode, $path);
        }
    }

    public static function genericDimensionMutationPaths(): iterable
    {
        yield 'replace existing dimensions' => ['replace'];
        yield 'direct dimension insert' => ['insert'];
        yield 'batch assignment insert' => ['batch'];
        yield 'direct split insert' => ['split'];
    }
    public function testTwoMembersProduceExactNativeTotalsAndReplayUsesCapturedProof(): void
    {
        $one = $this->member('M-001', 'one');
        $two = $this->member('M-002', 'two');
        $first = $this->issue($one['id'], 'first');
        $second = $this->issue($two['id'], 'second');

        $q = $this->pdo->prepare("SELECT a.account_code,l.side,SUM(l.amount) amount,COUNT(*) rows_count
            FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id
            JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id
            WHERE l.supplier_id=? AND e.source_type='nonprofit_membership' AND e.source_id IS NOT NULL
            GROUP BY a.account_code,l.side ORDER BY a.account_code,l.side");
        $q->execute([$this->supplier]);
        self::assertSame([
            ['account_code' => '315.100', 'side' => 'debit', 'amount' => '40000.00', 'rows_count' => 2],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => '40000.00', 'rows_count' => 2],
        ], array_map(static function (array $row): array { $row['rows_count'] = (int) $row['rows_count']; return $row; }, $q->fetchAll(PDO::FETCH_ASSOC)));
        $reconciliation = (new MembershipReconciliationService($this->balances))->periodReconciliation($this->supplier, $this->period);
        self::assertSame('balanced', $reconciliation['status']);
        self::assertTrue($reconciliation['proof_complete']);
        self::assertSame('4000000', (string) $reconciliation['claims']['cents']);
        self::assertSame('4000000', (string) $reconciliation['ledger']['receivable_cents']);
        self::assertSame('4000000', (string) $reconciliation['ledger']['revenue_cents']);

        $this->members->appendRevision($this->supplier, $one['id'], [
            'status' => 'ended', 'effective_from' => '2027-01-01', 'expected_revision' => 1,
            'idempotency_key' => 'member-one-ended',
            'legal_basis' => $this->basis('Synthetic future membership end'),
        ], $this->actor);
        $replayed = $this->charges->confirmIssue($this->supplier, $this->request($one['id']), 0, $first['preview_hash'], 'issue-first', $this->actor);
        self::assertTrue($replayed['idempotent_replay']);
        self::assertSame($first['operation']['id'], $replayed['operation']['id']);
        self::assertSame($second['operation']['amount_cents'], 2000000);
    }

    public function testStalePreviewOverlapAndClosedPeriodAreRejected(): void
    {
        $member = $this->member('M-005', 'five');
        $request = $this->request($member['id']);
        $stale = $this->charges->previewIssue($this->supplier, $request);
        $this->members->appendRevision($this->supplier, $member['id'], [
            'status' => 'ended', 'effective_from' => '2027-01-01', 'expected_revision' => 1,
            'idempotency_key' => 'member-five-ended', 'legal_basis' => $this->basis('Synthetic future end reviewed after preview'),
        ], $this->actor);
        try {
            $this->charges->confirmIssue($this->supplier, $request, 0, $stale['preview_hash'], 'stale-five', $this->actor);
            self::fail('Changed effective timeline must invalidate a prior preview.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_preview_stale', $e->errorCode);
        }
        $fresh = $this->charges->previewIssue($this->supplier, $request);
        $this->charges->confirmIssue($this->supplier, $request, 0, $fresh['preview_hash'], 'fresh-five', $this->actor);
        $overlap = $request;
        $overlap['period_from'] = '2026-02-01';
        $overlap['period_to'] = '2026-11-30';
        try {
            $this->charges->previewIssue($this->supplier, $overlap);
            self::fail('An active title cannot overlap itself.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_claim_overlap', $e->errorCode);
        }
        $closed = $request;
        $closed['title_key'] = 'closed-period-title';
        $closedPreview = $this->charges->previewIssue($this->supplier, $closed);
        $this->pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        try {
            $this->charges->confirmIssue($this->supplier, $closed, 0, $closedPreview['preview_hash'], 'closed-five', $this->actor);
            self::fail('Closed period must reject a financial write.');
        } catch (\MyInvoice\Service\Nonprofit\Membership\MembershipException $e) {
            self::assertSame('nonprofit_membership_period_locked', $e->errorCode);
        }
    }

    public function testNativeBankPairingIsTheOnlyPaymentEvidenceAndReversalConsumesClaim(): void
    {
        $member = $this->member('M-003', 'three');
        $issued = $this->issue($member['id'], 'payment');
        $claimId = (int) $this->pdo->query('SELECT id FROM nonprofit_membership_charge_claims WHERE operation_id=' . (int) $issued['operation']['id'])->fetchColumn();
        $reconciliation = new MembershipReconciliationService($this->balances);
        $before = $reconciliation->claimSettlement($this->supplier, $claimId);
        self::assertSame('unreconciled', $before['settlement']['status']);
        self::assertNull($before['settlement']['paid_cents']);
        self::assertNull($before['settlement']['open_cents']);

        $target = (new MembershipReceivablePaymentAdapter($this->balances))->target($this->supplier, $claimId);
        $forged = $this->posting->postDocument($this->supplier, 'bank', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => '20000.00'],
            ['account_code' => '315.100', 'side' => 'credit', 'amount' => '20000.00'],
        ], ['entry_date' => '2026-02-01', 'description' => 'Synthetic unowned bank-looking entry', 'posted' => true, 'posted_by' => $this->actor]);
        $q = $this->pdo->prepare("SELECT l.line_no,l.account_id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code='315.100'");
        $q->execute([$this->supplier, $forged]);
        $forgedLine = $q->fetch(PDO::FETCH_ASSOC);
        $pairings = new JournalLinePairingRepository($this->db);
        $forgedPairing = $pairings->create($this->supplier, $target['journal_line']['account_id'], [
            ['entry_id' => $target['journal_line']['entry_id'], 'line_no' => $target['journal_line']['line_no'], 'account_id' => $target['journal_line']['account_id']],
            ['entry_id' => $forged, 'line_no' => (int) $forgedLine['line_no'], 'account_id' => (int) $forgedLine['account_id']],
        ], 'Synthetic forged payment evidence', 'manual', $this->actor);
        self::assertSame('ambiguous', $reconciliation->claimSettlement($this->supplier, $claimId)['settlement']['status']);
        self::assertTrue($pairings->delete($this->supplier, $forgedPairing));

        $this->pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES(?,'synthetic-membership.gpc',?,'1000000005','0100','CZK','2026-02-01',?)")
            ->execute([$this->supplier, hash('sha256', 'synthetic membership payment ' . $this->supplier), $this->actor]);
        $statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,description,match_status) VALUES(?,'2026-02-01',20000,'CZK','Synthetic native membership payment','manual')")
            ->execute([$statement]);
        $transaction = (int) $this->pdo->lastInsertId();
        $payment = $this->posting->postDocument($this->supplier, 'bank', $transaction, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => '20000.00'],
            ['account_code' => '315.100', 'side' => 'credit', 'amount' => '20000.00'],
        ], ['entry_date' => '2026-02-01', 'description' => 'Synthetic native bank payment', 'posted' => true, 'posted_by' => $this->actor]);
        $q->execute([$this->supplier, $payment]);
        $paymentLine = $q->fetch(PDO::FETCH_ASSOC);
        $pairings->create($this->supplier, $target['journal_line']['account_id'], [
            ['entry_id' => $target['journal_line']['entry_id'], 'line_no' => $target['journal_line']['line_no'], 'account_id' => $target['journal_line']['account_id']],
            ['entry_id' => $payment, 'line_no' => (int) $paymentLine['line_no'], 'account_id' => (int) $paymentLine['account_id']],
        ], 'Synthetic exact membership payment', 'manual', $this->actor);
        $settled = $reconciliation->claimSettlement($this->supplier, $claimId);
        self::assertSame('settled', $settled['settlement']['status']);
        self::assertSame(2000000, $settled['settlement']['paid_cents']);
        self::assertSame(0, $settled['settlement']['open_cents']);

        $preview = $this->charges->previewReverse($this->supplier, ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-12-31', 'reason' => 'Synthetic approved cancellation']);
        $reversed = $this->charges->confirmReverse($this->supplier, ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-12-31', 'reason' => 'Synthetic approved cancellation'],
            $preview['expected_case_version'], $preview['preview_hash'], 'reverse-payment', $this->actor);
        self::assertSame('reverse', $reversed['operation']['operation']);
        self::assertSame('reversed', $reconciliation->claimSettlement($this->supplier, $claimId)['settlement']['status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM journal_line_pairing_items WHERE entry_id=' . $target['journal_line']['entry_id'])->fetchColumn());
    }

    public function testFinancialFactsAreAppendOnly(): void
    {
        $member = $this->member('M-004', 'four');
        $issued = $this->issue($member['id'], 'immutable');
        $this->expectException(\PDOException::class);
        $this->pdo->prepare('UPDATE nonprofit_membership_charge_operations SET amount_cents=1 WHERE supplier_id=? AND id=?')
            ->execute([$this->supplier, $issued['operation']['id']]);
    }

    #[DataProvider('genericJournalMutationPaths')]
    public function testGenericJournalMutationCannotRewriteMembershipReceipt(string $path): void
    {
        $member = $this->member('M-JOURNAL-' . $path, 'journal-' . $path);
        $issued = $this->issueTitle($member['id'], 'journal-' . $path, 'journal-' . $path);
        $entry = (int) $issued['operation']['journal_entry_id'];
        $repository = Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $before = $repository->find($entry, $this->supplier);
        $header = $before;
        $header['source_type'] = 'manual';
        $header['source_id'] = null;
        $lines = $before['lines'];
        $proof = (new \MyInvoice\Service\Nonprofit\Membership\MembershipClassification($this->db))->resolve($this->supplier, $this->classification);
        foreach ($lines as &$line) {
            $line['dimensions'] = array_column($proof, 'value_id', 'type_id');
            $line['dimension_origins'] = array_fill_keys(array_keys($line['dimensions']), ['origin' => 'explicit', 'ref' => null]);
        }
        unset($line);
        try {
            match ($path) {
                'replace' => $repository->replace($entry, $header, $lines),
                'partial' => $repository->replaceSomeLines($entry, $this->supplier, (int) $before['row_version'], [(int) $lines[0]['id']], [$lines[0]]),
                'detach' => $repository->detachSource($entry, $this->supplier),
                'link-reversal' => $repository->setReversedBy($entry, $this->supplier, $entry),
                'reverse' => $this->posting->reverse($this->supplier, $entry, ['entry_date' => '2026-02-01', 'posted_by' => $this->actor]),
            };
            self::fail('Generic journal mutation accepted a Membership-owned receipt: ' . $path);
        } catch (\MyInvoice\Service\Accounting\PostingException $error) {
            self::assertSame('nonprofit_membership_source_immutable', $error->errorCode, $path);
        }
        self::assertSame($before, $repository->find($entry, $this->supplier));
    }

    public static function genericJournalMutationPaths(): iterable
    {
        foreach (['replace', 'partial', 'detach', 'link-reversal', 'reverse'] as $path) yield $path => [$path];
    }

    public function testGenericInsertCannotForgeMembershipJournalOwner(): void
    {
        $member = $this->member('M-JOURNAL-FORGE', 'journal-forge');
        $issued = $this->issue($member['id'], 'journal-forge');
        $repository = Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $header = $repository->find((int) $issued['operation']['journal_entry_id'], $this->supplier);
        $header['source_id'] = (int) $issued['case']['id'] + 100000;
        $lines = $header['lines'];
        $proof = (new \MyInvoice\Service\Nonprofit\Membership\MembershipClassification($this->db))->resolve($this->supplier, $this->classification);
        foreach ($lines as &$line) {
            $line['dimensions'] = array_column($proof, 'value_id', 'type_id');
            $line['dimension_origins'] = array_fill_keys(array_keys($line['dimensions']), ['origin' => 'explicit', 'ref' => null]);
        }
        unset($line);
        $this->expectException(\MyInvoice\Service\Accounting\PostingException::class);
        $this->expectExceptionMessage('živý vlastnický kontext');
        $repository->insert($header, $lines);
    }

    public function testMarkPostedOnMembershipReceiptIsAnExactNoop(): void
    {
        $member = $this->member('M-JOURNAL-NOOP', 'journal-noop');
        $issued = $this->issue($member['id'], 'journal-noop');
        $repository = Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $entry = (int) $issued['operation']['journal_entry_id'];
        $before = $repository->find($entry, $this->supplier);
        self::assertFalse($repository->markPosted($entry, $this->supplier, $this->actor));
        self::assertSame($before, $repository->find($entry, $this->supplier));
    }

    public function testMemberBalanceQueryCountIsConstantWithinOnePage(): void
    {
        $member = $this->member('M-006', 'six');
        $this->issueTitle($member['id'], 'batch-1', 'balance-batch-1');
        $service = new MembershipReconciliationService($this->balances);
        $one = $this->selectQueries(fn (): array => $service->memberBalance($this->supplier, $member['id']));
        for ($index = 2; $index <= 6; ++$index) $this->issueTitle($member['id'], 'batch-' . $index, 'balance-batch-' . $index);
        $many = $this->selectQueries(fn (): array => $service->memberBalance($this->supplier, $member['id']));

        self::assertSame($one, $many);
        self::assertLessThanOrEqual(3, $many);
    }

    public function testPublicRoleUsesOnlyRevisionsRecordedByKnownAt(): void
    {
        $member = $this->member('M-PUBLIC-TIME', 'public-time');
        $q = $this->pdo->prepare('SELECT recorded_at_utc FROM nonprofit_member_revisions WHERE supplier_id=? AND member_id=? AND revision_no=1');
        $q->execute([$this->supplier, $member['id']]);
        $known = str_replace(' ', 'T', (string) $q->fetchColumn()) . 'Z';
        $this->pdo->query('SELECT SLEEP(0.01)');
        $this->members->appendRevision($this->supplier, $member['id'], [
            'status' => 'suspended', 'effective_from' => '2026-06-01', 'expected_revision' => 1,
            'idempotency_key' => 'member-public-time-suspended', 'legal_basis' => $this->basis('Synthetic later recorded suspension'),
        ], $this->actor);
        $catalog = $this->catalog();

        $historical = $catalog->roles($this->supplier, '2026-07-01', $known, null, 25, $member['id']);
        $current = $catalog->roles($this->supplier, '2026-07-01', null, null, 25, $member['id']);

        self::assertSame('active', $historical['items'][0]['status']);
        self::assertNull($historical['items'][0]['effective_to']);
        self::assertSame('suspended', $current['items'][0]['status']);
        self::assertSame('verified', $historical['items'][0]['proof_status']);
        self::assertSame('proven_utc', $historical['items'][0]['recorded_time_status']);
        self::assertArrayNotHasKey('member_number', $historical['items'][0]);
        self::assertArrayNotHasKey('legal_basis', $historical['items'][0]);
    }

    public function testPublicReceivableUsesStoredProofAndKeepsUnprovenSettlementNull(): void
    {
        $member = $this->member('M-PUBLIC-CLAIM', 'public-claim');
        $issued = $this->issue($member['id'], 'public-claim');
        $claim = (int) $this->pdo->query('SELECT id FROM nonprofit_membership_charge_claims WHERE operation_id=' . (int) $issued['operation']['id'])->fetchColumn();

        $response = $this->catalog()->receivables($this->supplier, $member['id'], null, 25);
        $detail = $this->catalog()->receivables($this->supplier, null, null, 25, $claim);

        self::assertCount(1, $response['items']);
        self::assertSame($response['items'][0], $detail['items'][0]);
        self::assertSame('unreconciled', $detail['items'][0]['settlement']['status']);
        self::assertNull($detail['items'][0]['settlement']['paid_cents']);
        self::assertNull($detail['items'][0]['settlement']['open_cents']);
        self::assertSame('proven_utc', $detail['items'][0]['recorded_time_status']);
        self::assertStringEndsWith('Z', $detail['items'][0]['recorded_at']);
        self::assertSame('verified', $detail['items'][0]['proof_status']);
        foreach (['member_number', 'name', 'evidence', 'request_json', 'idempotency_key', 'record_hash'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $detail['items'][0]);
        }
    }

    public function testPublicReceivableProofUsesConstantQueriesWithinPage(): void
    {
        $member = $this->member('M-PUBLIC-BATCH', 'public-batch');
        $this->issueTitle($member['id'], 'public-batch-1', 'public-batch-1');
        $one = $this->selectQueries(fn (): array => $this->catalog()->receivables($this->supplier, $member['id'], null, 100));
        for ($index = 2; $index <= 6; ++$index) $this->issueTitle($member['id'], 'public-batch-' . $index, 'public-batch-' . $index);
        $many = $this->selectQueries(fn (): array => $this->catalog()->receivables($this->supplier, $member['id'], null, 100));

        self::assertSame($one, $many);
        self::assertLessThanOrEqual(14, $many);
    }

    private function member(string $number, string $key): array
    {
        $currency = (int) $this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic member','Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)")
            ->execute([$this->supplier, $key . '@example.test', $currency]);
        $member = $this->members->create($this->supplier, (int) $this->pdo->lastInsertId(), $number, $this->actor);
        $this->members->appendRevision($this->supplier, $member['id'], [
            'status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0,
            'idempotency_key' => 'member-' . $key, 'legal_basis' => $this->basis('Synthetic member evidence ' . $key),
        ], $this->actor);
        return $member;
    }

    private function catalog(): MembershipPublicCatalog
    {
        return new MembershipPublicCatalog($this->db, new MembershipRepository($this->db), new MembershipChargeRepository($this->db), $this->balances);
    }

    private function basis(string $evidence): array
    {
        return ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
            'reference' => 'Synthetic association statutes article 5', 'evidence' => $evidence];
    }

    private function request(int $member): array
    {
        return ['member_id' => $member, 'title_key' => 'annual-2026', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'amount' => '20000.00',
            'receivable_account' => '315.100', 'revenue_account' => '684.100', 'title_reference' => 'Synthetic statutes article 5',
            'evidence' => 'Synthetic approved annual membership resolution', 'nonreciprocal' => true, 'reason' => 'Synthetic annual membership charge',
            'classification' => $this->classification];
    }

    private function issue(int $member, string $key): array
    {
        $request = $this->request($member);
        $preview = $this->charges->previewIssue($this->supplier, $request);
        return $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-' . $key, $this->actor)
            + ['preview_hash' => $preview['preview_hash']];
    }

    private function issueTitle(int $member, string $title, string $key): array
    {
        $request = $this->request($member);
        $request['title_key'] = $title;
        $preview = $this->charges->previewIssue($this->supplier, $request);
        return $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], $key, $this->actor);
    }

    private function selectQueries(callable $work): int
    {
        $before = (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        $work();
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1] - $before;
    }
}
