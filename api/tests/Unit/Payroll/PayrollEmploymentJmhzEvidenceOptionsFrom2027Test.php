<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Formulář nabízí hodnoty číselníků EDV 1.5.0.3 pro události od 1. 1. 2027
 * z připnutého balíku 2027, základní nabídka 2026 zůstává beze změny.
 */
final class PayrollEmploymentJmhzEvidenceOptionsFrom2027Test extends TestCase
{
    public function testOptionsCarryValuesAddedByEdv15(): void
    {
        $options = (new PayrollEmploymentJmhzEvidenceCatalog(
            new JmhzSpecPackageCatalog(),
            new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog()),
        ))->options();

        $codes = static fn (array $list): array => array_column($list, 'code');
        $from = $options['from_2027'];

        self::assertSame(['22', '23'], $codes($from['free_access_reason_codes']));
        self::assertSame(['3', '4'], $codes($from['employment_types']));
        self::assertSame(
            array_map('strval', range(1, 23)),
            $codes($from['service_termination_reasons']),
        );
        self::assertNotContains('22', $codes($options['foreign_worker_free_access_reason_codes']));

        $types = array_column($from['service_termination_reasons'], 'employment_types', 'code');
        self::assertSame(['2'], $types['1']);
        self::assertSame(['3'], $types['7']);
        self::assertSame(['4'], $types['23']);
        self::assertSame(['2', '3', '4'], array_values(array_unique(array_merge(...array_values($types)))));
        self::assertSame(['2', '3', '4'], $types['5']);
        self::assertStringContainsString('§ 98', $from['free_access_reason_codes'][0]['label']);

        // Postavení v zaměstnání 2027 z číselníku ČSÚ, 2026 ze slovníku 1.4.1.6.
        self::assertContains('2219', $codes($from['employment_status_codes']));
        self::assertNotContains('2119', $codes($from['employment_status_codes']));
        self::assertContains('2119', $codes($options['employment_status_codes']));
        self::assertCount(34, $from['employment_status_codes']);
    }
}
