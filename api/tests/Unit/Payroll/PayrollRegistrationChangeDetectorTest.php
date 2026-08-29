<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDeltaPlanner;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetector;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationReportableCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationReportableProfileBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Detekce změn hlásitelných do registru pojištěnců.
 *
 * Testy jsou psané na dvě strany téže hranice: co se hlásit MÁ (a musí se
 * poznat konkrétní údaj, ne jen „něco se změnilo") a co se hlásit NESMÍ,
 * protože jde o měsíční atribut hlášení.
 */
final class PayrollRegistrationChangeDetectorTest extends TestCase
{
    private const IDENTITY = [
        'first_name' => 'Jan',
        'last_name' => 'Novák',
        'title_prefix' => null,
        'title_suffix' => null,
        'birth_surname' => null,
        'birth_date' => '1985-03-14',
        'sex' => 'M',
        'citizenship_country_code' => 'CZ',
    ];

    private const IDENTIFIERS = [
        'birth_number' => '850314/1234',
        'ecp' => null,
        'vcp' => null,
    ];

    /** @return array<string,mixed> */
    private function profile(): array
    {
        return [
            'permanent_address' => [
                'street' => 'Dlouhá',
                'house_number' => '12',
                'orientation_number' => null,
                'city' => 'Praha',
                'postal_code' => '11000',
                'country_code' => 'CZ',
                'ruian_point' => null,
            ],
            'contact_address' => null,
            'czech_residence_address' => null,
            'tax_residency' => [
                'country_code' => 'CZ',
                'identifier_type' => null,
                'identifier' => null,
                'residence_address' => null,
            ],
            'employment' => [
                'activity_code' => '01',
                'relationship_detail_code' => '1',
                'actual_start_on' => '2026-08-01',
                'contract_start_on' => '2026-07-25',
                'small_scale' => false,
                'employment_status_code' => '10',
                'work_mode_code' => '01',
                'continuous_operation' => false,
                'prevailing_workplace_code' => '01',
                'expected_workplaces' => null,
                'contract_workplace' => 'Praha',
                'workplace_city' => 'Praha',
                'workplace_municipality_code' => '554782',
                'profession_code' => '2511',
                'required_education_code' => 'T',
                'position_name' => 'Vývojář',
                'leadership' => false,
            ],
            'pension' => null,
            'health_insurance_code' => '111',
            'facts' => [
                'highest_education_code' => 'T',
                'disability_card' => false,
                'health_restrictions' => [],
            ],
            'foreign_legislation' => ['applies' => false, 'country_code' => null],
            'proof_identity' => null,
            'foreign_worker' => null,
        ];
    }

    private function builder(): PayrollRegistrationReportableProfileBuilder
    {
        return new PayrollRegistrationReportableProfileBuilder();
    }

    public function testUnchangedProfileProducesNothing(): void
    {
        $builder = $this->builder();
        $baseline = $builder->build(self::IDENTITY, self::IDENTIFIERS, $this->profile());
        $current = $builder->build(self::IDENTITY, self::IDENTIFIERS, $this->profile());

        self::assertSame([], (new PayrollRegistrationChangeDetector())
            ->compare($baseline, $current));
    }

    /**
     * Návrh musí říct KTERÝ údaj, ne jen že se něco stalo. Bez toho nemá
     * účetní jak ověřit, jestli je návrh pravda.
     */
    public function testAddressAndActivityChangeAreNamedIndividually(): void
    {
        $builder = $this->builder();
        $baseline = $builder->build(self::IDENTITY, self::IDENTIFIERS, $this->profile());
        $changed = $this->profile();
        $changed['permanent_address']['street'] = 'Krátká';
        $changed['permanent_address']['house_number'] = '3';
        $current = $builder->build(
            self::IDENTITY,
            self::IDENTIFIERS,
            $changed,
            ['employment.activity_code' => '02'],
        );

        $paths = array_map(
            static fn ($finding): string => $finding->path,
            (new PayrollRegistrationChangeDetector())->compare($baseline, $current),
        );

        self::assertSame([
            'employment.activity_code',
            'permanent_address.house_number',
            'permanent_address.street',
        ], $paths);
    }

    /**
     * Jádro celého úkolu: úvazek a mzda jsou MĚSÍČNÍ atributy hlášení
     * (příloha č. 1 část C nař. vl. 417/2025 Sb.). Jejich změna osmidenní
     * lhůtu nespouští a registrační událost vzniknout NESMÍ.
     */
    public function testWorkloadAndSalaryNeverProduceAFinding(): void
    {
        $builder = $this->builder();
        $baseline = $builder->build(self::IDENTITY, self::IDENTIFIERS, $this->profile());

        // 1. Měsíční atribut se do průmětu vůbec nedostane, i kdyby ho tam
        //    někdo poslal: builder ho odmítne s právním důvodem.
        foreach ([
            'terms.weekly_hours' => '40.00',
            'terms.statutory_weekly_hours' => '40.00',
            'terms.monthly_gross_minor' => '9500000',
        ] as $path => $value) {
            self::assertTrue(
                PayrollRegistrationReportableCatalog::isMonthlyReportOnly($path),
                "Katalog musí {$path} vést jako měsíční atribut.",
            );
            try {
                $builder->build(
                    self::IDENTITY,
                    self::IDENTIFIERS,
                    $this->profile(),
                    [$path => $value],
                );
                self::fail("Průmět přijal měsíční atribut {$path}.");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('měsíční atribut', $exception->getMessage());
            }
        }

        // 2. A i kdyby se v profilu takový údaj objevil, porovnání ho nevidí:
        //    hlásitelné cesty jsou uzavřený seznam z přílohy č. 4 části G.
        $withPayroll = $this->profile();
        $withPayroll['employment']['weekly_hours'] = '30.00';
        $withPayroll['monthly_gross_minor'] = 9500000;
        $current = $builder->build(self::IDENTITY, self::IDENTIFIERS, $withPayroll);

        self::assertSame([], (new PayrollRegistrationChangeDetector())
            ->compare($baseline, $current));
    }

    /**
     * Vznik a skončení příslušnosti k cizím předpisům jde akcemi A6/A7,
     * ne A3 — poslat je jako A3 by byla správná skutečnost ve špatné akci.
     */
    public function testForeignLegislationStartAndEndUseTheirOwnActions(): void
    {
        $builder = $this->builder();
        $off = $this->profile();
        $on = $this->profile();
        $on['foreign_legislation'] = ['applies' => true, 'country_code' => 'SK'];

        $detector = new PayrollRegistrationChangeDetector();
        $start = $detector->compare(
            $builder->build(self::IDENTITY, self::IDENTIFIERS, $off),
            $builder->build(self::IDENTITY, self::IDENTIFIERS, $on),
        );
        $end = $detector->compare(
            $builder->build(self::IDENTITY, self::IDENTIFIERS, $on),
            $builder->build(self::IDENTITY, self::IDENTIFIERS, $off),
        );

        $applies = static fn (array $findings): int => array_values(array_filter(
            $findings,
            static fn ($finding): bool => $finding->path === 'foreign_legislation.applies',
        ))[0]->actionCode;

        self::assertSame(6, $applies($start));
        self::assertSame(7, $applies($end));
    }

    /** Hodnoty citlivých identifikátorů se z návrhu nesmí dostat ven. */
    public function testSensitiveIdentifiersNeverLeakTheirValues(): void
    {
        $builder = $this->builder();
        $baseline = $builder->build(self::IDENTITY, self::IDENTIFIERS, $this->profile());
        $current = $builder->build(
            self::IDENTITY,
            ['birth_number' => '850314/9999', 'ecp' => null, 'vcp' => null],
            $this->profile(),
        );

        $findings = (new PayrollRegistrationChangeDetector())
            ->compare($baseline, $current);

        self::assertCount(1, $findings);
        self::assertSame('identifiers.birth_number', $findings[0]->path);
        self::assertTrue($findings[0]->sensitive);
        $public = $findings[0]->toArray();
        self::assertNull($public['from']);
        self::assertNull($public['to']);
    }

    /**
     * Údaj, o kterém výchozí stav nic neví, není změna. Jinak by každý starší
     * snapshot spustil lavinu podání, která nemají co opravovat.
     */
    public function testPathsMissingFromTheBaselineAreNotChanges(): void
    {
        $builder = $this->builder();
        $baseline = $builder->build(self::IDENTITY, self::IDENTIFIERS, null);
        $current = $builder->build(self::IDENTITY, self::IDENTIFIERS, $this->profile());

        self::assertSame([], (new PayrollRegistrationChangeDetector())
            ->compare($baseline, $current));
    }

    /**
     * Plánovač musí umět jedním kliknutím poslat to, co datová věta A3 nese,
     * a zbytek nesmí zamlčet.
     */
    public function testPlannerSplitsFileableAndManualFields(): void
    {
        $builder = $this->builder();
        $baseline = $builder->build(self::IDENTITY, self::IDENTIFIERS, $this->profile());
        $changed = $this->profile();
        $changed['health_insurance_code'] = '201';
        $changed['permanent_address']['city'] = 'Brno';
        $current = $builder->build(self::IDENTITY, self::IDENTIFIERS, $changed);

        $findings = (new PayrollRegistrationChangeDetector())
            ->compare($baseline, $current);
        $plan = (new PayrollRegistrationChangeDeltaPlanner())
            ->plan($findings, $current, '2026-08-29');

        self::assertSame(['health_insurance_code' => '201'], $plan['changes']);
        self::assertSame([[
            'path' => 'permanent_address.city',
            'reason_code' => 'registration_change_field_not_in_a3_payload',
        ]], $plan['unsupported']);
    }

    /** Katalog nesmí mít průnik s měsíčními atributy hlášení. */
    public function testCatalogAndMonthlyAttributesNeverOverlap(): void
    {
        foreach (PayrollRegistrationReportableCatalog::MONTHLY_REPORT_ONLY as $path) {
            self::assertFalse(
                PayrollRegistrationReportableCatalog::isReportable($path),
                "Měsíční atribut {$path} nesmí být v katalogu hlásitelných údajů.",
            );
        }
    }
}
