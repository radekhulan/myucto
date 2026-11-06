<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyUctoRegression\Comparator;
use MyUctoRegression\Safety;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';

final class RegressionComparatorTest extends TestCase
{
    public function testDecimalFormattingDoesNotLoseLargeIntegerPrecision(): void
    {
        self::assertTrue(Comparator::values(['amount' => '12345678901234567890.120000'], ['amount' => '12345678901234567890.12'])['equal']);
        self::assertFalse(Comparator::values(['amount' => '12345678901234567890.12'], ['amount' => '12345678901234567890.13'])['equal']);
        self::assertSame('0.00000001', Comparator::decimal('1e-8'));
        self::assertSame('0', Comparator::decimal('-0.000'));
        self::assertFalse(Comparator::values(['amount' => '0.000000000001'], ['amount' => '0'])['equal']);
    }

    public function testScientificLookingDocumentNumberDoesNotEnterDecimalParser(): void
    {
        $result = Comparator::values(['original_doc_number' => '5E20990101', 'amount' => '12.00'], ['original_doc_number' => '5E20990101', 'amount' => 12]);
        self::assertTrue($result['equal']);
        self::assertSame(1, $result['numeric_count']);
    }

    public function testIdentifierLeadingZeroesRemainSignificant(): void
    {
        foreach (['original_doc_number', 'document_number', 'doc_number', 'document_no', 'invoice_number', 'vendor_invoice_number', 'purchase_number', 'doc_no', 'invoice_no', 'varsymbol', 'payment_variable_symbol', 'constant_symbol', 'specific_symbol', 'bank_ref', 'reference', 'payment_reference', 'snapshot_hash', 'idempotency_key'] as $field) {
            $result = Comparator::values([$field => '0012'], [$field => '12']);
            self::assertFalse($result['equal'], $field);
            self::assertSame(0, $result['numeric_count'], $field);
            self::assertSame('value_or_type', $result['differences'][0]['kind'], $field);
        }
    }

    public function testIdentifierTypesRemainSignificantIncludingNestedLists(): void
    {
        foreach (['original_doc_number', 'variable_symbol', 'bank_ref', 'input_hash', 'case_key'] as $field) {
            $result = Comparator::values([$field => ['12']], [$field => [12]]);
            self::assertFalse($result['equal'], $field);
            self::assertSame(0, $result['numeric_count'], $field);
        }
    }

    public function testFinancialDecimalRangeStillFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('decimal_range');
        Comparator::values(['amount' => '5E20990101'], ['amount' => '5E20990101']);
    }

    public function testZeroNullMissingRowAndDuplicateRemainDifferent(): void
    {
        self::assertFalse(Comparator::values(['amount' => 0], ['amount' => null])['equal']);
        self::assertFalse(Comparator::values([['amount' => 0]], [])['equal']);
        self::assertFalse(Comparator::values([['amount' => 0], ['amount' => 0]], [['amount' => 0]])['equal']);
        self::assertFalse(Comparator::values([1, 2], [2, 1])['equal']);
    }

    public function testMetadataIsComparedButDoesNotCountAsMoney(): void
    {
        $result = Comparator::values(['supplier_id' => 1, 'accounting_decree' => '500'], ['supplier_id' => 2, 'accounting_decree' => '504']);
        self::assertFalse($result['equal']);
        self::assertSame(0, $result['numeric_count']);
        $result = Comparator::values(['podklady' => []], ['podklady' => ['accounting_decree' => '500']]);
        self::assertSame('added_field', $result['differences'][0]['kind']);
    }

    public function testIdenticalExceptionsAndEmptyCapturesCannotPass(): void
    {
        $capture = ['format' => 1, 'status' => 'ok', 'dataset_id' => str_repeat('a', 64), 'as_of' => '2025-12-31', 'execution_date' => '2026-10-17', 'coverage' => [], 'manifest' => ['synthetic' => ['kind' => 'trial_balance', 'args' => []]], 'cases' => []];
        self::assertSame('error', Comparator::captures($capture, $capture)['status']);
        $capture['cases']['synthetic'] = ['status' => 'error', 'exception_class' => 'RuntimeException'];
        self::assertSame('error', Comparator::captures($capture, $capture)['status']);
        $capture['cases']['synthetic'] = ['status' => 'ok', 'data' => ['amount' => '0.00']];
        self::assertSame('ok', Comparator::captures($capture, $capture)['status']);
        $other = $capture;
        $other['dataset_id'] = str_repeat('b', 64);
        self::assertSame('error', Comparator::captures($capture, $other)['status']);
    }

    public function testFailedCaptureCannotPassEvenWhenEveryCaseHasMatchingNumbers(): void
    {
        $capture = self::completeCapture();
        $capture['status'] = 'error';
        self::assertSame('error', Comparator::captures($capture, $capture)['status']);
    }

    public function testIdenticallyOmittedManifestCaseCannotPass(): void
    {
        $capture = self::completeCapture();
        $capture['manifest']['missing'] = ['kind' => 'trial_balance', 'args' => [2]];
        self::assertSame('error', Comparator::captures($capture, $capture)['status']);
    }

    public function testUnplannedCaseCannotSupplyNumericCoverage(): void
    {
        $capture = self::completeCapture();
        $capture['cases']['unplanned'] = ['status' => 'ok', 'data' => ['amount' => 2]];
        self::assertSame('error', Comparator::captures($capture, $capture)['status']);
    }

    private static function completeCapture(): array
    {
        return [
            'format' => 1, 'status' => 'ok', 'dataset_id' => str_repeat('a', 64),
            'as_of' => '2025-12-31', 'execution_date' => '2026-10-17', 'coverage' => [],
            'manifest' => ['synthetic' => ['kind' => 'trial_balance', 'args' => [1]]],
            'cases' => ['synthetic' => ['status' => 'ok', 'data' => ['amount' => 1]]],
        ];
    }

    public function testWorkingDatabaseIsDeniedEvenWithValidUniqueName(): void
    {
        $this->expectException(\RuntimeException::class);
        Safety::database('synthetic_ab12cd34_test', 'synthetic_ab12cd34_test');
    }

    public function testNonTestDatabaseIsDenied(): void
    {
        $this->expectException(\RuntimeException::class);
        Safety::database('synthetic_source', '');
    }

    public function testOutputInsideRepositoryIsDeniedCaseInsensitively(): void
    {
        $this->expectException(\RuntimeException::class);
        Safety::outside(__DIR__ . '/new-capture.json', [dirname(__DIR__, 3)]);
    }
}
