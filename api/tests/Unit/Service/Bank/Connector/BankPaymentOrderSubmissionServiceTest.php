<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\BankPaymentOrderSubmissionRepository;
use MyInvoice\Repository\PaymentOrderRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\BankConnector;
use MyInvoice\Service\Bank\Connector\BankConnectorCallGuard;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankConnectorRegistry;
use MyInvoice\Service\Bank\Connector\BankPaymentCapabilityProvider;
use MyInvoice\Service\Bank\Connector\BankPaymentOrderSubmissionService;
use MyInvoice\Service\Bank\Connector\BankPaymentSubmissionService;
use MyInvoice\Service\Payment\PaymentOrderService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class BankPaymentOrderSubmissionServiceTest extends TestCase
{
    public function testPayrollSourceIsIsolatedAndPersistsBeforeSending(): void
    {
        $events = [];
        $connector = new SubmissionConnector(static function () use (&$events): array {
            $events[] = 'http';
            return ['accepted' => true, 'reference' => 'SYNTHETIC-PAYROLL'];
        });
        $h = $this->readyHarness($connector);
        $submission = ['id' => 901, 'payroll_batch_id' => 501, 'status' => 'accepted_awaiting_authorization'];
        $h['submissions']->expects(self::exactly(2))->method('find')
            ->with(1, 501, 'payroll')->willReturnOnConsecutiveCalls(null, $submission);
        $h['submissions']->expects(self::once())->method('begin')
            ->with(1, 501, 101, 'fio', hash('sha256', 'SYNTHETIC-PAYROLL-ABO'), 7, 'payroll')
            ->willReturnCallback(static function () use (&$events): array {
                $events[] = 'persist';
                return ['created' => true, 'submission' => ['id' => 901]];
            });
        $h['submissions']->expects(self::once())->method('accept')->with(1, 901, 'SYNTHETIC-PAYROLL');
        $h['orders']->expects(self::never())->method('find');
        $this->executeCredentialCallback($h['calls']);
        $result = $h['engine']->submit(1, 501, 101, 7, static function (array $connection) use (&$events): string {
            self::assertSame(11, $connection['currency_id']);
            $events[] = 'prepare';
            return 'SYNTHETIC-PAYROLL-ABO';
        }, 'payroll');
        self::assertSame($submission, $result['submission']);
        self::assertSame(['prepare', 'persist', 'http'], $events);
    }

    public function testExistingPayrollAttemptNeverPreparesOrSendsAgain(): void
    {
        $connector = new SubmissionConnector();
        $h = $this->harness($connector);
        $submission = ['id' => 901, 'payroll_batch_id' => 501, 'status' => 'unknown'];
        $h['submissions']->expects(self::once())->method('find')->with(1, 501, 'payroll')->willReturn($submission);
        $h['submissions']->expects(self::never())->method('begin');
        $h['connections']->expects(self::never())->method('findWithCredentialById');
        self::assertSame(['created' => false, 'submission' => $submission], $h['engine']->submit(
            1, 501, 101, 7, static fn (): never => throw new \LogicException('Unexpected preparation'), 'payroll',
        ));
        self::assertSame(0, $connector->submitCalls);
    }

    public function testAsynchronousReceiptRemainsImportStarted(): void
    {
        $connector = new SubmissionConnector(static fn (): array => ['accepted' => true, 'reference' => 'SYNTHETIC-ASYNC', 'status' => 'import_started']);
        $h = $this->readyHarness($connector);
        $h['submissions']->method('find')->willReturnOnConsecutiveCalls(null, $this->submission('import_started'));
        $h['submissions']->expects(self::once())->method('begin')->willReturn(['created' => true, 'submission' => $this->submission('unknown')]);
        $h['submissions']->expects(self::once())->method('accept')->with(1, 901, 'SYNTHETIC-ASYNC', 'import_started');
        $this->executeCredentialCallback($h['calls']);
        self::assertSame('import_started', $h['service']->submit(1, 501, 101, 7)['submission']['status']);
    }

    public function testBusyPreflightCreatesNoAttemptAndCallsNoBank(): void
    {
        $connector = new SubmissionConnector();
        $h = $this->harness($connector);
        $h['submissions']->method('find')->willReturn(null);
        $h['connections']->method('findWithCredentialById')->willReturn($this->connection());
        $h['calls']->expects(self::once())->method('withConnectionLock')
            ->willThrowException(new BankConnectorOperationException('bank_connection_busy'));
        $h['calls']->expects(self::never())->method('call');
        $h['submissions']->expects(self::never())->method('begin');

        try {
            $h['service']->submit(1, 501, 101, 7);
            self::fail('Busy preflight musí skončit výjimkou.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('bank_connection_busy', $e->errorCode);
        }
        self::assertSame(0, $connector->submitCalls);
    }

    public function testRateLimitBeforeCredentialCallbackCreatesNoAttemptAndCallsNoBank(): void
    {
        $connector = new SubmissionConnector();
        $h = $this->readyHarness($connector);
        $h['calls']->expects(self::once())->method('call')
            ->willThrowException(new BankConnectorOperationException('bank_rate_limited'));
        $h['submissions']->expects(self::never())->method('begin');

        try {
            $h['service']->submit(1, 501, 101, 7);
            self::fail('Cooldown preflight musí skončit výjimkou.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('bank_rate_limited', $e->errorCode);
        }
        self::assertSame(0, $connector->submitCalls);
    }

    public function testAcceptedAttemptIsPersistedBeforeHttpAndBecomesTerminal(): void
    {
        $events = [];
        $connector = new SubmissionConnector(static function () use (&$events): array {
            $events[] = 'http';
            return ['accepted' => true, 'reference' => 'SYNTHETIC-REF'];
        });
        $h = $this->readyHarness($connector);
        $accepted = $this->submission('accepted_awaiting_authorization');
        $h['submissions']->method('find')->willReturnOnConsecutiveCalls(null, $accepted);
        $h['submissions']->expects(self::once())->method('begin')
            ->willReturnCallback(function () use (&$events): array {
                $events[] = 'begin';
                return ['created' => true, 'submission' => $this->submission('unknown')];
            });
        $h['submissions']->expects(self::once())->method('accept')
            ->with(1, 901, 'SYNTHETIC-REF')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'accept';
            });
        $this->executeCredentialCallback($h['calls']);

        $result = $h['service']->submit(1, 501, 101, 7);

        self::assertTrue($result['created']);
        self::assertSame('accepted_awaiting_authorization', $result['submission']['status']);
        self::assertSame(['begin', 'http', 'accept'], $events);
    }

    #[DataProvider('ambiguousOutcomes')]
    public function testTimeoutOrPartialResultLeavesTerminalUnknownAttempt(
        BankConnectorException $failure,
        ?int $acceptedCount,
        ?int $rejectedCount,
    ): void {
        $connector = new SubmissionConnector(static function () use ($failure): array {
            throw $failure;
        });
        $h = $this->readyHarness($connector);
        $terminal = $this->submission('unknown', $failure->errorCode, $acceptedCount, $rejectedCount);
        $h['submissions']->method('find')->willReturnOnConsecutiveCalls(null, $terminal);
        $h['submissions']->method('begin')->willReturn([
            'created' => true,
            'submission' => $this->submission('unknown'),
        ]);
        $h['submissions']->expects(self::once())->method('fail')->with(
            1,
            901,
            'unknown',
            $failure->errorCode,
            $acceptedCount,
            $rejectedCount,
            $failure->remoteHttpStatus,
        );
        $this->executeCredentialCallback($h['calls']);

        $result = $h['service']->submit(1, 501, 101, 7);

        self::assertTrue($result['created']);
        self::assertSame('unknown', $result['submission']['status']);
        self::assertSame(1, $connector->submitCalls);
    }

    public static function ambiguousOutcomes(): iterable
    {
        yield 'timeout' => [
            new BankConnectorException('remote_unavailable', 'safe', true),
            null,
            null,
        ];
        yield 'partial acceptance' => [
            new BankConnectorException('payment_rejected', 'safe', false, 200, 1, 1),
            1,
            1,
        ];
    }

    public function testExistingSubmissionReturnsDuplicateWithoutLockOrHttp(): void
    {
        $connector = new SubmissionConnector();
        $h = $this->harness($connector);
        $existing = $this->submission('unknown', 'submission_started');
        $h['submissions']->method('find')->willReturn($existing);
        $h['connections']->expects(self::never())->method('findWithCredentialById');
        $h['calls']->expects(self::never())->method('withConnectionLock');
        $h['submissions']->expects(self::never())->method('begin');

        $result = $h['service']->submit(1, 501, 101, 7);

        self::assertFalse($result['created']);
        self::assertSame($existing, $result['submission']);
        self::assertSame(0, $connector->submitCalls);
    }

    public function testConnectionWithoutPaymentServiceCreatesNoAttemptAndCallsNoBank(): void
    {
        $connector = new SubmissionConnector();
        $connector->canSubmit = false;
        $h = $this->readyHarness($connector);
        $this->executeCredentialCallback($h['calls']);
        $h['submissions']->method('find')->willReturn(null);
        $h['submissions']->expects(self::never())->method('begin');

        try {
            $h['service']->submit(1, 501, 101, 7);
            self::fail('Napojení bez platební služby nesmí založit pokus o odeslání.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('payment_submission_unavailable', $e->errorCode);
        }
        self::assertSame(0, $connector->submitCalls);
    }

    /** @return array<string,mixed> */
    private function readyHarness(SubmissionConnector $connector): array
    {
        $h = $this->harness($connector);
        $h['connections']->method('findWithCredentialById')->willReturn($this->connection());
        $h['calls']->method('withConnectionLock')->willReturnCallback(
            static fn (int $supplierId, int $currencyId, callable $callback): mixed => $callback(),
        );
        $h['orders']->method('find')->willReturn($this->order());
        $h['orders']->method('allItemsStillPayable')->willReturn(true);
        $h['paymentOrders']->method('download')->willReturn(['bytes' => 'SYNTHETIC-ABO']);
        $h['secrets']->method('validateKey')->willReturn(null);
        $h['secrets']->method('decryptFor')->willReturn('synthetic-token');
        $h['registry']->method('get')->willReturn($connector);
        $h['registry']->method('supportsBankCode')->willReturn(true);
        return $h;
    }

    /** @return array<string,mixed> */
    private function harness(SubmissionConnector $connector): array
    {
        $connections = $this->createMock(BankConnectionRepository::class);
        $submissions = $this->createMock(BankPaymentOrderSubmissionRepository::class);
        $orders = $this->createMock(PaymentOrderRepository::class);
        $paymentOrders = $this->createMock(PaymentOrderService::class);
        $registry = $this->createMock(BankConnectorRegistry::class);
        $calls = $this->createMock(BankConnectorCallGuard::class);
        $secrets = $this->createMock(SecretEncryption::class);

        return [
            'engine' => new BankPaymentSubmissionService($connections, $submissions, $registry, $calls, $secrets),
            'connections' => $connections,
            'submissions' => $submissions,
            'orders' => $orders,
            'paymentOrders' => $paymentOrders,
            'registry' => $registry,
            'calls' => $calls,
            'secrets' => $secrets,
            'service' => new BankPaymentOrderSubmissionService(
                new BankPaymentSubmissionService($connections, $submissions, $registry, $calls, $secrets),
                $orders,
                $paymentOrders,
            ),
            'connector' => $connector,
        ];
    }

    private function executeCredentialCallback(BankConnectorCallGuard&MockObject $calls): void
    {
        $calls->method('call')->willReturnCallback(
            static fn (string $token, callable $callback): mixed => $callback(),
        );
    }

    /** @return array<string,mixed> */
    private function connection(): array
    {
        return [
            'id' => 101,
            'supplier_id' => 1,
            'currency_id' => 11,
            'provider' => 'fio',
            'enabled' => true,
            'has_token' => true,
            'token_ciphertext' => 'enc:v2:synthetic',
            'is_active' => true,
            'account_code' => 'CZK',
            'bank_code' => '2010',
            'account_number' => '1000000005',
            'iban' => null,
            'verified_account_number' => '1000000005',
            'verified_bank_code' => '2010',
            'verified_currency' => 'CZK',
        ];
    }

    /** @return array<string,mixed> */
    private function order(): array
    {
        return [
            'id' => 501,
            'supplier_id' => 1,
            'currency' => 'CZK',
            'payer_currency_id' => 11,
            'payer_account_number' => '1000000005',
            'payer_bank_code' => '2010',
            'mark_paid' => false,
            'payment_date' => date('Y-m-d'),
        ];
    }

    /** @return array<string,mixed> */
    private function submission(
        string $status,
        ?string $errorCode = null,
        ?int $acceptedCount = null,
        ?int $rejectedCount = null,
    ): array {
        return [
            'id' => 901,
            'payment_order_id' => 501,
            'connection_id' => 101,
            'provider' => 'fio',
            'status' => $status,
            'provider_reference' => null,
            'error_code' => $errorCode,
            'accepted_count' => $acceptedCount,
            'rejected_count' => $rejectedCount,
            'created_at' => '2026-09-07 12:00:00',
            'submitted_at' => null,
        ];
    }
}

final class SubmissionConnector implements BankConnector, BankPaymentCapabilityProvider
{
    public int $submitCalls = 0;
    public bool $canSubmit = true;

    public function __construct(private readonly ?\Closure $submit = null) {}

    public function canSubmitPaymentOrder(#[\SensitiveParameter] string $credential): bool
    {
        return $this->canSubmit;
    }

    public function provider(): string
    {
        return 'fio';
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        throw new \LogicException('Statement download nebyl v submit testu očekáván.');
    }

    public function submitPaymentOrder(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $abo,
    ): array {
        $this->submitCalls++;
        return $this->submit !== null
            ? ($this->submit)()
            : ['accepted' => true, 'reference' => 'UNEXPECTED'];
    }
}
