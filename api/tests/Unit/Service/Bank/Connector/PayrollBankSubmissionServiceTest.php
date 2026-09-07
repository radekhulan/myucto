<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\Payroll\PayrollBankSubmissionSourceRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankConnectorRegistry;
use MyInvoice\Service\Bank\Connector\BankPaymentSubmissionService;
use MyInvoice\Service\Payroll\Payment\PayrollBankSubmissionService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentExportService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentExportStorage;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class PayrollBankSubmissionServiceTest extends TestCase
{
    public function testSendsVerifiedArchivedAboThroughSharedEngine(): void
    {
        [$service, $sources, $engine, $exports, $storage] = $this->harness();
        $sources->expects(self::exactly(2))->method('hasSettlement')->with(1, 17)->willReturn(false);
        $exports->expects(self::once())->method('export')->with(1, 17, 'bank-submission:17', 8, null, 'abo')
            ->willReturn(['storage_key' => 'synthetic', 'source_snapshot_hash' => $this->batch()['snapshot_hash'],
                'file_sha256' => hash('sha256', 'SYNTHETIC-ABO'), 'size_bytes' => 13]);
        $storage->expects(self::once())->method('readVerified')->with(1, 'synthetic')->willReturn('SYNTHETIC-ABO');
        $engine->expects(self::once())->method('submit')->willReturnCallback(
            function (int $supplier, int $batch, int $connection, ?int $user, callable $prepare, string $source): array {
                self::assertSame([1, 17, 3, 8, 'payroll'], [$supplier, $batch, $connection, $user, $source]);
                self::assertSame('SYNTHETIC-ABO', $prepare($this->connection()));
                return ['created' => true, 'submission' => ['status' => 'accepted_awaiting_authorization']];
            });
        self::assertTrue($service->submit(1, 17, 3, 8)['created']);
    }

    public function testAlreadySettledBatchCannotReachExport(): void
    {
        [$service, $sources, $engine, $exports] = $this->harness();
        $sources->method('hasSettlement')->willReturn(true);
        $exports->expects(self::never())->method('export');
        $engine->method('submit')->willReturnCallback(fn ($s, $b, $c, $u, $prepare) => $prepare($this->connection()));
        $this->expectExceptionMessage('payment_order_no_longer_payable');
        $service->submit(1, 17, 3, 8);
    }

    public function testDifferentPayerCannotReachExport(): void
    {
        [$service, $sources, $engine, $exports] = $this->harness();
        $sources->method('hasSettlement')->willReturn(false);
        $exports->expects(self::never())->method('export');
        $engine->method('submit')->willReturnCallback(fn ($s, $b, $c, $u, $prepare) => $prepare(
            array_replace($this->connection(), ['bank_code' => '0300'])));
        $this->expectExceptionMessage('payment_order_account_mismatch');
        $service->submit(1, 17, 3, 8);
    }

    public function testPastDatedBatchIsBlocked(): void
    {
        [$service] = $this->harness(['planned_payment_date' => '2000-01-01']);
        self::assertSame('payment_order_date_in_past', $service->overview(1, 17)['blocked_reason']);
    }

    public function testSepaBatchIsBlocked(): void
    {
        [$service] = $this->harness(['export_format' => 'sepa', 'currency_code' => 'EUR']);
        self::assertSame('payment_order_unsupported', $service->overview(1, 17)['blocked_reason']);
    }

    public function testCorruptSnapshotDoesNotExposeOrUsePayer(): void
    {
        [$service, $sources] = $this->harness(['snapshot_hash' => str_repeat('0', 64)]);
        $sources->method('hasSettlement')->willReturn(false);
        $result = $service->overview(1, 17);
        self::assertSame('payment_export_invalid', $result['blocked_reason']);
        self::assertSame([], $result['connections']);
    }

    private function harness(array $overrides = []): array
    {
        $sources = $this->createMock(PayrollBankSubmissionSourceRepository::class);
        $sources->method('find')->willReturn(array_replace($this->batch(), $overrides));
        $engine = $this->createMock(BankPaymentSubmissionService::class);
        $connections = $this->createMock(BankConnectionRepository::class);
        $registry = new BankConnectorRegistry([]);
        $secrets = $this->createMock(SecretEncryption::class);
        $secrets->method('decryptFor')->willReturn($this->snapshot());
        $exports = $this->createMock(PayrollPaymentExportService::class);
        $storage = $this->createMock(PayrollPaymentExportStorage::class);
        return [new PayrollBankSubmissionService($sources, $engine, $connections, $registry, $secrets, $exports, $storage),
            $sources, $engine, $exports, $storage];
    }

    private function connection(): array
    {
        return ['account_number' => '1000000005', 'bank_code' => '2010', 'iban' => null, 'account_code' => 'CZK'];
    }

    private function snapshot(): string
    {
        return CanonicalJson::encode(['payer_instruction' => ['account_number' => '1000000005', 'bank_code' => '2010']]);
    }

    private function batch(): array
    {
        return ['id' => 17, 'batch_reference' => 'synthetic-batch', 'channel' => 'bank', 'direction' => 'outgoing',
            'export_format' => 'abo', 'currency_code' => 'CZK', 'declared_total_minor' => 10000, 'declared_item_count' => 1,
            'planned_payment_date' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
            'snapshot_ciphertext' => 'synthetic-encrypted', 'snapshot_hash' => hash('sha256', $this->snapshot())];
    }
}
