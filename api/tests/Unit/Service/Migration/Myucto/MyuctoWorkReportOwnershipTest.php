<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use MyInvoice\Service\Migration\Myucto\MyuctoImporter;
use MyInvoice\Service\Migration\Myucto\MyuctoRowStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class MyuctoWorkReportOwnershipTest extends TestCase
{
    public function testWorkReportWithNullableProjectIsOwnedByInvoice(): void
    {
        $this->validate(11);
        $this->addToAssertionCount(1);
    }

    public function testWorkReportCannotReferToInvoiceOutsideSelectedCompany(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Graf nemá zdrojového rodiče: work_reports.');
        $this->validate(12);
    }

    private function validate(int $invoiceId): void
    {
        $reflection = new ReflectionClass(MyuctoImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $tables = [
            'invoices' => [11 => ['id' => 11, 'supplier_id' => 1, 'prices_include_vat' => 1]],
            'work_reports' => [21 => ['id' => 21, 'invoice_id' => $invoiceId, 'project_id' => null]],
        ];
        $schema = ['columns' => [
            'invoices' => array_fill_keys(['id', 'supplier_id', 'prices_include_vat'], []),
            'work_reports' => array_fill_keys(['id', 'invoice_id', 'project_id'], []),
        ], 'foreignKeyRows' => [
            ['TABLE_NAME' => 'work_reports', 'COLUMN_NAME' => 'invoice_id',
                'REFERENCED_TABLE_NAME' => 'invoices', 'REFERENCED_COLUMN_NAME' => 'id'],
            ['TABLE_NAME' => 'work_reports', 'COLUMN_NAME' => 'project_id',
                'REFERENCED_TABLE_NAME' => 'projects', 'REFERENCED_COLUMN_NAME' => 'id'],
        ]];
        $reflection->getMethod('validateRows')->invoke($importer, MyuctoRowStore::fromArrays($tables), 1, $schema);
    }
}
