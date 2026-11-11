<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Run\PayrollSnapshotHealthInsurers;
use PDO;

/**
 * Podklad pro měsíční AGENDOVÉ povinnosti: mzdové běhy období, které už mají
 * schválenou revizi, a pojišťovny, kterých se to období týká.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč se nečte celý vstupní snímek
 * ═══════════════════════════════════════════════════════════════════════════
 * `input_snapshot_json` nese zákonnou evidenci VŠECH lidí běhu — u firmy
 * s pěti sty zaměstnanci megabajty osobních údajů. Potřebné jsou z toho tři
 * číslice na osobu, takže je vytáhne MariaDB stejnou cestou, jakou v PHP
 * prochází {@see PayrollSnapshotHealthInsurers::fromSnapshot()}; obě strany
 * pak výsledek proženou týmž {@see PayrollSnapshotHealthInsurers::normalize()},
 * aby se pravidlo „kód je trojmístný řetězec" nedalo obejít ani z jedné.
 *
 * Čte se jen snímek JEDNÉ revize každého běhu dotázaného období. Historie
 * firmy do dotazu nevstupuje: přehled podání ho volá při každém otevření.
 *
 * Revize se bere NEJVYŠŠÍ SCHVÁLENÁ (`approved`/`superseded`) — tatáž volba
 * jako {@see PayrollRunRepository::latestApprovedRevision()}: opravná revize
 * odsune předchozí do `superseded`, ale ta pořád jednou schválená byla, takže
 * pořadí podle `revision_no` zůstává správné.
 */
final class PayrollMonthlyAgendaDutyRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Běhy období se schválenou revizí — pro každý počet lidí ve snímku
     * a kódy jejich zdravotních pojišťoven.
     *
     * Zrušený běh se nevrací: povinnost z něj nevzniká.
     *
     * @return list<array{
     *   run_id:int,revision_id:int,person_count:int,insurer_codes:list<string>
     * }>
     */
    public function approvedRunsForPeriod(
        int $supplierId,
        string $periodStart,
    ): array {
        if (!$this->db->hasTable('payroll_run_revisions')) {
            return [];
        }
        // Nejdřív jen ÚZKÉ sloupce: běhy období (index firmy a období) a pořadí
        // jejich schválených revizí (index firmy, běhu a čísla revize). Megabajtový
        // snímek se čte až v druhém kroku, a to jedině u vybrané revize každého
        // běhu. Dřív CTE počítala JSON funkce nad schválenými revizemi CELÉ
        // historie firmy a období filtrovala až potom, takže přehled podání
        // zpomaloval s každým dalším měsícem. `STRAIGHT_JOIN` drží pořadí
        // (nejdřív výběr revizí, pak snímek podle klíče): u malé tabulky si
        // optimalizátor jinak vybral průchod všemi revizemi firmy i se snímky.
        $statement = $this->db->pdo()->prepare(
            'WITH approved AS (
                 SELECT run.id AS run_id,
                        revision.id AS revision_id,
                        ROW_NUMBER() OVER (
                            PARTITION BY run.id
                            ORDER BY revision.revision_no DESC
                        ) AS row_rank
                   FROM payroll_runs run
                   JOIN payroll_run_revisions revision
                     ON revision.supplier_id = run.supplier_id
                    AND revision.run_id = run.id
                  WHERE run.supplier_id = ?
                    AND run.period_start = ?
                    AND run.status <> "cancelled"
                    AND revision.status IN ("approved", "superseded")
             )
             SELECT approved.run_id,
                    approved.revision_id,
                    JSON_LENGTH(snapshot.input_snapshot_json, "$.people")
                        AS person_count,
                    JSON_EXTRACT(snapshot.input_snapshot_json, ?)
                        AS insurer_codes
               FROM approved
      STRAIGHT_JOIN payroll_run_revisions snapshot
                 ON snapshot.supplier_id = ?
                AND snapshot.id = approved.revision_id
              WHERE approved.row_rank = 1
              ORDER BY approved.run_id',
        );
        $statement->execute([
            $supplierId,
            $periodStart,
            PayrollSnapshotHealthInsurers::SNAPSHOT_JSON_PATH,
            $supplierId,
        ]);

        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = [
                'run_id' => (int) $row['run_id'],
                'revision_id' => (int) $row['revision_id'],
                'person_count' => (int) $row['person_count'],
                'insurer_codes' => PayrollSnapshotHealthInsurers::normalize(
                    self::decodeCodes($row['insurer_codes']),
                ),
            ];
        }

        return $rows;
    }

    /**
     * `JSON_EXTRACT` s hvězdičkou vrací JSON pole; když ve snímku neodpovídá
     * ani jedna osoba, vrací `NULL`. Nečitelná hodnota se bere jako prázdná —
     * hlásit povinnost pojišťovně, kterou jsme nepřečetli, by bylo horší než
     * ji nehlásit.
     *
     * @return list<mixed>
     */
    private static function decodeCodes(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
