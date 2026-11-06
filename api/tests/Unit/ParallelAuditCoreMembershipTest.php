<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

\defined('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY') || \define('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY', true);
require_once dirname(__DIR__, 2) . '/bin/test-parallel.php';

/**
 * --audit-check rozhoduje o AuditCore z padlých tříd plné sady. Příslušnost třídy musí
 * sedět s výjimkami v phpunit-audit.xml, jinak by brána pustila pád v jádru nebo
 * naopak shodila vydání kvůli mzdám.
 */
final class ParallelAuditCoreMembershipTest extends TestCase
{
    #[DataProvider('classes')]
    public function testClassMembershipFollowsAuditSuiteExcludes(string $class, bool $expected): void
    {
        self::assertSame($expected, \auditCoreContainsClass(dirname(__DIR__, 2), $class));
    }

    public static function classes(): iterable
    {
        yield 'účetnictví' => ['MyInvoice\\Tests\\Integration\\Accounting\\Bank\\BankAbortBoundariesTest', true];
        yield 'architektura' => ['MyInvoice\\Tests\\Architecture\\RbacSourceGuardsTest', true];
        yield 'invarianty' => ['MyInvoice\\Tests\\Invariants\\JournalBalanceInvariantTest', true];
        yield 'unit mimo mzdy' => ['MyInvoice\\Tests\\Unit\\AuditGateExitCodeTest', true];
        yield 'mzdy integrace' => ['MyInvoice\\Tests\\Integration\\Payroll\\PayrollRetroactiveYearFlowTest', false];
        yield 'mzdy unit' => ['MyInvoice\\Tests\\Unit\\Payroll\\Submission\\JmhzScenario1ControlViolationTest', false];
        yield 'převody' => ['MyInvoice\\Tests\\Integration\\Migration\\MoneyS3ImportAccountingTest', false];
        yield 'převody unit služby' => ['MyInvoice\\Tests\\Unit\\Service\\Migration\\PohodaMapperTest', false];
        yield 'anonymizace' => ['MyInvoice\\Tests\\Integration\\Anonymization\\AnonymizationServiceTest', false];
        yield 'ukázková data' => ['MyInvoice\\Tests\\Integration\\Sample\\SampleDataPurgeTest', false];
        yield 'ukázkové mzdy soubor' => ['MyInvoice\\Tests\\Integration\\SamplePayrollGeneratorTest', false];
        yield 'podobný název mimo výjimku' => ['MyInvoice\\Tests\\Integration\\SamplePayrollGeneratorExtraTest', true];
        yield 'cizí třída' => ['Other\\Tests\\Integration\\Accounting\\XTest', false];
    }
}
