<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Kontrola běhu: přesčas zaplacený rychlým měsíčním vstupem, který schválená
 * docházka nenese.
 *
 * Rychlý měsíční vstup zadává přesčas hodinami a vyplatí za ně dosaženou mzdu
 * i příplatek (§ 114 odst. 1 zákoníku práce). Odpracované hodiny a z toho
 * přesčas (atributy 10268 a 10269 měsíčního hlášení) se ale berou výhradně
 * ze schválené docházky, stejně jako odpracovaná doba pro průměrný výdělek
 * (§ 353 odst. 1 zákoníku práce). Bez přesčasu v docházce tak hlášení neslo
 * příplatek za přesčas bez jediné přesčasové hodiny a průměr vyšel vyšší.
 *
 * Docházka je jediný zdroj odpracované doby a rychlý vstup do ní nezapisuje
 * (neví, který den se přesčas odpracoval). Oba podklady přesčasu se navíc
 * vylučují: schválení docházky s přesčasem rychlý vstup přesčasu odmítne
 * (`overtime_conflict`), aby se příplatek nevyplatil dvakrát. Nic se tu proto
 * nedopočítává: varování s povinným potvrzením a odkazem na docházku vztahu
 * a měsíce říká, že přesčas patří do docházky, jejíž schválení dosaženou mzdu
 * i příplatek založí samo. Potvrdit ho má smysl jen u přesčasu odpracovaného
 * v jiném měsíci. Do kanonického snímku se nepíše, otisk vstupu zůstává.
 */
final class PayrollRunQuickOvertimeHours
{
    /** `external_id` příplatku za přesčas z rychlého měsíčního vstupu. */
    private const QUICK_PREMIUM_EXTERNAL_ID = 'quick-monthly:PRIPLATEK_PRESCAS';

    /**
     * @param list<array<string,mixed>> $employments řádky {@see PayrollRunSnapshotBuilder::employmentRows()}
     * @param array<int,list<array<string,mixed>>> $inputRows schválené vstupy období po vztazích
     * @param array<int,array<string,mixed>> $timeMonthRows pracovní měsíce po vztazích
     * @return list<PayrollRunValidation>
     */
    public static function validations(
        array $employments,
        array $inputRows,
        array $timeMonthRows,
        string $periodStart,
    ): array {
        $validations = [];
        $period = substr($periodStart, 0, 7);
        foreach ($employments as $row) {
            $employmentId = (int) $row['employment_id'];
            $quick = 0;
            foreach ($inputRows[$employmentId] ?? [] as $input) {
                if (($input['source_kind'] ?? null) === 'manual'
                    && ($input['external_id'] ?? null) === self::QUICK_PREMIUM_EXTERNAL_ID
                    && is_numeric($input['quantity_milliunits'] ?? null)
                ) {
                    $quick += (int) $input['quantity_milliunits'];
                }
            }
            $timeMonth = $timeMonthRows[$employmentId] ?? null;
            // Neschválenou docházku a chybějící souhrn hlásí vlastní kontroly.
            if ($quick <= 0 || !is_array($timeMonth)
                || ($timeMonth['status'] ?? null) !== 'approved'
                || ($timeMonth['summary_id'] ?? null) === null
            ) {
                continue;
            }
            $attendance = is_numeric($timeMonth['overtime_millihours'] ?? null)
                ? (int) $timeMonth['overtime_millihours']
                : 0;
            if ($attendance >= $quick) {
                continue;
            }
            $validations[] = new PayrollRunValidation(
                'warning',
                'quick_overtime_hours_not_in_attendance',
                'employment',
                $employmentId,
                sprintf(
                    '%s: rychlý měsíční vstup platí %s h přesčasu, schválená docházka jich eviduje %s. '
                    . 'Hodiny přesčasu v měsíčním hlášení i odpracovaná doba pro průměrný výdělek se '
                    . 'berou z docházky: hlášení by neslo příplatek bez přesčasových hodin a průměr by '
                    . 'vyšel vyšší. Zrušte běh, v rychlém vstupu hodiny přesčasu vymažte a přesčas '
                    . 'zapište do docházky; její schválení mzdu i příplatek za přesčas založí samo. '
                    . 'Potvrďte jen u přesčasu odpracovaného v jiném měsíci.',
                    (string) ($row['full_name'] ?? ''),
                    self::hours($quick),
                    self::hours($attendance),
                ),
                '/payroll/time?employment=' . $employmentId . '&period=' . $period,
                true,
            );
        }

        return $validations;
    }

    private static function hours(int $millihours): string
    {
        return rtrim(rtrim(number_format($millihours / 1000, 3, ',', ' '), '0'), ',');
    }
}
