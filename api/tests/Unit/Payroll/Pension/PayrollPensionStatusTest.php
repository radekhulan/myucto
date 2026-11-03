<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Pension;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Pension\PayrollPensionAgeTable;
use MyInvoice\Service\Payroll\Pension\PayrollPensionStatus;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollPensionStatusTest extends TestCase
{
    /** Druhy důchodu evidence jsou přesně CIS Druh důchodu připnutý pro REGZEC. */
    public function testPensionTypesMatchPinnedRegistrationCodebook(): void
    {
        $codebooks = new JmhzCodebookCatalog((new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        ));

        self::assertSame(
            PayrollPensionStatus::PENSION_TYPE_CODES,
            array_map(static fn (array $entry): string => (string) $entry['item_code'], $codebooks->entries('druh_duchodu')),
        );
    }

    public function testEmptyEvidenceGivesNoPensionData(): void
    {
        $evidence = PayrollPensionStatus::normalize([], []);

        self::assertTrue(PayrollPensionStatus::isEmpty($evidence));
        self::assertSame(
            ['pension_age_reached_on' => null, 'early_pension_from' => null, 'full_pension_paid_from' => null],
            PayrollPensionStatus::forInterval($evidence, '2026-07-01', '2026-07-31'),
        );
    }

    public function testPensionAgeAndEarlyPensionAreTakenAsRecorded(): void
    {
        $evidence = PayrollPensionStatus::normalize(
            [['basis' => 'statutory_table', 'effective_from' => '2027-04-15', 'effective_to' => null]],
            [self::pension('1', '2026-02-01', null, early: true)],
        );

        self::assertSame(
            ['pension_age_reached_on' => '2027-04-15', 'early_pension_from' => '2026-02-01', 'full_pension_paid_from' => null],
            PayrollPensionStatus::forInterval($evidence, '2026-07-01', '2026-07-31'),
        );
    }

    /**
     * Starobní důchod přiznaný uprostřed měsíce náleží k výplatě od měsíce
     * přiznání. List se vede do tohoto měsíce včetně (Všeobecné zásady ELDP;
     * Metodická pomůcka ELDP, příklad 8a), takže důchod od 15. 7. ukončí list
     * červencem, ne srpnem.
     */
    public function testOldAgePensionCountsFromMonthOfPaymentStart(): void
    {
        $evidence = PayrollPensionStatus::normalize([], [
            self::pension('1', '2026-07-15', null),
            self::pension('2', '2020-01-01', null),
        ]);

        self::assertSame(
            '2026-07',
            PayrollPensionStatus::forInterval($evidence, '2026-07-01', '2026-07-31')['full_pension_paid_from'],
        );
        $firstDay = PayrollPensionStatus::normalize([], [self::pension('1', '2026-07-01', null)]);
        self::assertSame(
            '2026-07',
            PayrollPensionStatus::forInterval($firstDay, '2026-07-01', '2026-07-31')['full_pension_paid_from'],
        );
    }

    /**
     * ELDP se vede do měsíce, od něhož výplata náleží, včetně něj (diskuse
     * JMHZ #363); první měsíc bez ELDP je následující. Bez důchodu se vede vždy.
     */
    public function testEldpIsKeptUpToAndIncludingTheMonthOfPaymentStart(): void
    {
        self::assertTrue(PayrollPensionStatus::eldpKeptInMonth('2026-04', '2026-03-01'));
        self::assertTrue(PayrollPensionStatus::eldpKeptInMonth('2026-04', '2026-04-01'));
        self::assertTrue(PayrollPensionStatus::eldpKeptInMonth('2026-04', '2026-04-30'));
        self::assertFalse(PayrollPensionStatus::eldpKeptInMonth('2026-04', '2026-05-01'));
        self::assertFalse(PayrollPensionStatus::eldpKeptInMonth('2026-12', '2027-01-01'));
        self::assertTrue(PayrollPensionStatus::eldpKeptInMonth(null, '2026-05-01'));

        self::assertSame('2026-05-01', PayrollPensionStatus::eldpNotKeptFrom('2026-04'));
        self::assertSame('2027-01-01', PayrollPensionStatus::eldpNotKeptFrom('2026-12'));
        self::assertNull(PayrollPensionStatus::eldpNotKeptFrom(null));
    }

    /** Důchod, který skončil před intervalem, ani budoucí, interval nezasáhne. */
    public function testPensionOutsideIntervalIsIgnored(): void
    {
        $evidence = PayrollPensionStatus::normalize([], [
            self::pension('1', '2025-01-01', '2025-12-31', early: true),
            self::pension('1', '2027-01-01', null),
        ]);

        self::assertSame(
            ['pension_age_reached_on' => null, 'early_pension_from' => null, 'full_pension_paid_from' => null],
            PayrollPensionStatus::forInterval($evidence, '2026-07-01', '2026-07-31'),
        );
    }

    /** @return iterable<string,array{list<array<string,mixed>>,list<array<string,mixed>>}> */
    public static function invalidEvidence(): iterable
    {
        yield 'dva dny důchodového věku' => [[
            ['basis' => 'statutory_table', 'effective_from' => '2027-04-15'],
            ['basis' => 'statutory_table', 'effective_from' => '2027-05-15'],
        ], []];
        yield 'věk s koncem platnosti' => [[
            ['basis' => 'statutory_table', 'effective_from' => '2027-04-15', 'effective_to' => '2027-12-31'],
        ], []];
        yield 'neznámý zdroj věku' => [[['basis' => 'guess', 'effective_from' => '2027-04-15']], []];
        yield 'druh mimo číselník' => [[], [self::pension('S', '2026-01-01', null)]];
        yield 'předčasný invalidní' => [[], [self::pension('2', '2026-01-01', null, early: true)]];
        yield 'překryv téhož druhu' => [[], [
            self::pension('1', '2026-01-01', null, early: true),
            self::pension('1', '2026-06-01', null),
        ]];
        yield 'konec před začátkem' => [[], [self::pension('1', '2026-06-01', '2026-05-31')]];
    }

    /**
     * @param list<array<string,mixed>> $age
     * @param list<array<string,mixed>> $pensions
     */
    #[DataProvider('invalidEvidence')]
    public function testInvalidEvidenceIsRejected(array $age, array $pensions): void
    {
        $this->expectException(InvalidArgumentException::class);
        PayrollPensionStatus::normalize($age, $pensions);
    }

    public function testDifferentPensionTypesMayRunConcurrently(): void
    {
        $evidence = PayrollPensionStatus::normalize([], [
            self::pension('1', '2026-01-01', null),
            self::pension('A', '2024-01-01', null),
        ]);

        self::assertCount(2, $evidence['pensions']);
    }

    public function testConfirmationIsOnlyACheck(): void
    {
        $fromEvidence = ['pension_age_reached_on' => '2025-10-01', 'early_pension_from' => null, 'full_pension_paid_from' => null];

        self::assertSame([], PayrollPensionStatus::mismatches(
            ['pension_age_reached_on' => null, 'early_pension_from' => null, 'full_pension_paid_from' => null],
            $fromEvidence,
        ));
        self::assertSame([], PayrollPensionStatus::mismatches(
            ['pension_age_reached_on' => '2025-10-01', 'early_pension_from' => null, 'full_pension_paid_from' => null],
            $fromEvidence,
        ));
        self::assertSame(['pension_age_reached_on', 'full_pension_paid_from'], PayrollPensionStatus::mismatches(
            ['pension_age_reached_on' => '2025-11-01', 'early_pension_from' => null, 'full_pension_paid_from' => '2025-10'],
            $fromEvidence,
        ));
    }

    /** @return iterable<string,array{string,?string,?string}> */
    public static function retirementAges(): iterable
    {
        // Příloha č. 1: muž 1960 = 64 let a 2 měsíce.
        yield 'muž 1960' => ['1960-05-31', 'male', '2024-07-31'];
        // Den, který v posledním přičteném měsíci není: poslední den měsíce.
        yield 'muž 1963, 31. den' => ['1963-03-31', 'male', '2027-11-30'];
        // Ženy 1973 mají ve všech sloupcích 65 let a 8 měsíců.
        yield 'žena 1973' => ['1973-01-10', 'female', '2038-09-10'];
        // § 32 odst. 3: 65 let 8 měsíců + (1980 - 1973) měsíců.
        yield 'rok 1980 bez pohlaví' => ['1980-06-15', null, '2046-09-15'];
        yield 'po roce 1988' => ['1990-02-28', 'female', '2057-02-28'];
    }

    #[DataProvider('retirementAges')]
    public function testRetirementAgeIsOfferedWhenUnambiguous(string $birthDate, ?string $sex, string $expected): void
    {
        self::assertSame($expected, PayrollPensionAgeTable::suggest($birthDate, $sex));
    }

    /** @return iterable<string,array{?string,?string}> */
    public static function ambiguousRetirementAges(): iterable
    {
        // Věk ženy závisí na počtu vychovaných dětí, které evidence nevede.
        yield 'žena 1965' => ['1965-04-01', 'female'];
        yield 'neuvedené pohlaví 1960' => ['1960-04-01', 'unspecified'];
        yield 'narození před 1936' => ['1935-04-01', 'male'];
        yield 'bez data narození' => [null, 'male'];
        yield '29. února, celé roky' => ['1992-02-29', 'male'];
    }

    #[DataProvider('ambiguousRetirementAges')]
    public function testRetirementAgeIsNotOfferedWhenAmbiguous(?string $birthDate, ?string $sex): void
    {
        self::assertNull(PayrollPensionAgeTable::suggest($birthDate, $sex));
    }

    /** @return array<string,mixed> */
    private static function pension(string $type, string $from, ?string $to, bool $early = false): array
    {
        return [
            'pension_type_code' => $type,
            'early_retirement' => $early ? '1' : '0',
            'reduced_retirement_age' => 0,
            'effective_from' => $from,
            'effective_to' => $to,
        ];
    }
}
