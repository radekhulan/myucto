<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollAbsenceOverlapException extends \RuntimeException
{
    private const TYPE_LABELS = [
        'vacation' => 'dovolená',
        'dpn' => 'nemoc (DPN)',
        'quarantine' => 'karanténa',
        'ocr' => 'ošetřovné',
        'long_term_care' => 'dlouhodobá péče',
        'ppm' => 'peněžitá pomoc v mateřství',
        'paternity' => 'otcovská',
        'parental' => 'rodičovská',
        'unpaid_leave' => 'neplacené volno',
        'employee_obstacle' => 'překážka na straně zaměstnance',
        'employer_obstacle' => 'překážka na straně zaměstnavatele',
        'compensatory_time_off' => 'náhradní volno',
        'unexcused' => 'neomluvená absence',
        'other' => 'jiná nepřítomnost',
    ];

    private const STATUS_LABELS = [
        'requested' => 'čeká na rozhodnutí',
        'approved' => 'schválená',
    ];

    public function __construct(
        public readonly ?int $conflictId = null,
        ?string $absenceType = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $status = null,
    ) {
        parent::__construct(
            $conflictId === null || $dateFrom === null || $dateTo === null
                ? 'Ve zvoleném období už existuje překrývající se nepřítomnost.'
                : sprintf(
                    'Období se překrývá s už evidovanou nepřítomností: %s %s – %s (%s). '
                    . 'Buď zrušte tu původní, nebo nové období zkraťte tak, aby se nepřekrývala.',
                    self::TYPE_LABELS[(string) $absenceType] ?? (string) $absenceType,
                    $dateFrom,
                    $dateTo,
                    self::STATUS_LABELS[(string) $status] ?? (string) $status,
                ),
        );
    }
}
