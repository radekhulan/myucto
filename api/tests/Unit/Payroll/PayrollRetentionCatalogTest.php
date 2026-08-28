<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Accounting\RetentionPolicy;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Katalog zákonných retenčních lhůt — tvrzení, která se nesmí rozjet.
 *
 * Katalog řídí NEVRATNÉ mazání osobních údajů, takže tady nejde o styl, ale
 * o následek: lhůta bez citace se nedá obhájit, neurčená lhůta uvedená jako
 * číslo maže dřív, než smí, a druhé číslo pro účetní lhůtu by se při novele
 * opravilo jen na jedné straně.
 */
final class PayrollRetentionCatalogTest extends TestCase
{
    public function testEveryCategoryCitesItsLegalBasis(): void
    {
        foreach (PayrollRetentionCatalog::rules() as $rule) {
            self::assertNotSame('', $rule->act, "Kategorie {$rule->category} neuvádí zákon.");
            self::assertNotSame(
                '',
                $rule->source(),
                "Kategorie {$rule->category} nemá citaci do UI ani do auditu.",
            );
            self::assertNotSame('', $rule->note, "Kategorie {$rule->category} nevysvětluje výklad.");
            self::assertContains($rule->sourceStatus, [
                PayrollRetentionCatalog::STATUTE_VERIFIED,
                PayrollRetentionCatalog::STATUTE_SILENT,
                PayrollRetentionCatalog::EXTERNAL_UNVERIFIED,
                PayrollRetentionCatalog::UNDETERMINED,
            ], "Kategorie {$rule->category} má neznámý stav doloženosti.");
            self::assertContains($rule->origin, [
                PayrollRetentionCatalog::ORIGIN_STATUTE,
                PayrollRetentionCatalog::ORIGIN_HOUSE_POLICY,
                PayrollRetentionCatalog::ORIGIN_NONE,
            ], "Kategorie {$rule->category} má neznámý původ lhůty.");
        }
    }

    /**
     * Ověřené hodnoty proti doslovnému znění předpisů (ověření 15. 8. 2026).
     * Test je tu proto, že chybné číslo se nepozná jinak než tím, že o patnáct
     * let dřív zmizí mzdový list.
     *
     * @return array<string,array{0:string,1:int,2:string}>
     */
    public static function verifiedPeriods(): array
    {
        return [
            'mzdové listy' => [
                PayrollRetentionCatalog::PAYROLL_SHEET,
                45,
                '§ 35a odst. 4 písm. c) zákona č. 582/1991 Sb.',
            ],
            'záznamy pro důchodové pojištění' => [
                PayrollRetentionCatalog::PENSION_EVIDENCE,
                45,
                '§ 35a odst. 4 písm. c) zákona č. 582/1991 Sb.',
            ],
            'pojistné na sociální zabezpečení' => [
                PayrollRetentionCatalog::SOCIAL_CONTRIBUTIONS,
                10,
                '§ 22c věta první zákona č. 589/1992 Sb.',
            ],
            'doklady ke slevám na pojistném' => [
                PayrollRetentionCatalog::SOCIAL_DISCOUNT_DOCS,
                10,
                '§ 22c věta druhá zákona č. 589/1992 Sb.',
            ],
            'nemocenské pojištění' => [
                PayrollRetentionCatalog::SICKNESS_INSURANCE,
                10,
                '§ 96 věta první zákona č. 187/2006 Sb.',
            ],
            'evidence pracovní doby' => [
                PayrollRetentionCatalog::WORKING_TIME,
                10,
                '§ 96 věta druhá zákona č. 187/2006 Sb.',
            ],
            'účetní doklady' => [
                PayrollRetentionCatalog::ACCOUNTING_RECORDS,
                5,
                '§ 31 odst. 2 písm. b) zákona č. 563/1991 Sb.',
            ],
            'stejnopisy ELDP' => [
                PayrollRetentionCatalog::PENSION_EVIDENCE_SHEETS,
                3,
                '§ 35a odst. 4 písm. a) zákona č. 582/1991 Sb., ve znění účinném do 31. 12. 2025',
            ],
        ];
    }

    #[DataProvider('verifiedPeriods')]
    public function testVerifiedPeriodsMatchTheStatute(
        string $category,
        int $years,
        string $section,
    ): void {
        $rule = PayrollRetentionCatalog::rule($category);

        self::assertSame($years, $rule->retentionYears, "Lhůta u {$category} neodpovídá zákonu.");
        self::assertSame($section, $rule->section, "Citace u {$category} neodpovídá zákonu.");
        self::assertTrue($rule->isStatutory(), "Kategorie {$category} má mít ZÁKONNOU lhůtu.");
        self::assertSame(
            PayrollRetentionCatalog::STATUTE_VERIFIED,
            $rule->sourceStatus,
            "Kategorie {$category} je doložená zněním předpisu, ať to i tvrdí.",
        );
        self::assertSame(PayrollRetentionCatalog::VERIFIED_ON, $rule->verifiedOn);
    }

