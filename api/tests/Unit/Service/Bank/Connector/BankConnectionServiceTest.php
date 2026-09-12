<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\BankConnectionService;
use MyInvoice\Service\Bank\Connector\BankConnector;
use MyInvoice\Service\Bank\Connector\BankConnectorCallGuard;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankConnectorRegistry;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementReconciliationException;
use MyInvoice\Service\Bank\StatementImporter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class BankConnectionServiceTest extends TestCase
{
    public function testConfigurePreservesCredentialRotatedDuringValidation(): void
    {
        $connection = array_replace($this->connection(), [
            'provider' => 'kb_plus',
            'bank_code' => '0100',
            'verified_bank_code' => '0100',
            'token_ciphertext' => 'enc:v2:before-refresh',
        ]);
        $rotated = array_replace($connection, ['token_ciphertext' => 'enc:v2:after-refresh']);
        $connector = new RotatingCredentialConnector();
        $connections = $this->createMock(BankConnectionRepository::class);
        $connections->expects(self::once())->method('ensure')->with(1, 11, 'kb_plus')->willReturn(101);
        $connections->expects(self::once())->method('findWithCredentialByCurrency')->with(1, 11)->willReturn($connection);
        $connections->expects(self::once())->method('findWithCredentialById')->with(1, 101)->willReturn($rotated);
        $connections->expects(self::once())->method('saveValidated')
            ->with(1, 101, 'kb_plus', 'enc:v2:after-refresh', true, '1000000005', '0100', 'CZK');
        $connections->method('findPublicByCurrency')->willReturn(['id' => 101, 'provider' => 'kb_plus']);
        $registry = $this->createMock(BankConnectorRegistry::class);
        $registry->method('supportsBankCode')->willReturn(true);
        $registry->expects(self::once())->method('get')->with('kb_plus')->willReturn($connector);
        $calls = $this->createMock(BankConnectorCallGuard::class);
        $calls->method('withConnectionLock')->willReturnCallback(
            static fn (int $supplierId, int $currencyId, callable $callback): mixed => $callback(),
        );
        $calls->method('call')->willReturnCallback(
            static fn (string $credential, callable $callback): mixed => $callback(),
        );
        $secrets = $this->createMock(SecretEncryption::class);
        $secrets->method('validateKey')->willReturn(null);
        $secrets->expects(self::once())->method('decryptFor')->with('enc:v2:before-refresh')->willReturn('serialized-old-credential');
        $secrets->expects(self::never())->method('encryptFor');
        $parser = $this->createMock(GpcParser::class);
        $parser->method('parse')->willReturn([
            'header' => ['account_number' => '1000000005'],
            'transactions' => [],
        ]);
        $importer = new RecordingStatementImporter(static fn (): array => self::importResult());
        $service = new BankConnectionService($connections, $registry, $calls, $secrets, $parser, $importer);

        $result = $service->configure(1, 11, ['provider' => 'kb_plus', 'enabled' => true]);

        self::assertSame(['id' => 101, 'provider' => 'kb_plus'], $result);
        self::assertSame(1, $connector->downloadCalls);
    }

    public function testSameFioConnectorSupportsBothNationalBankCodes(): void
    {
        $connector = new SyncConnector();
        $registry = new BankConnectorRegistry([$connector]);
        self::assertTrue($registry->supportsBankCode('fio', '2010'));
        self::assertTrue($registry->supportsBankCode('fio', '8330'));
        self::assertFalse($registry->supportsBankCode('fio', '0100'));
        self::assertSame($connector, $registry->get('fio'));
    }

    public function testSlovakIbanOnlyConnectionCanSynchronize(): void
    {
        $connection = array_replace($this->connection(), [
            'account_number' => null, 'iban' => 'SK0383300000001000000005',
            'bank_code' => '8330', 'verified_bank_code' => '8330',
            'account_code' => 'EUR', 'verified_currency' => 'EUR',
        ]);
        $connector = new SyncConnector();
        $importer = new RecordingStatementImporter(static fn (): array => self::importResult());
        $h = $this->harness($connector, $importer, $connection);
        self::assertSame('success', $h['service']->sync(1, 11)['status']);
        self::assertSame(1, $importer->calls);
    }

    public function testConfigurationRejectsForeignAccountWithoutSavingCredential(): void
    {
        $connector = new SyncConnector();
        $importer = new RecordingStatementImporter(static fn (): array => self::importResult());
        $h = $this->harness($connector, $importer, $this->connection(), '2000000018');
        $h['connections']->method('ensure')->willReturn(101);
        $h['connections']->expects(self::never())->method('saveValidated');
        $h['secrets']->expects(self::never())->method('encryptFor');
        $this->expectException(BankConnectorOperationException::class);
        $this->expectExceptionMessage('statement_account_mismatch');
        $h['service']->configure(1, 11, ['provider' => 'fio', 'token' => str_repeat('a', 64)]);
    }

    public function testDisableNeedsNeitherBankAccessNorUsableEncryptionKey(): void
    {
        $connector = new SyncConnector();
        $importer = new RecordingStatementImporter(static fn (): array => self::importResult());
        $h = $this->harness($connector, $importer, $this->connection());
        $h['connections']->method('ensure')->willReturn(101);
        $h['connections']->method('findPublicByCurrency')->willReturn(['enabled' => false]);
        $h['connections']->expects(self::once())->method('setEnabled')->with(1, 101, false);
        $h['secrets']->expects(self::never())->method('validateKey');
        $h['secrets']->expects(self::never())->method('decryptFor');
        self::assertSame(['enabled' => false], $h['service']->configure(1, 11, ['provider' => 'fio', 'enabled' => false]));
        self::assertSame(0, $connector->downloadCalls);
    }

    public function testConfigurationBindsEncryptedCredentialToSupplierAndConnection(): void
    {
        $connector = new SyncConnector();
        $importer = new RecordingStatementImporter(static fn (): array => self::importResult());
        $h = $this->harness($connector, $importer, $this->connection());
        $h['connections']->method('ensure')->willReturn(101);
        $h['connections']->method('findPublicByCurrency')->willReturn(['has_token' => true]);
        $token = str_repeat('a', 64);
        $h['secrets']->expects(self::once())->method('encryptFor')
            ->with($token, 'bank-connection:supplier:1:connection:101:type:token')
            ->willReturn('enc:v2:synthetic-ciphertext');
        $h['connections']->expects(self::once())->method('saveValidated')
            ->with(1, 101, 'fio', 'enc:v2:synthetic-ciphertext', true, '1000000005', '2010', 'CZK');
        self::assertSame(['has_token' => true], $h['service']->configure(1, 11, ['provider' => 'fio', 'token' => $token]));
        self::assertSame(1, $connector->downloadCalls);
        self::assertSame(0, $importer->calls);
    }

    public function testAutomaticSyncUsesOverlapCursorAndAdvancesWatermarkOnlyAfterImport(): void
    {
        $events = [];
        $today = new \DateTimeImmutable('today');
        $watermark = $today->modify('-1 day')->format('Y-m-d');
        $expectedFrom = $today->modify('-4 days')->format('Y-m-d');
        $connector = new SyncConnector(static function (string $from, string $to) use (
            &$events,
            $expectedFrom,
            $today,
        ): string {
            $events[] = 'download';
            self::assertSame($expectedFrom, $from);
            self::assertSame($today->format('Y-m-d'), $to);
            return 'SYNTHETIC-GPC';
        });
        $importer = new RecordingStatementImporter(static function () use (&$events): array {
            $events[] = 'import';
            return self::importResult();
        });
        $h = $this->harness($connector, $importer, $this->connection($watermark));
        $h['connections']->expects(self::once())->method('recordSyncSuccess')
            ->with(1, 101, $today->format('Y-m-d'))
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'checkpoint';
            });

        $result = $h['service']->sync(1, 11, userId: 7);

        self::assertSame(['download', 'import', 'checkpoint'], $events);
        self::assertSame(['from' => $expectedFrom, 'to' => $today->format('Y-m-d')], $result['period']);
        self::assertSame(801, $result['imported_statement_id']);
    }

    public function testConnectorErrorRecordsSafeCodeAndDoesNotImportOrAdvanceCursor(): void
    {
        $connector = new SyncConnector(static function (): string {
            throw new BankConnectorException('remote_unavailable', 'safe');
        });
        $importer = new RecordingStatementImporter(static function (): array {
            self::fail('Importer se po chybě konektoru nesmí spustit.');
        });
        $h = $this->harness($connector, $importer, $this->connection());
        $h['connections']->expects(self::never())->method('recordSyncSuccess');
        $h['connections']->expects(self::once())->method('recordSyncError')
            ->with(1, 101, 'remote_unavailable');

        try {
            $h['service']->sync(1, 11);
            self::fail('Chyba konektoru se musí propagovat jako bezpečný aplikační kód.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('remote_unavailable', $e->errorCode);
        }
        self::assertSame(1, $connector->downloadCalls);
        self::assertSame(0, $importer->calls);
    }

    /**
     * KB ADAA vrací 429 při opakovaném stažení nezměněných dat dřív než za
     * 61 minut. Cron běží po 30 minutách, takže spojení s takovým odstupem
     * se automaticky nevolá, dokud neuplyne.
     */
    public function testAutomaticSyncSkipsConnectionInsideBankMinimumInterval(): void
    {
        $connector = new PacedSyncConnector(3660);
        $importer = new RecordingStatementImporter(static fn (): array => self::importResult());
        $h = $this->harness($connector, $importer, $this->connection());
        $h['connections']->method('enabledWithCredentials')->willReturn([
            array_replace($this->connection(), ['provider' => 'kb_plus', 'seconds_since_last_sync' => 1800]),
        ]);
        $h['connections']->expects(self::never())->method('recordSyncError');

        $summary = $h['service']->syncAll();

        self::assertSame(0, $connector->downloadCalls);
        self::assertSame(1, $summary['skipped']);
        self::assertSame(0, $summary['errors']);
    }

    public function testAutomaticSyncRunsOnceBankMinimumIntervalElapsed(): void
    {
        $connector = new PacedSyncConnector(3660);
        $importer = new RecordingStatementImporter(static fn (): array => self::importResult());
        $h = $this->harness($connector, $importer, $this->connection());
        $h['connections']->method('enabledWithCredentials')->willReturn([
            array_replace($this->connection(), ['provider' => 'kb_plus', 'seconds_since_last_sync' => 4000]),
        ]);

        $summary = $h['service']->syncAll();

        self::assertSame(1, $connector->downloadCalls);
        self::assertSame(1, $summary['succeeded']);
        self::assertSame(0, $summary['skipped']);
    }

    /**
     * Omezení četnosti banky (429) není vada spojení: stav spojení se nemění
     * a cron ho počítá jako přeskočené, ne jako chybu.
     */
    public function testBankRateLimitIsNeitherConnectionErrorNorCronError(): void
    {
        $connector = new SyncConnector(static function (): string {
            throw new BankConnectorException(BankConnectorException::RATE_LIMITED, 'safe');
        });
        $importer = new RecordingStatementImporter(static fn (): array => self::importResult());
        $h = $this->harness($connector, $importer, $this->connection());
        $h['connections']->method('enabledWithCredentials')->willReturn([$this->connection()]);
        $h['connections']->expects(self::never())->method('recordSyncError');

        $summary = $h['service']->syncAll();

        self::assertSame(1, $summary['skipped']);
        self::assertSame(0, $summary['errors']);
    }

    public function testAmbiguousStatementDuplicateRequiresManualReconciliation(): void
    {
        $connector = new SyncConnector();
        $importer = new RecordingStatementImporter(
            static fn (): array => throw new StatementReconciliationException([[
                'confirmation_key' => str_repeat('a', 64),
                'posted_at' => '2026-01-12',
                'amount' => '1250.00',
                'currency' => 'CZK',
                'existing_transaction_id' => 901,
                'existing_statement_id' => 801,
            ]]),
        );
        $h = $this->harness($connector, $importer, $this->connection());
        $h['connections']->expects(self::never())->method('recordSyncSuccess');
        $h['connections']->expects(self::once())->method('recordSyncError')
            ->with(1, 101, StatementReconciliationException::ERROR_CODE);

        try {
            $h['service']->sync(1, 11);
            self::fail('Nejednoznačná duplicita musí zastavit synchronizaci bezpečným kódem.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame(StatementReconciliationException::ERROR_CODE, $e->errorCode);
            self::assertSame(str_repeat('a', 64), $e->details['reconciliation_candidates'][0]['confirmation_key']);
        }
        self::assertSame(1, $importer->calls);
    }

    public function testConfirmedIdentitiesReachImporterBeforeCursorAdvances(): void
    {
        $keys = [str_repeat('a', 64)];
        $importer = new RecordingStatementImporter(
            static function (string $content, string $fileName, ?int $userId, int $currencyId, int $supplierId, array $confirmations) use ($keys): array {
                self::assertSame($keys, $confirmations);
                self::assertSame(7, $userId);
                return self::importResult();
            },
        );
        $h = $this->harness(new SyncConnector(), $importer, $this->connection());
        $h['connections']->expects(self::once())->method('recordSyncSuccess')->with(1, 101, date('Y-m-d'));
        $result = $h['service']->sync(1, 11, userId: 7, reconciliationConfirmations: $keys);
        self::assertSame('success', $result['status']);
        self::assertSame(1, $importer->calls);
    }

    public function testNarrowManualPeriodImportsButDoesNotSkipInitialCursor(): void
    {
        $today = date('Y-m-d');
        $connector = new SyncConnector(static function (string $from, string $to) use ($today): string {
            self::assertSame($today, $from);
            self::assertSame($today, $to);
            return 'SYNTHETIC-GPC';
        });
        $importer = new RecordingStatementImporter(static fn (): array => self::importResult());
        $h = $this->harness($connector, $importer, $this->connection());
        $h['connections']->expects(self::once())->method('recordSyncSuccess')->with(1, 101, null);

        $result = $h['service']->sync(1, 11, $today, $today, 7);

        self::assertSame(['from' => $today, 'to' => $today], $result['period']);
        self::assertSame(1, $importer->calls);
    }

    public function testManualPeriodLongerThan31InclusiveDaysIsRejectedBeforeDownload(): void
    {
        $today = new \DateTimeImmutable('today');
        $connector = new SyncConnector();
        $importer = new RecordingStatementImporter(static fn (): array => self::importResult());
        $h = $this->harness($connector, $importer, $this->connection());
        $h['connections']->expects(self::once())->method('recordSyncError')->with(1, 101, 'period_invalid');

        try {
            $h['service']->sync(
                1,
                11,
                $today->modify('-31 days')->format('Y-m-d'),
                $today->format('Y-m-d'),
            );
            self::fail('Interval 32 kalendářních dní musí být odmítnut.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('period_invalid', $e->errorCode);
        }
        self::assertSame(0, $connector->downloadCalls);
        self::assertSame(0, $importer->calls);
    }

    public function testStatementForDifferentAccountNeverReachesImporterOrCursor(): void
    {
        $connector = new SyncConnector(static fn (): string => 'SYNTHETIC-GPC');
        $importer = new RecordingStatementImporter(static function (): array {
            self::fail('Cizí účet se nesmí předat importeru.');
        });
        $h = $this->harness($connector, $importer, $this->connection(), '2000000018');
        $h['connections']->expects(self::never())->method('recordSyncSuccess');
        $h['connections']->expects(self::once())->method('recordSyncError')
            ->with(1, 101, 'statement_account_mismatch');

        try {
            $h['service']->sync(1, 11);
            self::fail('Výpis cizího účtu musí být odmítnut.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('statement_account_mismatch', $e->errorCode);
        }
        self::assertSame(0, $importer->calls);
    }

    /** @return array<string,mixed> */
    private function harness(
        BankConnector $connector,
        RecordingStatementImporter $importer,
        array $connection,
        string $statementAccount = '1000000005',
    ): array {
        $connections = $this->createMock(BankConnectionRepository::class);
        $connections->method('findWithCredentialByCurrency')->willReturn($connection);
        $registry = $this->createMock(BankConnectorRegistry::class);
        $registry->method('supportsBankCode')->willReturn(true);
        $registry->method('get')->willReturn($connector);
        $calls = $this->createMock(BankConnectorCallGuard::class);
        $calls->method('withConnectionLock')->willReturnCallback(
            static fn (int $supplierId, int $currencyId, callable $callback): mixed => $callback(),
        );
        $calls->method('call')->willReturnCallback(
            static fn (string $token, callable $callback): mixed => $callback(),
        );
        $secrets = $this->createMock(SecretEncryption::class);
        $secrets->method('validateKey')->willReturn(null);
        $secrets->method('decryptFor')->willReturn('synthetic-token');
        $parser = $this->createMock(GpcParser::class);
        $parser->method('parse')->willReturn([
            'header' => ['account_number' => $statementAccount],
            'transactions' => [],
        ]);

        return [
            'connections' => $connections,
            'secrets' => $secrets,
            'service' => new BankConnectionService(
                $connections,
                $registry,
                $calls,
                $secrets,
                $parser,
                $importer,
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function connection(?string $watermark = null): array
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
            'sync_watermark_date' => $watermark,
        ];
    }

    /** @return array<string,mixed> */
    public static function importResult(): array
    {
        return [
            'statement_id' => 801,
            'transactions' => 2,
            'matched' => 1,
            'duplicate' => false,
            'parsed_transactions' => 2,
            'skipped_duplicates' => 0,
            'warnings' => [],
        ];
    }
}

final class SyncConnector implements BankConnector
{
    public int $downloadCalls = 0;

    public function __construct(private readonly ?\Closure $download = null) {}

    public function provider(): string
    {
        return 'fio';
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        $this->downloadCalls++;
        return $this->download !== null ? ($this->download)($from, $to) : 'SYNTHETIC-GPC';
    }

    public function submitPaymentOrder(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $abo,
    ): array {
        throw new \LogicException('Payment submit nebyl v sync testu očekáván.');
    }
}

final class PacedSyncConnector implements BankConnector, \MyInvoice\Service\Bank\Connector\BankConnectorSyncPacing
{
    public int $downloadCalls = 0;

    public function __construct(private readonly int $intervalSeconds) {}

    public function provider(): string
    {
        return 'kb_plus';
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        $this->downloadCalls++;
        return 'SYNTHETIC-GPC';
    }

    public function submitPaymentOrder(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $abo,
    ): array {
        throw new \LogicException('Payment submit nebyl v sync testu očekáván.');
    }

    public function minimumAutomaticSyncIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }
}

final class RotatingCredentialConnector implements BankConnector, \MyInvoice\Service\Bank\Connector\BankConnectorCredentialKeyProvider
{
    public int $downloadCalls = 0;

    public function provider(): string
    {
        return 'kb_plus';
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        $this->downloadCalls++;
        return 'SYNTHETIC-GPC';
    }

    public function submitPaymentOrder(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $abo,
    ): array {
        throw new \LogicException('Payment submit nebyl v configure testu očekáván.');
    }

    public function callGuardCredential(#[\SensitiveParameter] string $credential): string
    {
        return 'synthetic-stable-call-guard-key';
    }
}

final class RecordingStatementImporter extends StatementImporter
{
    public int $calls = 0;

    public function __construct(private readonly \Closure $import) {}

    public function importConnected(
        string $content,
        string $fileName,
        ?int $userId,
        int $currencyId,
        int $supplierId,
        array $reconciliationConfirmations = [],
    ): array {
        $this->calls++;
        return ($this->import)($content, $fileName, $userId, $currencyId, $supplierId, $reconciliationConfirmations);
    }
}
