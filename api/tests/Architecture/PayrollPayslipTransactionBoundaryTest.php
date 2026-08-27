<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollPayslipTransactionBoundaryTest extends TestCase
{
    public function testApprovalPersistsQueueIntentInsideCommandTransactionWithoutRendering(): void
    {
        $commandService = file_get_contents(
            dirname(__DIR__, 3)
                . '/api/src/Service/Payroll/Run/PayrollRunCommandService.php',
        );
        self::assertIsString($commandService);
        $executeAt = strpos($commandService, 'private function execute(');
        $nextMethodAt = strpos($commandService, 'private function replay(', $executeAt);
        self::assertIsInt($executeAt);
        self::assertIsInt($nextMethodAt);
        $execute = substr($commandService, $executeAt, $nextMethodAt - $executeAt);

        $transactionAt = strpos($execute, '$pdo->beginTransaction();');
        $queueAt = strpos($execute, '$this->documentQueue?->enqueueApprovedRevision(');
        $commitAt = strpos($execute, '$this->finishCommandTransaction(', $queueAt);
        self::assertIsInt($transactionAt);
        self::assertIsInt($queueAt);
        self::assertIsInt($commitAt);
        self::assertGreaterThan(
            $transactionAt,
            $queueAt,
            'Schválení musí zapsat záměr fronty uvnitř své transakce.',
        );
        self::assertLessThan(
            $commitAt,
            $queueAt,
            'Fronta musí být trvale zapsaná před potvrzením schvalovací transakce.',
        );
        self::assertStringNotContainsString('$this->approvedPayslips->prepare(', $execute);
        self::assertStringNotContainsString('$this->approvedPayslips->archivePrepared(', $execute);

        $queueService = file_get_contents(
            dirname(__DIR__, 3)
                . '/api/src/Service/Payroll/Document/PayrollDocumentBatchQueueService.php',
        );
        self::assertIsString($queueService);
        $workerAt = strpos($queueService, 'public function processOne()');
        self::assertIsInt($workerAt);
        $worker = substr($queueService, $workerAt);
        self::assertStringContainsString('$this->payslips->generateEmployee(', $worker);
    }
}
