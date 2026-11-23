<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\Payroll\PayrollTaxDeclarationBulkSign;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Prohlášení poplatníka podle § 38k ZDP na hranicích lhůt, hromadným
 * podpisem až po mzdový běh, který slevu na poplatníka uplatní (nebo ne).
 *
 * § 38k odst. 4 ZDP: plátce přihlédne ke slevě, „učiní-li poplatník
 * prokazatelně do 30 dnů po vstupu do zaměstnání a každoročně nejpozději do
 * 15. února na příslušné zdaňovací období prohlášení“; § 38k odst. 7: jinak
 * „počínaje měsícem následujícím po měsíci, v němž … prokazatelně učiní
 * prohlášení“. Lhůta 30 dnů běží ode dne následujícího po nástupu, 30. den
 * je ještě v ní (nástup 10. 3. → 9. 4.).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHppTaxDeclarationTest extends TestCase
{
    use PayrollFullFlowTrait;

    public static string $today = '2026-11-11';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        self::$today = '2026-11-11';
        $this->bootPayrollFullFlow();
        $this->container->set(ClockInterface::class, new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(
                    PayrollE2eHppTaxDeclarationTest::$today . ' 12:00:00',
                    new \DateTimeZone('Europe/Prague'),
                );
            }
        });
        $this->officeId = $this->createOffice('DAN', 'Syntetická účtárna prohlášení', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_38K', 'base_wage', 'regular');
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Nástup 15. 12. 2026, podpis 10. 1. 2027 (26. den): prohlášení na rok
     * 2026 platí od prosince (§ 38k odst. 1 věta poslední a odst. 4), i když
     * se podepsalo až v dalším roce. Hromadný podpis ho musí přijmout
     * a prosincová mzda musí slevu uplatnit.
     */
    public function testDecemberStarterSigningInJanuaryWithinThirtyDaysGetsDecember(): void
    {
        self::$today = '2027-01-12';
        $starter = $this->hireUnsigned('Syntetický Prosincový', '2026-12-15');
        $veteran = $this->hireUnsigned('Syntetický Stálý', '2025-06-01');

        $preview = $this->bulk()->preview($this->supplierId, 2026, '2027-01-10', [$starter['employee_id'], $veteran['employee_id']]);

        $rows = array_column($preview['people'], null, 'employee_id');
        self::assertSame('ready', $rows[$starter['employee_id']]['state'], CanonicalJson::encode($preview));
        self::assertSame('2026-12-01', $rows[$starter['employee_id']]['effective_from']);
        self::assertSame('employment_start', $rows[$starter['employee_id']]['effective_from_basis']);
        // Stávající zaměstnanec podepisující v lednu 2027 na rok 2026 je po
        // lhůtě: měsíc po podpisu už do roku 2026 nepatří.
        self::assertSame('after_year_end', $rows[$veteran['employee_id']]['state']);

        $applied = $this->bulk()->apply($this->supplierId, 2026, '2027-01-10', [$starter['employee_id']], $this->actors[0], null, 'e2e');
        self::assertSame(1, $applied['counts']['applied'], CanonicalJson::encode($applied));

        // Na rok 2027 platí podpis z 10. 1. od ledna (do 15. 2.).
        $next = $this->bulk()->preview($this->supplierId, 2027, '2027-01-10', [$veteran['employee_id']]);
        self::assertSame('2027-01-01', $next['people'][0]['effective_from']);
        self::assertSame('year_start', $next['people'][0]['effective_from_basis']);

        $this->pay($starter, 3_000_000, '2026-12-01');
        $run = $this->runPayrollMonth('2026-12-01', '2027-01-14', $this->officeId, 'december-starter');
        $person = $this->statutoryPerson($run, $starter['employee_id']);
        self::assertSame(257_000, $person['income_tax']['applied_non_refundable_credits_minor_units']);
    }

    /**
     * Hromadný podpis musí nést den podpisu: od 01/2027 ho JMHZ hlásí
     * (10575, IN16) a editor podle něj kontroluje první měsíc věty.
     */
    public function testBulkSignatureKeepsTheSigningDay(): void
    {
        $person = $this->hireUnsigned('Syntetická Datovaná', '2025-03-01');

        $this->bulk()->apply($this->supplierId, 2026, '2026-04-10', [$person['employee_id']], $this->actors[0], null, 'e2e');

        $row = $this->signedDeclaration($person['employee_id']);
        self::assertSame('2026-05-01', $row['effective_from']);
        self::assertSame('2026-04-10', $row['signed_on']);
    }

    /** Nástup 20. 1., podpis 10. 2. (21. den): od ledna, leden otevřený. */
    public function testJanuaryStarterSigningInFebruaryGetsJanuary(): void
    {
        $person = $this->hireUnsigned('Syntetická Lednová', '2026-01-20');

        $applied = $this->bulk()->apply($this->supplierId, 2026, '2026-02-10', [$person['employee_id']], $this->actors[0], null, 'e2e');

        self::assertSame('2026-01-01', $applied['applied'][0]['effective_from'], CanonicalJson::encode($applied));
        self::assertSame('employment_start', $applied['applied'][0]['effective_from_basis']);
        self::assertFalse($applied['applied'][0]['annual_settlement']);
        $this->pay($person, 2_000_000, '2026-01-01');
        $run = $this->runPayrollMonth('2026-01-01', '2026-02-13', $this->officeId, 'january-starter');
        self::assertSame(257_000, $this->statutoryPerson($run, $person['employee_id'])['income_tax']['applied_non_refundable_credits_minor_units']);
    }

    /**
     * Leden je schválený bez slevy; podpis 10. 2. by platil od ledna, ale do
     * schválené mzdy se nesahá: od února a slevu za leden dorovná roční
     * zúčtování (§ 38k odst. 7 věta druhá).
     */
    public function testClosedJanuaryMovesStarterSignatureToFebruary(): void
    {
        $person = $this->hireUnsigned('Syntetický Uzavřený', '2026-01-20');
        $this->pay($person, 2_000_000, '2026-01-01');
        $january = $this->runPayrollMonth('2026-01-01', '2026-02-06', $this->officeId, 'closed-january');
        self::assertNotNull($january['approved'], CanonicalJson::encode($january['blockers']));
        self::assertSame(0, $this->statutoryPerson($january, $person['employee_id'])['income_tax']['applied_non_refundable_credits_minor_units']);

        $applied = $this->bulk()->apply($this->supplierId, 2026, '2026-02-10', [$person['employee_id']], $this->actors[0], null, 'e2e');

        self::assertSame('2026-02-01', $applied['applied'][0]['effective_from'], CanonicalJson::encode($applied));
        self::assertSame('after_frozen_period', $applied['applied'][0]['effective_from_basis']);
        self::assertTrue($applied['applied'][0]['annual_settlement']);
        $this->pay($person, 2_000_000, '2026-02-01', 'feb');
        $february = $this->runPayrollMonth('2026-02-01', '2026-03-13', $this->officeId, 'closed-january-feb');
        self::assertSame(257_000, $this->statutoryPerson($february, $person['employee_id'])['income_tax']['applied_non_refundable_credits_minor_units']);
    }

    /** Stávající zaměstnanec: podpis 15. 2. platí od ledna, 16. 2. až od března. */
    public function testAnnualDeadlineFifteenthVersusSixteenthOfFebruary(): void
    {
        $onTime = $this->hireUnsigned('Syntetický Včasný', '2025-06-01');
        $late = $this->hireUnsigned('Syntetický Pozdní', '2025-06-01');

        $first = $this->bulk()->preview($this->supplierId, 2026, '2026-02-15', [$onTime['employee_id']]);
        $second = $this->bulk()->preview($this->supplierId, 2026, '2026-02-16', [$late['employee_id']]);

        self::assertSame(['2026-01-01', 'year_start'], [$first['people'][0]['effective_from'], $first['people'][0]['effective_from_basis']]);
        self::assertSame(['2026-03-01', 'following_month'], [$second['people'][0]['effective_from'], $second['people'][0]['effective_from_basis']]);
    }

    /**
     * Nástup 10. 3.: 30. den je 9. 4. (od března), 31. den 10. 4. už je po
     * lhůtě (od měsíce po podpisu, tedy od května).
     */
    public function testThirtiethDayIsStillInTimeThirtyFirstIsNot(): void
    {
        $inTime = $this->hireUnsigned('Syntetická Třicátá', '2026-03-10');
        $late = $this->hireUnsigned('Syntetická Jednatřicátá', '2026-03-10');

        $first = $this->bulk()->preview($this->supplierId, 2026, '2026-04-09', [$inTime['employee_id']]);
        $second = $this->bulk()->preview($this->supplierId, 2026, '2026-04-10', [$late['employee_id']]);

        self::assertSame(['2026-03-01', 'employment_start'], [$first['people'][0]['effective_from'], $first['people'][0]['effective_from_basis']]);
        self::assertSame(['2026-05-01', 'following_month'], [$second['people'][0]['effective_from'], $second['people'][0]['effective_from_basis']]);
    }

    /** Nástup 18. 5., podpis 5. 6. (v dalším měsíci, 18. den): od května. */
    public function testSignatureInTheMonthAfterEntryKeepsTheEntryMonth(): void
    {
        $person = $this->hireUnsigned('Syntetický Květnový', '2026-05-18');

        $preview = $this->bulk()->preview($this->supplierId, 2026, '2026-06-05', [$person['employee_id']]);

        self::assertSame(['2026-05-01', 'employment_start'], [$preview['people'][0]['effective_from'], $preview['people'][0]['effective_from_basis']]);
    }

    /**
     * Dva vztahy téže osoby: HPP od 1. 6. 2025 trvá, dohoda od 4. 5. 2026,
     * podpis 20. 5. 2026. Aplikace bere za „vstup do zaměstnání“ nejbližší
     * předchozí nástup (dohodu), takže prohlášení platí od května. Výklad
     * souběhu u téhož plátce je otevřená otázka (viz zpráva agenta).
     */
    public function testConcurrentSecondEmploymentCountsAsEntry(): void
    {
        $person = $this->hireUnsigned('Syntetický Souběžný', '2025-06-01');
        $agreement = $this->createEmployment(
            $this->officeId,
            $person['name'],
            ++$this->sequence,
            'dpp',
            'dpp',
            10,
            2_500,
            false,
            '2026-05-01',
            existingEmployeeId: $person['employee_id'],
        );
        $this->moveStart($agreement['employment_id'], '2026-05-04');

        $preview = $this->bulk()->preview($this->supplierId, 2026, '2026-05-20', [$person['employee_id']]);

        self::assertSame(['2026-05-01', 'employment_start'], [$preview['people'][0]['effective_from'], $preview['people'][0]['effective_from_basis']]);
    }

    private function bulk(): PayrollTaxDeclarationBulkSign
    {
        $service = $this->container->get(PayrollTaxDeclarationBulkSign::class);
        self::assertInstanceOf(PayrollTaxDeclarationBulkSign::class, $service);

        return $service;
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hireUnsigned(string $name, string $startedOn): array
    {
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            ++$this->sequence,
            'hpp',
            'employment',
            40,
            10_000,
            false,
            '2026-01-01',
        );
        $this->moveStart($person['employment_id'], $startedOn);

        return $person;
    }

    private function moveStart(int $employmentId, string $startedOn): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employments SET start_date = ?, actual_start_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$startedOn, $startedOn, $this->supplierId, $employmentId]);
        $pdo->prepare('UPDATE payroll_employment_terms SET effective_from = ?, planned_start_on = ?, actual_start_on = ? WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$startedOn, $startedOn, $startedOn, $this->supplierId, $employmentId]);
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor, string $periodStart, string $suffix = ''): void
    {
        $this->createApprovedInput($person, $this->baseComponentId, $amountMinor, "38k-{$person['employment_id']}-{$periodStart}{$suffix}", $periodStart);
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function statutoryPerson(array $run, int $employeeId): array
    {
        $revision = ($run['approved'] ?? $run['calculated'])->revision;
        foreach ($revision['result_snapshot']['statutory']['people'] ?? [] as $person) {
            if (($person['person_reference'] ?? null) === "employee:{$employeeId}") {
                return $person;
            }
        }
        self::fail('Osoba chybí ve výsledku běhu: ' . CanonicalJson::encode($run['blockers']) . ' ' . CanonicalJson::encode($revision['result_snapshot']['statutory'] ?? null));
    }

    /** @return array<string,mixed> */
    private function signedDeclaration(int $employeeId): array
    {
        $repository = $this->container->get(PayrollPersonStatutoryEvidenceRepository::class);
        self::assertInstanceOf(PayrollPersonStatutoryEvidenceRepository::class, $repository);
        $view = $repository->editorView($this->supplierId, $employeeId, '2026-12-31');
        foreach ($view['sections']['tax_declarations'] ?? [] as $row) {
            if ($row['status'] === 'signed') {
                return $row;
            }
        }
        self::fail('Podepsané prohlášení chybí.');
    }
}
