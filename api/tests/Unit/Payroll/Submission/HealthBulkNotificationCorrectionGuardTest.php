<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthBulkNotificationCorrectionGuard;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationChange;
use PHPUnit\Framework\TestCase;

/**
 * Poučení k HOZ: chybné číslo pojištěnce ruší kód X, chybné datum přihlášky
 * (P, A, E, C) opravuje Y, chybné datum odhlášky (O, Q) opravuje Z. Nové HOZ
 * s druhou větou téhož kódu by pojišťovně poslalo duplicitní hlášení.
 */
final class HealthBulkNotificationCorrectionGuardTest extends TestCase
{
    private const XML = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<hromadneOznameniZamestnavatele xmlns="urn:x">'
        . '<zmenaZamestance><kodzmeny>O</kodzmeny><datumZmeny>2026-03-31</datumZmeny>'
        . '<cisloPojistence>9052224313</cisloPojistence><jmeno>Jana</jmeno><prijmeni>Nováková</prijmeni></zmenaZamestance>'
        . '<zmenaZamestance><kodzmeny>P</kodzmeny><datumZmeny>2026-03-01</datumZmeny>'
        . '<cisloPojistence>8001011236</cisloPojistence><jmeno>Petr</jmeno><prijmeni>Syntetický</prijmeni></zmenaZamestance>'
        . '</hromadneOznameniZamestnavatele>';

    public function testCorrectedDepartureDateNeedsZAndCorrectedNumberNeedsX(): void
    {
        $delivered = HealthBulkNotificationCorrectionGuard::linesFromXml(self::XML);
        $conflicts = HealthBulkNotificationCorrectionGuard::conflicts($delivered, [
            $this->change('O', '2026-03-30', '9052224313', 'Jana', 'Nováková'),
            $this->change('P', '2026-03-01', '8001011247', 'Petr', 'Syntetický'),
        ]);

        self::assertSame(['Z', 'X'], array_column($conflicts, 'correction'));
        self::assertSame(['2026-03-31', '8001011236'], array_column($conflicts, 'was'));
    }

    public function testUnchangedOrNewPeopleAreNoConflict(): void
    {
        $delivered = HealthBulkNotificationCorrectionGuard::linesFromXml(self::XML);

        self::assertSame([], HealthBulkNotificationCorrectionGuard::conflicts($delivered, [
            $this->change('O', '2026-03-31', '9052224313', 'Jana', 'Nováková'),
            $this->change('P', '2026-03-15', '7552013336', 'Eva', 'Nová'),
        ]));
    }

    /**
     * Opravné HOZ k číslu pojištěnce: X s chybným číslem a původním datem,
     * P se správným, pak ostatní dříve hlášené věty osoby se správným číslem.
     * Nezměněné věty jiných osob se znovu neposílají, nové ano.
     */
    public function testCorrectionXIsBuiltOnlyOnConfirmation(): void
    {
        $delivered = HealthBulkNotificationCorrectionGuard::linesFromXml(
            '<?xml version="1.0"?><h xmlns="urn:x">'
            . '<zmenaZamestance><kodzmeny>P</kodzmeny><datumZmeny>2026-03-01</datumZmeny><cisloPojistence>8001011236</cisloPojistence><jmeno>Petr</jmeno><prijmeni>Syntetický</prijmeni></zmenaZamestance>'
            . '<zmenaZamestance><kodzmeny>M</kodzmeny><datumZmeny>2026-03-20</datumZmeny><cisloPojistence>8001011236</cisloPojistence><jmeno>Petr</jmeno><prijmeni>Syntetický</prijmeni></zmenaZamestance>'
            . '<zmenaZamestance><kodzmeny>P</kodzmeny><datumZmeny>2026-03-02</datumZmeny><cisloPojistence>9052224313</cisloPojistence><jmeno>Jana</jmeno><prijmeni>Nováková</prijmeni></zmenaZamestance>'
            . '</h>',
        );
        $payload = new \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthBulkNotificationPayload(
            insurerCode: '111',
            employer: (new \ReflectionClass(\MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthEmployerIdentification::class))->newInstanceWithoutConstructor(),
            changes: [
                $this->change('P', '2026-03-01', '8001011247', 'Petr', 'Syntetický'),
                $this->change('M', '2026-03-20', '8001011247', 'Petr', 'Syntetický'),
                $this->change('P', '2026-03-02', '9052224313', 'Jana', 'Nováková'),
                $this->change('P', '2026-03-15', '7552013336', 'Eva', 'Nová'),
            ],
        );

        try {
            HealthBulkNotificationCorrectionGuard::apply($delivered, $payload, false);
            self::fail('Oprava X se bez potvrzení nesmí sestavit.');
        } catch (\MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException $exception) {
            self::assertSame('zp_bulk_notification_correction_x_available', $exception->errorCode);
        }

        $corrected = HealthBulkNotificationCorrectionGuard::apply($delivered, $payload, true);
        self::assertSame(
            [
                'X|2026-03-01|8001011236|Syntetický',
                'P|2026-03-01|8001011247|Syntetický',
                'M|2026-03-20|8001011247|Syntetický',
                'P|2026-03-15|7552013336|Nová',
            ],
            array_map(
                static fn (HealthNotificationChange $c): string => "{$c->changeCode}|{$c->changedOn}|{$c->insuranceNumber}|{$c->lastName}",
                $corrected->changes,
            ),
        );
    }

    /** Oprava data přihlášky (Y) zůstává ruční i s potvrzením. */
    public function testDateCorrectionStaysManualEvenWhenConfirmed(): void
    {
        $delivered = HealthBulkNotificationCorrectionGuard::linesFromXml(self::XML);
        $payload = new \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthBulkNotificationPayload(
            insurerCode: '111',
            employer: (new \ReflectionClass(\MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthEmployerIdentification::class))->newInstanceWithoutConstructor(),
            changes: [$this->change('P', '2026-03-05', '8001011236', 'Petr', 'Syntetický')],
        );

        $this->expectExceptionMessage('oprava kódem Y');
        HealthBulkNotificationCorrectionGuard::apply($delivered, $payload, true);
    }

    private function change(string $code, string $date, string $number, string $first, string $last): HealthNotificationChange
    {
        return new HealthNotificationChange(
            changeCode: $code,
            changedOn: $date,
            insuranceNumber: $number,
            firstName: $first,
            lastName: $last,
            address: null,
        );
    }
}
