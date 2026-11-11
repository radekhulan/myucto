<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

/**
 * Pracovní souhrn měsíce nejde potvrdit, protože jeho zdroje jsou neúplné.
 *
 * Dřív zněla hláška jen „Pracovní souhrn obsahuje neúplné nebo nejednoznačné
 * zdroje." a účetní nevěděla, který zdroj ani co doplnit. Typicky pracovní
 * poměr s nástupem uprostřed měsíce, který nemá pracovní kalendář: fond
 * 10260 se počítá z kalendáře a dny od nástupu nemá nic pokrývat. Výjimka
 * proto nese nálezy náhledu a skládá z nich větu „co chybí a co udělat".
 */
final class PayrollJmhzWorkSummaryIncompleteException extends \InvalidArgumentException
{
    /** Krok k nápravě podle kódu nálezu náhledu. */
    private const STEPS = [
        'calendar_day_not_uniquely_covered' => 'V Mzdy → Docházka v řádku vztahu zvolte „Založit kalendář" (vznikne od dne nástupu podle týdenní pracovní doby z podmínek vztahu) a měsíc schvalte znovu.',
        'employment_terms_not_unique_for_month' => 'Na kartě vztahu v účinných podmínkách sjednoťte týdenní pracovní dobu pro celý měsíc, nebo měsíc schvalte jednotlivě se souhrnem zadaným ručně.',
        'absence_not_final' => 'Nepřítomnost schvalte nebo zamítněte v Mzdy → Nepřítomnosti a měsíc schvalte znovu.',
        'work_source_conflict' => 'V Mzdy → Docházka ponechte jen jeden zdroj odpracované doby (záznamy, nebo souhrn importu).',
    ];

    /** @param list<array{code?:mixed,message?:mixed}> $issues */
    public function __construct(public readonly array $issues)
    {
        parent::__construct(self::describe($issues));
    }

    /** @return list<string> */
    public function codes(): array
    {
        $codes = [];
        foreach ($this->issues as $issue) {
            $code = (string) ($issue['code'] ?? '');
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** @param list<array{code?:mixed,message?:mixed}> $issues */
    private static function describe(array $issues): string
    {
        $uncovered = [];
        $sentences = [];
        foreach ($issues as $issue) {
            $code = (string) ($issue['code'] ?? '');
            $message = trim((string) ($issue['message'] ?? ''));
            if ($code === 'calendar_day_not_uniquely_covered') {
                if (preg_match('/(\d{4}-\d{2}-\d{2})/', $message, $match) === 1) {
                    $uncovered[] = $match[1];
                }
                continue;
            }
            $sentence = rtrim($message, '.') . '.';
            if (isset(self::STEPS[$code])) {
                $sentence .= ' ' . self::STEPS[$code];
            }
            if ($message !== '' && !in_array($sentence, $sentences, true)) {
                $sentences[] = $sentence;
            }
        }
        if ($uncovered !== []) {
            sort($uncovered);
            array_unshift($sentences, sprintf(
                'Pracovní kalendář nepokrývá %s, takže nejde spočítat sjednaný fond pracovní doby. %s',
                count($uncovered) === 1
                    ? 'den ' . self::date($uncovered[0])
                    : sprintf('dny %s až %s (%d dní)', self::date($uncovered[0]), self::date(end($uncovered)), count($uncovered)),
                self::STEPS['calendar_day_not_uniquely_covered'],
            ));
        }

        return $sentences === []
            ? 'Pracovní souhrn obsahuje neúplné nebo nejednoznačné zdroje. Otevřete schválení měsíce u vztahu jednotlivě, ukáže, co doplnit.'
            : 'Pracovní souhrn nejde potvrdit: ' . implode(' ', $sentences);
    }

    private static function date(string $iso): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $iso));

        return sprintf('%d. %d. %d', $day, $month, $year);
    }
}
