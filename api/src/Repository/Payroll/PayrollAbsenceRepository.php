<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\AbsenceHolidaySegments;
use MyInvoice\Service\Payroll\Absence\AbsenceHolidayTreatment;
use MyInvoice\Service\Payroll\Absence\AbsenceRuleset;
use MyInvoice\Service\Payroll\PayrollYearCloseGuard;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollWorkCalendarSchedule;
use PDO;

final class PayrollAbsenceRepository
{
    private readonly PayrollYearCloseGuard $yearClose;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly CzechHolidayCalendar $holidayCalendar = new CzechHolidayCalendar(),
    ) {
        $this->yearClose = new PayrollYearCloseGuard($db);
    }

    /** @return list<array<string,mixed>> */
    public function employments(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment.id, employment.employee_id, employment.code,
                    employment.relation_type, employment.status,
                    employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.status NOT IN ('archived', 'no_show')
              ORDER BY employee.full_name, employment.code"
        );
        $stmt->execute([$supplierId]);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['employee_id'] = (int) $row['employee_id'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Strop stránky seznamu. Absence jsou pracovní tabulka — obrazovka ukazuje
     * pár desítek řádků jednoho období. Počet řádků přitom roste součinem
     * počtu zaměstnanců a délky filtrovaného rozsahu, takže bez stropu je
     * odpověď u větší firmy a ročního filtru neomezená.
     */
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function list(
        int $supplierId,
        string $from,
        string $to,
        ?int $employmentId = null,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $where = 'absence.supplier_id = ? AND absence.date_from <= ? AND absence.date_to >= ?';
        $params = [$supplierId, $to, $from];
        if ($employmentId !== null) {
            $where .= ' AND absence.employment_id = ?';
            $params[] = $employmentId;
        }
        $source = "FROM payroll_absences absence
               JOIN payroll_employments employment
                 ON employment.supplier_id = absence.supplier_id
                AND employment.id = absence.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               LEFT JOIN payroll_average_earning_snapshots average
                 ON average.supplier_id = absence.supplier_id
                AND average.id = absence.average_snapshot_id
              WHERE {$where}";

        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) {$source}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            "SELECT absence.*, employment.code AS employment_code,
                    employment.relation_type, employee.full_name,
                    average.average_hourly_minor,
                    average.applicable_year AS average_year,
                    average.applicable_quarter AS average_quarter
               {$source}
              ORDER BY absence.date_from, employee.full_name, absence.id
              LIMIT ? OFFSET ?"
        );
        $position = 1;
        foreach ($params as $param) {
            $stmt->bindValue($position++, $param);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total,
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function create(int $supplierId, array $data, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->yearClose->assertOpenForDateRange(
                $supplierId,
                (string) $data['date_from'],
                (string) $data['date_to'],
            );
            $this->lockEmployment($supplierId, (int) $data['employment_id']);
            $this->assertNoOverlap(
                $supplierId,
                (int) $data['employment_id'],
                (string) $data['date_from'],
                (string) $data['date_to'],
                null,
            );

            if ($data['average_snapshot_id'] !== null) {
                $this->assertApprovedAverage(
                    $supplierId,
                    (int) $data['employment_id'],
                    (int) $data['average_snapshot_id'],
                    (string) $data['date_from'],
                );
            }
            $childbirth = $data['childbirth_date'] ?? null;
            $insert = $pdo->prepare(
                'INSERT INTO payroll_absences
                    (supplier_id, employment_id, absence_type, obstacle_kind, date_from, date_to,
                     expected_childbirth_date, childbirth_date, lone_carer,
                     childbirth_recorded_by, childbirth_recorded_at,
                     timezone_name, partial_first_minutes, partial_last_minutes, note,
                     compensation_policy, compensation_rate_basis_points, compensation_rate_reason,
                     average_snapshot_id, support_status, status, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? IS NULL, NULL, NOW()),
                         ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $data['employment_id'],
                $data['absence_type'],
                $data['obstacle_kind'] ?? null,
                $data['date_from'],
                $data['date_to'],
                $data['expected_childbirth_date'] ?? null,
                $childbirth,
                ($data['lone_carer'] ?? false) === true ? 1 : 0,
                $childbirth === null ? null : $userId,
                $childbirth,
                $data['timezone_name'],
                $data['partial_first_minutes'],
                $data['partial_last_minutes'],
                $data['note'],
                $data['compensation_policy'],
                $data['compensation_rate_basis_points'],
                $data['compensation_rate_reason'] ?? null,
                $data['average_snapshot_id'],
                'manual_review',
                'requested',
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Uložená absence nebyla nalezena.');
    }

    /** @return array<string,mixed> */
    public function decide(
        int $supplierId,
        int $id,
        int $expectedVersion,
        string $decision,
        ?int $userId,
    ): array {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Rozhodnutí absence není platné.');
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $absence = $this->find($supplierId, $id);
            if ($absence !== null) {
                $this->yearClose->assertOpenForDateRange(
                    $supplierId,
                    (string) $absence['date_from'],
                    (string) $absence['date_to'],
                );
            }
            /*
             * Zamítnutí je vratné. Zamítnutá absence nic nematerializovala —
             * nemá mzdový vstup, nečerpá dovolenou, neblokuje ani překryv —
             * takže překliknuté „Zamítnout“ nesmí být konec. Jediné, co je
             * potřeba ohlídat, je překryv: mezitím mohla vzniknout náhradní
             * absence na stejné dny.
             */
            if ($absence !== null
                && $decision === 'approved'
                && ($absence['status'] ?? null) === 'rejected'
            ) {
                $this->assertNoOverlap(
                    $supplierId,
                    (int) $absence['employment_id'],
                    (string) $absence['date_from'],
                    (string) $absence['date_to'],
                    $id,
                );
            }
            $stmt = $pdo->prepare(
                "UPDATE payroll_absences
                    SET status = ?, decided_by = ?, decided_at = NOW(),
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status IN ('requested', 'rejected')"
            );
            $stmt->execute([$decision, $userId, $supplierId, $id, $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                $this->throwConflictOrInvalid($supplierId, $id, $expectedVersion);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Rozhodnutá absence nebyla nalezena.');
    }

    /** @return array<string,mixed> */
    public function cancel(
        int $supplierId,
        int $id,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $absence = $this->find($supplierId, $id);
            if ($absence !== null) {
                $this->yearClose->assertOpenForDateRange(
                    $supplierId,
                    (string) $absence['date_from'],
                    (string) $absence['date_to'],
                );
            }
            $stmt = $pdo->prepare(
                "UPDATE payroll_absences
                    SET correction_pending = IF(status = 'approved', 1, correction_pending),
                        status = 'cancelled',
                        decided_by = ?, decided_at = NOW(), row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status IN ('requested','approved')"
            );
            $stmt->execute([$userId, $supplierId, $id, $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                $this->throwConflictOrInvalid($supplierId, $id, $expectedVersion);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Zrušená absence nebyla nalezena.');
    }

    /**
     * Doplní den porodu k peněžité pomoci v mateřství, i ke schválené.
     *
     * PPM se zapisuje dopředu a porod nastane až v jejím průběhu, takže den
     * porodu je jediný údaj absence, který se po schválení doplňuje. Mzdu
     * nemění (PPM vyplácí ČSSZ), mění ale vyloučené doby evidenčního listu.
     *
     * Rozhodnutí: den porodu jde doplnit JEDNOU a pak se už nemění. Z něj se
     * odvozuje atribut 10359 měsíčního hlášení a evidenčního listu; tichý
     * přepis by rozešel už podaná hlášení s evidencí, aniž by to kdokoli
     * viděl. Opravit překlep jde zrušením nepřítomnosti a novým zápisem, kde
     * zrušení nechá dohledatelnou stopu.
     *
     * `correction_pending` se tu záměrně NEROZSVĚCUJE. Příznak zhasíná jen
     * opravná revize měsíce, do kterého se celá absence vejde
     * ({@see PayrollRunRepository::clearAbsenceCorrectionPending()}), a PPM
     * trvá měsíce, takže rozsvícený by zablokoval pracovní souhrn i uzávěrku roku
     * napořád. Měsíce před porodem se doplněním navíc nemění: bez dne porodu
     * se hlásit dají jen ty, které končí před očekávaným dnem porodu.
     *
     * Uzávěrka roku se respektuje stejně jako u schválení a zrušení.
     *
     * @return array<string,mixed>
     */
    public function recordChildbirth(
        int $supplierId,
        int $id,
        int $expectedVersion,
        string $childbirthDate,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $absence = $this->find($supplierId, $id)
                ?? throw new \InvalidArgumentException('Absence nebyla nalezena.');
            $this->yearClose->assertOpenForDateRange(
                $supplierId,
                (string) $absence['date_from'],
                (string) $absence['date_to'],
            );
            $stmt = $pdo->prepare(
                "UPDATE payroll_absences
                    SET childbirth_date = ?, childbirth_recorded_by = ?,
                        childbirth_recorded_at = NOW(), row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND absence_type = 'ppm'
                    AND status IN ('requested', 'approved')
                    AND expected_childbirth_date IS NOT NULL
                    AND childbirth_date IS NULL"
            );
            $stmt->execute([$childbirthDate, $userId, $supplierId, $id, $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                $this->throwChildbirthNotRecordable($supplierId, $id, $expectedVersion);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Absence s doplněným dnem porodu nebyla nalezena.');
    }

    private function throwChildbirthNotRecordable(int $supplierId, int $id, int $expectedVersion): never
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_version, status, absence_type, childbirth_date, expected_childbirth_date
               FROM payroll_absences WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \InvalidArgumentException('Absence nebyla nalezena.');
        }
        if ((int) $row['row_version'] !== $expectedVersion) {
            throw new PayrollAbsenceConflictException((int) $row['row_version']);
        }
        throw new \InvalidArgumentException(match (true) {
            $row['absence_type'] !== 'ppm' => 'Den porodu se doplňuje jen u peněžité pomoci v mateřství.',
            $row['childbirth_date'] !== null => 'Den porodu je už doplněný (' . $row['childbirth_date']
                . ') a nemění se, protože vstupuje do podaných hlášení. Je-li chybný, zrušte '
                . 'nepřítomnost a zapište ji znovu.',
            $row['expected_childbirth_date'] === null => 'Nepřítomnost nemá očekávaný den porodu. '
                . 'Zrušte ji a zapište znovu i s očekávaným dnem porodu.',
            default => 'Den porodu lze doplnit jen k nepřítomnosti, která čeká na schválení '
                . 'nebo je schválená.',
        });
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT absence.*, employment.code AS employment_code,
                    employment.relation_type, employee.full_name,
                    average.average_hourly_minor,
                    average.applicable_year AS average_year,
                    average.applicable_quarter AS average_quarter
               FROM payroll_absences absence
               JOIN payroll_employments employment
                 ON employment.supplier_id = absence.supplier_id
                AND employment.id = absence.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               LEFT JOIN payroll_average_earning_snapshots average
                 ON average.supplier_id = absence.supplier_id
                AND average.id = absence.average_snapshot_id
              WHERE absence.supplier_id = ? AND absence.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::cast($row) : null;
    }

    /**
     * Začátek sociální události, jejíž součástí je tahle nepřítomnost.
     *
     * Neschopnost zapsaná po částech (typicky po měsících nebo prodloužením)
     * je jedna událost: schválené nepřítomnosti téhož druhu a vztahu, které na
     * sebe den po dni navazují, se sčítají zpět až k té první. Vrací id, první
     * den a dny okna vyčerpané před ní ({@see carriedWindowDays}).
     *
     * @return array{id:int,date_from:string,carried_days:int}|null
     */
    public function contiguousChainStart(int $supplierId, int $absenceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "WITH RECURSIVE chain AS (
                 SELECT id, employment_id, absence_type, date_from, sickness_window_carried_days
                   FROM payroll_absences
                  WHERE supplier_id = ? AND id = ?
                 UNION ALL
                 SELECT previous.id, previous.employment_id, previous.absence_type,
                        previous.date_from, previous.sickness_window_carried_days
                   FROM payroll_absences previous
                   JOIN chain
                     ON previous.employment_id = chain.employment_id
                    AND previous.absence_type = chain.absence_type
                    AND previous.date_to = DATE_SUB(chain.date_from, INTERVAL 1 DAY)
                  WHERE previous.supplier_id = ?
                    AND previous.status = 'approved'
             )
             SELECT id, date_from, sickness_window_carried_days
               FROM chain
              ORDER BY date_from ASC, id ASC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $absenceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'date_from' => (string) $row['date_from'],
            'carried_days' => self::carriedWindowDays($row),
        ];
    }

    /**
     * Sjednaná týdenní pracovní doba jako DECIMAL(5,2) na celé minuty;
     * nesouměřitelné hodnoty zahodí.
     */
    public static function weeklyMinutesFromHours(mixed $value): ?int
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D', (string) $value, $parts) !== 1
        ) {
            return null;
        }
        $centihours = ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
        $minuteHundredths = $centihours * 60;
        if ($centihours <= 0 || $minuteHundredths % 100 !== 0) {
            return null;
        }

        return intdiv($minuteHundredths, 100);
    }

    /**
     * @return list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>
     */
    public function publishedShiftSegments(
        array $absence,
        bool $firstDayFullyWorked,
        AbsenceHolidayTreatment $holidayTreatment = AbsenceHolidayTreatment::Ignore,
    ): array {
        if (!$this->db->hasTable('payroll_shifts')) {
            return [];
        }
        $bounds = $this->absenceBounds($absence, $firstDayFullyWorked);
        if ($bounds['window_to'] < $bounds['from']) {
            return [];
        }

        return $this->segmentsBetween($absence, $bounds['from'], $bounds['window_to'], $holidayTreatment);
    }

    /**
     * Rozsah dnů nepřítomnosti, za které může vzniknout náhrada, a u nemoci okno § 192 ZP.
     *
     * Jediné místo, které ty hranice určuje. Čtou je směnová cesta
     * ({@see publishedShiftSegments}, {@see publishedShiftSegmentsBeyondSicknessWindow}),
     * kalendářní cesta krácení mzdy
     * ({@see \MyInvoice\Service\Payroll\Absence\PayrollWageProrationService}) i uložené okno
     * výpočtu náhrady ({@see PayrollSicknessRepository::record()}). Dřív si je každý počítal
     * sám a stačila jedna odlišnost (vyčerpané dny, konec vztahu), aby se náhrada, krácení
     * a hlášení rozešly.
     *
     * - `from`: první den, který se měří (po odpracovaném prvním dni o den později),
     *   nejdřív ale dnem skutečného nástupu do vztahu;
     * - `window_to`: poslední den okna náhrady, u jiných druhů konec nepřítomnosti;
     *   menší než `from` znamená prázdné okno;
     * - `to`: konec nepřítomnosti.
     *
     * `window_to` i `to` končí nejpozději dnem skončení vztahu (DPN-04): náhrada mzdy je
     * povinnost zaměstnavatele v pracovním vztahu, po jeho skončení ji nikdo nedluží.
     * Okno se přitom počítá od skutečného prvního dne neschopnosti, ne od oříznutého.
     *
     * @param array<string,mixed> $absence
     * @return array{from:\DateTimeImmutable,window_to:\DateTimeImmutable,to:\DateTimeImmutable}
     */
    public function absenceBounds(array $absence, bool $firstDayFullyWorked): array
    {
        $timezone = new \DateTimeZone((string) ($absence['timezone_name'] ?? 'Europe/Prague'));
        $from = new \DateTimeImmutable((string) $absence['date_from'], $timezone);
        if ($firstDayFullyWorked) {
            $from = $from->modify('+1 day');
        }
        $to = new \DateTimeImmutable((string) $absence['date_to'], $timezone);
        $windowTo = $to;
        if (self::isSickness($absence)) {
            $windowEnd = $this->sicknessWindowEnd($absence, $from);
            if ($windowEnd < $windowTo) {
                $windowTo = $windowEnd;
            }
        }

        $employment = $this->employmentBounds(
            (int) ($absence['supplier_id'] ?? 0),
            (int) ($absence['employment_id'] ?? 0),
        );
        if ($employment['start'] !== null) {
            $start = new \DateTimeImmutable($employment['start'], $timezone);
            if ($start > $from) {
                $from = $start;
            }
        }
        if ($employment['end'] !== null) {
            $end = new \DateTimeImmutable($employment['end'], $timezone);
            if ($end < $to) {
                $to = $end;
            }
            if ($end < $windowTo) {
                $windowTo = $end;
            }
        }

        return ['from' => $from, 'window_to' => $windowTo, 'to' => $to];
    }

    /**
     * Den skutečného nástupu a den skončení pracovního vztahu (`null` = neomezeno).
     *
     * @return array{start:?string,end:?string}
     */
    public function employmentBounds(int $supplierId, int $employmentId): array
    {
        if ($supplierId <= 0 || $employmentId <= 0) {
            return ['start' => null, 'end' => null];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(actual_start_date, start_date) AS start_on, end_date
               FROM payroll_employments WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['start' => null, 'end' => null];
        }

        return [
            'start' => $row['start_on'] === null ? null : (string) $row['start_on'],
            'end' => $row['end_date'] === null ? null : (string) $row['end_date'],
        ];
    }

    /**
     * Naplánované směny nemoci LEŽÍCÍ AŽ ZA oknem náhrady mzdy podle § 192 ZP.
     *
     * Doplněk k {@see publishedShiftSegments}, který u DPN a karantény vrací
     * právě to okno. Za ním zaměstnavatel náhradu neposkytuje (dávku vyplácí
     * ČSSZ), ale hodiny jsou pořád neodpracované a měsíční hlášení je chce
     * uvést zvlášť (atribut 10277 proti 10278). Bez tohohle doplňku by se dala
     * z evidence odvodit jen ta placená část a delší nemoc by hlášení
     * podhodnotila.
     *
     * Dělení je čistý řez podle data: obě metody čtou tytéž publikované směny
     * a tutéž logiku částečně zameškaných směn, jen na disjunktních rozsazích
     * dnů. Svátek se tu neřeší — mimo okno náhrady za něj zaměstnavatel nic
     * neposkytuje, takže bez rozvržené směny je hodin nula.
     *
     * @param array<string,mixed> $absence
     * @return list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>
     */
    public function publishedShiftSegmentsBeyondSicknessWindow(
        array $absence,
        bool $firstDayFullyWorked,
    ): array {
        if (!self::isSickness($absence) || !$this->db->hasTable('payroll_shifts')) {
            return [];
        }
        $bounds = $this->absenceBounds($absence, $firstDayFullyWorked);
        $tailFrom = $bounds['window_to']->modify('+1 day');
        if ($tailFrom < $bounds['from']) {
            $tailFrom = $bounds['from'];
        }
        if ($tailFrom > $bounds['to']) {
            return [];
        }

        return $this->segmentsBetween(
            $absence,
            $tailFrom,
            $bounds['to'],
            AbsenceHolidayTreatment::Ignore,
        );
    }

    /**
     * Druh s oknem náhrady mzdy podle § 192 ZP (neschopnost, karanténa).
     *
     * @param array<string,mixed> $absence
     */
    public static function isSickness(array $absence): bool
    {
        return in_array($absence['absence_type'] ?? null, ['dpn', 'quarantine'], true);
    }

    /**
     * Poslední den okna náhrady mzdy podle § 192 ZP.
     *
     * Délka okna se historicky měnila (21 → 14 dnů), proto je v rulesetu,
     * ne v literálu. Okno se zkracuje o dny, které padly ještě před začátkem
     * téhle nepřítomnosti — u neschopnosti převzaté z jiného mzdového programu
     * (`sickness_window_carried_days`, migrace 1850). Okno patří případu, ne
     * plátci: bez toho by převzatá nemoc dostala celých čtrnáct dnů znovu.
     *
     * @param array<string,mixed> $absence
     */
    private function sicknessWindowEnd(
        array $absence,
        \DateTimeImmutable $windowFrom,
    ): \DateTimeImmutable {
        return AbsenceRuleset::forDate($this->rulesets, (string) $absence['date_from'])
            ->sicknessWindowEnd($windowFrom, $this->carriedFor($absence));
    }

    /**
     * Vyčerpané dny okna i pro řádek, který sloupec nenese.
     *
     * Někteří čtenáři (souhrn měsíce pro JMHZ) skládají řádek nepřítomnosti z vlastního
     * výběru sloupců. Bez sloupce by {@see carriedWindowDays()} vrátilo nulu a okno by
     * se tiše prodloužilo na celých čtrnáct dnů.
     *
     * @param array<string,mixed> $absence
     */
    private function carriedFor(array $absence): int
    {
        if (array_key_exists('sickness_window_carried_days', $absence)) {
            return self::carriedWindowDays($absence);
        }
        $id = (int) ($absence['id'] ?? 0);
        $supplierId = (int) ($absence['supplier_id'] ?? 0);
        if ($id <= 0 || $supplierId <= 0) {
            return 0;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT sickness_window_carried_days FROM payroll_absences WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : max(0, (int) $value);
    }

    /**
     * Dny okna § 192 ZP vyčerpané před začátkem navazující části téže neschopnosti.
     *
     * Okno je prvních 14 kalendářních dnů TRVÁNÍ neschopnosti (§ 192 odst. 1 ZP), ne
     * každé zapsané části zvlášť. Část, která navazuje na předchozí, má vyčerpané:
     * dny od začátku okna řetězu do svého prvního dne plus dny, které řetěz vyčerpal
     * ještě před svou první částí (předchozí plátce). Nejvýš celé okno.
     *
     * Jediný vzorec pro schválení v aplikaci ({@see sicknessChain()}) i pro převod
     * z jiného programu ({@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter}).
     *
     * @param string $chainWindowFrom první den okna řetězu (den vzniku, po odpracovaném
     *                                prvním dni den následující)
     */
    public static function continuedWindowDays(
        string $chainWindowFrom,
        int $chainCarriedDays,
        string $partFrom,
        int $windowCalendarDays,
    ): int {
        $elapsed = (int) (new \DateTimeImmutable($chainWindowFrom))
            ->diff(new \DateTimeImmutable($partFrom))
            ->format('%r%a');

        return min($windowCalendarDays, max(0, $chainCarriedDays) + max(0, $elapsed));
    }

    /**
     * Neschopnost zapsaná po částech jako jedna sociální událost (DPN-01).
     *
     * Vrací:
     * - `pending_predecessor`: navazující předchozí část téhož druhu, o které se ještě
     *   nerozhodlo — dokud není schválená, nedá se říct, kolik okna vyčerpala;
     * - `chain_start`: první schválená část řetězu (celý řádek), `null` u samostatné
     *   neschopnosti;
     * - `chain_start_event`: příznaky jejího výpočtu náhrady (`first_day_fully_worked`,
     *   `insurance_eligibility_confirmed`), `null` bez výpočtu;
     * - `carried_days`: dny okna vyčerpané před touto částí; u části řetězu podle
     *   {@see continuedWindowDays()}, nikdy méně než to, co už má zapsané.
     *
     * Řetěz tvoří jen SCHVÁLENÉ předchůdce téhož vztahu a druhu, kteří končí den před
     * začátkem následující části — stejně jako případ NEMPRI ({@see contiguousChainStart}).
     *
     * @param array<string,mixed> $absence
     * @return array{
     *   pending_predecessor:?array{id:int,date_from:string,date_to:string},
     *   chain_start:?array<string,mixed>,
     *   chain_start_event:?array{first_day_fully_worked:bool,insurance_eligibility_confirmed:bool},
     *   carried_days:int
     * }
     */
    public function sicknessChain(int $supplierId, array $absence): array
    {
        $own = $this->carriedFor($absence);
        $result = [
            'pending_predecessor' => null,
            'chain_start' => null,
            'chain_start_event' => null,
            'carried_days' => $own,
        ];
        if (!self::isSickness($absence)) {
            return $result;
        }
        $previousTo = (new \DateTimeImmutable((string) $absence['date_from']))->modify('-1 day')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, date_from, date_to, status FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ? AND absence_type = ?
                AND date_to = ? AND id <> ? AND status IN ('requested', 'approved')
              ORDER BY status = 'approved' DESC, id LIMIT 1"
        );
        $stmt->execute([
            $supplierId,
            (int) $absence['employment_id'],
            (string) $absence['absence_type'],
            $previousTo,
            (int) $absence['id'],
        ]);
        $previous = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($previous)) {
            return $result;
        }
        if ($previous['status'] !== 'approved') {
            $result['pending_predecessor'] = [
                'id' => (int) $previous['id'],
                'date_from' => (string) $previous['date_from'],
                'date_to' => (string) $previous['date_to'],
            ];

            return $result;
        }

        $start = $this->contiguousChainStart($supplierId, (int) $absence['id']);
        $startRow = $start === null ? null : $this->find($supplierId, $start['id']);
        if ($startRow === null || (int) $startRow['id'] === (int) $absence['id']) {
            return $result;
        }
        $event = $this->db->pdo()->prepare(
            'SELECT first_day_fully_worked, insurance_eligibility_confirmed
               FROM payroll_sickness_events WHERE supplier_id = ? AND absence_id = ?
              ORDER BY id DESC LIMIT 1'
        );
        $event->execute([$supplierId, (int) $startRow['id']]);
        $flags = $event->fetch(PDO::FETCH_ASSOC);
        $startEvent = is_array($flags) ? [
            'first_day_fully_worked' => (int) $flags['first_day_fully_worked'] === 1,
            'insurance_eligibility_confirmed' => (int) $flags['insurance_eligibility_confirmed'] === 1,
        ] : null;

        $windowFrom = new \DateTimeImmutable((string) $startRow['date_from']);
        if ($startEvent !== null && $startEvent['first_day_fully_worked']) {
            $windowFrom = $windowFrom->modify('+1 day');
        }
        $carried = self::continuedWindowDays(
            $windowFrom->format('Y-m-d'),
            self::carriedWindowDays($startRow),
            (string) $absence['date_from'],
            // Začátek řetězu smí ležet před prvním rulesetem, je-li to převzatá
            // historická nepřítomnost ({@see AbsenceRuleset::forSicknessWindow()}).
            AbsenceRuleset::forSicknessWindow($this->rulesets, (string) $startRow['date_from'])->sicknessWindowCalendarDays(),
        );

        return [
            'pending_predecessor' => null,
            'chain_start' => $startRow,
            'chain_start_event' => $startEvent,
            'carried_days' => max($own, $carried),
        ];
    }

    /**
     * Schválená část téže neschopnosti, která na tuto navazuje (začíná den po jejím konci).
     *
     * @param array<string,mixed> $absence
     * @return array{id:int,date_from:string,date_to:string}|null
     */
    public function approvedSicknessSuccessor(int $supplierId, array $absence): ?array
    {
        if (!self::isSickness($absence)) {
            return null;
        }
        $nextFrom = (new \DateTimeImmutable((string) $absence['date_to']))->modify('+1 day')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, date_from, date_to FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ? AND absence_type = ?
                AND date_from = ? AND id <> ? AND status = 'approved'
              ORDER BY id LIMIT 1"
        );
        $stmt->execute([
            $supplierId,
            (int) $absence['employment_id'],
            (string) $absence['absence_type'],
            $nextFrom,
            (int) $absence['id'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'date_from' => (string) $row['date_from'],
            'date_to' => (string) $row['date_to'],
        ] : null;
    }

    /**
     * Dny okna § 192 ZP vyčerpané předchozím plátcem. Jediná čtecí cesta k tomu
     * sloupci — sahají na ni {@see publishedShiftSegments},
     * {@see publishedShiftSegmentsBeyondSicknessWindow} i
     * {@see PayrollSicknessRepository::record()}, aby se uložené okno a spočítané
     * segmenty nerozešly.
     *
     * @param array<string,mixed> $absence
     */
    public static function carriedWindowDays(array $absence): int
    {
        return max(0, (int) ($absence['sickness_window_carried_days'] ?? 0));
    }

    /**
     * Zápis dnů okna náhrady mzdy vyčerpaných před začátkem nepřítomnosti.
     *
     * Odmítne se po výpočtu náhrady: `payroll_sickness_events` je neměnný důkaz
     * výpočtu a jeho okno by se změnou rozešlo s tím, ze kterého vznikl mzdový
     * vstup. Opravuje se stornem výpočtu, ne přepsáním vstupu.
     *
     * `$expectedVersion` je `null` jen pro interní volání z převodu z PAMICA
     * ({@see \MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollSicknessWriter}),
     * kde absence vzniká ve stejném běhu a souběh nehrozí. Zápis z API
     * ({@see \MyInvoice\Action\Payroll\PayrollAbsenceAction::sicknessWindowCarried()})
     * ho vyžaduje vždy.
     *
     * @return array<string,mixed>
     */
    public function setSicknessWindowCarriedDays(
        int $supplierId,
        int $id,
        int $days,
        ?int $expectedVersion = null,
    ): array {
        if ($days < 0) {
            throw new \InvalidArgumentException('Vyčerpaných dnů okna náhrady nemůže být záporný počet.');
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $absence = $this->find($supplierId, $id)
                ?? throw new \InvalidArgumentException('Nepřítomnost nebyla nalezena.');
            if (!self::isSickness($absence)) {
                throw new \DomainException(
                    'Okno náhrady mzdy podle § 192 ZP má jen dočasná pracovní neschopnost a karanténa.'
                );
            }
            $this->yearClose->assertOpenForDateRange(
                $supplierId,
                (string) $absence['date_from'],
                (string) $absence['date_to'],
            );
            $computed = $pdo->prepare(
                'SELECT COUNT(*) FROM payroll_sickness_events WHERE supplier_id = ? AND absence_id = ?'
            );
            $computed->execute([$supplierId, $id]);
            if ((int) $computed->fetchColumn() > 0) {
                throw new \DomainException(
                    'Náhrada mzdy k téhle neschopnosti je už spočítaná; vyčerpané dny okna '
                    . 'nastavte až po jejím stornu.'
                );
            }
            $sql = 'UPDATE payroll_absences
                       SET sickness_window_carried_days = ?, row_version = row_version + 1
                     WHERE supplier_id = ? AND id = ?';
            $params = [$days, $supplierId, $id];
            if ($expectedVersion !== null) {
                $sql .= ' AND row_version = ?';
                $params[] = $expectedVersion;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($expectedVersion !== null && $stmt->rowCount() !== 1) {
                $current = $pdo->prepare(
                    'SELECT row_version FROM payroll_absences WHERE supplier_id = ? AND id = ?'
                );
                $current->execute([$supplierId, $id]);
                $currentVersion = $current->fetchColumn();
                throw new PayrollAbsenceConflictException(
                    $currentVersion !== false ? (int) $currentVersion : $expectedVersion,
                );
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Nepřítomnost nebyla po zápisu nalezena.');
    }

    /**
     * @param array<string,mixed> $absence
     * @return list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}>
     */
    private function segmentsBetween(
        array $absence,
        \DateTimeImmutable $windowFrom,
        \DateTimeImmutable $windowTo,
        AbsenceHolidayTreatment $holidayTreatment,
    ): array {
        $utc = new \DateTimeZone('UTC');
        $queryFrom = $windowFrom->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
        $queryTo = $windowTo->modify('+1 day')->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, starts_at_utc, ends_at_utc, timezone_name, break_minutes
               FROM payroll_shifts
              WHERE supplier_id = ? AND employment_id = ? AND status = 'published'
                AND starts_at_utc < ? AND ends_at_utc > ?
              ORDER BY starts_at_utc, id"
        );
        $stmt->execute([
            $absence['supplier_id'],
            $absence['employment_id'],
            $queryTo,
            $queryFrom,
        ]);

        $segments = [];
        $remainingByDate = [];
        if ($absence['partial_first_minutes'] !== null) {
            $remainingByDate[(string) $absence['date_from']] = (int) $absence['partial_first_minutes'];
        }
        if ($absence['partial_last_minutes'] !== null) {
            $lastDate = (string) $absence['date_to'];
            $lastLimit = (int) $absence['partial_last_minutes'];
            $remainingByDate[$lastDate] = isset($remainingByDate[$lastDate])
                ? min($remainingByDate[$lastDate], $lastLimit)
                : $lastLimit;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $shiftTimezone = new \DateTimeZone((string) $row['timezone_name']);
            $start = new \DateTimeImmutable((string) $row['starts_at_utc'], $utc);
            $end = new \DateTimeImmutable((string) $row['ends_at_utc'], $utc);
            $localDate = $start->setTimezone($shiftTimezone)->format('Y-m-d');
            if ($localDate < $windowFrom->format('Y-m-d') || $localDate > $windowTo->format('Y-m-d')) {
                continue;
            }
            $minutes = intdiv($end->getTimestamp() - $start->getTimestamp(), 60)
                - (int) $row['break_minutes'];
            if ($minutes <= 0) {
                continue;
            }
            $eligible = $minutes;
            if (array_key_exists($localDate, $remainingByDate)) {
                $eligible = min($eligible, $remainingByDate[$localDate]);
                $remainingByDate[$localDate] -= $eligible;
            }
            if ($eligible <= 0) {
                continue;
            }
            $segments[] = [
                'shift_id' => (int) $row['id'],
                'local_date' => $localDate,
                'planned_minutes' => $minutes,
                'eligible_minutes' => $eligible,
            ];
        }

        if ($holidayTreatment === AbsenceHolidayTreatment::Ignore) {
            return $segments;
        }

        $holidays = PayrollWorkCalendarSchedule::holidaysBetween(
            $this->holidayCalendar,
            $windowFrom->format('Y-m-d'),
            $windowTo->format('Y-m-d'),
        );
        if ($holidays === []) {
            return $segments;
        }
        if ($holidayTreatment === AbsenceHolidayTreatment::ExcludeFromLeave) {
            return AbsenceHolidaySegments::excludeFromLeave($segments, $holidays);
        }

        return AbsenceHolidaySegments::compensateSickness(
            $segments,
            (new PayrollWorkCalendarSchedule($this->db))->plannedMinutes(
                (int) $absence['supplier_id'],
                (int) $absence['employment_id'],
                array_keys($holidays),
            ),
            $remainingByDate,
        );
    }

    private function assertApprovedAverage(
        int $supplierId,
        int $employmentId,
        int $snapshotId,
        string $applicationDate,
    ): void
    {
        $date = new \DateTimeImmutable($applicationDate);
        $year = (int) $date->format('Y');
        $quarter = intdiv((int) $date->format('n') - 1, 3) + 1;
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND employment_id = ? AND id = ?
                AND applicable_year = ? AND applicable_quarter = ?
                AND status = 'approved'"
        );
        $stmt->execute([$supplierId, $employmentId, $snapshotId, $year, $quarter]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException(
                'Náhrada vyžaduje schválený snapshot průměru stejného vztahu a čtvrtletí.'
            );
        }
    }

    /**
     * Překryv nepřítomností s KONKRÉTNÍM viníkem.
     *
     * Hláška „ve zvoleném období už existuje překrývající se absence“ účetní
     * neřekla která — musela seznam projít očima a u pěti set lidí to je
     * hledání jehly. Kolizní záznam se proto pojmenuje včetně dat a stavu,
     * protože cesta ven (zrušit ten druhý, nebo zúžit rozsah) se odvíjí
     * právě od nich.
     */
    private function assertNoOverlap(
        int $supplierId,
        int $employmentId,
        string $dateFrom,
        string $dateTo,
        ?int $excludeId,
    ): void {
        $sql = "SELECT id, absence_type, date_from, date_to, status
                   FROM payroll_absences
                  WHERE supplier_id = ? AND employment_id = ?
                    AND status IN ('requested','approved')
                    AND date_from <= ? AND date_to >= ?";
        $params = [$supplierId, $employmentId, $dateTo, $dateFrom];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY date_from LIMIT 1 FOR UPDATE');
        $stmt->execute($params);
        $conflict = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($conflict)) {
            return;
        }

        throw new PayrollAbsenceOverlapException(
            (int) $conflict['id'],
            (string) $conflict['absence_type'],
            (string) $conflict['date_from'],
            (string) $conflict['date_to'],
            (string) $conflict['status'],
        );
    }

    private function lockEmployment(int $supplierId, int $employmentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }
    }

    private function throwConflictOrInvalid(int $supplierId, int $id, int $expectedVersion): never
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_version, status FROM payroll_absences WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \InvalidArgumentException('Absence nebyla nalezena.');
        }
        if ((int) $row['row_version'] !== $expectedVersion) {
            throw new PayrollAbsenceConflictException((int) $row['row_version']);
        }
        // Stav řekni jménem a rovnou i to, co s ním jde dělat — „v tomto stavu
        // to nelze“ účetní nechávalo hádat, jestli je slepá ulička, nebo ne.
        throw new \InvalidArgumentException(match ((string) $row['status']) {
            'cancelled' => 'Tahle nepřítomnost je zrušená. Zrušení se nevrací zpět — '
                . 'zapište nepřítomnost znovu, období už není blokované.',
            'approved' => 'Tahle nepřítomnost je už schválená. Chcete-li ji změnit, '
                . 'nejdřív ji zrušte (čerpání dovolené i mzdový vstup se vrátí) '
                . 'a zapište ji znovu.',
            default => 'Nepřítomnost je ve stavu „' . (string) $row['status']
                . '“ a tenhle krok z něj nevede.',
        });
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function cast(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'employment_id', 'partial_first_minutes',
            'partial_last_minutes', 'compensation_rate_basis_points',
            'average_snapshot_id', 'average_hourly_minor', 'average_year',
            'average_quarter', 'row_version', 'childbirth_recorded_by',
            'sickness_window_carried_days',
        ] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        $row['correction_pending'] = (bool) $row['correction_pending'];
        if (array_key_exists('lone_carer', $row)) {
            $row['lone_carer'] = (bool) $row['lone_carer'];
        }
        return $row;
    }
}
