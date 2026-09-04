<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PayrollDeadlineAssessmentServiceTest extends TestCase
{
    private PayrollDeadlineAssessmentService $service;

    protected function setUp(): void
    {
        $this->service = new PayrollDeadlineAssessmentService(
            new MockClock('2026-08-15 12:00:00 Europe/Prague'),
        );
    }

    /**
     * @param array{
     *   phase:string,days_to_due:int,is_action_required:bool,is_overdue:bool
     * } $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('phaseProvider')]
    public function testAssessesStoredDeadlineWithoutMutatingIt(
        string $earliest,
        string $due,
        string $obligationStatus,
        ?string $submissionStatus,
        array $expected,
    ): void {
        $assessment = $this->service->assess(
            $earliest,
            $due,
            $obligationStatus,
            $submissionStatus,
        );

        self::assertSame($expected, $assessment->toArray());
    }

    /** @return iterable<string,array{string,string,string,?string,array<string,mixed>}> */
    public static function phaseProvider(): iterable
    {
        yield 'window not open' => [
            '2026-08-20',
            '2026-08-31',
            'open',
            null,
            [
                'phase' => 'not_open',
                'days_to_due' => 16,
                'is_action_required' => false,
                'is_overdue' => false,
            ],
        ];
        yield 'open with time' => [
            '2026-08-01',
            '2026-08-25',
            'open',
            null,
            [
                'phase' => 'open',
                'days_to_due' => 10,
                'is_action_required' => true,
                'is_overdue' => false,
            ],
        ];
        yield 'due soon' => [
            '2026-08-01',
            '2026-08-20',
            'prepared',
            'ready',
            [
                'phase' => 'due_soon',
                'days_to_due' => 5,
                'is_action_required' => true,
                'is_overdue' => false,
            ],
        ];
        yield 'due today' => [
            '2026-08-01',
            '2026-08-15',
            'prepared',
            'ready',
            [
                'phase' => 'due_today',
                'days_to_due' => 0,
                'is_action_required' => true,
                'is_overdue' => false,
            ],
        ];
        yield 'overdue without effective receipt' => [
            '2026-08-01',
            '2026-08-14',
            'submitted',
            'submitted',
            [
                'phase' => 'overdue',
                'days_to_due' => -1,
                'is_action_required' => true,
                'is_overdue' => true,
            ],
        ];
        yield 'submitted awaits result before due' => [
            '2026-08-01',
            '2026-08-20',
            'submitted',
            'processing',
            [
                'phase' => 'awaiting_result',
                'days_to_due' => 5,
                'is_action_required' => false,
                'is_overdue' => false,
            ],
        ];
        yield 'accepted is fulfilled even after due' => [
            '2026-08-01',
            '2026-08-10',
            'fulfilled',
            'accepted',
            [
                'phase' => 'fulfilled',
                'days_to_due' => -5,
                'is_action_required' => false,
                'is_overdue' => false,
            ],
        ];
        yield 'rejected needs action' => [
            '2026-08-01',
            '2026-08-20',
            'manual_review',
            'rejected',
            [
                'phase' => 'action_required',
                'days_to_due' => 5,
                'is_action_required' => true,
                'is_overdue' => false,
            ],
        ];
        yield 'rejected overrides stale fulfilled projection' => [
            '2026-08-01',
            '2026-08-20',
            'fulfilled',
            'rejected',
            [
                'phase' => 'action_required',
                'days_to_due' => 5,
                'is_action_required' => true,
                'is_overdue' => false,
            ],
        ];
        /*
         * Měsíc uzavřený protokolem ČSSZ nesmí dál volat po zásahu.
         *
         * Obsahová oprava JMHZ řádné podání ZÁMĚRNĚ nenahrazuje, takže řádné
         * zůstane navždy `partially_accepted`. Když ČSSZ potvrdí, že hlášení
         * je úplné, uzavře se povinnost — a dokud stav podání přebíjel stav
         * povinnosti, ukazoval tentýž řádek zároveň „Splněno" i „Je nutný
         * zásah".
         */
        yield 'fulfilled obligation beats partially accepted submission' => [
            '2026-08-01',
            '2026-08-20',
            'fulfilled',
            'partially_accepted',
            [
                'phase' => 'fulfilled',
                'days_to_due' => 5,
                'is_action_required' => false,
                'is_overdue' => false,
            ],
        ];
        // Nahrazené podání povinnost nenese; nese ji nástupce, který ji splnil.
        yield 'fulfilled obligation beats superseded submission' => [
            '2026-08-01',
            '2026-08-10',
            'fulfilled',
            'superseded',
            [
                'phase' => 'fulfilled',
                'days_to_due' => -5,
                'is_action_required' => false,
                'is_overdue' => false,
            ],
        ];
        yield 'cancelled remains terminal' => [
            '2026-08-01',
            '2026-08-10',
            'cancelled',
            'cancelled_in_time',
            [
                'phase' => 'cancelled',
                'days_to_due' => -5,
                'is_action_required' => false,
                'is_overdue' => false,
            ],
        ];
    }

    public function testRejectsInvalidIntervalAndUnknownStates(): void
    {
        foreach ([
            ['2026-08-20', '2026-08-10', 'open', null],
            ['2026-08-01', '2026-08-20', 'unknown', null],
            ['2026-08-01', '2026-08-20', 'open', 'unknown'],
        ] as [$earliest, $due, $obligation, $submission]) {
            try {
                $this->service->assess(
                    $earliest,
                    $due,
                    $obligation,
                    $submission,
                );
                self::fail('Neplatný stav nebo interval musí být odmítnut.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
