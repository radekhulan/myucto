<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Deadline;

use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationChangeProposalRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Deadline\PayrollTaxStatementDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Roční vyúčtování v hlídači termínů.
 *
 * Lhůtu uměl modul spočítat, ale přehled o ní nevěděl, takže se účetní nikde
 * nepřipomněla. Test drží obě strany té změny: že se termín OBJEVÍ, dokud je
 * vyúčtování nepodané, a že ZMIZÍ, jakmile je podání doložené — a taky že
 * nevznikne tam, kde povinnost nevzniká (firma bez schváleného běhu, rok bez
 * sražené daně).
 */
final class PayrollDeadlineOverviewTaxStatementTest extends TestCase
{
    /** 10. 3. 2026: lhůta DPZVD6 za 2025 je za deset dnů, DPSVD2 za tři týdny. */
    private const TODAY = '2026-03-10 08:30:00';

    public function testUnfiledStatementsAppearAndFiledOnesDisappear(): void
    {
        $service = $this->service(
            basis: [
                2025 => ['approved_runs' => 12, 'withholding_minor' => 0],
                2024 => ['approved_runs' => 12, 'withholding_minor' => 5_000],
            ],
            filed: ['dpzvd6' => [2024]],
        );

        $items = $this->taxStatementItems($service);

        // 2025: zálohová daň nepodaná → termín. Srážkovou daň firma v roce
        // nesrážela, prázdný tiskopis se nepřipomíná.
        // 2024: zálohová daň PODANÁ → termín zmizel, i když je po lhůtě.
        //       Srážková daň sražená a nepodaná → termín, a to po termínu.
        self::assertSame(
            ['tax_statement:dpsvd2:2024', 'tax_statement:dpzvd6:2025'],
            array_column($items, 'reference'),
        );

        $overdue = $items[0];
        self::assertSame('dpsvd2', $overdue['title']);
        self::assertSame('2025-04-01', $overdue['due_on']);
        self::assertSame('overdue', $overdue['phase']);
        self::assertTrue($overdue['is_overdue']);
        self::assertNull($overdue['electronic_due_on']);
        self::assertSame('280/2009 Sb. § 137 odst. 2 a 3', $overdue['subject']);

        $open = $items[1];
        self::assertSame('dpzvd6', $open['title']);
        // Aplikace podává elektronicky, hlídá se tedy 20. březen — ne 2. březen.
        self::assertSame('2026-03-20', $open['due_on']);
        self::assertSame('2026-03-02', $open['statutory_due_on']);
        self::assertSame('2026-03-20', $open['electronic_due_on']);
        // Deset dnů je nad prahem „brzy" (pět) — termín je otevřený, ne hořící.
        self::assertSame('open', $open['phase']);
        self::assertSame(10, $open['days_to_due']);
        self::assertFalse($open['extendable']);
        self::assertSame(2025, $open['statement_year']);
        // Vyúčtování je roční — měsíční období by v UI tvrdilo něco jiného.
        self::assertNull($open['period']);
        self::assertSame('/payroll#payroll-tax-statement', $open['path']);
        self::assertSame('statute_verified', $open['deadline_source_status']);
    }

    /** Firma bez schváleného běhu žádné příjmy nezúčtovala — povinnost nevzniká. */
    public function testCompanyWithoutApprovedRunsGetsNoStatementDeadline(): void
    {
        $service = $this->service(basis: [], filed: []);

        self::assertSame([], $this->taxStatementItems($service));
    }

    /**
     * @param array<int,array{approved_runs:int,withholding_minor:int}> $basis
     * @param array<string,list<int>> $filed
     */
    private function service(array $basis, array $filed): PayrollDeadlineOverviewService
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(
            new \DateTimeImmutable(self::TODAY, new \DateTimeZone('Europe/Prague')),
        );

        $repository = $this->createStub(PayrollDeadlineOverviewRepository::class);
        $repository->method('submissionDeadlines')->willReturn([]);
        $repository->method('levyDeadlines')->willReturn([]);
        $repository->method('checklistDeadlines')->willReturn([]);
        $repository->method('taxStatementBasisYears')->willReturn($basis);
        $repository->method('filedTaxStatementYears')->willReturn($filed);

        $proposals = $this->createStub(PayrollRegistrationChangeProposalRepository::class);
        $proposals->method('openDeadlines')->willReturn([]);


        return new PayrollDeadlineOverviewService(
            $repository,
            $this->createStub(PayrollDeadlineAssessmentService::class),
            $proposals,
            $this->createStub(PayrollRegistrationChangeDetectionService::class),
            new PayrollTaxStatementDeadlinePolicy(),
            $this->sicknessCaseStub(),
            new SicknessDeadlinePolicy(),
            $clock,
        );
    }

    /** @return list<array<string,mixed>> */
    private function taxStatementItems(PayrollDeadlineOverviewService $service): array
    {
        $items = $service->overview(11, 'production')['items'];

        return array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['source'] === 'tax_statement',
        ));
    }

    /** Nemocenské případy do přehledu termínů nepatří — test hlídá vyúčtování daně. */
    private function sicknessCaseStub(): PayrollSicknessCaseRepository
    {
        $stub = $this->createStub(PayrollSicknessCaseRepository::class);
        $stub->method('openCases')->willReturn([]);

        return $stub;
    }
}
