<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollMonthlyAgendaDutyRepository;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlineWindow;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\PayrollMonthlyAgendaDutyService;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Povinnost existuje ZE ZÁKONA, ne z toho, že si ji někdo založil.
 *
 * Před opravou uměl měsíční přehled vypsat jen povinnosti, ke kterým UŽ
 * existoval řádek v evidenci — takže účetní, která ještě nic neudělala, četla
 * „za zvolené období nemá firma žádnou otevřenou položku". Testy proto hlídají
 * obojí: že povinnost bez podání vznikne, a že se s podáním neobjeví DVAKRÁT.
 */
final class PayrollMonthlyAgendaDutyServiceTest extends TestCase
{
    private const PERIOD = '2026-08';

    /** Lhůta JMHZ i přehledu o platbě padá na 21. 9. 2026 (20. je neděle). */
    private const DUE_ON = '2026-09-21';

    public function testUnpreparedRunYieldsJmhzAndOneItemPerInsurer(): void
    {
        $duties = $this->service([$this->runRow(insurerCodes: ['111', '201'])])
            ->unprepared(11, self::PERIOD, []);

        self::assertSame(
            [
                JmhzSubmissionBridgeService::AGENDA_CODE,
                HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
                HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            ],
            array_column($duties, 'agenda_code'),
        );
        self::assertSame([null, '111', '201'], array_column($duties, 'insurer_code'));
        foreach ($duties as $duty) {
            self::assertSame(self::DUE_ON, $duty['due_on'], 'Lhůta musí být konkrétní datum.');
            self::assertSame(self::PERIOD, $duty['period']);
            self::assertSame(7, $duty['revision_id']);
        }
    }

    /**
     * Tentýž člověk u téže pojišťovny nesmí povinnost zdvojit — pojišťovna je
     * jedna, i když u ní má pojištěné tři lidi.
     */
    public function testSameInsurerYieldsSingleDuty(): void
    {
        $duties = $this->service([$this->runRow(insurerCodes: ['111'])])
            ->unprepared(11, self::PERIOD, []);

        self::assertCount(2, $duties);
        self::assertSame(['111'], array_values(array_filter(
            array_column($duties, 'insurer_code'),
        )));
    }

    /**
     * Jakmile podání existuje, mluví o povinnosti UŽ JEN pramen `submission`.
     * Kdyby ji vydal i tenhle, měla by účetní na obrazovce dva řádky o jedné
     * věci — a jeden z nich by tvrdil, že není nic hotovo.
     */
    public function testRegisteredObligationRemovesDutyFromThisSource(): void
    {
        $duties = $this->service([$this->runRow(insurerCodes: ['111', '201'])])
            ->unprepared(11, self::PERIOD, [
                // JMHZ nese navíc účtárnu, přehled o platbě kód pojišťovny.
                ['agenda_code' => JmhzSubmissionBridgeService::AGENDA_CODE,
                 'subject_reference' => 'payroll_run:3:office:1'],
                ['agenda_code' => HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
                 'subject_reference' => 'payroll_run:3:111'],
            ]);

        self::assertSame(
            [HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW],
            array_column($duties, 'agenda_code'),
        );
        self::assertSame(['201'], array_column($duties, 'insurer_code'));
    }

    /** Podání za JINÝ běh povinnost tohoto běhu neodškrtne. */
    public function testObligationOfAnotherRunDoesNotCoverThisDuty(): void
    {
        $duties = $this->service([$this->runRow(insurerCodes: ['111'])])
            ->unprepared(11, self::PERIOD, [
                ['agenda_code' => JmhzSubmissionBridgeService::AGENDA_CODE,
                 'subject_reference' => 'payroll_run:99:office:1'],
            ]);

        self::assertCount(2, $duties);
    }

    /** Běh bez lidí nezakládá ani hlášení, ani přehled o platbě. */
    public function testRunWithoutPeopleYieldsNothing(): void
    {
        $duties = $this->service([$this->runRow(insurerCodes: [], personCount: 0)])
            ->unprepared(11, self::PERIOD, []);

        self::assertSame([], $duties);
    }

    /** Běh se zaměstnanci, ale bez čitelné pojišťovny, má aspoň JMHZ. */
    public function testRunWithoutInsurerCodesStillOwesJmhz(): void
    {
        $duties = $this->service([$this->runRow(insurerCodes: [])])
            ->unprepared(11, self::PERIOD, []);

        self::assertSame(
            [JmhzSubmissionBridgeService::AGENDA_CODE],
            array_column($duties, 'agenda_code'),
        );
    }

    public function testInvalidPeriodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service([])->unprepared(11, '2026-13', []);
    }

    /**
     * @param list<string> $insurerCodes
     * @return array{run_id:int,revision_id:int,person_count:int,insurer_codes:list<string>}
     */
    private function runRow(array $insurerCodes, int $personCount = 3): array
    {
        return [
            'run_id' => 3,
            'revision_id' => 7,
            'person_count' => $personCount,
            'insurer_codes' => $insurerCodes,
        ];
    }

    /** @param list<array<string,mixed>> $runs */
    private function service(array $runs): PayrollMonthlyAgendaDutyService
    {
        $repository = $this->createStub(PayrollMonthlyAgendaDutyRepository::class);
        $repository->method('approvedRunsForPeriod')->willReturn($runs);

        // Lhůta JMHZ žije v rulesetu (tedy v databázi), takže se sem předává
        // hotová; lhůta přehledu o platbě se počítá SKUTEČNOU politikou —
        // testuje se tím i to, že se termín nebere z nějakého druhého výpočtu.
        $jmhz = $this->createStub(JmhzDeadlinePolicy::class);
        $jmhz->method('forPeriod')->willReturn(new JmhzDeadlineWindow(
            '2026-09-01',
            self::DUE_ON,
            'business_days',
            'cz-jmhz-deadlines-2026.regular.v1',
            str_repeat('a', 64),
        ));

        return new PayrollMonthlyAgendaDutyService(
            $repository,
            $jmhz,
            new HealthNotificationDeadlinePolicy(),
            new PayrollDeadlineAssessmentService(
                new class () implements ClockInterface {
                    public function now(): \DateTimeImmutable
                    {
                        return new \DateTimeImmutable('2026-09-03 08:00:00', new \DateTimeZone('Europe/Prague'));
                    }
                },
            ),
        );
    }
}
