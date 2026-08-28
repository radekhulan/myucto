<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollDocumentQueueArchitectureTest extends TestCase
{
    public function testApprovalPersistsQueueIntentWithoutRenderingPayslips(): void
    {
        $code = file_get_contents(
            dirname(__DIR__, 3)
                . '/api/src/Service/Payroll/Run/PayrollRunCommandService.php',
        );
        self::assertIsString($code);
        $approveAt = strpos($code, 'elseif ($command === PayrollRunCommand::APPROVE)');
        $nextAt = strpos($code, 'elseif ($command === PayrollRunCommand::PREPARE_PAYMENTS)', $approveAt);
        self::assertIsInt($approveAt);
        self::assertIsInt($nextAt);
        $approve = substr($code, $approveAt, $nextAt - $approveAt);

        self::assertStringContainsString('$this->documentQueue?->enqueueApprovedRevision(', $approve);
        self::assertStringNotContainsString('renderPayslip(', $approve);
        self::assertStringNotContainsString('archivePrepared(', $approve);
    }

    public function testQueueMigrationDefinesTenantScopedBatchItemsAndAttempts(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 3)
                . '/db/migrations/1587_payroll_document_generation_queue.sql',
        );
        self::assertIsString($sql);

        foreach ([
            'payroll_document_batches',
            'payroll_document_batch_items',
            'payroll_document_batch_attempts',
            'FOREIGN KEY (supplier_id, batch_id)',
            'FOREIGN KEY (supplier_id, revision_id)',
            'UNIQUE KEY uq_payroll_document_batch_revision',
            'UNIQUE KEY uq_payroll_document_batch_item_employee',
        ] as $required) {
            self::assertStringContainsString($required, $sql);
        }
    }

    public function testBatchEndpointAcknowledgesDurableQueueWithAcceptedStatus(): void
    {
        $code = file_get_contents(
            dirname(__DIR__, 3)
                . '/api/src/Action/Payroll/PayrollDocumentAction.php',
        );
        self::assertIsString($code);
        $methodAt = strpos($code, 'public function generateBatch(');
        $nextAt = strpos($code, 'public function batchDetail(', $methodAt);
        self::assertIsInt($methodAt);
        self::assertIsInt($nextAt);
        $method = substr($code, $methodAt, $nextAt - $methodAt);

        self::assertStringContainsString('enqueueApprovedRevision(', $method);
        self::assertStringContainsString("['batch' => \$report], 202", $method);
    }
}
