<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Repository\Property\Payments\DebtRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\PaymentCorrectionIntent;
use MyInvoice\Service\Property\Payments\PaymentCorrectionReversalIntent;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use MyInvoice\Tests\Support\PaymentCorrectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentCorrectionOwnershipTest extends TestCase
{
    use ChargeIssuanceFixture;
    use PaymentCorrectionFixture;
    use \MyInvoice\Tests\Support\SharedFixtureSavepointTrait;

    /** Testy, které samy ukončují a začínají transakci, si firmu staví vlastní. */
    private const OWN_TRANSACTION = ['testIntentCannotSurviveRollbackAndNewTransactionOnSamePdo'];

    private Connection $db;
    private array $f;
    private ?array $base = null;

    /** Úhrada dluhu, nad kterou test staví: testy záměrů 900 Kč, varianty úlev podle dat, ostatní 10 Kč. */
    private function settledPaid(): string
    {
        if (in_array($this->name(), ['testGenericReversalCannotDetachAnOwnedCorrection', 'testOwnedCorrectionAndReversalCompleteBothMirrors'], true)) return (string) $this->providedData()[0];
        return str_contains($this->name(), 'Intent') || in_array($this->name(), ['testTypedWriterCompletesBothMirrorsOnceAndPreservesCallerSavepoint', 'testPersistedProofCannotBeChangedAfterIntentCreation'], true) ? '90000' : '1000';
    }

    private function settled(string $paid): array
    {
        if ($this->base !== null && $this->base['paid'] === $paid) return $this->base;
        return $this->settledPaymentFixture($paid, '0');
    }

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->beginSharedFixtureTest($this->db->pdo());
        if (in_array($this->name(), self::OWN_TRANSACTION, true)) {
            $this->sharedClean();
            $this->f = $this->issuanceFixture($this->db);
        } else {
            // Firma s předpisy a zaplaceným dluhem je stejná pro testy se stejnou úhradou; opravu si staví každý test sám.
            $paid = $this->settledPaid();
            [$this->f, $this->base] = $this->sharedFixture('settled|' . $paid, function () use ($paid): array {
                $this->f = $this->issuanceFixture($this->db);
                return [$this->f, $this->settledPaymentFixture($paid, '0') + ['paid' => $paid]];
            });
        }
    }

    protected function tearDown(): void
    {
        $this->endSharedFixtureTest($this->db->pdo());
    }

    public static function reductions(): array
    {
        return ['direct ancestor' => [false, false], 'distant ancestor' => [true, false], 'mixed signs with positive total' => [false, true]];
    }

    #[DataProvider('reductions')]
    public function testGenericPostingCannotReduceRegisteredPrincipal(bool $distant, bool $mixed): void
    {
        $base = $this->settled('1000');
        $previous = $base['charge'];
        $revision = 1;
        if ($distant) {
            $middle = $this->difference($previous, $revision++, '1200', null);
            self::assertGreaterThan(0, bccomp($middle['amount_cents'], '0', 0));
            $previous = $this->issuance()->post($this->f['supplier'], $middle['batch_id'], [['id' => $middle['id'], 'row_version' => 1]], $this->f['actor'])['charges'][0];
            self::assertSame('posted', $previous['status']);
        }
        $charge = $this->difference($previous, $revision, '800', $mixed ? '1200000' : null);
        self::assertNotEmpty(array_filter($charge['snapshot']['lines'], static fn (array $line): bool => bccomp($line['amount_cents'], '0', 0) < 0));
        if ($mixed) self::assertGreaterThanOrEqual(0, bccomp($charge['amount_cents'], '0', 0));
        $this->assertBlocked('property_payment_correction_owner_required', fn () => $this->issuance()->post(
            $this->f['supplier'], $charge['batch_id'], [['id' => $charge['id'], 'row_version' => 1]], $this->f['actor'],
        ));
    }

    public function testGenericReversalCannotDetachAnExistingPayment(): void
    {
        $base = $this->settled('1000');
        $this->assertBlocked('property_payment_source_immutable', fn () => $this->issuance()->reverse(
            $this->f['supplier'], $base['charge']['id'], $this->reverseInput($base['charge']), $this->f['actor'],
        ));
    }

    public static function reliefs(): array
    {
        return ['without relief' => ['1000', '0'], 'with relief' => ['90000', '10000']];
    }

    #[DataProvider('reliefs')]
    public function testGenericReversalCannotDetachAnOwnedCorrection(string $paid, string $relief): void
    {
        $base = $this->settled($paid);
        $request = $this->negativePaymentCorrection($base, '800', $relief);
        $writer = $this->correctionWriter();
        $done = $writer->apply($this->f['supplier'], $request, $writer->preview($this->f['supplier'], $request)['preview_hash'], 'owned-correction', $this->f['actor']);
        $charge = (new IssuanceReadRepository($this->db))->find($this->f['supplier'], $done['adjustment_charge_id'], true);
        self::assertSame('posted', $charge['status']);
        self::assertSame($relief === '0', $done['operation_id'] === null);
        $this->assertBlocked('property_payment_source_immutable', fn () => $this->issuance()->reverse(
            $this->f['supplier'], $charge['id'], $this->reverseInput($charge), $this->f['actor'],
        ));
    }

    public function testDirectS3ReversalWriterCannotConsumeAStoredRequestForPaidCharge(): void
    {
        $base = $this->settled('1000');
        $request = $this->reverseInput($base['charge']);
        $token = $request['idempotency_key'];
        unset($request['idempotency_key']);
        $hash = PropertyInput::hash(['charge_id' => $base['charge']['id'], 'request' => $request, 'idempotency_key' => $token, 'actor' => $this->f['actor']]);
        $q = $this->db->pdo()->prepare("INSERT INTO unit_charge_adjustments(supplier_id,charge_id,kind,effective_at,request_json,request_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,'reversal',?,?,?,?,?,UTC_TIMESTAMP(6))");
        $q->execute([$this->f['supplier'], $base['charge']['id'], PropertyInstant::sql($request['effective_at']), json_encode($request, JSON_THROW_ON_ERROR), $hash, $token, $this->f['actor']]);
        $intent = (int) $this->db->pdo()->lastInsertId();
        $this->assertBlocked('property_payment_source_immutable', fn () => $this->paymentFixtureContainer()->get(JournalEntryRepository::class)->insertPropertyChargeReversal($this->f['supplier'], $intent));
    }

    #[DataProvider('reliefs')]
    public function testOwnedCorrectionAndReversalCompleteBothMirrors(string $paid, string $relief): void
    {
        $base = $this->settled($paid);
        $request = $this->negativePaymentCorrection($base, '800', $relief);
        $writer = $this->correctionWriter();
        $hash = $writer->preview($this->f['supplier'], $request)['preview_hash'];
        $posted = $writer->apply($this->f['supplier'], $request, $hash, 'complete-correction', $this->f['actor']);
        $reader = new IssuanceReadRepository($this->db);
        $charge = $reader->find($this->f['supplier'], $posted['adjustment_charge_id'], true);
        self::assertSame('posted', $posted['status']);
        self::assertSame('posted', $charge['status']);
        self::assertNotNull($charge['journal_entry_id']);
        self::assertSame($relief === '0', $posted['operation_id'] === null);
        if ($relief !== '0') self::assertSame($relief, $this->correctionPayments()->credit($this->f['supplier'], $posted['credit_id'])['balance_cents']);
        $reverse = ['expected_version' => 2, 'accounting_date' => '2026-03-01', 'effective_at' => '2026-03-01T10:00:00+01:00',
            'reason' => 'Synthetic coupled reversal', 'evidence' => 'Synthetic replacement decision'];
        $done = $writer->reverse($this->f['supplier'], $posted['id'], $reverse, 'complete-reversal', $this->f['actor']);
        $charge = $reader->find($this->f['supplier'], $posted['adjustment_charge_id'], true);
        self::assertSame('reversed', $done['status']);
        self::assertSame('reversed', $charge['status']);
        self::assertNotNull($charge['reversal_entry_id']);
        self::assertSame($paid, (new DebtRepository($this->db))->current($this->f['supplier'], $base['debt']['id'])['paid']['principal:' . $base['line_id']]);
        if ($relief !== '0') self::assertSame('0', $this->correctionPayments()->credit($this->f['supplier'], $posted['credit_id'])['balance_cents']);
        self::assertSame($done, $writer->reverse($this->f['supplier'], $posted['id'], $reverse, 'complete-reversal', $this->f['actor']));
        self::assertSame($posted['id'], $writer->apply($this->f['supplier'], $request, $hash, 'complete-correction', $this->f['actor'])['id']);
    }

    public static function intentKinds(): array
    {
        return ['posting' => [false], 'reversal' => [true]];
    }

    #[DataProvider('intentKinds')]
    public function testTypedWriterCompletesBothMirrorsOnceAndPreservesCallerSavepoint(bool $reverse): void
    {
        $this->db->pdo()->exec('SAVEPOINT correction_caller');
        $intent = $this->intent($reverse);
        $done = $this->consume($intent);
        self::assertSame($reverse ? 'reversed' : 'posted', $done['status']);
        self::assertNotNull($done['operation_id']);
        $charge = (new IssuanceReadRepository($this->db))->find($this->f['supplier'], $done['adjustment_charge_id'], true);
        self::assertSame($done['status'], $charge['status']);
        $before = $this->state();
        $this->assertLogicRejected(fn () => $this->consume($intent));
        self::assertSame($before, $this->state());
        $this->db->pdo()->exec('RELEASE SAVEPOINT correction_caller');
    }

    #[DataProvider('intentKinds')]
    public function testIntentCannotBeClonedOrSerializedOrCompletedOutsideItsWriter(bool $reverse): void
    {
        $intent = $this->intent($reverse);
        $before = $this->state();
        try { clone $intent; self::fail('Intent cloned.'); } catch (\Error $e) { self::assertStringContainsString('__clone', $e->getMessage()); }
        $this->assertLogicRejected(fn () => serialize($intent));
        $class = $intent::class;
        $this->assertLogicRejected(fn () => unserialize('O:' . strlen($class) . ':"' . $class . '":0:{}'));
        $this->assertLogicRejected(fn () => $intent->complete($this->db));
        self::assertSame($before, $this->state());
        self::assertSame($reverse ? 'reversed' : 'posted', $this->consume($intent)['status']);
    }

    #[DataProvider('intentKinds')]
    public function testIntentRejectsForeignPdoWithoutConsumingOriginalPermission(bool $reverse): void
    {
        $intent = $this->intent($reverse);
        $before = $this->state();
        $foreign = Connection::withoutSharedTestConnection(fn () => new Connection($this->paymentFixtureContainer()->get(Config::class)));
        try {
            $foreign->pdo()->beginTransaction();
            $this->assertLogicRejected(fn () => $intent->claim($foreign));
        } finally {
            if ($foreign->pdo()->inTransaction()) $foreign->pdo()->rollBack();
            $foreign->close();
        }
        self::assertSame($before, $this->state());
        self::assertSame($reverse ? 'reversed' : 'posted', $this->consume($intent)['status']);
    }

    #[DataProvider('intentKinds')]
    public function testIntentCannotSurviveRollbackAndNewTransactionOnSamePdo(bool $reverse): void
    {
        $intent = $this->intent($reverse);
        $pdo = $this->db->pdo();
        $pdo->rollBack();
        $pdo->beginTransaction();
        self::assertSame($pdo, $this->db->pdo());
        $before = $this->state();
        $this->assertLogicRejected(fn () => $this->consume($intent));
        self::assertSame($before, $this->state());
    }

    public static function changedProofs(): array
    {
        return ['anchor' => [false, 'unit_payment_correction_anchors', 'amount_cents=amount_cents+1'],
            'relief' => [false, 'unit_payment_reliefs', 'amount_cents=amount_cents+1'],
            'snapshot' => [false, 'unit_payment_corrections', "snapshot_hash=REPEAT('0',64)"],
            'reversal audit' => [true, 'unit_payment_audit', "payload_hash=REPEAT('0',64)"]];
    }

    #[DataProvider('changedProofs')]
    public function testPersistedProofCannotBeChangedAfterIntentCreation(bool $reverse, string $table, string $change): void
    {
        $intent = $this->intent($reverse);
        $before = $this->state();
        $q = $this->db->pdo()->prepare('UPDATE ' . $table . ' SET ' . $change . ' WHERE supplier_id=?');
        $rejected = false;
        try { $q->execute([$this->f['supplier']]); } catch (\PDOException $e) { $rejected = true; self::assertSame(1644, (int) $e->errorInfo[1]); }
        self::assertTrue($rejected, 'Persisted correction proof changed.');
        self::assertSame($before, $this->state());
        self::assertSame($reverse ? 'reversed' : 'posted', $this->consume($intent)['status']);
    }

    public function testReversalRechecksCreditReservedAfterIntentCreation(): void
    {
        $intent = $this->intent(true);
        $credit = $this->correctionWriter()->find($this->f['supplier'], $intent->correctionId)['credit_id'];
        $this->correctionPayments()->reserve($this->f['supplier'], $credit, 1, ['amount_cents' => '1', 'party_ids' => [$this->f['clients'][1]],
            'disposition' => 'Synthetic disposition after preview', 'recipient_account' => '1000000005/0100'], 'reserve-after-intent', $this->f['actor']);
        $before = $this->state();
        $rejected = false;
        try { $this->consume($intent); } catch (PaymentException $e) { $rejected = true; self::assertSame('property_payment_credit_in_use', $e->errorCode); }
        self::assertTrue($rejected, 'Reserved credit reached the reversal writer.');
        self::assertSame($before, $this->state());
    }

    public function testLateJournalAuditFailureRollsBackBothMirrorsAndAllowsFreshIntentRetry(): void
    {
        $this->db->pdo()->exec('SAVEPOINT correction_caller');
        $intent = $this->intent(true);
        $before = $this->state();
        $activity = $this->createMock(\MyInvoice\Service\ActivityLogger::class);
        $activity->expects(self::once())->method('log')->with('accounting.reversed', self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), self::anything())
            ->willThrowException(new \RuntimeException('Synthetic correction journal audit failure'));
        $journal = new JournalEntryRepository($this->db, $activity);
        $rejected = false;
        try { $journal->insertPropertyCorrectionChargeReversal($intent); } catch (\RuntimeException $e) { $rejected = true; self::assertSame('Synthetic correction journal audit failure', $e->getMessage()); }
        self::assertTrue($rejected);
        self::assertSame($before, $this->state());
        $fresh = PaymentCorrectionReversalIntent::fromCurrentRequest($this->db, $this->correctionWriter(), $this->f['supplier'], $intent->auditId);
        $done = $this->consume($fresh);
        self::assertSame('reversed', $done['status']);
        self::assertSame('reversed', (new IssuanceReadRepository($this->db))->find($this->f['supplier'], $done['adjustment_charge_id'], true)['status']);
        $this->db->pdo()->exec('RELEASE SAVEPOINT correction_caller');
    }

    private function assertLogicRejected(callable $work): void
    {
        $rejected = false;
        try { $work(); } catch (\LogicException) { $rejected = true; }
        self::assertTrue($rejected, 'Invalid capability reached the writer.');
    }

    private function consume(PaymentCorrectionIntent|PaymentCorrectionReversalIntent $intent): array
    {
        return $intent instanceof PaymentCorrectionIntent ? $this->issuance()->postPaymentCorrection($intent) : $this->issuance()->reversePaymentCorrection($intent);
    }

    private function intent(bool $reverse): PaymentCorrectionIntent|PaymentCorrectionReversalIntent
    {
        $base = $this->settled('90000');
        $request = $this->negativePaymentCorrection($base, '800', '10000');
        $writer = $this->correctionWriter();
        $snapshot = $writer->preview($this->f['supplier'], $request)['snapshot'];
        $token = 'capability-correction';
        $hash = PropertyInput::hash(['request' => $request, 'token' => $token, 'actor' => $this->f['actor']]);
        $q = $this->db->pdo()->prepare('INSERT INTO unit_payment_corrections(supplier_id,adjustment_charge_id,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $q->execute([$this->f['supplier'], $request['adjustment_charge_id'], json_encode($request, JSON_THROW_ON_ERROR), json_encode($snapshot, JSON_THROW_ON_ERROR), $hash, PropertyInput::hash($snapshot), $token, $this->f['actor']]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $q = $this->db->pdo()->prepare('INSERT INTO unit_payment_correction_anchors(supplier_id,correction_id,adjustment_line_id,original_line_id,amount_cents) VALUES (?,?,?,?,?)');
        foreach ($snapshot['anchors'] as $part) $q->execute([$this->f['supplier'], $id, $part['adjustment_line_id'], $part['original_line_id'], $part['amount_cents']]);
        $q = $this->db->pdo()->prepare('INSERT INTO unit_payment_reliefs(supplier_id,correction_id,allocation_id,amount_cents) VALUES (?,?,?,?)');
        foreach ($snapshot['reliefs'] as $part) $q->execute([$this->f['supplier'], $id, $part['allocation_id'], $part['amount_cents']]);
        $intent = PaymentCorrectionIntent::fromCurrentRequest($this->db, $writer, $this->f['supplier'], $id);
        if (!$reverse) return $intent;
        $done = $this->consume($intent);
        $charge = (new IssuanceReadRepository($this->db))->find($this->f['supplier'], $done['adjustment_charge_id'], true);
        $request = ['expected_version' => 2, 'accounting_date' => '2026-03-01', 'effective_at' => '2026-03-01T10:00:00+01:00', 'reason' => 'Synthetic owned reversal', 'evidence' => 'Synthetic replacement'];
        $payload = ['request' => $request, 'token' => 'capability-reversal', 'actor' => $this->f['actor'], 'snapshot_hash' => $done['snapshot_hash'],
            'charge' => ['id' => $charge['id'], 'row_version' => $charge['row_version'], 'snapshot_hash' => $charge['snapshot_hash']]];
        $q = $this->db->pdo()->prepare("INSERT INTO unit_payment_audit(supplier_id,object_kind,object_id,action,payload_json,payload_hash,created_by,recorded_at) VALUES (?,'correction',?,'reversal_requested',?,?,?,UTC_TIMESTAMP(6))");
        $q->execute([$this->f['supplier'], $id, json_encode($payload, JSON_THROW_ON_ERROR), PropertyInput::hash($payload), $this->f['actor']]);
        return PaymentCorrectionReversalIntent::fromCurrentRequest($this->db, $writer, $this->f['supplier'], (int) $this->db->pdo()->lastInsertId());
    }

    private function difference(array $previous, int $revision, string $admin, ?string $long): array
    {
        $f = $this->f;
        $version = self::chargeRequest($f);
        $version['expected_revision'] = $revision;
        foreach ($version['rates'] as &$rate) {
            if ($rate['component_id'] === $f['components']['ADMIN']) $rate['amount'] = $admin;
            if ($long !== null && $rate['component_id'] === $f['components']['LONG']) $rate['amount'] = $long;
        }
        unset($rate);
        $preview = (new ChargePreviewService($this->db))->preview($f['supplier'], $version);
        $saved = (new ChargeRepository($this->db))->saveVersion($f['supplier'], ['request' => $version, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'ownership-version-' . $revision], $f['actor']);
        $request = $f['issue_request'];
        $request['version_id'] = $saved['id'];
        $request['issue_mode'] = 'difference';
        $request['accounting_date'] = $revision === 1 ? '2026-02-02' : '2026-02-03';
        $request['issued_at'] = $request['accounting_date'] . 'T10:00:00+01:00';
        $request['units'] = array_values(array_filter($request['units'], static fn (array $unit): bool => $unit['unit_id'] === $previous['unit_id']));
        $request['units'][0]['expected_revision'] = $previous['revision_no'];
        $request['units'][0]['previous_charge_id'] = $previous['id'];
        $preview = (new IssuancePreviewService($this->db))->preview($f['supplier'], $request);
        return $this->issuance()->issue($f['supplier'], ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'ownership-difference-' . $revision], $f['actor'])['charges'][0];
    }

    private function issuance(): IssuanceRepository
    {
        return $this->paymentFixtureContainer()->get(IssuanceRepository::class);
    }

    private function reverseInput(array $charge): array
    {
        return ['row_version' => $charge['row_version'], 'accounting_date' => '2026-03-01',
            'effective_at' => PropertyInstant::normalize('2026-03-01T10:00:00+01:00'), 'reason' => 'Synthetic generic reversal',
            'evidence_ref' => 'Synthetic independent request', 'idempotency_key' => 'forbidden-generic-reversal'];
    }

    private function assertBlocked(string $code, callable $work): void
    {
        $before = $this->state();
        $rejected = false;
        try {
            $work();
        } catch (PaymentException|IssuanceException|PostingException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            $rejected = true;
        }
        self::assertTrue($rejected, 'Generic path bypassed the coupled correction owner.');
        self::assertSame($before, $this->state());
    }

    private function state(): string
    {
        $state = [];
        foreach (['unit_charges', 'unit_charge_adjustments', 'unit_charge_issue_audit', 'unit_payment_operations', 'unit_payment_corrections',
            'unit_payment_correction_anchors', 'unit_payment_reliefs', 'unit_credit_lots', 'unit_credit_parties', 'unit_payment_audit', 'journal_entries', 'journal_entry_lines'] as $table) {
            $q = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=?');
            $q->execute([$this->f['supplier']]);
            $rows = $q->fetchAll(\PDO::FETCH_ASSOC);
            usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            $state[$table] = $rows;
        }
        return PropertyInput::hash($state);
    }
}
