<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceApplicability;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceApplicabilityException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzOrdinaryEvidenceBuilderTest extends TestCase
{
    public function testAuditedSpecificationUpdateNeedsNoNewPayrollRevision(): void
    {
        $source = $this->source();
        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(7, $source, 101, $this->facts(), 12, '2026-08-13T12:00:00Z');
        $payload = $snapshot->payload;
        $payload['specification'] = array_replace($payload['specification'], [
            'package_key' => 'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1',
            'spec_manifest_sha256' => '429e3de56e37442f35fdf8a79aab4bdff49a99beb8b3ac06afa8306312c1d205',
            'scenario_catalog_key' => 'jmhz-scenario-requirements-1.4.0.2-source-v1',
            'scenario_manifest_sha256' => 'bb43e8621c713729d534c026379c87e761711c53c42ce7e97377b68b0868b4e0',
            'control_catalog_key' => 'jmhz-controls-1.4.2.8-source-v4',
            'control_manifest_sha256' => '83ec6a985cf1c6d6e2429657d4ba6d12b09bfb849a32b504fff882383fb03800',
        ]);
        $evidence = ['id' => 1, 'source_manifest_sha256' => str_repeat('a', 64), 'snapshot_fingerprint' => str_repeat('b', 64), 'payload' => $payload];
        $before = $evidence;
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $applicability = new JmhzOrdinaryEvidenceApplicability();
        $applicability->assertApplicable($evidence, 7, $source['revision'], 11, 101, $input['people'][0]['employments'][0]['term']);
        self::assertSame($before, $evidence);
        $evidence['payload']['specification']['attribute_requirement_row_sha256']['10546'] = str_repeat('c', 64);
        try {
            $applicability->assertApplicable($evidence, 7, $source['revision'], 11, 101, $input['people'][0]['employments'][0]['term']);
            self::fail('Změněný požadavek nesmí projít jako kompatibilní aktualizace.');
        } catch (JmhzOrdinaryEvidenceApplicabilityException $exception) {
            self::assertSame('jmhz_ordinary_evidence_specification_mismatch', $exception->validationCode);
        }
    }

    public function testBuildsExplicitFalseEvidenceFromConsistentApprovedRevision(): void
    {
        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $this->source(),
            101,
            $this->facts(),
            12,
            '2026-08-13T12:00:00.000000Z',
        );

        self::assertSame(['10116' => false, '10546' => false], $snapshot->payload['attribute_values']);
        self::assertSame(
            ['IN13', 'IN28', 'IN30'],
            array_column($snapshot->payload['interaction_decisions'], 'interaction_id'),
        );
        self::assertSame(false, $snapshot->payload['derived_interactions'][0]['triggered']);
        self::assertSame(101, $snapshot->payload['scope']['employment_id']);
    }

    public function testBuildsOrdinaryEvidenceFromFrozenDefaultProfileWithoutMonthlyConfirmation(): void
    {
        $source = $this->sourceWithOrdinaryProfile();

        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $source,
            101,
            $this->facts(),
            12,
            '2026-08-13T12:00:00.000000Z',
            'derived_from_frozen_payroll_sources',
        );

        self::assertSame(
            'derived_from_frozen_payroll_sources',
            $snapshot->payload['confirmation']['source_kind'],
        );
        self::assertSame(81, $snapshot->payload['confirmation']['source_term_id']);
        self::assertSame(3, $snapshot->payload['confirmation']['source_term_row_version']);
    }

    public function testCurrentApprovedCorrectionCanFreezeOrdinaryEvidence(): void
    {
        $source = $this->sourceWithOrdinaryProfile();
        $source['revision']['revision_no'] = 2;
        $source['revision']['current_revision_no'] = 2;
        $source['revision']['revision_kind'] = 'correction';

        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $source,
            101,
            $this->facts(),
            12,
            '2026-08-13T12:00:00.000000Z',
            'derived_from_frozen_payroll_sources',
        );

        self::assertSame(2, $snapshot->payload['scope']['revision_no']);
        self::assertSame(101, $snapshot->payload['scope']['employment_id']);
    }

    public function testDerivedDefaultNamesLegacyRevisionWithoutOrdinaryProfile(): void
    {
        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $this->source(),
                101,
                $this->facts(),
                12,
                '2026-08-13T12:00:00.000000Z',
                'derived_from_frozen_payroll_sources',
            );
            self::fail('Stará revize bez zmrazeného profilu musí vyžádat nový přepočet.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame(
                'jmhz_ordinary_evidence_profile_missing',
                $exception->validationCode,
            );
            self::assertStringContainsString('znovu přepočítejte', $exception->getMessage());
        }
    }

    public function testDerivedDefaultRefusesEmploymentMarkedAsDeepMiningException(): void
    {
        $source = $this->sourceWithOrdinaryProfile([
            'deep_mining_work_applies' => true,
        ]);

        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $source,
                101,
                $this->facts(),
                12,
                '2026-08-13T12:00:00.000000Z',
                'derived_from_frozen_payroll_sources',
            );
            self::fail('Výjimečný vztah nesmí dostat automatické nulové potvrzení.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame(
                'jmhz_ordinary_evidence_monthly_exception_required',
                $exception->validationCode,
            );
            self::assertSame(['field' => 'deep_mining_work_applies'], $exception->context);
        }
    }

    public function testMissingEnforcementEvidenceIsRejectedFailClosed(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        unset($input['people'][0]['enforcement_evidence']);
        $this->replaceInput($source, $input);

        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $source,
                101,
                $this->facts(),
                12,
                '2026-08-13T12:00:00Z',
            );
            self::fail('Chybějící enforcement evidence musí být odmítnuta.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_source_invalid', $exception->validationCode);
        }
    }

    public function testEmptyClaimRegisterDoesNotRequireManualConfirmationWhenFrozenScopeSaysNotApplicable(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['enforcement_evidence']['claim_register_evidence_complete'] = false;
        $this->replaceInput($source, $input);

        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['enforcement']['input']['evidence']['claim_register_complete'] = false;
        $result['people'][0]['enforcement']['result']['evidence_source'] = [
            'claim_register' => 'not_applicable',
            'dependants' => 'not_applicable',
            'spouse' => 'not_applicable',
        ];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $source,
            101,
            $this->facts(),
            12,
            '2026-08-13T12:00:00Z',
        );

        self::assertSame(101, $snapshot->payload['scope']['employment_id']);
    }

    public function testMissingClaimRegisterScopeIsRejectedWhenMonthlyConfirmationIsFalse(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['enforcement_evidence']['claim_register_evidence_complete'] = false;
        $this->replaceInput($source, $input);

        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['enforcement']['input']['evidence']['claim_register_complete'] = false;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $source,
                101,
                $this->facts(),
                12,
                '2026-08-13T12:00:00Z',
            );
            self::fail('Chybějící rozsah kontroly musí být odmítnut.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_source_invalid', $exception->validationCode);
        }
    }

    public function testResultDeductionIsRejectedEvenWhenInputRegistersAreEmpty(): void
    {
        $source = $this->source();
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['net_pay']['deducted_minor_units'] = 100;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $source,
                101,
                $this->facts(),
                12,
                '2026-08-13T12:00:00Z',
            );
            self::fail('Srážka ve výsledku musí být odmítnuta.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_deduction_conflict', $exception->validationCode);
        }
    }

    /**
     * 10116 podle pokynů MPSV 1.4.13: výkon rozhodnutí, konkurs nebo dohoda
     * o srážkách podle OZ. Srážka za závodní stravování (§ 236 ZP) je v pokynech
     * samostatný druh (10352) a srážka ze zákona (§ 147 odst. 1 ZP) dohodou není;
     * dřív obě daly ANO.
     */
    public function testMealAndStatutoryDeductionsAreNotReportedIn10116(): void
    {
        foreach ([
            'obědy' => [['deduction_kind' => 'meal'], false],
            '§ 147' => [['deduction_kind' => 'advance', 'legal_basis' => 'zp_147_1_c'], false],
            'srážka z podkladů docházky (PAMICA S07)' => [['deduction_kind' => 'imported'], false],
            'dohoda podle OZ' => [['deduction_kind' => 'other'], true],
        ] as $label => [$agreement, $expected]) {
            $source = $this->source();
            $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($input);
            $input['people'][0]['deduction_agreements'] = [['id' => 5, 'title' => 'Syntetická srážka'] + $agreement];
            $this->replaceInput($source, $input);
            $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            $result['people'][0]['statutory']['net_pay']['deducted_minor_units'] = 64_100;
            $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
            $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

            $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(7, $source, 101, $this->facts(), 12, '2026-08-13T12:00:00Z');

            self::assertSame($expected, $snapshot->payload['attribute_values']['10116'], $label);
        }
    }

    /**
     * 10116 = zaměstnavatel v měsíci srážky PROVÁDÍ (pokyny MPSV k MH 1.4.13).
     * Zahájené insolvenční řízení bez exekuce nic nesráží a dohodu pozastaví
     * (§ 109 odst. 1 písm. c) a d) IZ); dřív samotný režim `alert_only` dal ANO.
     * Aktivní exekuce s nulovou srážkou (nezabavitelné minimum) i schválené
     * oddlužení zůstávají ANO.
     */
    public function testOnlyDeductionsActuallyPerformedInTheMonthAreReportedIn10116(): void
    {
        $claim = (new DeductionClaim(
            'claim:1',
            DeductionLegalBasis::Statutory,
            ClaimCategory::NonPriority,
            1_000_000,
            '2026-06-01',
            legalTitleVerified: true,
            orderOrNoticeDelivered: true,
            orderIssuedOn: '2026-05-20',
            priorityClassificationVerified: true,
        ))->toCanonicalArray();
        foreach ([
            'insolvence zahájena, bez exekuce' => ['alert_only', [], [], false],
            'insolvence zahájena, dohoda pozastavena' => ['alert_only', [], [['deduction_kind' => 'other']], false],
            'insolvence zahájena, exekuce se deponuje' => ['alert_only', [$claim], [], true],
            'neaktivní pohledávka' => ['none', [['active' => false] + $claim], [], false],
            'aktivní exekuce, srážka 0 (nezabavitelné minimum)' => ['none', [$claim], [], true],
            'schválené oddlužení' => ['approved_standard', [], [], true],
        ] as $label => [$mode, $claims, $agreements, $expected]) {
            $source = $this->source();
            $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($input);
            self::assertIsArray($result);
            $input['people'][0]['enforcement_evidence']['insolvency']['mode'] = $mode;
            $input['people'][0]['enforcement_evidence']['claims'] = $claims;
            $input['people'][0]['deduction_agreements'] = array_map(
                static fn (array $agreement): array => ['id' => 5, 'title' => 'Syntetická srážka'] + $agreement,
                $agreements,
            );
            $result['people'][0]['enforcement']['input']['insolvency']['mode'] = $mode;
            $result['people'][0]['enforcement']['input']['claims'] = $claims;
            $result['people'][0]['enforcement']['result']['insolvency_applied'] = $mode !== 'none';
            $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
            $this->replaceInput($source, $input);

            $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(7, $source, 101, $this->facts(), 12, '2026-08-13T12:00:00Z');

            self::assertSame($expected, $snapshot->payload['attribute_values']['10116'], $label);
        }
    }

    /**
     * Revize se dvěma osobami zmrazí evidenci za KAŽDÝ vztah zvlášť.
     *
     * Bez opravy tady builder skončil `jmhz_ordinary_evidence_scope_unsupported`
     * ("První ordinary profil vyžaduje právě jednu osobu"), takže firma s víc
     * zaměstnanci ordinary evidenci nezmrazila vůbec.
     */
    public function testEachEmploymentOfATwoPersonRevisionGetsItsOwnEvidence(): void
    {
        $source = $this->sourceWithTwoPeople();
        $builder = new JmhzOrdinaryEvidenceBuilder();

        $first = $builder->build(7, $source, 101, $this->facts(), 12, '2026-08-13T12:00:00Z');
        $second = $builder->build(7, $source, 102, $this->facts(), 12, '2026-08-13T12:00:00Z');

        self::assertSame(11, $first->payload['scope']['employee_id']);
        self::assertSame(101, $first->payload['scope']['employment_id']);
        self::assertSame(12, $second->payload['scope']['employee_id']);
        self::assertSame(102, $second->payload['scope']['employment_id']);
    }

    /** Vztah, který v revizi není, musí být pojmenovaná chyba. */
    public function testEvidenceForAnEmploymentOutsideTheRevisionIsRejected(): void
    {
        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $this->sourceWithTwoPeople(),
                999,
                $this->facts(),
                12,
                '2026-08-13T12:00:00Z',
            );
            self::fail('Vztah mimo revizi musí být odmítnut.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_scope_mismatch', $exception->validationCode);
        }
    }

    /**
     * Přehled evidence volá builder za KAŽDÝ vztah revize nad týmž zdrojem.
     *
     * Dřív každé volání znovu ověřilo otisk, dekódovalo a kanonicky
     * překódovalo oba snímky revize (u 226 lidí 7,7 MB) a znovu načetlo
     * katalogy JMHZ z disku — přehled pro 226 vztahů trval ~40 s a webový
     * požadavek končil timeoutem. Test srovnává cenu dalšího volání s prvním,
     * takže nezávisí na rychlosti stroje: práce za celou revizi se smí udělat
     * jednou, další vztah smí stát jen zlomek.
     */
    public function testBuildingEveryEmploymentOfALargeRevisionDoesNotRedoTheWholeRevision(): void
    {
        $count = 120;
        $source = $this->sourceWithPeople($count);
        $builder = new JmhzOrdinaryEvidenceBuilder();

        $started = hrtime(true);
        $builder->build(7, $source, 101, $this->facts(), 12, '2026-08-13T12:00:00Z');
        $first = hrtime(true) - $started;

        $started = hrtime(true);
        for ($ordinal = 2; $ordinal <= $count; ++$ordinal) {
            $snapshot = $builder->build(7, $source, 100 + $ordinal, $this->facts(), 12, '2026-08-13T12:00:00Z');
            self::assertSame(100 + $ordinal, $snapshot->payload['scope']['employment_id']);
        }
        $averageNext = (hrtime(true) - $started) / ($count - 1);

        self::assertLessThan(
            $first / 10,
            $averageNext,
            sprintf(
                'Další vztah téže revize stojí %.2f ms, první %.2f ms — builder zřejmě znovu zpracovává celou revizi.',
                $averageNext / 1e6,
                $first / 1e6,
            ),
        );
    }

    /** Totéž pro kontrolu použitelnosti už uložené evidence. */
    public function testApplicabilityOfManyStoredEvidencesDoesNotReloadCatalogs(): void
    {
        $source = $this->source();
        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(7, $source, 101, $this->facts(), 12, '2026-08-13T12:00:00Z');
        $evidence = [
            'id' => 1,
            'source_manifest_sha256' => str_repeat('a', 64),
            'snapshot_fingerprint' => str_repeat('b', 64),
            'payload' => $snapshot->payload,
        ];
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $term = $input['people'][0]['employments'][0]['term'];
        $applicability = new JmhzOrdinaryEvidenceApplicability();
        // Přibalené katalogy drží proces ověřené; první kontrola je má načíst za studena.
        (new \ReflectionProperty(JmhzScenarioRequirementSourceCatalog::class, 'bundled'))->setValue(null, null);
        (new \ReflectionProperty(JmhzSpecPackageCatalog::class, 'bundled'))->setValue(null, []);
        (new \ReflectionProperty(JmhzSpecPackageCatalog::class, 'validated'))->setValue(null, []);

        $started = hrtime(true);
        $applicability->assertApplicable($evidence, 7, $source['revision'], 11, 101, $term);
        $first = hrtime(true) - $started;

        $count = 60;
        $started = hrtime(true);
        for ($i = 0; $i < $count; ++$i) {
            $applicability->assertApplicable($evidence, 7, $source['revision'], 11, 101, $term);
        }
        $averageNext = (hrtime(true) - $started) / $count;

        self::assertLessThan(
            $first / 10,
            $averageNext,
            sprintf(
                'Další kontrola stojí %.2f ms, první %.2f ms — katalogy se zřejmě načítají pro každý vztah znovu.',
                $averageNext / 1e6,
                $first / 1e6,
            ),
        );
    }

    /**
     * Podpora zaměstnávání OZP (IN28): výdělek OZP z potvrzení účetní (10347,
     * 10348) a srážky ve prospěch zaměstnavatele ze zmrazené revize podle
     * zařazení dohody (10350 až 10353). Srážka ve prospěch třetí osoby, srážka
     * ze zákona (§ 147 odst. 1 písm. c) ZP, datový slovník vede 10353 jako
     * srážku „podle § 146 písm. b)") ani nulová srážka se nezapočítají.
     * Dřív builder kladné IN28 odmítl (`positive_unsupported`).
     */
    public function testOzpSupportFreezesWageAndEmployerDeductionsByTarget(): void
    {
        $source = $this->ozpSource([
            [['id' => 5, 'deduction_kind' => 'meal', 'creditor_is_employer' => true, 'jmhz_target' => '10352'], 120_000],
            [['id' => 6, 'deduction_kind' => 'advance', 'creditor_is_employer' => true, 'jmhz_target' => '10350'], 40_000],
            [['id' => 7, 'deduction_kind' => 'advance', 'creditor_is_employer' => true, 'jmhz_target' => '10350'], 10_000],
            [['id' => 8, 'deduction_kind' => 'other', 'creditor_is_employer' => false], 50_000],
            [['id' => 9, 'deduction_kind' => 'advance', 'legal_basis' => 'zp_147_1_c'], 30_000],
            [['id' => 10, 'deduction_kind' => 'damage', 'creditor_is_employer' => true, 'jmhz_target' => '10351'], 0],
        ]);

        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $source,
            101,
            $this->facts(['ozp_employment_support_claimed' => true]),
            12,
            '2026-08-13T12:00:00Z',
            'explicit_confirmation',
            ['payment_date' => '2026-08-12', 'cash_paid' => true],
        );

        self::assertTrue($snapshot->payload['interaction_decisions'][1]['triggered']);
        self::assertSame('IN28', $snapshot->payload['interaction_decisions'][1]['interaction_id']);
        self::assertSame([
            'payment_date' => '2026-08-12',
            'cash_paid' => true,
            'employer_deductions_made' => true,
            'employer_deductions_minor' => [10350 => 50_000, 10352 => 120_000],
            'employer_deduction_sources' => [
                ['agreement_id' => 5, 'jmhz_target' => '10352', 'applied_minor_units' => 120_000],
                ['agreement_id' => 6, 'jmhz_target' => '10350', 'applied_minor_units' => 40_000],
                ['agreement_id' => 7, 'jmhz_target' => '10350', 'applied_minor_units' => 10_000],
            ],
        ], $snapshot->payload['ozp_wage']);

        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $evidence = [
            'id' => 1,
            'source_manifest_sha256' => str_repeat('a', 64),
            'snapshot_fingerprint' => str_repeat('b', 64),
            'payload' => json_decode($snapshot->canonicalJson(), true, flags: JSON_THROW_ON_ERROR),
        ];
        (new JmhzOrdinaryEvidenceApplicability())->assertApplicable(
            $evidence,
            7,
            $source['revision'],
            11,
            101,
            $input['people'][0]['employments'][0]['term'],
        );
    }

    /**
     * Automatické doložení vztahu s IN28 nemá datum úhrady ani hotovost, takže
     * se zastaví s pojmenovaným nálezem; datum se nikdy nedomýšlí. Dřív tu
     * padalo obecné `monthly_exception_required`.
     */
    public function testDerivedConfirmationOfOzpSupportRequiresTheWageData(): void
    {
        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $this->ozpSource([]),
                101,
                $this->facts(),
                12,
                '2026-08-13T12:00:00Z',
                'derived_from_frozen_payroll_sources',
            );
            self::fail('Výdělek OZP bez data úhrady se nesmí zmrazit.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_ozp_wage_required', $exception->validationCode);
        }
    }

    /**
     * Fail-closed případy výdělku OZP: dohoda bez rozhodnutí o věřiteli, datum
     * úhrady po dni potvrzení (kontrola 20201, FAQ MPSV 11. 5. 2026 dotaz 12)
     * a před vykazovaným měsícem, potvrzení v rozporu s nastavením vztahu
     * a zaměstnavatel neuznaný na chráněném trhu práce.
     */
    public function testOzpWageFailsClosed(): void
    {
        $cases = [
            'nerozhodnutý věřitel' => [
                $this->ozpSource([[['id' => 5, 'deduction_kind' => 'meal'], 1_000]]),
                $this->facts(['ozp_employment_support_claimed' => true]),
                '2026-08-12',
                'jmhz_ordinary_evidence_ozp_deduction_unclassified',
            ],
            'úhrada po potvrzení' => [
                $this->ozpSource([]),
                $this->facts(['ozp_employment_support_claimed' => true]),
                '2026-08-14',
                'jmhz_ordinary_evidence_ozp_payment_date_invalid',
            ],
            'úhrada před měsícem' => [
                $this->ozpSource([]),
                $this->facts(['ozp_employment_support_claimed' => true]),
                '2026-06-30',
                'jmhz_ordinary_evidence_ozp_payment_date_invalid',
            ],
            'potvrzení Ne proti nastavení vztahu' => [
                $this->ozpSource([]),
                $this->facts(),
                null,
                'jmhz_ordinary_evidence_ozp_profile_mismatch',
            ],
            'zaměstnavatel mimo chráněný trh' => [
                $this->ozpSource([], false),
                $this->facts(['ozp_employment_support_claimed' => true]),
                '2026-08-12',
                'jmhz_ordinary_evidence_ozp_employer_not_protected',
            ],
        ];
        foreach ($cases as $label => [$source, $facts, $paymentDate, $code]) {
            try {
                (new JmhzOrdinaryEvidenceBuilder())->build(
                    7,
                    $source,
                    101,
                    $facts,
                    12,
                    '2026-08-13T12:00:00Z',
                    'explicit_confirmation',
                    $paymentDate === null ? null : ['payment_date' => $paymentDate, 'cash_paid' => false],
                );
                self::fail("{$label}: výdělek OZP se nesmí zmrazit.");
            } catch (JmhzOrdinaryEvidenceException $exception) {
                self::assertSame($code, $exception->validationCode, $label);
            }
        }
    }

    /**
     * Od 01/2027 se 10347 dělí na datum úhrady mzdy 10589 a dny odvodu
     * sociálního 10570 a zdravotního pojištění 10571: bez nich se výdělek OZP
     * nezmrazí, s nimi je evidence nese a projde kontrolou použitelnosti.
     * V roce 2026 zůstává tvar evidence beze změny.
     */
    public function testOzpWageFrom2027CarriesContributionDates(): void
    {
        $source = $this->atPeriod($this->ozpSource([]), '2027-01-01');
        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $source,
                101,
                $this->facts(['ozp_employment_support_claimed' => true]),
                12,
                '2027-02-13T12:00:00Z',
                'explicit_confirmation',
                ['payment_date' => '2027-02-10', 'cash_paid' => false],
            );
            self::fail('Výdělek OZP za 2027 bez dat odvodů se nesmí zmrazit.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_ozp_wage_required', $exception->validationCode);
        }

        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $source,
            101,
            $this->facts(['ozp_employment_support_claimed' => true]),
            12,
            '2027-02-13T12:00:00Z',
            'explicit_confirmation',
            [
                'payment_date' => '2027-02-10',
                'cash_paid' => false,
                'social_insurance_paid_on' => '2027-02-11',
                'health_insurance_paid_on' => '2027-02-12',
            ],
        );

        self::assertSame('2027-02-11', $snapshot->payload['ozp_wage']['social_insurance_paid_on']);
        self::assertSame('2027-02-12', $snapshot->payload['ozp_wage']['health_insurance_paid_on']);
        $this->assertApplicable($source, $snapshot->canonicalJson());
    }

    /**
     * Práce kategorie 4 od 01/2027: počet směn (10580) mzdy neevidují, takže
     * automatické doložení se zastaví a účetní ho zadá. V roce 2026 se nic
     * nemění; u jiného vztahu počet směn uvést nejde.
     */
    public function testRiskShiftCountIsRequiredForCategoryFourFrom2027(): void
    {
        $risk = $this->sourceWithOrdinaryProfile();
        $input = json_decode($risk['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $input['people'][0]['employments'][0]['term']['social_employer_rate_category'] = 'risk_employment';
        $this->replaceInput($risk, $input);
        $risk2027 = $this->atPeriod($risk, '2027-01-01');

        (new JmhzOrdinaryEvidenceBuilder())->build(7, $risk, 101, $this->facts(), 12, '2026-08-13T12:00:00Z', 'derived_from_frozen_payroll_sources');
        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(7, $risk2027, 101, $this->facts(), 12, '2027-02-13T12:00:00Z', 'derived_from_frozen_payroll_sources');
            self::fail('Bez počtu směn se evidence 2027 rizikové práce nesmí zmrazit.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_risk_shifts_required', $exception->validationCode);
        }

        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $risk2027,
            101,
            $this->facts(),
            12,
            '2027-02-13T12:00:00Z',
            'explicit_confirmation',
            null,
            20,
        );
        self::assertSame(20, $snapshot->payload['risk_shift_count']);
        $this->assertApplicable($risk2027, $snapshot->canonicalJson());

        $this->expectException(JmhzOrdinaryEvidenceException::class);
        (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $this->atPeriod($this->source(), '2027-01-01'),
            101,
            $this->facts(),
            12,
            '2027-02-13T12:00:00Z',
            'explicit_confirmation',
            null,
            3,
        );
    }

    /**
     * Kontrola 386 a IN42: 10580 jen při hodinách rizikové práce (10273 > 0).
     * V měsíci bez odpracovaných hodin se počet směn nežádá, stejně jako ho
     * resolver a serializér do věty nezapíšou ({@see Jmhz2027DocumentContract::riskShiftCountRequired()}).
     */
    public function testRiskShiftCountIsNotRequiredWithoutWorkedHours(): void
    {
        $risk = $this->sourceWithOrdinaryProfile();
        $input = json_decode($risk['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $input['people'][0]['employments'][0]['term']['social_employer_rate_category'] = 'risk_employment';
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']['values'] = [
            'worked_millihours' => 0,
            'overtime_millihours' => null,
        ];
        $this->replaceInput($risk, $input);
        $risk2027 = $this->atPeriod($risk, '2027-01-01');

        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7, $risk2027, 101, $this->facts(), 12, '2027-02-13T12:00:00Z', 'derived_from_frozen_payroll_sources',
        );
        self::assertArrayNotHasKey('risk_shift_count', $snapshot->payload);

        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']['values']['worked_millihours'] = 8_000;
        $this->replaceInput($risk, $input);
        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7, $this->atPeriod($risk, '2027-01-01'), 101, $this->facts(), 12, '2027-02-13T12:00:00Z',
                'derived_from_frozen_payroll_sources',
            );
            self::fail('S hodinami rizikové práce se počet směn žádá.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_risk_shifts_required', $exception->validationCode);
        }
    }

    /**
     * @param array{revision:array<string,mixed>} $source
     * @return array{revision:array<string,mixed>}
     */
    private function atPeriod(array $source, string $periodStart): array
    {
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['period_start'] = $periodStart;
        $input['period_end'] = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $source['revision']['period_start'] = $periodStart;
        $this->replaceInput($source, $input);

        return $source;
    }

    /** @param array{revision:array<string,mixed>} $source */
    private function assertApplicable(array $source, string $canonicalJson): void
    {
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        (new JmhzOrdinaryEvidenceApplicability())->assertApplicable(
            [
                'id' => 1,
                'source_manifest_sha256' => str_repeat('a', 64),
                'snapshot_fingerprint' => str_repeat('b', 64),
                'payload' => json_decode($canonicalJson, true, flags: JSON_THROW_ON_ERROR),
            ],
            7,
            $source['revision'],
            11,
            101,
            $input['people'][0]['employments'][0]['term'],
        );
    }

    /**
     * Bez IN28 evidence nenese klíč `ozp_wage` a má tvar jako dřív, takže
     * otisky dosavadních potvrzení zůstávají stejné.
     */
    public function testEvidenceWithoutOzpSupportKeepsItsShape(): void
    {
        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(7, $this->source(), 101, $this->facts(), 12, '2026-08-13T12:00:00Z');

        self::assertSame([
            'schema_reference', 'builder_version', 'scope', 'specification', 'source_revision',
            'attribute_values', 'interaction_decisions', 'derived_interactions', 'confirmation',
        ], array_keys($snapshot->payload));
        self::assertSame([false, false, false], array_column($snapshot->payload['interaction_decisions'], 'triggered'));
    }

    /**
     * Zdroj s vztahem, který má podporu zaměstnávání OZP a zaměstnavatele na
     * chráněném trhu práce, a s dohodami a jejich sraženou částkou v měsíci.
     *
     * @param list<array{array<string,mixed>,int}> $agreements
     * @return array{revision:array<string,mixed>}
     */
    private function ozpSource(array $agreements, bool $protectedEmployer = true): array
    {
        $source = $this->sourceWithOrdinaryProfile(['ozp_employment_support_applies' => true]);
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['term']['employer_protected_labor_market'] = $protectedEmployer;
        $input['people'][0]['employments'][0]['term']['jmhz_ozp_employment_support_applies'] = true;
        $input['people'][0]['deduction_agreements'] = array_map(
            static fn (array $row): array => ['title' => 'Syntetická srážka'] + $row[0],
            $agreements,
        );
        $this->replaceInput($source, $input);
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['net_pay']['deductions'] = array_map(
            static fn (array $row): array => [
                'deduction_reference' => 'agreement:' . $row[0]['id'],
                'priority' => 100,
                'requested_minor_units' => $row[1],
                'applied_minor_units' => $row[1],
                'unapplied_minor_units' => 0,
                'active' => true,
            ],
            $agreements,
        );
        $result['people'][0]['statutory']['net_pay']['deducted_minor_units'] = array_sum(array_column($agreements, 1));
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        return $source;
    }

    /**
     * Revize s `$count` osobami po jednom vztahu (101, 102, …). Každý vztah
     * nese výplň, aby snímek měl velikost srovnatelnou se skutečnou firmou.
     *
     * @return array{revision:array<string,mixed>}
     */
    private function sourceWithPeople(int $count): array
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        self::assertIsArray($result);
        $templatePerson = $input['people'][0];
        $templateResult = $result['people'][0];
        $input['people'] = [];
        $result['people'] = [];
        for ($ordinal = 1; $ordinal <= $count; ++$ordinal) {
            $employeeId = 10 + $ordinal;
            $person = $templatePerson;
            $person['employee']['id'] = $employeeId;
            $person['employments'][0]['employment'] = ['id' => 100 + $ordinal, 'employee_id' => $employeeId];
            $person['employments'][0]['synthetic_padding'] = str_repeat('x', 4000);
            $input['people'][] = $person;
            $resultPerson = $templateResult;
            $resultPerson['employee_id'] = $employeeId;
            $resultPerson['synthetic_padding'] = str_repeat('y', 4000);
            $result['people'][] = $resultPerson;
        }
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
        $this->replaceInput($source, $input);

        return $source;
    }

    /** @return array{revision:array<string,mixed>} */
    private function sourceWithTwoPeople(): array
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        self::assertIsArray($result);

        $person = $input['people'][0];
        $person['employee']['id'] = 12;
        $person['employments'][0]['employment'] = ['id' => 102, 'employee_id' => 12];
        $input['people'][] = $person;

        $resultPerson = $result['people'][0];
        $resultPerson['employee_id'] = 12;
        $result['people'][] = $resultPerson;

        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
        $this->replaceInput($source, $input);

        return $source;
    }

    /**
     * @param array<string,bool> $overrides
     * @return array<string,bool>
     */
    private function facts(array $overrides = []): array
    {
        return array_replace([
            'reportable_wage_deductions_recorded' => false,
            'employee_social_discount_claimed' => false,
            'specific_legal_fact_occurred' => false,
            'ozp_employment_support_claimed' => false,
            'deep_mining_work_occurred' => false,
        ], $overrides);
    }

    /** @return array{revision:array<string,mixed>} */
    private function source(): array
    {
        $enforcement = [
            'claim_register_evidence_complete' => true,
            'claims' => [],
            'dependants_evidence_complete' => false,
            'eligible_dependants' => 0,
            'eligible_spouse' => false,
            'has_multiple_payers' => false,
            'insolvency' => [
                'court_determined_amount_minor_units' => null,
                'decision_verified' => false,
                'employment_id' => null,
                'mode' => 'none',
                'payment_instruction_hash' => null,
                'payment_instruction_id' => null,
                'recipient_verified' => false,
            ],
            'pension_evidence' => 'unknown',
            'protected_amount_override_minor_units' => null,
            'protected_amount_override_verified' => false,
            'spouse_evidence_complete' => false,
        ];
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-07-01',
            'people' => [[
                'employee' => ['id' => 11],
                'deduction_agreements' => [],
                'enforcement_evidence' => $enforcement,
                'employments' => [[
                    'employment' => ['id' => 101, 'employee_id' => 11],
                    'term' => [
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                    ],
                ]],
            ]],
        ];
        $inputJson = CanonicalJson::encode($input);
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $inputJson),
            'people' => [[
                'employee_id' => 11,
                'statutory' => [
                    'status' => 'calculated',
                    'net_pay' => [
                        'deductions' => [],
                        'deducted_minor_units' => 0,
                    ],
                ],
                'enforcement' => [
                    'input' => [
                        'claims' => [],
                        'evidence' => [
                            'claim_register_complete' => true,
                            'dependants_complete' => false,
                            'eligible_dependants' => 0,
                            'eligible_spouse' => false,
                            'has_multiple_payers' => false,
                            'pension' => 'unknown',
                            'protected_amount_override_minor_units' => null,
                            'protected_amount_override_verified' => false,
                            'spouse_complete' => false,
                        ],
                        'income' => [
                            'excluded_minor_units' => 0,
                            'garnishable_minor_units' => 0,
                            'issues' => [],
                            'status' => 'supported',
                            'trace' => [],
                        ],
                        'insolvency' => [
                            'court_determined_amount_minor_units' => null,
                            'decision_verified' => false,
                            'employment_id' => null,
                            'mode' => 'none',
                            'payment_instruction_hash' => null,
                            'payment_instruction_id' => null,
                            'recipient_verified' => false,
                        ],
                        'payment_date' => '2026-08-15',
                        'period' => '2026-07',
                    ],
                    'result' => [
                        'status' => 'supported',
                        'issues' => [],
                        'allocations' => [],
                        'total_withheld_minor_units' => 0,
                        'insolvency_applied' => false,
                    ],
                ],
            ]],
        ];
        $resultJson = CanonicalJson::encode($result);
        return ['revision' => [
            'id' => 301,
            'run_id' => 401,
            'revision_no' => 1,
            'current_revision_no' => 1,
            'revision_kind' => 'regular',
            'status' => 'approved',
            'period_start' => '2026-07-01',
            'input_snapshot_json' => $inputJson,
            'input_snapshot_hash' => hash('sha256', $inputJson),
            'result_snapshot_json' => $resultJson,
            'result_snapshot_hash' => hash('sha256', $resultJson),
            'ruleset_manifest_hash' => str_repeat('a', 64),
        ]];
    }

    /**
     * @param array<string,bool> $overrides
     * @return array{revision:array<string,mixed>}
     */
    private function sourceWithOrdinaryProfile(array $overrides = []): array
    {
        $source = $this->source();
        $input = json_decode(
            $source['revision']['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($input);
        $profile = array_replace([
            'source_term_id' => 81,
            'source_term_row_version' => 3,
            'orchard_discount_eligible' => false,
            'specific_legal_fact_applies' => false,
            'ozp_employment_support_applies' => false,
            'deep_mining_work_applies' => false,
        ], $overrides);
        $input['people'][0]['employments'][0]['ordinary_evidence_profile'] = $profile;
        $this->replaceInput($source, $input);
        return $source;
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $input */
    private function replaceInput(array &$source, array $input): void
    {
        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] = hash('sha256', $source['revision']['input_snapshot_json']);
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['source_snapshot_hash'] = $source['revision']['input_snapshot_hash'];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
    }

}
