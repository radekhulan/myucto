<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Migration\Myucto\MyuctoImportException;
use MyInvoice\Service\Migration\Myucto\MyuctoImportJobService;
use MyInvoice\Service\Migration\Myucto\MyuctoImportWorkflow;
use MyInvoice\Service\Migration\Shared\MigrationCompanyLock;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class MyuctoImportJobServiceTest extends TestCase
{
    private ImportJobRepository $jobs;
    private MyuctoImportWorkflow $workflow;
    private MigrationCompanyLock $lock;
    private SecretEncryption $secrets;
    private MyuctoImportJobService $service;

    protected function setUp(): void
    {
        $this->jobs = $this->createMock(ImportJobRepository::class);
        $this->workflow = $this->createMock(MyuctoImportWorkflow::class);
        $this->lock = $this->createMock(MigrationCompanyLock::class);
        $this->lock->method('acquire')->willReturn(true);
        $this->secrets = new SecretEncryption(new Config(['app' => ['secret_encryption_key' => base64_encode(random_bytes(32))]]));
        $this->service = new MyuctoImportJobService($this->jobs, $this->workflow, $this->secrets, $this->lock, $this->createStub(ActivityLogger::class), $this->createStub(\Psr\Log\LoggerInterface::class));
    }

    public function testQueueOnlyStoresContextBoundCiphertextAndDoesNotRunImporter(): void
    {
        $password = bin2hex(random_bytes(16));
        $token = str_repeat('a', 16);
        $this->jobs->method('listForTenant')->willReturn([]);
        $this->workflow->expects(self::once())->method('validateStart')->with(4, 1, $token, 'synthetic', false, false);
        $this->workflow->expects(self::never())->method('run');
        $this->jobs->expects(self::once())->method('create')->willReturnCallback(function ($supplier, $source, $params, $actor) use ($password, $token): int {
            self::assertSame([4, MyuctoImportJobService::SOURCE, 1], [$supplier, $source, $actor]);
            self::assertArrayNotHasKey('password', $params);
            self::assertStringStartsWith('enc:v2:', $params['password_enc']);
            self::assertSame($password, $this->secrets->decryptFor($params['password_enc'], MyuctoImportJobService::passwordContext(4, $token)));
            try {
                $this->secrets->decryptFor($params['password_enc'], MyuctoImportJobService::passwordContext(5, $token));
                self::fail('Ciphertext was reusable in another company.');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
            return 12;
        });
        $this->jobs->method('find')->willReturn(['source' => MyuctoImportJobService::SOURCE]);
        $this->lock->expects(self::once())->method('release');
        self::assertSame(['job_id' => 12, 'status' => 'queued'], $this->service->start(4, 1, $token, 'synthetic', $password, false, false));
    }

    public function testSecondQueuedRunIsRejectedBeforeCreatingAnotherJob(): void
    {
        $this->jobs->method('listForTenant')->willReturn([['id' => 11, 'status' => 'queued']]);
        $this->jobs->expects(self::never())->method('create');
        $this->expectException(MyuctoImportException::class);
        $this->service->start(4, 1, str_repeat('a', 16), 'synthetic', null, false, false);
    }

    public function testWorkerDeletesSecretBeforeImportAndPersistsProtocol(): void
    {
        $password = bin2hex(random_bytes(16));
        $token = str_repeat('a', 16);
        $job = ['supplier_id' => 4, 'created_by' => 1, 'params' => [
            'token' => $token, 'source_name' => 'synthetic', 'mode' => 'dry_run',
            'password_enc' => $this->secrets->encryptFor($password, MyuctoImportJobService::passwordContext(4, $token)),
        ]];
        $this->jobs->method('findById')->willReturnOnConsecutiveCalls($job, $job + ['status' => 'completed']);
        $this->jobs->method('markRunning')->willReturn(true);
        $removed = false;
        $this->jobs->method('removeParams')->willReturnCallback(static function () use (&$removed): void {
            $removed = true;
        });
        $result = ['report' => ['dry_run' => true, 'created' => ['invoices' => 2], 'existing' => [], 'reused' => []]];
        $this->workflow->expects(self::once())->method('run')->with(4, 1, $token, 'synthetic', $password, false, false)
            ->willReturnCallback(static function () use (&$removed, $result): array {
                self::assertTrue($removed, 'Password was not removed before import.');
                return $result;
            });
        $this->jobs->expects(self::once())->method('setReport')->with(12, $result);
        $this->jobs->expects(self::once())->method('markCompleted')->with(12);
        $this->lock->expects(self::once())->method('release');
        $this->service->run(12);
    }

    public function testPlaintextJobPasswordIsRejectedAndRemoved(): void
    {
        $job = ['supplier_id' => 4, 'created_by' => 1, 'params' => ['token' => str_repeat('a', 16), 'password_enc' => bin2hex(random_bytes(16))]];
        $this->jobs->method('findById')->willReturnOnConsecutiveCalls($job, $job + ['status' => 'failed']);
        $this->jobs->method('markRunning')->willReturn(true);
        $this->workflow->expects(self::never())->method('run');
        $this->jobs->expects(self::atLeastOnce())->method('removeParams')->with(12, ['password_enc']);
        $this->jobs->expects(self::once())->method('markFailed')->with(12, self::stringContains('Heslo exportu'));
        $this->service->run(12);
    }

    public function testUnexpectedDatabaseErrorDoesNotLeakIntoProtocol(): void
    {
        $job = ['supplier_id' => 4, 'created_by' => 1, 'params' => ['token' => str_repeat('a', 16), 'source_name' => 'synthetic']];
        $this->jobs->method('findById')->willReturnOnConsecutiveCalls($job, $job + ['status' => 'failed']);
        $this->jobs->method('markRunning')->willReturn(true);
        $this->workflow->method('run')->willThrowException(new \PDOException('Synthetic sensitive database diagnostic'));
        $this->jobs->expects(self::once())->method('markFailed')->with(12, MyuctoImportJobService::FAILED);
        $this->service->run(12);
    }

    public function testHistoryAndStatusNeverExposeParamsAndRejectForeignActor(): void
    {
        $job = ['id' => 12, 'source' => MyuctoImportJobService::SOURCE, 'created_by' => 1, 'status' => 'queued', 'params' => ['password_enc' => 'redacted', 'token' => str_repeat('a', 16)]];
        $this->jobs->method('listForTenant')->willReturn([$job]);
        $this->jobs->method('find')->willReturn($job);
        $public = $this->service->status(4, 1, 12);
        self::assertArrayNotHasKey('params', $public);
        self::assertStringNotContainsString('redacted', json_encode($this->service->history(4, 1)));
        self::assertSame(['runs' => []], $this->service->history(4, 2));
        $this->expectException(MyuctoImportException::class);
        $this->service->status(4, 2, 12);
    }    public function testRunningWorkerLockPreventsStaleReapingByPolling(): void
    {
        $lock = $this->createStub(MigrationCompanyLock::class);
        $lock->method('acquire')->willReturn(false);
        $service = new MyuctoImportJobService($this->jobs, $this->workflow, $this->secrets, $lock, $this->createStub(ActivityLogger::class), $this->createStub(\Psr\Log\LoggerInterface::class));
        $this->jobs->method('find')->willReturn(['id' => 12, 'source' => MyuctoImportJobService::SOURCE, 'created_by' => 1, 'status' => 'running']);
        $this->jobs->expects(self::never())->method('reapStale');
        self::assertSame('running', $service->status(4, 1, 12)['status']);
    }

    public function testFailedJobDropsPasswordEvenWhenWorkerCannotAcquireCompanyLock(): void
    {
        $lock = $this->createStub(MigrationCompanyLock::class);
        $lock->method('acquire')->willReturn(false);
        $service = new MyuctoImportJobService($this->jobs, $this->workflow, $this->secrets, $lock, $this->createStub(ActivityLogger::class), $this->createStub(\Psr\Log\LoggerInterface::class));
        $job = ['supplier_id' => 4, 'params' => ['password_enc' => 'encrypted']];
        $this->jobs->method('findById')->willReturnOnConsecutiveCalls($job, $job + ['status' => 'failed']);
        $this->jobs->method('markRunning')->willReturn(true);
        $this->workflow->expects(self::never())->method('run');
        $this->jobs->expects(self::once())->method('markFailed');
        $this->jobs->expects(self::once())->method('removeParams')->with(12, ['password_enc']);
        $service->run(12);
    }

}
