<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollWarningSuppressionCatalog;
use PHPUnit\Framework\TestCase;

final class PayrollWarningSuppressionCatalogTest extends TestCase
{
    /**
     * Registraci DPP bez účasti podanou mimo aplikaci jde skrýt u vybraných
     * vztahů; registrace vztahu s účastí drží výjimka běhu a skrýt nejde.
     */
    public function testSocialRegistrationOfAgreementWithoutParticipationCanBeHidden(): void
    {
        $code = 'employment_social_registration_missing';

        self::assertTrue(PayrollWarningSuppressionCatalog::isHideable($code, 'warning', false));
        self::assertFalse(PayrollWarningSuppressionCatalog::isHideable($code, 'warning', true));
        self::assertSame('employment', PayrollWarningSuppressionCatalog::subjectType($code));
        self::assertSame([84], PayrollWarningSuppressionCatalog::subjects($code, 'employment', 84, []));
        self::assertFalse(PayrollWarningSuppressionCatalog::isHideable('employment_health_registration_missing', 'warning', false));
    }

    public function testStatutoryDefaultSummariesAreAggregateAndRecount(): void
    {
        foreach ([
            'tax_residence_missing_unsigned_summary' => PayrollRunSnapshotBuilder::unsignedWithoutResidenceMessage(3),
            'health_coverage_missing_summary' => PayrollRunSnapshotBuilder::withoutHealthCoverageMessage(3),
        ] as $code => $message) {
            self::assertTrue(PayrollWarningSuppressionCatalog::isHideable($code, 'warning', false));
            self::assertTrue(PayrollWarningSuppressionCatalog::isAggregate($code));
            self::assertSame([5, 6], PayrollWarningSuppressionCatalog::subjects($code, 'run', null, [5, 6, 5]));
            self::assertSame($message, PayrollWarningSuppressionCatalog::aggregateMessage($code, 3));
            self::assertStringContainsString(': 3.', $message);
        }
    }
}
