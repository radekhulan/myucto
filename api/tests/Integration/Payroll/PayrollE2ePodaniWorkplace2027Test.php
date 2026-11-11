<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\CzIscoCodebook;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollFieldValidationException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pracoviště v podmínkách vztahu účinných od 1. 1. 2027, kdy číselník obcí
 * a států na rok 2027 ještě nevyšel. Nevyhlášený číselník se neodhaduje, ale
 * kód, který není ani v posledním vyhlášeném, projít nesmí; správný kód projde
 * s varováním a bez provenience (pro období neověřeno).
 */
#[Group('integration')]
final class PayrollE2ePodaniWorkplace2027Test extends TestCase
{
    public function testNonsenseCodesFrom2027AreRejectedAgainstLastPublishedCodebook(): void
    {
        $terms = $this->terms('2027-01-15');
        $terms['jmhz_workplace_municipality_code'] = '123456';
        $terms['jmhz_workplace_country_code'] = 'CZ';
        try {
            $this->validator()->terms($terms);
            self::fail('Kód obce 123456 nesmí od 2027 projít tiše.');
        } catch (PayrollFieldValidationException $e) {
            self::assertSame('jmhz_workplace_municipality_code', $e->field);
            self::assertStringContainsString('není ani v posledním vyhlášeném číselníku obcí', $e->getMessage());
            self::assertStringContainsString('31. 12. 2026', $e->getMessage());
        }

        $terms['jmhz_workplace_municipality_code'] = '554782';
        $terms['jmhz_workplace_country_code'] = 'ZZ';
        try {
            $this->validator()->terms($terms);
            self::fail('Stát ZZ nesmí od 2027 projít tiše.');
        } catch (PayrollFieldValidationException $e) {
            self::assertSame('jmhz_workplace_country_code', $e->field);
            self::assertStringContainsString('není ani v posledním vyhlášeném číselníku států', $e->getMessage());
        }
    }

    public function testValidCodeFrom2027IsSavedUnverifiedWithExplanation(): void
    {
        $terms = $this->terms('2027-01-15');
        $terms['work_place'] = null;
        $terms['jmhz_workplace_municipality_code'] = '554782';
        $terms['jmhz_workplace_country_code'] = 'CZ';
        $warnings = [];

        $result = $this->validator()->terms($terms, warnings: $warnings);

        // Prázdné místo výkonu se doplní úředním názvem z posledního
        // vyhlášeného číselníku (dřív zavádějící chyba „vyžaduje kód obce
        // a kód státu", přestože obojí bylo vyplněné).
        self::assertSame('Hlavní město Praha', $result['work_place']);
        self::assertSame('554782', $result['jmhz_workplace_municipality_code']);
        // Pro období 2027 ověřené není: provenience se nezapíše a běh i příprava
        // JMHZ pracoviště ověří znovu, jakmile aplikace číselník 2027 dostane.
        self::assertNull($result['jmhz_external_codebook_overlay_key']);
        self::assertNull($result['jmhz_external_codebook_manifest_sha256']);
        self::assertSame(['workplace_codebook_not_published'], array_column($warnings, 'code'));
        self::assertStringContainsString('ještě nebyl vyhlášen', $warnings[0]['message']);
        self::assertStringContainsString('ověří se znovu', $warnings[0]['message']);
    }

    public function testCoveredPeriodsBehaveAsBefore(): void
    {
        $terms = $this->terms('2026-10-01');
        $terms['work_place'] = 'Hlavní město Praha';
        $terms['jmhz_workplace_municipality_code'] = '554782';
        $terms['jmhz_workplace_country_code'] = 'CZ';
        $warnings = [];
        $result = $this->validator()->terms($terms, warnings: $warnings);
        self::assertSame([], $warnings);
        self::assertSame(JmhzExternalCodebookCatalog::DEFAULT_OVERLAY_KEY, $result['jmhz_external_codebook_overlay_key']);

        // Před JMHZ se dál neověřuje vůbec (rok 2025 nemá číselník).
        $past = $this->terms('2025-12-31');
        $past['jmhz_workplace_municipality_code'] = '123456';
        $past['jmhz_workplace_country_code'] = 'CZ';
        $past['work_place'] = 'Kdekoli';
        self::assertSame('123456', $this->validator()->terms($past)['jmhz_workplace_municipality_code']);
    }

    private function validator(): PayrollEmploymentValidator
    {
        return new PayrollEmploymentValidator(
            new PayrollEmploymentJmhzEvidenceCatalog(
                new JmhzSpecPackageCatalog(),
                new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog()),
            ),
            new CzIscoCodebook(),
        );
    }

    /** @return array<string,mixed> */
    private function terms(string $effectiveFrom): array
    {
        return [
            'office_id' => 1,
            'effective_from' => $effectiveFrom,
            'contract_signed_on' => '2025-12-20',
            'planned_start_on' => '2025-12-31',
            'actual_start_on' => null,
            'fixed_term_end_on' => null,
            'weekly_hours' => '40',
            'workload_basis_points' => 10000,
            'work_place' => 'Praha',
            'regular_workplace' => 'Praha',
            'jmhz_workplace_municipality_code' => null,
            'jmhz_workplace_country_code' => null,
            'jmhz_apz_contribution_status' => 'unverified',
            'jmhz_apz_instrument_code' => null,
            'jmhz_functional_benefits_status' => 'unverified',
            'jmhz_temporary_assignment_status' => 'unverified',
            'cz_isco_code' => '43111',
            'activity_code' => '1',
            'jmhz_relationship_detail_code' => '1',
            'social_insurance_participation' => 'foreign',
            'health_insurance_participation' => 'foreign',
            'tax_regime' => 'foreign',
            'foreign_legislation_country_code' => 'cz',
            'a1_certificate_until' => '2027-12-31',
            'risky_work' => false,
            'tax_declaration_signed' => true,
            'is_primary' => true,
            'change_reason' => 'Syntetické podmínky',
        ];
    }
}
