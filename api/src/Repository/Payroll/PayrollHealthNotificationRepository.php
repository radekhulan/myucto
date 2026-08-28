<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Zdroje pro oznamovací povinnost vůči zdravotní pojišťovně.
 *
 * Repozitář vrací HOLÁ FAKTA, ne rozhodnutí. Co se z nich stane povinností,
 * určuje `HealthNotificationDutyResolver` — jinak by se hraniční případy
 * zúžení od 2026 nedaly otestovat bez databáze.
 */
final readonly class PayrollHealthNotificationRepository
{
    public function __construct(private Connection $db) {}

    /**
     * @return array{
     *   business_id:?string,name:string,street:?string,house_number:?string,
     *   postal_code:?string,city:?string,phone:?string
     * }|null
     */
    public function findEmployerIdentification(int $supplierId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT ic, company_name, street, street_number_pop, zip, city, phone
               FROM supplier
              WHERE id = ?'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'business_id' => $this->nullableString($row['ic']),
            'name' => (string) $row['company_name'],
            'street' => $this->nullableString($row['street']),
            'house_number' => $this->nullableString($row['street_number_pop']),
            'postal_code' => $this->normalizePostalCode(
                $this->nullableString($row['zip']),
            ),
            'city' => $this->nullableString($row['city']),
            'phone' => $this->nullableString($row['phone']),
        ];
    }

    /**
     * Fakta jednoho pracovního vztahu ke dni `$onDate`.
     *
     * Pojišťovna se čte z časové řady krytí, ne z „aktuálního" údaje: oznámení
     * se váže ke dni skutečnosti a k pojišťovně, která toho dne platila.
     *
     * `start_date` je SKUTEČNÝ den nástupu, plánovaný jen jako záloha. Den
     * nástupu je obsahem oznámení, ne jen filtrem: dokud se bral plánovaný,
     * dostala pojišťovna datum, které se nestalo, kdykoli se nástup posunul.
     * Sloupec si drží název `start_date`, aby se doména nemusela ptát,
     * které z obou dat dostala.
     *
     * @return array{
     *   employment_id:int,employee_id:int,relation_type:string,status:string,
     *   participates:bool,insurer_code:?string,start_date:?string,
     *   end_date:?string,full_name:string,
     *   insurer_changed_on:?string,previous_insurer_code:?string,
     *   maternity_leave_started_on:?string,parental_leave_started_on:?string,
     *   maternity_or_parental_leave_ended_on:?string
     * }|null
     */
    public function findNotificationFacts(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id,
                    employment.employee_id,
                    employment.relation_type,
                    employment.status,
                    COALESCE(employment.actual_start_date, employment.start_date)
                        AS start_date,
                    employment.end_date,
                    employee.full_name,
                    terms.health_insurance_participation,
                    coverage.insurer_code
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               LEFT JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
                AND terms.effective_from <= ?
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
               LEFT JOIN payroll_person_health_coverage_history coverage
                 ON coverage.supplier_id = employment.supplier_id
                AND coverage.employee_id = employment.employee_id
                AND coverage.effective_from <= ?
                AND (coverage.effective_to IS NULL OR coverage.effective_to >= ?)
              WHERE employment.supplier_id = ?
                AND employment.id = ?
              ORDER BY terms.effective_from DESC,
                       coverage.effective_from DESC
              LIMIT 1'
        );
        $statement->execute([
            $onDate,
            $onDate,
            $onDate,
            $onDate,
            $supplierId,
            $employmentId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        // Mateřská, rodičovská i přestup se čtou za CELÝ měsíc dne skutečnosti,
        // ne za ten jediný den. Metodika VZP je oznamuje souhrnně k 20. dni
        // následujícího měsíce, takže měsíc je to okno, ve kterém dávají smysl
        // — a hlavně: přehled za období a detail jednoho vztahu pak vydají
        // tutéž povinnost místo dvou různých odpovědí nad týmiž daty.
        $month = $this->monthBounds($onDate);
        $employmentIds = [(int) $row['id']];
        $leave = $this->leaveOccurrences(
            $supplierId,
            $employmentIds,
            $month['from'],
            $month['to'],
        )[(int) $row['id']] ?? [];
        $change = $this->insurerChanges(
            $supplierId,
            $employmentIds,
            $month['from'],
            $month['to'],
        )[(int) $row['id']] ?? [];

        return [
            'employment_id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'relation_type' => (string) $row['relation_type'],
            'status' => (string) $row['status'],
            'participates' => $this->participates(
                $this->nullableString($row['health_insurance_participation']),
                (string) $row['relation_type'],
            ),
            'insurer_code' => $this->nullableString($row['insurer_code']),
            'start_date' => $this->nullableString($row['start_date']),
            'end_date' => $this->nullableString($row['end_date']),
            'full_name' => (string) $row['full_name'],
            'insurer_changed_on' => $change['changed_on'] ?? null,
            'previous_insurer_code' => $change['previous_insurer_code'] ?? null,
            'maternity_leave_started_on' => $leave['maternity_started_on'] ?? null,
            'parental_leave_started_on' => $leave['parental_started_on'] ?? null,
            'maternity_or_parental_leave_ended_on' =>
                $leave['leave_ended_on'] ?? null,
        ];
    }

    /** @return array{from:string,to:string} */
    private function monthBounds(string $onDate): array
    {
        $date = new \DateTimeImmutable(
            $onDate,
            new \DateTimeZone('Europe/Prague'),
        );

        return [
            'from' => $date->modify('first day of this month')->format('Y-m-d'),
            'to' => $date->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    /**
     * `automatic` znamená „rozhodne výpočet", ne „účastní se". Bez výslovného
     * zahrnutí se proto účast NEPŘEDPOKLÁDÁ — oznámit nástup u vztahu, který
     * účast nezakládá, je stejná vada jako neoznámit ten, který ji zakládá.
     */
    private function participates(
        ?string $participation,
        string $relationType,
    ): bool {
        if ($participation === 'included') {
            return true;
        }
        if ($participation === 'excluded' || $participation === 'foreign') {
            return false;
        }

        return $relationType === 'employment';
    }

    /**
     * Fakta VŠECH pracovních vztahů, u kterých v období `[$from, $to]` mohla
     * oznamovaná skutečnost nastat.
     *
     * Proč to je vlastní dotaz a ne smyčka nad
     * {@see self::findNotificationFacts()}: přehled povinností za období by
     * jinak vydal tolik dotazů, kolik má firma vztahů, a stránkovat by se
     * musel až po jejich načtení. Kandidáti se proto vyberou v SQL a doména
     * z nich odvodí povinnosti nad jedním výsledkem.
     *
     * Vrací se KANDIDÁTI, ne povinnosti — o tom, co je povinnost, rozhoduje
     * dál {@see HealthNotificationDutyResolver}.
     *
     * @return list<array{
     *   employment_id:int,employee_id:int,relation_type:string,status:string,
     *   participates:bool,insurer_code:?string,start_date:?string,
     *   end_date:?string,full_name:string,
     *   insurer_changed_on:?string,previous_insurer_code:?string,
     *   maternity_leave_started_on:?string,parental_leave_started_on:?string,
     *   maternity_or_parental_leave_ended_on:?string
     * }>
     */
    public function listNotificationFacts(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id,
                    employment.employee_id,
                    employment.relation_type,
                    employment.status,
                    COALESCE(employment.actual_start_date, employment.start_date)
                        AS start_date,
                    employment.end_date,
                    employee.full_name,
                    (SELECT terms.health_insurance_participation
                       FROM payroll_employment_terms terms
                      WHERE terms.supplier_id = employment.supplier_id
                        AND terms.employment_id = employment.id
                        AND terms.effective_from <= ?
                        AND (terms.effective_to IS NULL
                             OR terms.effective_to >= ?)
                      ORDER BY terms.effective_from DESC
                      LIMIT 1) AS health_insurance_participation,
                    (SELECT coverage.insurer_code
                       FROM payroll_person_health_coverage_history coverage
                      WHERE coverage.supplier_id = employment.supplier_id
                        AND coverage.employee_id = employment.employee_id
                        AND coverage.effective_from <= ?
                        AND (coverage.effective_to IS NULL
                             OR coverage.effective_to >= ?)
                      ORDER BY coverage.effective_from DESC
                      LIMIT 1) AS insurer_code
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.status NOT IN (\'no_show\', \'archived\')
                AND COALESCE(employment.actual_start_date, employment.start_date) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
              ORDER BY employee.full_name, employment.id'
        );
        // Vztah se do výběru dostane, pokud v období TRVAL. Skutečnost sama
        // (nástup, skončení, nástup na mateřskou) se filtruje až v doméně —
        // kdyby se filtrovala tady, vypadl by vztah, který v období skončil,
        // ale nastoupil dřív, a jeho odhláška by se nikde neukázala.
        //
        // Dvě výjimky se ale filtrují UŽ TADY, protože o nich nerozhoduje
        // doména, ale životní cyklus vztahu: `no_show` je zrušený nástup —
        // člověk do práce nikdy nenastoupil, takže není koho k pojištění
        // přihlásit, a přihláška fiktivního pojištěnce je vada, ne opomenutí.
        // `archived` je vztah vyřazený z evidence (oprava omylu); dokud se
        // nevrátí zpět do `ended`, nemá vyrábět povinnosti. Doména je
        // rozlišit neumí — `HealthNotificationFacts` stav vztahu vůbec nenese.
        $statement->execute([
            $to, $to, $to, $to, $supplierId, $to, $from,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $employmentIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows,
        );
        $leaves = $this->leaveOccurrences($supplierId, $employmentIds, $from, $to);
        $changes = $this->insurerChanges($supplierId, $employmentIds, $from, $to);

        $facts = [];
        foreach ($rows as $row) {
            $employmentId = (int) $row['id'];
            $leave = $leaves[$employmentId] ?? [];
            $change = $changes[$employmentId] ?? [];
            $facts[] = [
                'employment_id' => $employmentId,
                'employee_id' => (int) $row['employee_id'],
                'relation_type' => (string) $row['relation_type'],
                'status' => (string) $row['status'],
                'participates' => $this->participates(
                    $this->nullableString($row['health_insurance_participation']),
                    (string) $row['relation_type'],
                ),
                'insurer_code' => $this->nullableString($row['insurer_code']),
                'start_date' => $this->nullableString($row['start_date']),
                'end_date' => $this->nullableString($row['end_date']),
                'full_name' => (string) $row['full_name'],
                'insurer_changed_on' => $change['changed_on'] ?? null,
                'previous_insurer_code' => $change['previous_insurer_code'] ?? null,
                'maternity_leave_started_on' => $leave['maternity_started_on'] ?? null,
                'parental_leave_started_on' => $leave['parental_started_on'] ?? null,
                'maternity_or_parental_leave_ended_on' =>
                    $leave['leave_ended_on'] ?? null,
            ];
        }

        return $facts;
    }

    /**
     * Zahájení a ukončení mateřské a rodičovské dovolené z evidence absencí.
     *
     * Bere se JEN `approved` — oznámit pojišťovně nástup, který zaměstnavatel
     * teprve zvažuje, znamená podat větu o skutečnosti, která nenastala.
     * `ppm` je peněžitá pomoc v mateřství, tedy mateřská dovolená; `parental`
     * je rodičovská. Obě míří v datové větě na týž kód `M`, ukončení na `U` —
     * proto se ukončení bere z pozdější z obou absencí, ne z každé zvlášť.
     *
     * @param list<int> $employmentIds
     * @return array<int,array{
     *   maternity_started_on:?string,parental_started_on:?string,
     *   leave_ended_on:?string
     * }>
     */
    private function leaveOccurrences(
        int $supplierId,
        array $employmentIds,
        string $from,
        string $to,
    ): array {
        // Prázdný seznam by vyrobil `IN ()`, což je syntaktická chyba SQL.
        // Volající to dnes nikdy neudělá, ale invariant si metoda hlídá sama —
        // implicitní předpoklad se při refaktoru ztratí dřív než guard.
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT employment_id,
                    MIN(CASE WHEN absence_type = \'ppm\'
                              AND date_from BETWEEN ? AND ?
                             THEN date_from END) AS maternity_started_on,
                    MIN(CASE WHEN absence_type = \'parental\'
                              AND date_from BETWEEN ? AND ?
                             THEN date_from END) AS parental_started_on,
                    MAX(CASE WHEN date_to BETWEEN ? AND ?
                             THEN date_to END) AS leave_ended_on
               FROM payroll_absences
              WHERE supplier_id = ?
                AND status = \'approved\'
                AND absence_type IN (\'ppm\', \'parental\')
                AND employment_id IN (' . $placeholders . ')
              GROUP BY employment_id'
        );
        $statement->execute(array_merge(
            [$from, $to, $from, $to, $from, $to, $supplierId],
            $employmentIds,
        ));

        $occurrences = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $occurrences[(int) $row['employment_id']] = [
                'maternity_started_on' =>
                    $this->nullableString($row['maternity_started_on']),
                'parental_started_on' =>
                    $this->nullableString($row['parental_started_on']),
                'leave_ended_on' =>
                    $this->nullableString($row['leave_ended_on']),
            ];
        }

        return $occurrences;
    }

    /**
     * Přestup zaměstnance k jiné zdravotní pojišťovně v období.
     *
     * Změna se pozná tak, že v časové řadě krytí navazuje řádek s JINÝM kódem
     * pojišťovny. Navazující řádek se stejným kódem (jen jiná evidence) změnou
     * není a oznamovat se nemá.
     *
     * @param list<int> $employmentIds
     * @return array<int,array{changed_on:string,previous_insurer_code:string}>
     */
    private function insurerChanges(
        int $supplierId,
        array $employmentIds,
        string $from,
        string $to,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'WITH coverage AS (
                 SELECT employee_id,
                        insurer_code,
                        effective_from,
                        LAG(insurer_code) OVER (
                            PARTITION BY employee_id ORDER BY effective_from
                        ) AS previous_insurer_code
                   FROM payroll_person_health_coverage_history
                  WHERE supplier_id = ?
                    AND insurer_code IS NOT NULL
             )
             SELECT employment.id AS employment_id,
                    coverage.effective_from,
                    coverage.previous_insurer_code
               FROM payroll_employments employment
               JOIN coverage
                 ON coverage.employee_id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.id IN (' . $placeholders . ')
                AND coverage.previous_insurer_code IS NOT NULL
                AND coverage.previous_insurer_code <> coverage.insurer_code
                AND coverage.effective_from BETWEEN ? AND ?
              ORDER BY coverage.effective_from'
        );
        $statement->execute(array_merge(
            [$supplierId, $supplierId],
            $employmentIds,
            [$from, $to],
        ));

        $changes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            // Víc přestupů v jednom měsíci je patologie evidence; bere se
            // první, protože ten je ten, po kterém běží lhůta nejdřív.
            $changes[(int) $row['employment_id']] ??= [
                'changed_on' => (string) $row['effective_from'],
                'previous_insurer_code' =>
                    (string) $row['previous_insurer_code'],
            ];
        }

        return $changes;
    }

    private function normalizePostalCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/\s+/', '', $value);

        return is_string($digits) && $digits !== '' ? $digits : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
