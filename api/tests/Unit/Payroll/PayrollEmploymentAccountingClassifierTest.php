<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollEmploymentAccountingClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollEmploymentAccountingClassifierTest extends TestCase
{
    /** @return iterable<string,array{string,array<string,string>}> */
    public static function relationTypes(): iterable
    {
        $employeeAccounts = [
            'gross_debit' => '521',
            'gross_credit' => '331',
            'employer_insurance_debit' => '524',
            'employer_insurance_credit' => '336.100',
        ];

        yield 'employment' => ['employment', $employeeAccounts];
        yield 'small-scale employment' => ['small_scale_employment', $employeeAccounts];
        yield 'dpp' => ['dpp', $employeeAccounts];
        yield 'dpc' => ['dpc', $employeeAccounts];
        yield 'partner dependent activity' => ['partner_dependent', [
            'gross_debit' => '522',
            'gross_credit' => '366',
            'employer_insurance_debit' => '524',
            'employer_insurance_credit' => '336.100',
        ]];
        yield 'statutory body' => ['statutory_body', [
            'gross_debit' => '523',
            'gross_credit' => '366',
            'employer_insurance_debit' => '524',
            'employer_insurance_credit' => '336.100',
        ]];
    }

    /** @param array<string,string> $expected */
    #[DataProvider('relationTypes')]
    public function testClassifiesEverySupportedRelationType(string $relationType, array $expected): void
    {
        self::assertSame($expected, (new PayrollEmploymentAccountingClassifier())($relationType));
    }

    public function testRejectsUnknownRelationType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PayrollEmploymentAccountingClassifier())('unknown');
    }
}
