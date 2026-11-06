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

    #[DataProvider('foreignItems')]
    public function testForeignOrNonCzkItemIsRejectedBeforeAnyAttemptOrBankCall(array $item): void
    {
        $connector = new SubmissionConnector();
        $h = $this->harness($connector);
        $h['connections']->method('findWithCredentialById')->willReturn($this->connection());
        $h['calls']->method('withConnectionLock')->willReturnCallback(static fn (int $s, int $c, callable $cb): mixed => $cb());
        $h['orders']->method('find')->willReturn(['items' => [$item]] + $this->order());
        $h['orders']->method('allItemsStillPayable')->willReturn(true);
        $h['secrets']->method('validateKey')->willReturn(null);
        $h['secrets']->method('decryptFor')->willReturn('synthetic-token');
        $h['registry']->method('get')->willReturn($connector);
        $h['registry']->method('supportsBankCode')->willReturn(true);
        $h['submissions']->method('find')->willReturn(null);
        $h['submissions']->expects(self::never())->method('begin');
        $h['paymentOrders']->expects(self::never())->method('download');

        try {
            $h['service']->submit(1, 501, 101, 7);
            self::fail('Zahraniční nebo cizoměnová položka nesmí do banky.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('payment_order_not_domestic', $e->errorCode);
        }
        self::assertSame(0, $connector->submitCalls);
    }

    public static function foreignItems(): iterable
    {
        yield 'SEPA jen s IBAN' => [['payee_account_number' => null, 'payee_bank_code' => null, 'payee_iban' => 'DE89370400440532013000', 'currency' => 'CZK']];
        yield 'cizí IBAN u čísla účtu' => [['payee_account_number' => '1000000005', 'payee_bank_code' => '0100', 'payee_iban' => 'SK3112000000198742637541', 'currency' => 'CZK']];
        yield 'EUR položka' => [['payee_account_number' => '1000000005', 'payee_bank_code' => '0100', 'payee_iban' => null, 'currency' => 'EUR']];
    }

    public function testArchivedOrderIsRejectedBeforeAnyAttempt(): void
    {
        $connector = new SubmissionConnector();
        $h = $this->readyHarness($connector);
        $this->executeCredentialCallback($h['calls']);
        $orders = $this->createMock(PaymentOrderRepository::class);
        $orders->method('find')->willReturn(['archived_at' => '2026-10-22 10:00:00'] + $this->order());
        $orders->method('allItemsStillPayable')->willReturn(true);
        $paymentOrders = $this->createMock(PaymentOrderService::class);
        $paymentOrders->expects(self::never())->method('download');
        $service = new BankPaymentOrderSubmissionService($h['engine'], $orders, $paymentOrders);
        $h['submissions']->method('find')->willReturn(null);
        $h['submissions']->expects(self::never())->method('begin');

        try {
            $service->submit(1, 501, 101, 7);
            self::fail('Archivovaný příkaz nesmí do banky.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('payment_order_no_longer_payable', $e->errorCode);
        }
        self::assertSame(0, $connector->submitCalls);
    }

    public function testInterruptedSubmissionOfArchivedOrderIsClosedWithoutBankCall(): void
    {
        $connector = new StagedSubmissionConnector();
        $h = $this->readyHarness(new SubmissionConnector());
        $h['registry'] = $this->createMock(BankConnectorRegistry::class);
        $h['registry']->method('get')->willReturn($connector);
        $h['registry']->method('supportsBankCode')->willReturn(true);
        $engine = new BankPaymentSubmissionService($h['connections'], $h['submissions'], $h['registry'], $h['calls'], $h['secrets']);
        $orders = $this->createMock(PaymentOrderRepository::class);
        $orders->method('find')->willReturn(['archived_at' => '2026-10-22 10:00:00'] + $this->order());
        $service = new BankPaymentOrderSubmissionService($engine, $orders, $this->createMock(PaymentOrderService::class));
        $h['submissions']->method('find')->willReturn($this->submission('creating'));
        $h['submissions']->expects(self::once())->method('fail')->with(1, 901, 'unknown', 'payment_order_no_longer_payable');

        try {
            $service->submit(1, 501, 101, 7);
            self::fail('Přerušené odeslání archivovaného příkazu se nesmí dokončit.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('payment_order_no_longer_payable', $e->errorCode);
        }
        self::assertSame(0, $connector->calls);
    }

    #[DataProvider('transientResumeCodes')]
    public function testTransientPreflightFailureOnResumeKeepsCreating(string $code): void
    {
        $connector = new StagedSubmissionConnector();
        $h = $this->readyHarness(new SubmissionConnector());
        $h['registry'] = $this->createMock(BankConnectorRegistry::class);
        $h['registry']->method('get')->willReturn($connector);
        $h['registry']->method('supportsBankCode')->willReturn(true);
        $connector->capabilityFailure = $code === 'submission_failed';
        $secrets = $this->createMock(SecretEncryption::class);
        $secrets->method('validateKey')->willReturn($code === 'encryption_key_unavailable' ? 'missing' : null);
        $secrets->method('decryptFor')->willReturn('synthetic-token');
        $engine = new BankPaymentSubmissionService($h['connections'], $h['submissions'], $h['registry'], $h['calls'], $secrets);
        $service = new BankPaymentOrderSubmissionService($engine, $h['orders'], $h['paymentOrders']);
        $h['submissions']->method('find')->willReturn($this->submission('creating'));
        $h['submissions']->expects(self::never())->method('fail');

        try {
            $service->submit(1, 501, 101, 7);
            self::fail('Přechodná chyba se vrací uživateli.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame($code, $e->errorCode);
        }
        self::assertSame(0, $connector->calls);
    }

    public static function transientResumeCodes(): iterable
    {
        yield 'šifrovací klíč' => ['encryption_key_unavailable'];
        yield 'ověření schopnosti napojení' => ['submission_failed'];
    }

    public function testInvalidAboBecomesValidationErrorInsteadOfServerError(): void
    {
        $connector = new SubmissionConnector();
        $h = $this->harness($connector);
        $h['connections']->method('findWithCredentialById')->willReturn($this->connection());
        $h['calls']->method('withConnectionLock')->willReturnCallback(static fn (int $s, int $c, callable $cb): mixed => $cb());
        $h['orders']->method('find')->willReturn($this->order());
        $h['orders']->method('allItemsStillPayable')->willReturn(true);
        $h['paymentOrders']->method('download')->willThrowException(new \RuntimeException('ABO/KPC export je možný jen pro CZK příkazy.'));
        $h['secrets']->method('validateKey')->willReturn(null);
        $h['secrets']->method('decryptFor')->willReturn('synthetic-token');
        $h['registry']->method('get')->willReturn($connector);
        $h['registry']->method('supportsBankCode')->willReturn(true);
        $h['submissions']->method('find')->willReturn(null);
        $h['submissions']->expects(self::never())->method('begin');

        try {
            $h['service']->submit(1, 501, 101, 7);
            self::fail('Neplatné ABO musí skončit validační chybou.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('payment_order_invalid', $e->errorCode);
        }
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
            'items' => [['payee_account_number' => '1000000005', 'payee_bank_code' => '0100', 'payee_iban' => null, 'currency' => 'CZK']],
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

final class StagedSubmissionConnector implements \MyInvoice\Service\Bank\Connector\StagedBankPaymentConnector, BankPaymentCapabilityProvider
{
    public int $calls = 0;
    public bool $capabilityFailure = false;

    public function provider(): string { return 'csas'; }

    public function canSubmitPaymentOrder(#[\SensitiveParameter] string $credential): bool
    {
        if ($this->capabilityFailure) throw new BankConnectorException(BankConnectorException::REMOTE_UNAVAILABLE, 'synthetic');
        return true;
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        throw new \LogicException('Výpis se v testu odeslání nestahuje.');
    }

    public function assertPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): void {}

    public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
    {
        $this->calls++;
        throw new \LogicException('Bez evidence se neodesílá.');
    }

    public function submitStagedPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo,
        \MyInvoice\Service\Bank\Connector\BankPaymentSubmissionJournal $journal): array
    {
        $this->calls++;
        throw new \LogicException('Pokračování nemělo dojít k bance.');
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
