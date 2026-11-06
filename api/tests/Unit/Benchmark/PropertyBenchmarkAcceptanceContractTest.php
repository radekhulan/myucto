<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Benchmark;

use InvalidArgumentException;
use MyInvoice\Benchmark\PropertyBenchmarkAcceptance;
use MyInvoice\Benchmark\PropertyBenchmarkRead;
use MyInvoice\Benchmark\PropertyBenchmarkQueries;
use MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository;
use MyInvoice\Repository\Property\Receivables\SelectedFinancialClosure;
use ReflectionClass;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/bin/Support/PropertyBenchmarkRead.php';
require_once dirname(__DIR__, 3) . '/bin/Support/PropertyBenchmarkQueries.php';
require_once dirname(__DIR__, 3) . '/bin/Support/PropertyBenchmarkAcceptance.php';

final class PropertyBenchmarkAcceptanceContractTest extends TestCase
{
    public function testPhasesAreFixedAndCannotCombineFullLoads(): void
    {
        self::assertSame([
            'subjects' => 1,
            'units_per_subject' => 12,
            'source_months' => 2,
            'source_first' => '2025-01',
            'source_granularity' => 'monthly',
            'monthly_generation' => false,
        ], PropertyBenchmarkAcceptance::phaseSpec('pilot'));
        self::assertSame(50, PropertyBenchmarkAcceptance::phaseSpec('portfolio-50')['subjects']);
        self::assertSame(12, PropertyBenchmarkAcceptance::phaseSpec('portfolio-50')['units_per_subject']);
        self::assertSame([
            'subjects' => 1,
            'units_per_subject' => 400,
            'source_months' => 120,
            'source_first' => '2016-01',
            'source_granularity' => 'annual_rollup',
            'monthly_generation' => true,
        ], PropertyBenchmarkAcceptance::phaseSpec('heavy-adoption'));
    }

    public function testUnknownCombinedPhaseIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PropertyBenchmarkAcceptance::phaseSpec('all');
    }

    public function testFrozenSourceHashAcceptsOnlyTheCapturedGeneration(): void
    {
        $root = dirname(__DIR__, 4);
        $path = 'api/bin/Support/PropertyBenchmarkAcceptance.php';
        $proof = ['file' => $path, 'sha256' => hash_file('sha256', $root . '/' . $path)];
        $verify = new \ReflectionMethod(PropertyBenchmarkAcceptance::class, 'verifyFrozen');
        $verify->invoke(null, ['frozen' => [$proof]]);
        $proof['sha256'] = str_repeat('0', 64);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Frozen benchmark dependency changed');
        $verify->invoke(null, ['frozen' => [$proof]]);
    }

    public function testSeparatePhasesNeverClaimCombinedFullAcceptance(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/bin/Support/PropertyBenchmarkAcceptance.php');
        self::assertIsString($source);
        self::assertStringContainsString('$report[\'full_acceptance_claimed\'] = false;', $source);
    }

    public function testFullHistoryReferenceCannotBeUsedOutsidePilotBound(): void
    {
        $reader = (new ReflectionClass(PropertyBenchmarkRead::class))->newInstanceWithoutConstructor();
        $this->expectException(InvalidArgumentException::class);
        $reader->reference(1, '2026-01-31T00:00:00+01:00', '2026-02-01T00:00:00Z', array_fill(0, 26, ['id' => 1, 'code' => 'U001']));
    }

    public function testReferencePlanSupportsPilotAndHeavyFixtureWithoutAnUnboundedBatch(): void
    {
        self::assertSame([12], PropertyBenchmarkRead::referenceBatchSizes(12));
        self::assertSame(array_fill(0, 16, 25), PropertyBenchmarkRead::referenceBatchSizes(400));
        foreach ([1, 11, 13, 25, 26, 399, 401] as $units) {
            try {
                PropertyBenchmarkRead::referenceBatchSizes($units);
                self::fail('Unsupported reference fixture size was accepted: ' . $units);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testHeavyReferenceUsesTheIndependentChargeSeededFinancialClosure(): void
    {
        self::assertTrue(method_exists(SelectedFinancialClosure::class, 'currentForSources'));
        self::assertTrue(method_exists(FinancialHistoryRepository::class, 'readForCharges'));
        $source = file_get_contents(dirname(__DIR__, 3) . '/bin/Support/PropertyBenchmarkRead.php');
        self::assertIsString($source);
        self::assertStringContainsString('->readForCharges(', $source);
        self::assertStringNotContainsString('PropertySaldoReadClosure', $source);
    }

    public function testQuerySummaryCountsCallsWithoutRetainingResultRows(): void
    {
        $queries = new PropertyBenchmarkQueries();
        $queries->record('SELECT * FROM synthetic WHERE supplier_id=?', [7], 1.25);
        $queries->record('SELECT * FROM synthetic WHERE supplier_id=?', [7], 2.75);
        $queries->record('SELECT * FROM other WHERE supplier_id=?', [7], 0.5);
        self::assertSame([
            'distinct_selects' => 2,
            'recorded_select_calls' => 3,
            'recorded_total_ms' => 4.5,
            'max_calls_per_sql' => 2,
        ], $queries->summary());
    }

    public function testBenchmarkReceiptMetadataDoesNotLeakIntoNativeOpeningRequest(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/bin/Support/PropertyBenchmarkAdoption.php');
        self::assertIsString($source);
        self::assertSame(1, preg_match('/\\$request = \\[(.*?)\\];\\s*\\n\\s*\\$owner =/s', $source, $matches));
        self::assertStringNotContainsString("'source_month_count'", $matches[1]);
        self::assertStringNotContainsString("'monthly_claim_granularity'", $matches[1]);
    }
}
