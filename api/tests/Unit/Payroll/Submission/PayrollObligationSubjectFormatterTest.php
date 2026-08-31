<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationSubjectFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Sdílená mezi měsíčním přehledem, inboxem a přehledem podání — testy proto
 * pokrývají oba tvary `payroll_run:…`, které se liší jen podle AGENDY, ne
 * podle počtu dvojteček (viz docblock třídy).
 */
final class PayrollObligationSubjectFormatterTest extends TestCase
{
    public function testJmhzWithOfficeShowsOffice(): void
    {
        self::assertSame(
            'mzdová účtárna 4',
            PayrollObligationSubjectFormatter::humanSubject(
                JmhzSubmissionBridgeService::AGENDA_CODE,
                'payroll_run:8:office:4',
            ),
        );
    }

    public function testJmhzWithoutOfficeIsSuppressed(): void
    {
        self::assertNull(PayrollObligationSubjectFormatter::humanSubject(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'payroll_run:8',
        ));
    }

    /**
     * Stejný prefix `payroll_run:` jako JMHZ, ale TŘI segmenty místo čtyř a
     * jiný význam posledního — u PPZ je to kód pojišťovny, ne účtárna. Bez
     * agendy v parametrech by se to nedalo rozlišit jinak než hádáním.
     */
    public function testHealthPaymentOverviewShowsInsurerNotOffice(): void
    {
        self::assertSame(
            'VZP (111)',
            PayrollObligationSubjectFormatter::humanSubject(
                HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
                'payroll_run:8:111',
            ),
        );
    }

    /**
     * Neznámý kód se nesmí ozdobit vymyšlenou zkratkou — zůstane holý,
     * ať je na první pohled vidět, že ho číselník nezná.
     */
    public function testUnknownInsurerCodeStaysPlain(): void
    {
        self::assertSame(
            'zdravotní pojišťovna 999',
            PayrollObligationSubjectFormatter::humanSubject(
                HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
                'health_bulk_notification:2026-08:999',
            ),
        );
    }

    public function testHealthBulkNotificationShowsInsurer(): void
    {
        self::assertSame(
            'VZP (111)',
            PayrollObligationSubjectFormatter::humanSubject(
                HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
                'health_bulk_notification:2026-08:111',
            ),
        );
    }

    /**
     * `employment:{id}` (ELDP/OZUSPOJ/PREZEC/REGZEC) appka nezná jak
     * přeložit na jméno osoby — radši nic než syrové interní ID.
     */
    public function testUnrecognizedFormatIsSuppressed(): void
    {
        self::assertNull(PayrollObligationSubjectFormatter::humanSubject(
            'ELDP',
            'employment:37',
        ));
    }

    public function testEmptyInsurerCodeIsSuppressedNotEmptyLabel(): void
    {
        self::assertNull(PayrollObligationSubjectFormatter::humanSubject(
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            'health_bulk_notification:2026-08:',
        ));
    }
}
