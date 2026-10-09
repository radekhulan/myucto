<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use PHPUnit\Framework\TestCase;

/**
 * NEMPRI25-lhuta-15 (FAQ ČSSZ k dávkám NP, dotazy 1, 3 a 5): ošetřovné,
 * dlouhodobé ošetřovné, otcovská a PPM s rozhodnutím z roku 2024 patří
 * k NEMPRI20, nemocenské smí jít NEMPRI25 i ze starého období.
 */
final class NempriLegacyFormTest extends TestCase
{
    public function testBenefitsWithApplicationFrom2024NeedNempri20(): void
    {
        foreach ([SicknessBenefitKind::Ose, SicknessBenefitKind::Dlo, SicknessBenefitKind::Opp, SicknessBenefitKind::Ppm] as $kind) {
            $problem = $kind->legacyFormProblem('2024-12-31', false);
            self::assertSame('nempri_legacy_form_required', $problem['code'] ?? null, $kind->value);
            self::assertStringContainsString('NEMPRI20', $problem['message']);
            self::assertNull($kind->legacyFormProblem('2025-01-01', false), $kind->value);
        }
    }

    public function testSicknessAndPregnancyCompensationAreNotStopped(): void
    {
        self::assertNull(SicknessBenefitKind::Nem->legacyFormProblem('2024-12-02', false));
        self::assertNull(SicknessBenefitKind::Vpm->legacyFormProblem('2024-12-02', false));
    }

    /** U otcovské a převzetí dítěte do péče rozhoduje den žádosti, který aplikace nezná. */
    public function testApplicationDateCasesExplainWhyTheyStop(): void
    {
        $paternity = SicknessBenefitKind::Opp->legacyFormProblem('2024-12-20', false);
        $takenIntoCare = SicknessBenefitKind::Ppm->legacyFormProblem('2024-12-20', true);
        $maternity = SicknessBenefitKind::Ppm->legacyFormProblem('2024-12-20', false);

        self::assertStringContainsString('den podání žádosti', (string) ($paternity['message'] ?? ''));
        self::assertStringContainsString('den podání žádosti', (string) ($takenIntoCare['message'] ?? ''));
        self::assertStringNotContainsString('den podání žádosti', (string) ($maternity['message'] ?? ''));
    }
}
