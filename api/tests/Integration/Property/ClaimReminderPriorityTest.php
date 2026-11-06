<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Repository\Property\Payments\DebtRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture, PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;

/**
 * Pořadí § 1933: upomenutí zaznamenané u předpisu (unit_charge_evidence_events) musí
 * rozhodnout mezi jinak rovnocennými dluhy téhož dlužníka.
 */
final class ClaimReminderPriorityTest extends TestCase
{
    use ChargeIssuanceFixture, PaymentCorrectionFixture;

    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) getenv('MYINVOICE_DB_NAME'));
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testEqualDebtsWithoutReminderNeedManualDecision(): void
    {
        $case = $this->twoUnitDebts();
        try {
            $this->correctionPayments()->preview($this->f['supplier'], $this->payment($case));
            self::fail('Two equal debts must not be ordered by internal id.');
        } catch (PaymentException $e) {
            self::assertSame('property_payment_legal_review', $e->errorCode);
            self::assertSame('ambiguous_priority', $e->details['reason']);
        }
    }

    public function testRecordedChargeReminderGivesPriorityToRemindedDebt(): void
    {
        $case = $this->twoUnitDebts();
        $this->remind($case['charges']['U04'], $case['debtor'], '2026-01-25T09:00:00+01:00');
        $request = $this->payment($case);
        $request['payment']['principal_distribution'] = [$case['debts']['U04'] => ['evidence' => 'Synthetic debtor line instruction',
            'lines' => [['line_id' => $case['largest_lines']['U04'], 'amount_cents' => '10000']]]];
        $preview = $this->correctionPayments()->preview($this->f['supplier'], $request);
        $allocations = $preview['snapshot']['resolution']['allocations'];
        self::assertCount(1, $allocations);
        self::assertSame($case['debts']['U04'], $allocations[0]['claim_id']);
        self::assertSame('cz_1933', $allocations[0]['selection']);
    }

    public function testReminderDeliveredAfterPaymentDoesNotChangeOrder(): void
    {
        $case = $this->twoUnitDebts();
        $this->remind($case['charges']['U04'], $case['debtor'], '2026-02-01T11:00:00+01:00');
        try {
            $this->correctionPayments()->preview($this->f['supplier'], $this->payment($case));
            self::fail('Reminder delivered after the payment must not create priority.');
        } catch (PaymentException $e) {
            self::assertSame('ambiguous_priority', $e->details['reason']);
        }
    }

    public function testInvalidAccessoryAmountIsInputErrorNotServerError(): void
    {
        $case = $this->twoUnitDebts();
        $request = $this->payment($case);
        $request['payment']['accessory_distribution'] = [$case['debts']['U03'] => ['interest' => ['evidence' => 'Synthetic', 'parts' => [['id' => 1, 'amount_cents' => '12.5']]]]];
        try {
            $this->correctionPayments()->preview($this->f['supplier'], $request);
            self::fail('Invalid amount must be rejected.');
        } catch (PropertyException $e) {
            self::assertSame('property_invalid_input', $e->errorCode);
            self::assertSame('accessory_distribution.amount_cents', $e->details['field']);
            self::assertLessThan(500, $e->httpStatus);
        }
    }

    public function testInvalidPrincipalLineAmountIsInputError(): void
    {
        $case = $this->twoUnitDebts();
        $request = $this->payment($case);
        $request['payment']['principal_distribution'] = [$case['debts']['U03'] => ['evidence' => 'Synthetic', 'lines' => [['line_id' => 1, 'amount_cents' => -5]]]];
        $this->expectException(PropertyException::class);
        $this->correctionPayments()->preview($this->f['supplier'], $request);
    }

    /** U03 a U04 vlastní tatáž osoba; dluhy mají stejnou splatnost i zajištění. */
    private function twoUnitDebts(): array
    {
        $f = $this->f;
        $issuance = $this->paymentFixtureContainer()->get(IssuanceRepository::class);
        $request = $f['issue_request'];
        $preview = (new IssuancePreviewService($this->db))->preview($f['supplier'], $request);
        $batch = $issuance->issue($f['supplier'], ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'reminder-batch'], $f['actor']);
        $batch = $issuance->post($f['supplier'], $batch['id'], array_map(fn ($c) => ['id' => $c['id'], 'row_version' => 1], $batch['charges']), $f['actor']);
        $charges = [];
        $debts = [];
        $largest = [];
        foreach (['U03', 'U04'] as $code) {
            $charge = array_values(array_filter($batch['charges'], fn ($c) => $c['unit_id'] === $f['units'][$code]))[0];
            $q = $this->db->pdo()->prepare("SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? AND amount_cents>0 ORDER BY amount_cents DESC, id");
            $q->execute([$f['supplier'], $charge['id']]);
            $lines = array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
            $largest[$code] = $lines[0];
            sort($lines, SORT_NUMERIC);
            // Dluh se všemi kladnými řádky zaregistrovalo zaúčtování dávky.
            $q = $this->db->pdo()->prepare('SELECT id FROM unit_payment_debts WHERE supplier_id=? AND charge_id=?');
            $q->execute([$f['supplier'], $charge['id']]);
            $registered = array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
            self::assertCount(1, $registered);
            $charges[$code] = $charge['id'];
            $debts[$code] = $registered[0];
        }
        $debtor = $f['states']['U03']['ownerships'][0]['members'][0];
        self::assertSame($debtor, $f['states']['U04']['ownerships'][0]['members'][0]);
        return ['charges' => $charges, 'debts' => $debts, 'largest_lines' => $largest, 'debtor' => $debtor, 'bank' => $this->correctionBank('100.00')];
    }

    private function remind(int $chargeId, int $debtor, string $deliveredAt): void
    {
        $this->paymentFixtureContainer()->get(IssuanceRepository::class)->recordEvidence($this->f['supplier'], $chargeId, ['request' => [
            'kind' => 'reminder', 'effective_at' => $deliveredAt, 'reason' => 'Synthetic reminder', 'evidence_ref' => 'Synthetic delivery receipt',
            'facts' => ['recipient_id' => $debtor, 'represented_ids' => [$debtor], 'authority_ref' => '', 'delivered_at' => $deliveredAt, 'delivery_ref' => 'Synthetic post'],
            'expected_event_id' => 0], 'idempotency_key' => 'reminder-' . $chargeId], $this->f['actor']);
    }

    private function payment(array $case): array
    {
        return ['kind' => 'allocation', 'source' => ['kind' => 'bank', 'id' => $case['bank'], 'expected_version' => 0], 'amount_cents' => '10000',
            'accounting_date' => '2026-02-01', 'reason' => 'Synthetic payment without identifier', 'debts' => array_values($case['debts']),
            'payment' => ['payer_id' => $case['debtor'], 'debtor_id' => $case['debtor'], 'received_at' => '2026-02-01T10:00:00+01:00']];
    }
}
