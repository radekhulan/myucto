<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\{PaymentExportRepository, PaymentRepository};
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\Workflow\{PaymentWorkflowFacade, PaymentWorkflowPlanner};
use MyInvoice\Tests\Support\{ChargeIssuanceFixture, PaymentCorrectionFixture};
use PHPUnit\Framework\Attributes\{DataProvider, PreserveGlobalState, RunInSeparateProcess};
use PHPUnit\Framework\TestCase;

final class PaymentWorkflowRefundTest extends TestCase
{
    use ChargeIssuanceFixture, PaymentCorrectionFixture;

    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) getenv('MYINVOICE_DB_NAME'));
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public static function combinations(): array
    {
        return [
            'same reservation' => [true, false, false],
            'same reservation reversed' => [true, true, false],
            'distinct reservations' => [false, false, false],
            'distinct reservations reversed' => [false, true, false],
            'submitted same reservation' => [true, false, true],
            'submitted distinct reversed' => [false, true, true],
        ];
    }

    #[DataProvider('combinations')]
    public function testReadySharedCreditRefundConfirmsEveryPartAndRetainsIdempotency(bool $same, bool $reverse, bool $submitted): void
    {
        $fixture = $this->refundFixture($same, $submitted);
        $request = $fixture['request'];
        if ($reverse) $request['parts'] = array_reverse($request['parts']);
        $supplier = $this->f['supplier']; $actor = $this->f['actor'];
        $facade = $this->paymentFixtureContainer()->get(PaymentWorkflowFacade::class);
        $before = $this->state($fixture);
        $preview = $facade->preview($supplier, $request);
        self::assertSame('ready', $preview['status']);
        self::assertSame($before, $this->state($fixture));
        $result = $facade->confirm($supplier, $request, $preview['preview_hash'], 'shared-refund', $actor);
        self::assertSame('700', $result['settled_cents']);
        self::assertSame('0', $result['available_cents']);
        self::assertCount(2, $result['parts']);
        $payments = $this->correctionPayments();
        $credit = $payments->credit($supplier, $fixture['credit']);
        self::assertSame('300', $credit['balance_cents']);
        self::assertSame('0', $credit['reserved_cents']);
        self::assertSame('300', $credit['available_cents']);
        self::assertSame($before['credit']['row_version'] + 2, $credit['row_version']);
        foreach ($fixture['reservations'] as $reservation) {
            $current = $payments->reservation($supplier, $reservation['id']);
            self::assertSame('paid', $current['status']);
            self::assertSame($reservation['amount_cents'], $current['paid_cents']);
            self::assertSame($reservation['row_version'] + ($same ? 2 : 1), $current['row_version']);
        }
        $firstAmount = $request['parts'][0]['request']['amount_cents'];
        $second = $payments->operation($supplier, (int) $result['parts'][1]['operation_id']);
        self::assertSame(bcsub('1000', $firstAmount, 0), $second['snapshot']['credit']['balance_cents']);
        self::assertSame(bcsub('700', $firstAmount, 0), $second['snapshot']['credit']['reserved_cents']);
        self::assertSame($same ? $firstAmount : '0', $second['snapshot']['reservation']['paid_cents']);
        self::assertSame($submitted ? 'submitted' : 'reserved', $second['snapshot']['reservation']['status']);
        foreach ($result['parts'] as $index => $part) {
            $actual = $payments->operation($supplier, (int) $part['operation_id']);
            foreach (['credit', 'reservation'] as $field) self::assertSame($preview['snapshot']['parts'][$index]['plan']['snapshot'][$field], $actual['snapshot'][$field]);
        }
        if ($fixture['export'] !== null) {
            $export = $this->paymentFixtureContainer()->get(PaymentExportRepository::class)->find($supplier, $fixture['export']['id']);
            self::assertSame($fixture['export']['content_base64'], $export['content_base64']);
            foreach ($export['reservation_statuses'] as $status) self::assertSame('paid', $status);
        }
        $after = $this->state($fixture);
        self::assertSame($result, $facade->confirm($supplier, $request, $preview['preview_hash'], 'shared-refund', $actor));
        self::assertSame($after, $this->state($fixture));
    }

    public function testCombinedReservationOverdrawRejectsWithoutWrites(): void
    {
        $fixture = $this->refundFixture(true);
        $request = $fixture['request'];
        foreach ($request['parts'] as &$part) $part['request']['amount_cents'] = '400';
        unset($part);
        $this->db->pdo()->prepare('UPDATE bank_transactions SET amount=-8.00 WHERE id=?')->execute([$request['source']['id']]);
        $request['clearing_cells'][0]['amount_cents'] = '800';
        $before = $this->state($fixture);
        try {
            $this->paymentFixtureContainer()->get(PaymentWorkflowFacade::class)->preview($this->f['supplier'], $request);
            self::fail('Combined refunds exceeded the reservation.');
        } catch (PaymentException $e) {
            self::assertSame('property_payment_workflow_part_capacity', $e->errorCode);
            self::assertSame('reservation:' . $fixture['reservations'][0]['id'], $e->details['reference']);
        }
        self::assertSame($before, $this->state($fixture));
    }

    public function testExternalCreditChangeStillInvalidatesPreview(): void
    {
        $fixture = $this->refundFixture(false);
        $supplier = $this->f['supplier']; $payments = $this->correctionPayments();
        $facade = $this->paymentFixtureContainer()->get(PaymentWorkflowFacade::class);
        $preview = $facade->preview($supplier, $fixture['request']);
        $credit = $payments->credit($supplier, $fixture['credit']);
        $payments->reserve($supplier, $fixture['credit'], $credit['row_version'], $this->reservationRequest('50'), 'external-reservation', $this->f['actor']);
        $before = $this->state($fixture);
        try {
            $facade->confirm($supplier, $fixture['request'], $preview['preview_hash'], 'stale-refund', $this->f['actor']);
            self::fail('External credit change was treated as an internal refund.');
        } catch (PaymentException $e) {
            self::assertSame('property_payment_preview_stale', $e->errorCode);
        }
        self::assertSame($before, $this->state($fixture));
    }

    public function testStaleReservationVersionIsNotRebasedByTheWorkflow(): void
    {
        $fixture = $this->refundFixture(true);
        $request = $fixture['request'];
        $request['parts'][1]['request']['expected_reservation_version']++;
        $before = $this->state($fixture);
        try {
            $this->paymentFixtureContainer()->get(PaymentWorkflowFacade::class)->preview($this->f['supplier'], $request);
            self::fail('A caller supplied a projected version instead of the current reservation version.');
        } catch (PaymentException $e) {
            self::assertSame('property_payment_reservation_version_conflict', $e->errorCode);
        }
        self::assertSame($before, $this->state($fixture));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFailureAfterSecondRefundRollsBackAllPartsAndNativeOwnerThenRetriesOnce(): void
    {
        $fixture = $this->refundFixture(true, true);
        $supplier = $this->f['supplier']; $container = $this->paymentFixtureContainer();
        $real = $this->correctionPayments();
        $payments = $this->createMock(PaymentRepository::class);
        $payments->method('preview')->willReturnCallback(fn ($supplier, $request) => $real->preview($supplier, $request));
        $calls = 0;
        $payments->expects(self::exactly(2))->method('apply')->willReturnCallback(function ($supplier, $request, $hash, $token, $actor) use ($real, &$calls, $fixture): array {
            $result = $real->apply($supplier, $request, $hash, $token, $actor);
            if (++$calls === 2) {
                self::assertSame('paid', $real->reservation($supplier, $fixture['reservations'][0]['id'])['status']);
                self::assertSame('300', $real->credit($supplier, $fixture['credit'])['balance_cents']);
                throw new \RuntimeException('Synthetic failure after both refunds');
            }
            return $result;
        });
        $facade = new PaymentWorkflowFacade($this->db, $container->get(PaymentWorkflowPlanner::class), $payments,
            $container->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class), $container->get(\MyInvoice\Service\Accounting\Cash\CashDocumentService::class));
        $preview = $facade->preview($supplier, $fixture['request']);
        $before = $this->state($fixture);
        try {
            $facade->confirm($supplier, $fixture['request'], $preview['preview_hash'], 'atomic-shared-refund', $this->f['actor']);
            self::fail('The injected late failure was not reached.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic failure after both refunds', $e->getMessage());
        }
        self::assertSame($before, $this->state($fixture));
        self::assertTrue($this->db->pdo()->inTransaction());
        $facade = $container->get(PaymentWorkflowFacade::class);
        $result = $facade->confirm($supplier, $fixture['request'], $preview['preview_hash'], 'atomic-shared-refund', $this->f['actor']);
        $after = $this->state($fixture);
        self::assertSame($before['counts']['unit_credit_events'] + 2, $after['counts']['unit_credit_events']);
        self::assertSame($before['counts']['journal_entries'] + 3, $after['counts']['journal_entries']);
        self::assertSame($result, $facade->confirm($supplier, $fixture['request'], $preview['preview_hash'], 'atomic-shared-refund', $this->f['actor']));
        self::assertSame($after, $this->state($fixture));
    }

    private function refundFixture(bool $same, bool $submitted = false): array
    {
        $supplier = $this->f['supplier']; $actor = $this->f['actor']; $payments = $this->correctionPayments();
        $request = ['kind' => 'allocation', 'source' => ['kind' => 'bank', 'id' => $this->correctionBank('10.00'), 'expected_version' => 0],
            'amount_cents' => '1000', 'accounting_date' => '2026-02-01', 'reason' => 'Synthetic evidenced credit', 'debts' => [],
            'credit_entitlement' => ['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id' => $this->f['units']['G12'], 'party_ids' => [$this->f['clients'][1]],
                'evidence' => 'Synthetic entitlement', 'due_date' => '2026-03-01']];
        $credit = $payments->apply($supplier, $request, $payments->preview($supplier, $request)['preview_hash'], 'credit-origin', $actor)['credit_id'];
        $reservations = [];
        \MyInvoice\Tests\Support\RefundAccountFixture::document($this->db->pdo(), $supplier, $this->f['clients'][1]);
        foreach ($same ? ['700'] : ['300', '400'] as $index => $amount) {
            $reservations[] = $payments->reserve($supplier, $credit, $payments->credit($supplier, $credit)['row_version'], $this->reservationRequest($amount), 'reservation-' . $index, $actor);
        }
        $export = null;
        if ($submitted) {
            $this->db->pdo()->prepare("INSERT INTO supplier_bank_accounts (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,analytic_suffix) VALUES (?,'Synthetic refund account','1000000005','0100','0100','CZK','1000000005','100')")->execute([$supplier]);
            $exportRequest = ['reservation_ids' => array_column($reservations, 'id'), 'expected_versions' => array_column($reservations, 'row_version', 'id'),
                'bank_account_id' => (int) $this->db->pdo()->lastInsertId(), 'due_date' => '2026-02-01', 'reason' => 'Synthetic export', 'payment_symbols' => []];
            $this->paymentFixtureContainer()->get(\MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner::class)->ensureChartAccount($supplier, '100', 'Synthetic refund account');
            foreach ($reservations as $reservation) $exportRequest['payment_symbols'][$reservation['id']] = ['allow_missing_variable_symbol' => true, 'message' => 'Synthetic refund'];
            $exports = $this->paymentFixtureContainer()->get(PaymentExportRepository::class);
            $export = $exports->confirm($supplier, $exportRequest, $exports->preview($supplier, $exportRequest)['preview_hash'], 'refund-export', $actor);
            $reservations = array_map(fn ($r) => $payments->reservation($supplier, $r['id']), $reservations);
        }
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic split refund',?,'1000000005','0100','CZK','2026-02-01')")->execute([$supplier, hash('sha256', random_bytes(20))]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,'2026-02-01','-7.00','CZK','1000000005','0100')")->execute([$statement]);
        $request = ['source' => ['kind' => 'bank', 'id' => (int) $pdo->lastInsertId()],
            'clearing_cells' => [['key' => 'refund', 'amount_cents' => '700', 'dimensions' => []]], 'parts' => []];
        foreach (['300', '400'] as $index => $amount) {
            $reservation = $reservations[$same ? 0 : $index];
            $request['parts'][] = ['key' => 'refund-' . $index, 'request' => ['kind' => 'refund', 'amount_cents' => $amount,
                'accounting_date' => '2026-02-01', 'reason' => 'Synthetic partial refund', 'reservation_id' => $reservation['id'],
                'expected_reservation_version' => $reservation['row_version']]];
        }
        return compact('credit', 'reservations', 'request', 'export');
    }

    private function reservationRequest(string $amount): array
    {
        return ['amount_cents' => $amount, 'party_ids' => [$this->f['clients'][1]],
            'disposition' => 'Synthetic refund instruction', 'recipient_account' => '1000000005/0100'];
    }

    private function state(array $fixture): array
    {
        $supplier = $this->f['supplier']; $payments = $this->correctionPayments(); $counts = [];
        foreach (['journal_entries', 'unit_payment_sources', 'unit_payment_operations', 'unit_payment_workflows', 'unit_payment_workflow_operations',
            'unit_credit_events', 'unit_credit_lots', 'unit_credit_reservations', 'unit_payment_audit', 'unit_payment_exports', 'unit_payment_export_items'] as $table) {
            $q = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE supplier_id=?'); $q->execute([$supplier]);
            $counts[$table] = (int) $q->fetchColumn();
        }
        $q = $this->db->pdo()->prepare('SELECT * FROM bank_transactions WHERE id=?'); $q->execute([$fixture['request']['source']['id']]);
        return ['counts' => $counts, 'bank' => $q->fetch(\PDO::FETCH_ASSOC), 'credit' => $payments->credit($supplier, $fixture['credit']),
            'reservations' => array_map(fn ($r) => $payments->reservation($supplier, $r['id']), $fixture['reservations'])];
    }
}
