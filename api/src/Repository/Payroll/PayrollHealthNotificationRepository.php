<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\EpoSupplierBlockBuilder;
use MyInvoice\Service\Payroll\HealthInsurance\PayrollExpectedHealthParticipation;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
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
    public function __construct(
        private Connection $db,
        // Práh účasti DPČ pro očekávanou účast ({@see PayrollExpectedHealthParticipation}).
        // Bez výchozí hodnoty schválně: nepovinný parametr kontejner nevyplní
        // a dohody by pak tiše vypadly z oznámení.
        private ?PayrollRulesetProvider $rulesets,
    ) {}

    /**
     * @return array{
     *   business_id:?string,name:string,street:?string,house_number:?string,
     *   postal_code:?string,city:?string,phone:?string
     * }|null
     */
    public function findEmployerIdentification(int $supplierId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT s.ic, s.company_name, s.street, s.street_number_pop,
                    s.street_number_orient, s.zip, s.city, s.phone,
                    settings.payroll_contact_phone
               FROM supplier s
          LEFT JOIN payroll_employer_settings settings
                 ON settings.supplier_id = s.id
              WHERE s.id = ?'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $houseNumber = self::houseNumber(
            $this->nullableString($row['street_number_pop']),
            $this->nullableString($row['street_number_orient']),
        );
        $street = self::streetWithoutHouseNumber(
            $this->nullableString($row['street']),
            $houseNumber,
            $this->nullableString($row['street_number_pop']),
        );
        // Firma bez čísel v samostatných polích má číslo jen v adresním řádku
        // („Plzeňská 3070"). Rozdělí ho stejné pravidlo jako u ostatních podání.
        if ($houseNumber === null && $street !== null) {
            [$parsedStreet, $popisne, $orientacni] = EpoSupplierBlockBuilder::parseStreet(['street' => $street]);
            $parsedNumber = EpoSupplierBlockBuilder::houseNumber($popisne, $orientacni);
            if ($parsedNumber !== '') {
                $houseNumber = $parsedNumber;
                $street = $parsedStreet;
            }
        }

        return [
            'business_id' => $this->nullableString($row['ic']),
            'name' => (string) $row['company_name'],
            'street' => $street,
            'house_number' => $houseNumber,
            'postal_code' => $this->normalizePostalCode(
                $this->nullableString($row['zip']),
            ),
            'city' => $this->nullableString($row['city']),
            // Formulář pojišťovny se ptá na kontakt, kam zavolat kvůli pojistnému,
            // ne na spojovatelku firmy. Mzdový kontakt je proto přednější; bez
            // něj zbývá firemní telefon, aby pole nezůstalo prázdné.
            'phone' => $this->nullableString($row['payroll_contact_phone'])
                ?? $this->nullableString($row['phone']),
        ];
    }

    /**
     * Číslo popisné a orientační v úředním tvaru „1104/36".
     *
     * Formulář pojišťovny má jediné pole „číslo popisné / číslo orientační".
     * Dokud se plnilo jen popisným, orientační číslo z adresy zmizelo — a to
     * je u adres, kde obě čísla existují, jiná adresa.
     */
    private static function houseNumber(?string $popisne, ?string $orientacni): ?string
    {
        if ($popisne === null) {
            return $orientacni;
        }

        return $orientacni === null ? $popisne : $popisne . '/' . $orientacni;
    }

    /**
     * Název ulice bez čísla domu.
     *
     * `supplier.street` drží celý adresní řádek („Dlouhá 1104/36"),
     * zatímco formulář má ulici a číslo v oddělených polích. Bez tohohle
     * odříznutí bylo číslo domu na přehledu dvakrát — jednou v ulici a jednou
     * ve vlastním poli.
     *
     * Neodhaduje se: odřízne se jen koncovka, která se PŘESNĚ shoduje s číslem
     * složeným z evidovaných polí. Když se neshoduje, zůstane adresní řádek tak,
     * jak ho firma zadala — vymýšlet rozdělení by znamenalo poslat pojišťovně
     * adresu, která takhle nikdy zapsaná nebyla.
     */
    private static function streetWithoutHouseNumber(
        ?string $street,
        ?string $houseNumber,
        ?string $popisne,
    ): ?string {
        if ($street === null) {
            return null;
        }
        foreach ([$houseNumber, $popisne] as $koncovka) {
            if ($koncovka === null || $koncovka === '') {
                continue;
            }
            if (str_ends_with($street, ' ' . $koncovka)) {
                $zbytek = trim(substr($street, 0, -strlen($koncovka)));

                return $zbytek === '' ? $street : $zbytek;
            }
        }

        return $street;
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
     * které z obou dat dostala. Výjimkou je dohoda, u které účast doložil až
     * mzdový běh pozdějšího měsíce — viz {@see self::assemble()}.
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
                    terms.health_association_member,
                    terms.activity_code,
                    ' . PayrollEmploymentLifecycleSql::effectiveMonthlyGrossAtPlaceholder() . '
                        AS agreed_monthly_minor,
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

        return $this->assemble(
            $supplierId,
            [$row],
            $month['from'],
            $month['to'],
            $onDate,
        )[0] ?? null;
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
                    (SELECT terms.health_association_member
                       FROM payroll_employment_terms terms
                      WHERE terms.supplier_id = employment.supplier_id
                        AND terms.employment_id = employment.id
                        AND terms.effective_from <= ?
                        AND (terms.effective_to IS NULL
                             OR terms.effective_to >= ?)
                      ORDER BY terms.effective_from DESC
                      LIMIT 1) AS health_association_member,
                    (SELECT terms.activity_code
                       FROM payroll_employment_terms terms
                      WHERE terms.supplier_id = employment.supplier_id
                        AND terms.employment_id = employment.id
                        AND terms.effective_from <= ?
                        AND (terms.effective_to IS NULL
                             OR terms.effective_to >= ?)
                      ORDER BY terms.effective_from DESC
                      LIMIT 1) AS activity_code,
                    ' . PayrollEmploymentLifecycleSql::effectiveMonthlyGrossAtPlaceholder() . ' AS agreed_monthly_minor,
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
            $to, $to, $to, $to, $to, $to, $to, $to, $to, $to, $supplierId, $to, $from,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return $this->assemble($supplierId, $rows, $from, $to, $to);
    }

    /**
     * Z řádků vztahů složí fakta pro doménu.
     *
     * Účast na zdravotním pojištění rozhoduje
     * {@see PayrollExpectedHealthParticipation}, ne pravidla ČSSZ. U DPP a DPČ,
     * kde ji doložil až schválený mzdový běh, se jako den „nástupu" oznamuje
     * první den prvního měsíce s účastí (nebo skutečný nástup, je-li pozdější):
     * v měsících před ním dohoda zaměstnáním pro ZP nebyla a přihláška k původnímu
     * dni by hlásila pojištění, které nevzniklo. Skončení se pak hlásí ke dni
     * skončení dohody.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{
     *   employment_id:int,employee_id:int,relation_type:string,status:string,
     *   participates:bool,insurer_code:?string,start_date:?string,
     *   end_date:?string,full_name:string,
     *   insurer_changed_on:?string,previous_insurer_code:?string,
     *   maternity_leave_started_on:?string,parental_leave_started_on:?string,
     *   maternity_or_parental_leave_ended_on:?string
     * }>
     */
    private function assemble(
        int $supplierId,
        array $rows,
        string $from,
        string $to,
        string $thresholdOn,
    ): array {
        $threshold = $this->rulesets === null
            ? null
            : PayrollExpectedHealthParticipation::dpcThreshold($this->rulesets, $thresholdOn);
        $employmentIds = [];
        $endDates = [];
        $agreements = [];
        foreach ($rows as $row) {
            $employmentId = (int) $row['id'];
            $employmentIds[] = $employmentId;
            $endDates[$employmentId] = $this->nullableString($row['end_date']);
            if (PayrollExpectedHealthParticipation::decidedByMonthlyIncome(
                (string) $row['relation_type'],
                (int) ($row['health_association_member'] ?? 0) === 1,
            )) {
                $agreements[] = $employmentId;
            }
        }
        $leaves = $this->leaveOccurrences($supplierId, $employmentIds, $endDates, $from, $to);
        $changes = $this->insurerChanges($supplierId, $employmentIds, $from, $to);
        $participatedSince = $this->firstParticipatingPeriods($supplierId, $agreements, $to);

        $facts = [];
        foreach ($rows as $row) {
            $employmentId = (int) $row['id'];
            $leave = $leaves[$employmentId] ?? [];
            $change = $changes[$employmentId] ?? [];
            $participation = $this->nullableString($row['health_insurance_participation']);
            $relationType = (string) $row['relation_type'];
            $agreed = $row['agreed_monthly_minor'] === null ? null : (int) $row['agreed_monthly_minor'];
            $firstPeriod = $participatedSince[$employmentId] ?? null;
            $associationMember = (int) ($row['health_association_member'] ?? 0) === 1;
            $activityCode = $this->nullableString($row['activity_code'] ?? null);
            $predicted = PayrollExpectedHealthParticipation::expected(
                $participation,
                $relationType,
                $agreed,
                $threshold,
                associationMember: $associationMember,
                activityCode: $activityCode,
            );
            $participates = $predicted || PayrollExpectedHealthParticipation::expected(
                $participation,
                $relationType,
                $agreed,
                $threshold,
                $firstPeriod !== null,
                $associationMember,
                $activityCode,
            );
            $startDate = $this->nullableString($row['start_date']);
            $endDate = $endDates[$employmentId];
            if (!$predicted
                && $firstPeriod !== null
                && $startDate !== null
                && $firstPeriod > $startDate
                && ($endDate === null || $firstPeriod <= $endDate)
            ) {
                $startDate = $firstPeriod;
            }
            $facts[] = [
                'employment_id' => $employmentId,
                'employee_id' => (int) $row['employee_id'],
                'relation_type' => $relationType,
                'status' => (string) $row['status'],
                'participates' => $participates,
                'insurer_code' => $this->nullableString($row['insurer_code']),
                'start_date' => $startDate,
                'end_date' => $endDate,
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
     * První mzdové období (`YYYY-MM-01`) nejpozději do `$to`, ve kterém
     * schválený mzdový běh u vztahu doložil účast na zdravotním pojištění.
     *
     * Čte se poslední schválená revize každého běhu, stejně jako u měsíční
     * agendy; zrušený běh účast nedokládá.
     *
     * @param list<int> $employmentIds
     * @return array<int,string>
     */
    private function firstParticipatingPeriods(
        int $supplierId,
        array $employmentIds,
        string $to,
    ): array {
        if ($employmentIds === []
            || !$this->db->hasTable('payroll_statutory_relationship_results')
        ) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'WITH current_revision AS (
                 SELECT revision.id,
                        run.period_start,
                        ROW_NUMBER() OVER (
                            PARTITION BY revision.supplier_id, revision.run_id
                            ORDER BY revision.revision_no DESC
                        ) AS row_rank
                   FROM payroll_run_revisions revision
                   JOIN payroll_runs run
                     ON run.supplier_id = revision.supplier_id
                    AND run.id = revision.run_id
                  WHERE revision.supplier_id = ?
                    AND revision.status IN (\'approved\', \'superseded\')
                    AND run.status <> \'cancelled\'
                    AND run.period_start <= ?
             )
             SELECT relationship.employment_id,
                    MIN(current_revision.period_start) AS first_period
               FROM payroll_statutory_relationship_results relationship
               JOIN current_revision
                 ON current_revision.id = relationship.revision_id
                AND current_revision.row_rank = 1
              WHERE relationship.supplier_id = ?
                AND relationship.calculation_kind = \'health_insurance\'
                AND relationship.employment_id IN (' . $placeholders . ')
                AND JSON_VALUE(relationship.result_snapshot_json, \'$.participation.status\')
                    = \'participates\'
              GROUP BY relationship.employment_id'
        );
        $statement->execute(array_merge(
            [$supplierId, $to, $supplierId],
            $employmentIds,
        ));

        $periods = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $period = $this->nullableString($row['first_period']);
            if ($period !== null) {
                $periods[(int) $row['employment_id']] = $period;
            }
        }

        return $periods;
    }

    /**
     * Zahájení a ukončení mateřské a rodičovské dovolené z evidence absencí.
     *
     * Bere se JEN `approved` — oznámit pojišťovně nástup, který zaměstnavatel
     * teprve zvažuje, znamená podat větu o skutečnosti, která nenastala.
     * `ppm` je peněžitá pomoc v mateřství, tedy mateřská dovolená; `parental`
     * je rodičovská. Obě míří v datové větě na týž kód `M`, ukončení na `U`.
     *
     * Navazující absence (rodičovská hned po mateřské, bez mezery) tvoří JEDEN
     * řetěz: `M` se hlásí jen na jeho začátku a `U` jen na jeho konci. Anotace
     * `kodZmenyZamestnaceTyp` v HOZ XSD váže `M` na začátek a `U` na ukončení
     * nepřítomnosti, po kterou je pojištěncem stát; přechod mateřské do
     * rodičovské tu nepřítomnost nepřerušuje, takže dvojice `U` + `M` týž měsíc
     * by hlásila konec a nový začátek něčeho, co neskončilo. Přerušená řada
     * (mezi absencemi je aspoň jeden den) jsou dva řetězy a dává `U` i `M`.
     *
     * Skončí-li pracovní vztah v době, kdy řetěz trvá, hlásí se vedle odhlášky
     * `O` i `U` ke dni skončení vztahu: zaměstnavatel tím dnem přestává být tím,
     * kdo skutečnost „plátcem je stát" za pojištěnce hlásí, a pojišťovna by jinak
     * kategorii státního pojištěnce vedla dál bez konce.
     *
     * @param list<int> $employmentIds
     * @param array<int,?string> $endDates den skončení vztahu podle id vztahu
     * @return array<int,array{
     *   maternity_started_on:?string,parental_started_on:?string,
     *   leave_ended_on:?string
     * }>
     */
    private function leaveOccurrences(
        int $supplierId,
        array $employmentIds,
        array $endDates,
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
        // Kromě absencí v období je potřeba i soused těsně před ním a těsně
        // po něm: jen tak jde poznat, že začátek v období navazuje na dřívější
        // absenci, nebo že konec v období plynule pokračuje další.
        $statement = $this->db->pdo()->prepare(
            'SELECT employment_id, absence_type, date_from, date_to
               FROM payroll_absences
              WHERE supplier_id = ?
                AND status = \'approved\'
                AND absence_type IN (\'ppm\', \'parental\')
                AND employment_id IN (' . $placeholders . ')
                AND date_from <= DATE_ADD(?, INTERVAL 1 DAY)
                AND (date_to IS NULL OR date_to >= DATE_SUB(?, INTERVAL 1 DAY))
              ORDER BY employment_id, date_from, id'
        );
        $statement->execute(array_merge(
            [$supplierId],
            $employmentIds,
            [$to, $from],
        ));

        $absences = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $absences[(int) $row['employment_id']][] = [
                'type' => (string) $row['absence_type'],
                'from' => (string) $row['date_from'],
                'to' => $this->nullableString($row['date_to']),
            ];
        }

        $occurrences = [];
        foreach ($absences as $employmentId => $rows) {
            $endDate = $endDates[$employmentId] ?? null;
            $maternity = null;
            $parental = null;
            $ended = null;
            foreach (self::leaveChains($rows) as $chain) {
                if ($chain['from'] >= $from && $chain['from'] <= $to) {
                    if ($chain['type'] === 'ppm') {
                        $maternity ??= $chain['from'];
                    } else {
                        $parental ??= $chain['from'];
                    }
                }
                $chainEnd = $chain['to'];
                if ($endDate !== null
                    && $chain['from'] <= $endDate
                    && ($chainEnd === null || $chainEnd > $endDate)
                ) {
                    // Vztah skončil uprostřed řetězu: konec státní kategorie
                    // se hlásí ke dni skončení vztahu, ne k pozdějšímu konci
                    // absence, kterou už zaměstnavatel nehlásí.
                    $chainEnd = $endDate;
                }
                if ($chainEnd !== null && $chainEnd >= $from && $chainEnd <= $to) {
                    $ended = $ended === null || $chainEnd > $ended ? $chainEnd : $ended;
                }
            }
            if ($maternity === null && $parental === null && $ended === null) {
                continue;
            }
            $occurrences[$employmentId] = [
                'maternity_started_on' => $maternity,
                'parental_started_on' => $parental,
                'leave_ended_on' => $ended,
            ];
        }

        return $occurrences;
    }

    /**
     * Seřazené absence jednoho vztahu sloučí do souvislých řetězů. Další
     * absence navazuje, když začíná nejpozději den po konci předchozí;
     * otevřená absence (bez konce) řetěz neukončí nikdy.
     *
     * @param list<array{type:string,from:string,to:?string}> $rows
     * @return list<array{type:string,from:string,to:?string}>
     */
    private static function leaveChains(array $rows): array
    {
        $chains = [];
        $current = null;
        foreach ($rows as $row) {
            if ($current !== null
                && ($current['to'] === null
                    || $row['from'] <= self::nextDay($current['to']))
            ) {
                if ($current['to'] !== null
                    && ($row['to'] === null || $row['to'] > $current['to'])
                ) {
                    $current['to'] = $row['to'];
                }
                continue;
            }
            if ($current !== null) {
                $chains[] = $current;
            }
            $current = $row;
        }
        if ($current !== null) {
            $chains[] = $current;
        }

        return $chains;
    }

    private static function nextDay(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
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
