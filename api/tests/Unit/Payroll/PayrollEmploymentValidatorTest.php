<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\CzIscoCodebook;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use PHPUnit\Framework\TestCase;

final class PayrollEmploymentValidatorTest extends TestCase
{
    public function testAcceptsSmallScaleEmploymentAndHistoricalInputs(): void
    {
        $result = $this->validator()->create([
            'code' => 'ZMR-2026-01',
            'relation_type' => 'small_scale_employment',
            'monthly_gross_minor' => 450000,
            'terms' => $this->terms(),
        ]);

        self::assertSame('small_scale_employment', $result['relation_type']);
        self::assertSame('20.00', $result['terms']['weekly_hours']);
        self::assertSame('CZ', $result['terms']['foreign_legislation_country_code']);
        self::assertTrue($result['terms']['is_primary']);
    }

    public function testForeignModeRequiresCountry(): void
    {
        $terms = $this->terms();
        $terms['foreign_legislation_country_code'] = null;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kód státu');
        $this->validator()->terms($terms);
    }

    public function testRejectsInvalidDatesAndWorkload(): void
    {
        $terms = $this->terms();
        $terms['fixed_term_end_on'] = '2025-12-31';
        $terms['workload_basis_points'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->validator()->terms($terms);
    }

    public function testInitialCreateCannotBypassActualStartTransition(): void
    {
        $terms = $this->terms();
        $terms['actual_start_on'] = '2026-01-01';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Skutečný nástup');
        $this->validator()->create([
            'code' => 'HPP-1',
            'relation_type' => 'employment',
            'monthly_gross_minor' => 4000000,
            'terms' => $terms,
        ]);
    }

    public function testAcceptsCompleteJmhzCoreEvidenceAndCanonicalizesIt(): void
    {
        $terms = $this->terms();
        $terms['work_place'] = '  Hlavní město Praha  ';
        $terms['jmhz_workplace_municipality_code'] = '554782';
        $terms['jmhz_workplace_country_code'] = 'cz';
        $terms['jmhz_apz_contribution_status'] = 'yes';
        $terms['jmhz_apz_instrument_code'] = '3';
        $terms['jmhz_functional_benefits_status'] = 'no';
        $terms['jmhz_temporary_assignment_status'] = 'unverified';

        $result = $this->validator()->terms($terms);

        self::assertSame('Hlavní město Praha', $result['work_place']);
        self::assertSame('554782', $result['jmhz_workplace_municipality_code']);
        self::assertSame('CZ', $result['jmhz_workplace_country_code']);
        self::assertSame('3', $result['jmhz_apz_instrument_code']);
        self::assertSame(
            JmhzExternalCodebookCatalog::AUGUST_2026_MANIFEST_SHA256,
            $result['jmhz_external_codebook_manifest_sha256'],
        );
    }

    public function testFutureJmhzEvidenceCanBePlannedButPredatesOverlayCannot(): void
    {
        $future = $this->terms();
        $future['effective_from'] = '2026-09-01';
        $future['work_place'] = 'Hlavní město Praha';
        $future['jmhz_workplace_municipality_code'] = '554782';
        $future['jmhz_workplace_country_code'] = 'CZ';
        $futureResult = $this->validator()->terms($future);
        self::assertSame('554782', $futureResult['jmhz_workplace_municipality_code']);
        self::assertSame(
            JmhzExternalCodebookCatalog::DEFAULT_MANIFEST_SHA256,
            $futureResult['jmhz_external_codebook_manifest_sha256'],
        );

        $past = $future;
        $past['effective_from'] = '2025-12-31';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nejsou pro datum');
        $this->validator()->terms($past);
    }

    public function testRejectsPartialWorkplaceAndUnknownApzCode(): void
    {
        $partial = $this->terms();
        $partial['work_place'] = 'Praha';
        $partial['jmhz_workplace_municipality_code'] = null;
        $partial['jmhz_workplace_country_code'] = 'CZ';

        try {
            $this->validator()->terms($partial);
            self::fail('Neúplné pracoviště musí být odmítnuto.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Pracoviště JMHZ', $e->getMessage());
        }

        $apz = $this->terms();
        $apz['jmhz_apz_contribution_status'] = 'yes';
        $apz['jmhz_apz_instrument_code'] = '9';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nástroje APZ');
        $this->validator()->terms($apz);
    }

    public function testRequiresExplicitTriStateAndClearsNoApzCode(): void
    {
        $missing = $this->terms();
        unset($missing['jmhz_functional_benefits_status']);
        try {
            $this->validator()->terms($missing);
            self::fail('Chybějící tri-state nesmí být vyložen jako ne.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('jmhz_functional_benefits_status', $e->getMessage());
        }

        $no = $this->terms();
        $no['jmhz_apz_contribution_status'] = 'no';
        $no['jmhz_apz_instrument_code'] = '1';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Bez příspěvku APZ');
        $this->validator()->terms($no);
    }

    public function testCzIscoAcceptsFourAndFiveDigitCodes(): void
    {
        foreach (['2512', '43111', null] as $code) {
            $terms = $this->terms();
            $terms['cz_isco_code'] = $code;
            self::assertSame($code, $this->validator()->terms($terms)['cz_isco_code']);
        }
    }

    public function testCzIscoRejectsNonNumericAndWrongLength(): void
    {
        foreach (['ISCO-4311', '431', '431101'] as $code) {
            $terms = $this->terms();
            $terms['cz_isco_code'] = $code;
            try {
                $this->validator()->terms($terms);
                self::fail("Kód {$code} měl být odmítnut.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('CZ-ISCO', $e->getMessage());
                self::assertStringContainsString('25120', $e->getMessage());
            }
        }
    }

    /**
     * Tvar sám o sobě nestačí: 43110 vypadá jako kód CZ-ISCO, ale v klasifikaci
     * není. Dřív prošel až do podání JMHZ a vrátil se jako odmítnutí ČSSZ.
     */
    public function testCzIscoRejectsWellFormedCodeMissingFromClassification(): void
    {
        $terms = $this->terms();
        $terms['cz_isco_code'] = '43110';

        try {
            $this->validator()->terms($terms);
            self::fail('Kód mimo klasifikaci měl být odmítnut.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('43110', $e->getMessage(), 'Hláška musí kód jmenovat.');
            self::assertStringContainsString('našeptávače', $e->getMessage(), 'Hláška musí poradit.');
        }
    }

    /** Kód, který platil ve starším vydání CZ-ISCO, je legitimní historická hodnota. */
    public function testCzIscoAcceptsRetiredCodeFromOlderClassificationVersion(): void
    {
        $codebook = new CzIscoCodebook();
        self::assertSame(CzIscoCodebook::STATUS_RETIRED, $codebook->status('32114'));

        $terms = $this->terms();
        $terms['cz_isco_code'] = '32114';

        self::assertSame('32114', $this->validator()->terms($terms)['cz_isco_code']);
    }

    /**
     * Zpětná kompatibilita: v databázi jsou hodnoty z doby, kdy pole bylo volný
     * text. Uložení vztahu kvůli úplně jiné změně nesmí padnout na cizí kód —
     * ale jakmile se kód změní, musí trefit číselník.
     */
    public function testCzIscoGrandfathersOnlyTheAlreadyStoredValue(): void
    {
        $terms = $this->terms();
        $terms['cz_isco_code'] = '99999';

        self::assertSame(
            '99999',
            $this->validator()->terms($terms, '99999')['cz_isco_code'],
            'Beze změny pole musí uložení projít.',
        );

        $changed = $this->terms();
        $changed['cz_isco_code'] = '99998';
        try {
            $this->validator()->terms($changed, '99999');
            self::fail('Změna kódu na jiný nesmyslný kód měla být odmítnuta.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('99998', $e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function terms(): array
    {
        return [
            'office_id' => 1,
            'effective_from' => '2026-01-01',
            'contract_signed_on' => '2025-12-20',
            'planned_start_on' => '2026-01-01',
            'actual_start_on' => null,
            'fixed_term_end_on' => '2026-12-31',
            'weekly_hours' => '20',
            'workload_basis_points' => 5000,
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
            'a1_certificate_until' => '2026-12-31',
            'risky_work' => false,
            'tax_declaration_signed' => true,
            'is_primary' => true,
            'change_reason' => 'Počáteční podmínky',
        ];
    }

    /**
     * Sazbová kategorie § 5a odst. 1 je zdroj pravdy a `risky_work` se z ní
     * odvozuje. Dva zapisovatelné údaje o téže věci by se rozešly — a rozešly
     * by se tiše, protože jeden z nich čte mzdový výpočet a druhý JMHZ.
     */
    public function testRateCategoryDrivesTheLegacyRiskyWorkFlag(): void
    {
        $input = $this->terms();
        $input['social_employer_rate_category'] = 'risk_employment';
        $input['social_employer_rate_category_evidence'] = 'kategorizace-praci/2026/17';
        unset($input['risky_work']);

        $validated = $this->validator()->terms($input);

        self::assertSame('risk_employment', $validated['social_employer_rate_category']);
        self::assertTrue($validated['risky_work']);
        self::assertSame(
            'kategorizace-praci/2026/17',
            $validated['social_employer_rate_category_evidence'],
        );
    }

    /**
     * Starší obrazovka kategorii neposílá, jen boolean. Ten se přeloží na
     * písm. c) — jinak by uložení nesouvisející změny shodilo zařazení
     * rizikové práce na běžnou sazbu.
     */
    public function testLegacyRiskyWorkFlagAloneStillMeansRiskEmployment(): void
    {
        $input = $this->terms();
        $input['risky_work'] = true;

        $validated = $this->validator()->terms($input);

        self::assertSame('risk_employment', $validated['social_employer_rate_category']);
        self::assertTrue($validated['risky_work']);
    }

    public function testContradictoryRiskyWorkFlagAndRateCategoryIsRejected(): void
    {
        $input = $this->terms();
        $input['risky_work'] = true;
        $input['social_employer_rate_category'] = 'ordinary';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('si odporují');
        $this->validator()->terms($input);
    }

    /** U běžné sazby žádný podklad neexistuje, takže se ani neukládá. */
    public function testOrdinaryCategoryNeverKeepsAnEvidenceReference(): void
    {
        $input = $this->terms();
        $input['social_employer_rate_category'] = 'ordinary';
        $input['social_employer_rate_category_evidence'] = 'nesmysl';

        $validated = $this->validator()->terms($input);

        self::assertNull($validated['social_employer_rate_category_evidence']);
    }

    public function testUnknownRateCategoryIsRejected(): void
    {
        $input = $this->terms();
        $input['social_employer_rate_category'] = 'unverified';
        unset($input['risky_work']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sazbová kategorie zaměstnavatele');
        $this->validator()->terms($input);
    }

    public function testRelationshipDetailRequiresPinnedCodeAndApplicableActivity(): void
    {
        $valid = $this->terms();
        $validated = $this->validator()->terms($valid);
        self::assertSame('1', $validated['activity_code']);
        self::assertSame('1', $validated['jmhz_relationship_detail_code']);

        $notApplicable = $valid;
        $notApplicable['activity_code'] = 'A';
        try {
            $this->validator()->terms($notApplicable);
            self::fail('Bližší určení nesmí být u druhu činnosti mimo 1 až 9.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('jen pro druh činnosti 1 až 9', $exception->getMessage());
        }

        $unknown = $valid;
        $unknown['jmhz_relationship_detail_code'] = '9';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Bližší určení pracovněprávního vztahu');
        $this->validator()->terms($unknown);
    }

    /**
     * Prohlášení plátce podle § 6 odst. 4 písm. b) ZDP se ukládá jen tehdy,
     * když ho klient poslal. Podmínky se zapisují celé, takže obrazovka, která
     * o poli neví (rychlá editace, založení vztahu ze seznamu), by ho jinak
     * shodila na „neurčeno" — a příští mzdový běh by kvůli uložení docela jiné
     * změny skončil ručním posouzením.
     */
    public function testPayerStatementIsCarriedOverWhenTheClientDoesNotSendIt(): void
    {
        $terms = $this->terms();
        self::assertArrayNotHasKey('other_withholding_eligibility', $terms);

        self::assertSame(
            'eligible',
            $this->validator()->terms($terms, null, 'eligible')['other_withholding_eligibility'],
        );
        self::assertSame(
            'unverified',
            $this->validator()->terms($terms)['other_withholding_eligibility'],
        );

        $explicit = $terms;
        $explicit['other_withholding_eligibility'] = 'ineligible';
        self::assertSame(
            'ineligible',
            $this->validator()->terms($explicit, null, 'eligible')['other_withholding_eligibility'],
        );
    }

    public function testUnsupportedPayerStatementIsRejected(): void
    {
        $terms = $this->terms();
        $terms['other_withholding_eligibility'] = 'automatic';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('srážkovou daň');
        $this->validator()->terms($terms);
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
}