    /**
     * Čísla, která se v posledních letech měnila, musí nést i novelu. Bez ní se
     * u příští kontroly nepozná, jestli je citace aktuální, nebo zastaralá —
     * přesně tak katalog tři a půl roku držel zrušených 30 let.
     */
    public function testChangedNumbersCiteTheAmendmentThatChangedThem(): void
    {
        foreach ([
            PayrollRetentionCatalog::PAYROLL_SHEET,
            PayrollRetentionCatalog::PENSION_EVIDENCE,
        ] as $category) {
            $amendment = (string) PayrollRetentionCatalog::rule($category)->amendment;
            self::assertStringContainsString('455/2022', $amendment);
            self::assertStringContainsString('45', $amendment);
        }

        $eldp = PayrollRetentionCatalog::rule(PayrollRetentionCatalog::PENSION_EVIDENCE_SHEETS);
        self::assertStringContainsString('360/2025', (string) $eldp->amendment);
        self::assertTrue(
            $eldp->closingAgenda,
            'Stejnopisy ELDP jsou dobíhající agenda — ustanovení bylo zrušeno a lhůta '
            . 'žije jen v přechodných ustanoveních.',
        );
    }

    /**
     * Číslo, které v žádné sbírce není, se nesmí tvářit jako paragraf. Zdravotní
     * pojištění je jediná dodaná lhůta katalogu: fulltext zákona č. 592/1992 Sb.
     * uschovávací lhůtu neobsahuje, deset let je rozhodnutí aplikace.
     */
    public function testHealthInsuranceIsAHousePolicyAndSaysSo(): void
    {
        $health = PayrollRetentionCatalog::rule(PayrollRetentionCatalog::HEALTH_INSURANCE);

        self::assertSame(10, $health->retentionYears);
        self::assertFalse($health->isStatutory());
        self::assertSame(PayrollRetentionCatalog::ORIGIN_HOUSE_POLICY, $health->origin);
        self::assertSame(PayrollRetentionCatalog::STATUTE_SILENT, $health->sourceStatus);
        self::assertNull(
            $health->section,
            'Ustanovení, které lhůtu nestanoví, se citovat nesmí — vypadalo by jako doklad.',
        );
        self::assertStringContainsString('dodaná politika', $health->source());
        self::assertStringNotContainsString('§', $health->source());

        // A naopak: dodaná lhůta je právě jedna. Kdyby přibyla další, je to
        // rozhodnutí o výkladu, ne úklid.
        $housePolicies = array_values(array_filter(
            PayrollRetentionCatalog::rules(),
            static fn ($rule): bool => !$rule->isStatutory() && $rule->isDetermined(),
        ));
        self::assertCount(1, $housePolicies);
        self::assertSame(PayrollRetentionCatalog::HEALTH_INSURANCE, $housePolicies[0]->category);
    }

    /**
     * Nedoložená lhůta MUSÍ být `null`, ne odhad. Kdyby se sem propsalo číslo,
     * modul by podle něj navrhl výmaz a nikdo by nepoznal, že pro něj není opora.
     */
    public function testCategoriesWithoutAPeriodCarryNoNumber(): void
    {
        $withoutPeriod = 0;
        foreach (PayrollRetentionCatalog::rules() as $rule) {
            if ($rule->origin !== PayrollRetentionCatalog::ORIGIN_NONE) {
                continue;
            }
            $withoutPeriod++;
            self::assertNull(
                $rule->retentionYears,
                "Kategorie {$rule->category} je vedená bez lhůty, ale nese číslo.",
            );
            self::assertFalse($rule->isDetermined());
            self::assertNull($rule->retainedUntil(1990), 'Neurčená lhůta nesmí nikdy expirovat.');
        }

        self::assertSame(
            1,
            $withoutPeriod,
            'Bez lhůty zůstává jediná kategorie — spis k exekučním srážkám, kde je '
            . 'neexistence lhůty doložená negativně v OSŘ i v exekučním řádu. Kdyby '
            . 'jich přibylo nebo ubylo, je to změna výkladu, ne úklid.',
        );
        self::assertSame(
            PayrollRetentionCatalog::STATUTE_SILENT,
            PayrollRetentionCatalog::rule(PayrollRetentionCatalog::GARNISHMENT)->sourceStatus,
        );
    }

