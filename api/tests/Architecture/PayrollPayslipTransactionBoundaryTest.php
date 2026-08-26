<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollPayslipTransactionBoundaryTest extends TestCase
{
    public function testApprovalRendersBeforeAndArchivesInsideCommandTransaction(): void
    {
        $code = file_get_contents(
            dirname(__DIR__, 3)
                . '/api/src/Service/Payroll/Run/PayrollRunCommandService.php',
        );
        self::assertIsString($code);
        $executeAt = strpos($code, 'private function execute(');
        $nextMethodAt = strpos($code, 'private function replay(', $executeAt);
        self::assertIsInt($executeAt);
        self::assertIsInt($nextMethodAt);
        $execute = substr($code, $executeAt, $nextMethodAt - $executeAt);

        $prepareAt = strpos($execute, '$this->approvedPayslips->prepare(');
        $transactionAt = strpos($execute, '$pdo->beginTransaction();');
        $archiveAt = strpos($execute, '$this->approvedPayslips->archivePrepared(');
        self::assertIsInt($prepareAt);
        self::assertIsInt($transactionAt);
        self::assertIsInt($archiveAt);
        self::assertLessThan(
            $transactionAt,
            $prepareAt,
            'PDF výplatních pásek se musí připravit před schvalovací transakcí.',
        );
        self::assertGreaterThan(
            $transactionAt,
            $archiveAt,
            'Archivace výplatních pásek musí zůstat uvnitř schvalovací transakce.',
        );
    }
}
