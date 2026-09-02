<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\CzIscoCodebook;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\PayrollPersonCreateValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollPersonCreateValidatorTest extends TestCase
{
    /** @return iterable<string,array{string,string,string}> */
    public static function relationMappings(): iterable
    {
        yield 'pracovní poměr' => ['employment', 'employee', 'hpp'];
        yield 'zaměstnání malého rozsahu' => ['small_scale_employment', 'employee', 'hpp'];
        yield 'DPP' => ['dpp', 'employee', 'dpp'];
        yield 'DPČ' => ['dpc', 'employee', 'dpc'];
        // `partner_dependent` protějšek v ENUMu `payroll_employees.employment_type` nemá,
        // takže spadá na `hpp`; kontaci 522/366 mu zajistí `taxpayer_type`.
        yield 'závislý příjem společníka' => ['partner_dependent', 'managing_partner', 'hpp'];
        // Migrace 1302 — do té doby dostal člen statutárního orgánu na legacy kartě
        // „pracovní poměr", což u odměny podle § 6 odst. 1 písm. c) ZDP není pravda.
        // Klíč je v obou větvích SHODNÝ, takže je mapování identita.
        yield 'výkon funkce' => ['statutory_body', 'managing_partner', 'statutory_body'];
    }

    #[DataProvider('relationMappings')]
    public function testMapsModernRelationshipToSharedLegacyProjection(
        string $relationType,
        string $taxpayerType,
        string $employmentType,
    ): void {
        $result = (new PayrollPersonCreateValidator(new PayrollEmploymentValidator(
            new PayrollEmploymentJmhzEvidenceCatalog(
                new JmhzSpecPackageCatalog(),
                new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog()),
            ),
            new CzIscoCodebook(),
        )))->validate([
            'full_name' => 'Syntetická Osoba',
            'birth_date' => null,
            'birth_number' => null,
            'relation_type' => $relationType,
            'planned_start_on' => '2026-08-10',
            'monthly_gross' => 12_500,
        ]);

        self::assertSame($taxpayerType, $result['employee']['taxpayer_type']);
        self::assertSame($employmentType, $result['employee']['employment_type']);
        self::assertSame(12_500, $result['employee']['monthly_gross']);
        self::assertSame(1_250_000, $result['employment']['monthly_gross_minor']);
        self::assertSame($relationType, $result['employment']['relation_type']);
        self::assertTrue($result['employment']['terms']['is_primary']);
        // Bez vlastní hodnoty zůstává plný úvazek.
        self::assertSame('40.00', $result['employment']['terms']['weekly_hours']);
    }

    private static function validator(): PayrollPersonCreateValidator
    {
        return new PayrollPersonCreateValidator(new PayrollEmploymentValidator(
            new PayrollEmploymentJmhzEvidenceCatalog(
                new JmhzSpecPackageCatalog(),
                new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog()),
            ),
            new CzIscoCodebook(),
        ));
    }

    /** @return array<string,mixed> */
    private static function baseInput(): array
    {
        return [
            'full_name' => 'Syntetická Osoba',
            'birth_date' => null,
            'birth_number' => null,
            'relation_type' => 'employment',
            'planned_start_on' => '2026-08-10',
            'monthly_gross' => null,
        ];
    }

    /**
     * Týdenní doba se dosazovala natvrdo na 40 hodin, takže poloviční úvazek se
     * musel po založení hned přepsat novou verzí podmínek — a do historie vztahu
     * spadl jednodenní interval s úvazkem, který nikdy neplatil.
     */
    public function testKeepsWeeklyHoursFromInput(): void
    {
        $result = self::validator()->validate(
            ['weekly_hours' => '20.00'] + self::baseInput(),
        );

        self::assertSame('20.00', $result['employment']['terms']['weekly_hours']);
    }

    public function testFallsBackToFullTimeWhenWeeklyHoursIsBlank(): void
    {
        $result = self::validator()->validate(['weekly_hours' => ''] + self::baseInput());

        self::assertSame('40.00', $result['employment']['terms']['weekly_hours']);
    }

    /**
     * Pojišťovna je nepovinná — bez ní musí zakládání fungovat dál (zpětná
     * kompatibilita s klienty, které o poli nevědí).
     */
    public function testHealthInsurerCodeIsOptional(): void
    {
        self::assertNull(self::validator()->validate(self::baseInput())['health_insurer_code']);
        self::assertNull(
            self::validator()->validate(['health_insurer_code' => ' '] + self::baseInput())
                ['health_insurer_code'],
        );
    }

    /**
     * Kód se jen převezme; platnost proti číselníku ověřuje až zákonná evidence,
     * aby pravidlo zůstalo na jednom místě.
     */
    public function testPassesHealthInsurerCodeThroughUntouched(): void
    {
        $result = self::validator()->validate(
            ['health_insurer_code' => ' 111 '] + self::baseInput(),
        );

        self::assertSame('111', $result['health_insurer_code']);
    }

    /** Meze i tvar hlídá jediný validátor podmínek — tady se jen předává dál. */
    public function testRejectsWeeklyHoursOverTheLegalCeiling(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::validator()->validate(['weekly_hours' => '200.00'] + self::baseInput());
    }

    /** @return iterable<string,array{?string,int}> */
    public static function workloads(): iterable
    {
        yield 'plný úvazek' => ['40.00', 10_000];
        yield 'poloviční úvazek' => ['20.00', 5_000];
        yield 'třicetisedmiapůlhodinový týden' => ['37.50', 9_375];
        yield 'bez zadané doby' => [null, 10_000];
        // Delší sjednaná doba než stanovená není přesčasový úvazek — strop je 100 %.
        yield 'delší než stanovená doba' => ['45.00', 10_000];
    }

    /**
     * Úvazek se dosazoval natvrdo na 10 000 bazických bodů, takže zkrácený úvazek
     * měl hned po založení v historii verzi se 100 %. Na tom čísle přitom stojí
     * zákaz nařízeného přesčasu podle § 78 odst. 1 písm. i) ZP.
     */
    #[DataProvider('workloads')]
    public function testDerivesWorkloadFromWeeklyHours(?string $weeklyHours, int $expected): void
    {
        $result = self::validator()->validate(
            ['weekly_hours' => $weeklyHours] + self::baseInput(),
        );

        self::assertSame($expected, $result['employment']['terms']['workload_basis_points']);
    }

    /**
     * Rodné číslo míří jen do šifrovaného `payroll_person_identifiers`, ne na
     * kartu zaměstnance — a v kanonickém tvaru, jak ho čekají podání.
     */
    public function testNormalizesBirthNumberOutsideTheLegacyEmployeeProjection(): void
    {
        $result = self::validator()->validate(
            ['birth_number' => ' 900412 1236 '] + self::baseInput(),
        );

        self::assertSame('900412/1236', $result['birth_number']);
        self::assertArrayNotHasKey('birth_number', $result['employee']);
    }

    public function testBirthNumberStaysOptional(): void
    {
        self::assertNull(self::validator()->validate(self::baseInput())['birth_number']);
        self::assertNull(
            self::validator()->validate(['birth_number' => '  '] + self::baseInput())['birth_number'],
        );
    }

    public function testRejectsBirthNumberThatSubmissionsWouldRefuse(): void
    {
        $this->expectExceptionMessage('Rodné číslo neprošlo kontrolou modulo 11.');

        self::validator()->validate(['birth_number' => '9004121234'] + self::baseInput());
    }
}