    /**
     * Regrese proti „doplnění" lhůty odhadem: co dnes lhůtu nemá, ji nesmí dostat
     * jinak než s citací ustanovení, které ji stanoví.
     */
    public function testNoCategoryGetsAPeriodWithoutSayingWhereItComesFrom(): void
    {
        foreach (PayrollRetentionCatalog::rules() as $rule) {
            if (!$rule->isDetermined()) {
                continue;
            }
            self::assertGreaterThan(0, (int) $rule->retentionYears);

            if ($rule->isStatutory()) {
                self::assertNotNull(
                    $rule->section,
                    "Kategorie {$rule->category} tvrdí zákonnou lhůtu, ale neříká ustanovení.",
                );
                self::assertMatchesRegularExpression(
                    '/^§ .+ zákona č\. \d+\/\d{4} Sb\./u',
                    $rule->section,
                    "Citace u {$rule->category} se nedá dohledat — chybí paragraf nebo předpis.",
                );

                continue;
            }

            self::assertSame(
                PayrollRetentionCatalog::ORIGIN_HOUSE_POLICY,
                $rule->origin,
                "Kategorie {$rule->category} nese lhůtu bez zákona i bez přiznané politiky.",
            );
        }
    }

    public function testDeterminedCategoriesExpireOnTheLastDayOfTheYear(): void
    {
        foreach (PayrollRetentionCatalog::rules() as $rule) {
            if (!$rule->isDetermined()) {
                continue;
            }
            $until = $rule->retainedUntil(1990);
            self::assertIsString($until);
            self::assertSame(
                sprintf('%04d-12-31', 1990 + (int) $rule->retentionYears),
                $until,
                "Kategorie {$rule->category} počítá konec lhůty jinak než ke konci roku.",
            );
        }
    }

    public function testPayrollSheetHoldsTheLongestPeriod(): void
    {
        $sheet = PayrollRetentionCatalog::rule(PayrollRetentionCatalog::PAYROLL_SHEET);
        self::assertSame(45, $sheet->retentionYears);
        self::assertStringContainsString('582/1991', $sheet->source());

        foreach (PayrollRetentionCatalog::rules() as $rule) {
            if ($rule->retentionYears !== null) {
                self::assertLessThanOrEqual(
                    45,
                    $rule->retentionYears,
                    'Delší lhůtu než mzdový list katalog nezná — kdyby přibyla, musí se '
                    . 'přepsat i komentář, který mzdový list označuje za rozhodující.',
                );
            }
        }
    }

    /**
     * Účetní lhůta má v aplikaci JEDEN zdroj pravdy. Kdyby si ji katalog držel
     * vlastní, novela by opravila jen jedno číslo a druhé by mazalo dál.
     */
    public function testAccountingPeriodComesFromTheSingleSourceOfTruth(): void
    {
        self::assertSame(
            RetentionPolicy::retentionYears(RetentionPolicy::ACCOUNTING_RECORDS),
            PayrollRetentionCatalog::rule(PayrollRetentionCatalog::ACCOUNTING_RECORDS)
                ->retentionYears,
        );
    }

    public function testUnknownCategoryIsRefusedLoudly(): void
    {
        self::assertFalse(PayrollRetentionCatalog::has('vymyslena_kategorie'));
        $this->expectException(\InvalidArgumentException::class);
        PayrollRetentionCatalog::rule('vymyslena_kategorie');
    }

    public function testTrackedTablesAreUniqueAndNonEmpty(): void
    {
        $tables = PayrollRetentionCatalog::trackedTables();
        self::assertNotSame([], $tables);
        self::assertSame(
            $tables,
            array_values(array_unique($tables)),
            'Duplicitní tabulka by osobu započítala do kategorie dvakrát.',
        );
    }

    public function testNewBlockingTablesUseTheirActualRetentionCategories(): void
    {
        self::assertContains(
            'payroll_document_batch_items',
            PayrollRetentionCatalog::rule(PayrollRetentionCatalog::PAYROLL_SHEET)->employeeTables,
        );
        self::assertContains(
            'payroll_enforcement_xmlzam_requests',
            PayrollRetentionCatalog::rule(PayrollRetentionCatalog::GARNISHMENT)->employeeTables,
        );
        self::assertContains(
            'payroll_statutory_obligation_evidence',
            PayrollRetentionCatalog::rule(PayrollRetentionCatalog::SICKNESS_INSURANCE)->employeeTables,
        );
        self::assertContains(
            'payroll_registration_a1_profiles',
            PayrollRetentionCatalog::rule(PayrollRetentionCatalog::PENSION_EVIDENCE)->employeeTables,
        );
    }
}
