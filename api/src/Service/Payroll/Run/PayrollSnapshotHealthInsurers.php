<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Kódy zdravotních pojišťoven, na které se za mzdové období odvádí — jediné
 * místo, které je ze snímku běhu čte.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to není privátní metoda jedné služby
 * ═══════════════════════════════════════════════════════════════════════════
 * Původně to byl `private static` helper v {@see PayrollRunReadinessService}.
 * Jenže tentýž pojem („které pojišťovny se toho období týkají") potřebuje
 * i měsíční přehled povinností
 * ({@see \MyInvoice\Service\Payroll\Submission\PayrollMonthlyAgendaDutyService}),
 * a pravidlo schované jako privátní helper se okopíruje rychleji, než kdyby
 * neexistovalo. Kdyby se obě čtení rozešla, hlásil by přehled povinnost
 * pojišťovně, na kterou kontrola účtů vůbec nekouká — nebo naopak.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Kód je ŘETĚZEC, ne číslo
 * ═══════════════════════════════════════════════════════════════════════════
 * Trojmístný číselný řetězec (`'111'`) se v PHP jako klíč pole tiše změní na
 * `int`, takže `array_keys()` vrátí `111` a striktní porovnání s ověřenými
 * kódy nesedne nikdy. Deduplikace proto drží kód i v HODNOTĚ, ne jen v klíči.
 */
final class PayrollSnapshotHealthInsurers
{
    /**
     * Tatáž cesta, jakou prochází {@see self::fromSnapshot()} — jen zapsaná
     * pro MariaDB, aby repozitář nemusel do PHP tahat celý vstupní snímek
     * (osobní údaje všech zaměstnanců) kvůli seznamu trojmístných kódů.
     * Obě cesty musí zůstat shodné; výsledek se v obou případech normalizuje
     * {@see self::normalize()}.
     */
    public const SNAPSHOT_JSON_PATH =
        '$.people[*].statutory_evidence.health.coverage.insurer_code';

    /**
     * @param array<string,mixed> $snapshotData
     * @return list<string>
     */
    public static function fromSnapshot(array $snapshotData): array
    {
        $people = $snapshotData['people'] ?? [];
        if (!is_array($people)) {
            return [];
        }
        $codes = [];
        foreach ($people as $person) {
            if (!is_array($person)) {
                continue;
            }
            $evidence = $person['statutory_evidence'] ?? null;
            if (!is_array($evidence)) {
                continue;
            }
            $health = $evidence['health'] ?? null;
            if (!is_array($health)) {
                continue;
            }
            $coverage = $health['coverage'] ?? null;
            if (!is_array($coverage)) {
                continue;
            }
            $codes[] = $coverage['insurer_code'] ?? null;
        }

        return self::normalize($codes);
    }

    /**
     * @param iterable<mixed> $codes
     * @return list<string>
     */
    public static function normalize(iterable $codes): array
    {
        $result = [];
        foreach ($codes as $code) {
            if (is_string($code) && preg_match('/^[0-9]{3}$/D', $code) === 1) {
                $result[$code] = $code;
            }
        }
        sort($result, SORT_STRING);

        return array_values($result);
    }
}
