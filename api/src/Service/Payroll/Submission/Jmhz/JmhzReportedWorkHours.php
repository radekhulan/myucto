<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Odpracované hodiny (10268) a přesčas (10269) tak, jak se vykazují v měsíci
 * čerpání náhradního volna za přesčas (§ 114 odst. 1 ZP).
 *
 * Pokyny MPSV k 10269: přesčasové hodiny, za které bylo v měsíci poskytnuto
 * náhradní volno, se od přesčasu odečtou; stejný princip platí pro 10268.
 * Ve verzi 2026 je 10269 nezáporné („záporný … uvedou se nuly"), ve verzi
 * 2027 datový slovník 1.5.0.3 u 10269 zápornost připouští (ZP 103/120/…,
 * „desetinné číslo (3), zápornost"), takže se rozdíl neořezává.
 *
 * Verze 2026 má totéž pravidlo zatím soukromě v serializéru
 * ({@see JmhzScenario1XmlSerializer}, `reportedWorkedHours`); tahle třída je
 * jeho volatelná podoba pro normalizovaný dokument 2027. Odpracované hodiny
 * neklesnou pod kladný přesčas (kontrola ČSSZ 10268 ≥ 10269) ani pod nulu.
 */
final class JmhzReportedWorkHours
{
    /**
     * @param array<string,mixed> $values hodnoty pracovního souhrnu měsíce
     * @return array{worked_millihours:?int,overtime_millihours:?int}
     */
    public static function reported(array $values, bool $signedOvertime): array
    {
        $worked = $values['worked_millihours'] ?? null;
        $overtime = $values['overtime_millihours'] ?? null;
        $worked = is_int($worked) ? $worked : null;
        $overtime = is_int($overtime) ? $overtime : null;
        $timeOff = $values['compensatory_time_off_millihours'] ?? null;
        if (!is_int($timeOff) || $timeOff <= 0 || $worked === null) {
            return ['worked_millihours' => $worked, 'overtime_millihours' => $overtime];
        }
        $reportedOvertime = $overtime === null
            ? null
            : ($signedOvertime ? $overtime - $timeOff : max(0, $overtime - $timeOff));

        return [
            'worked_millihours' => max(0, $reportedOvertime ?? 0, $worked - $timeOff),
            'overtime_millihours' => $reportedOvertime,
        ];
    }
}
