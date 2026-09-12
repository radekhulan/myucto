<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Porovnání dvou evidencí náhradního volna za jeden měsíc.
 *
 * ## Proč jsou dvě a která je zdrojem pravdy pro co
 *
 * Náhradní volno za práci přesčas se z povahy věci eviduje dvakrát, protože
 * každý zápis odpovídá na jinou otázku a je klíčovaný JINÝM DNEM:
 *
 * | evidence | klíč | zdroj pravdy pro |
 * |---|---|---|
 * | `payroll_absences` typu `compensatory_time_off` | den ČERPÁNÍ | docházka, fond pracovní doby, mzda (§ 114 odst. 1 — za dobu čerpání mzda nepřísluší) |
 * | `payroll_overtime_compensations` | den PŘESČASU | vyrovnávací období podle § 93 odst. 4 (odst. 5 z něj vyjímá přesčas, za který bylo poskytnuto náhradní volno) |
 *
 * Sjednotit je nejde: absence den přesčasu nenese a jeden den čerpání může
 * vyrovnávat přesčas z několika dnů (i naopak). Odvozením by se ztratil klíč,
 * na kterém stojí celé vynětí — a vynětí by vypadlo v nesprávném týdnu.
 *
 * ## Co tenhle výpočet dělá
 *
 * Nesjednocuje, jen hlásí ROZPOR. Jednostranný zápis je tichá vada: chybí-li
 * absence, mzda o volnu neví; chybí-li kompenzace, přesčas se z vyrovnávacího
 * období neodečte a limit se ohlásí jako překročený, i když překročený není.
 *
 * Porovnává se PŘÍTOMNOST za měsíc, ne minuty: rozsah čerpání v minutách plyne
 * až z fondu kalendáře daného dne, kdežto kompenzace nese minuty přesčasu.
 * Sečíst je proti sobě a rovnat by znamenalo tvrdit rovnost, kterou zákon
 * neříká — § 114 odst. 1 mluví o volnu „v rozsahu práce konané přesčas", ale
 * poskytnuté v dohodnutém termínu, klidně po částech.
 */
final readonly class CompensatoryTimeOffReconciliation
{
    public const OK = 'ok';
    public const ABSENCE_WITHOUT_COMPENSATION = 'absence_without_compensation';
    public const COMPENSATION_WITHOUT_ABSENCE = 'compensation_without_absence';
    public const GRANT_DATE_UNKNOWN = 'grant_date_unknown';

    /** Číselník nálezů. Klient, který některý nezná, by ho vykreslil jako prázdno. */
    public const FINDINGS = [
        self::ABSENCE_WITHOUT_COMPENSATION,
        self::COMPENSATION_WITHOUT_ABSENCE,
        self::GRANT_DATE_UNKNOWN,
    ];

    /** @param list<string> $findings */
    private function __construct(
        public int $employmentId,
        public string $period,
        public int $absenceRows,
        public int $grantedRows,
        public int $grantedMinutes,
        public int $ungrantedRows,
        public array $findings,
    ) {}

    /**
     * @param array{granted_minutes:int,granted_rows:int,ungranted_rows:int,absence_rows:int} $row
     */
    public static function fromRow(int $employmentId, string $period, array $row): self
    {
        $findings = [];
        if ($row['absence_rows'] > 0 && $row['granted_rows'] === 0) {
            $findings[] = self::ABSENCE_WITHOUT_COMPENSATION;
        }
        if ($row['granted_rows'] > 0 && $row['absence_rows'] === 0) {
            $findings[] = self::COMPENSATION_WITHOUT_ABSENCE;
        }
        // Zápis bez data poskytnutí nejde zařadit do měsíce. Fail-closed:
        // radši pojmenovaný nález než tichý předpoklad, že se vybralo teď.
        if ($row['ungranted_rows'] > 0) {
            $findings[] = self::GRANT_DATE_UNKNOWN;
        }

        return new self(
            $employmentId,
            $period,
            $row['absence_rows'],
            $row['granted_rows'],
            $row['granted_minutes'],
            $row['ungranted_rows'],
            $findings,
        );
    }

    public function status(): string
    {
        return $this->findings === [] ? self::OK : $this->findings[0];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'employment_id' => $this->employmentId,
            'period' => $this->period,
            'status' => $this->status(),
            'findings' => $this->findings,
            'absence_rows' => $this->absenceRows,
            'granted_rows' => $this->grantedRows,
            'granted_minutes' => $this->grantedMinutes,
            'ungranted_rows' => $this->ungrantedRows,
        ];
    }
}
