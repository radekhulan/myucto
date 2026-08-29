<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\LeaveEntitlementResult;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollLeaveRepository
{
    /**
     * Nárok na daný rok ještě nebyl určený, takže se čerpání neporovnalo s ničím.
     * Není to chyba — položka se zapíše a zůstává v `manual_review` — ale účetní
     * má vědět, že zůstatek pod ní zatím nic neznamená.
     */
    public const WARNING_ENTITLEMENT_NOT_DETERMINED = 'leave_entitlement_not_determined';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollLeaveLedgerDeletionRepository $ledgerDeletion,
        private readonly PayrollLeaveEntitlementDeletionRepository $entitlementDeletion,
        private readonly PayrollAbsenceRepository $absences,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, int $employmentId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ledger.*, employee.full_name, employment.code AS employment_code
               FROM payroll_leave_ledger ledger
               JOIN payroll_employments employment
                 ON employment.supplier_id = ledger.supplier_id
                AND employment.id = ledger.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE ledger.supplier_id = ? AND ledger.employment_id = ? AND ledger.leave_year = ?
              ORDER BY ledger.effective_date, ledger.id'
        );
        $stmt->execute([$supplierId, $employmentId, $year]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = self::cast($row);
        }

        return $this->ledgerDeletion->decorate($supplierId, $rows);
    }

    /**
     * Revize nároku na dovolenou za rok. Bez tohohle výpisu by uživatel neměl
     * kde smazaný nárok najít — dosud se snapshot vracel jen v odpovědi na jeho
     * vytvoření.
     *
     * @return list<array<string,mixed>>
     */
    public function entitlements(int $supplierId, int $employmentId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, employment_id, leave_year, revision_no,
                    relation_type, weekly_minutes, entitlement_weeks,
                    continuous_calendar_days, worked_equivalent_minutes,
                    worked_week_multiples, entitlement_minutes, rationale,
                    support_status, leave_ledger_entry_id, row_version,
                    created_by, created_at
               FROM payroll_leave_entitlement_snapshots
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?
              ORDER BY revision_no DESC'
        );
        $stmt->execute([$supplierId, $employmentId, $year]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ([
                'id', 'supplier_id', 'employment_id', 'leave_year', 'revision_no',
                'weekly_minutes', 'entitlement_weeks', 'continuous_calendar_days',
                'worked_equivalent_minutes', 'worked_week_multiples',
                'entitlement_minutes', 'row_version',
            ] as $key) {
                $row[$key] = (int) $row[$key];
            }
            foreach (['leave_ledger_entry_id', 'created_by'] as $key) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
            $rows[] = $row;
        }

        return $this->entitlementDeletion->decorate($supplierId, $rows);
    }

    public function balance(int $supplierId, int $employmentId, int $year, ?string $asOf = null): int
    {
        $sql = 'SELECT COALESCE(SUM(minutes_delta), 0)
                  FROM payroll_leave_ledger
                 WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?';
        $params = [$supplierId, $employmentId, $year];
        if ($asOf !== null) {
            $sql .= ' AND effective_date <= ?';
            $params[] = $asOf;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function employmentRelationType(int $supplierId, int $employmentId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT relation_type
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $relationType = $stmt->fetchColumn();
        if (!is_string($relationType) || $relationType === '') {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }
        return $relationType;
    }

    /** @return array<string,mixed> */
    public function appendManual(
        int $supplierId,
        int $employmentId,
        int $year,
        string $effectiveDate,
        string $entryType,
        int $minutesDelta,
        string $reason,
        ?int $userId,
        ?int $sourceAbsenceId = null,
    ): array {
        if (!in_array($entryType, ['carryover', 'adjustment', 'shortening', 'overdrawn', 'payout'], true)) {
            throw new \InvalidArgumentException('Typ ruční položky dovolené není platný.');
        }
        if ($entryType === 'carryover' && $minutesDelta < 0) {
            throw new \InvalidArgumentException('Převod dovolené musí být kladný.');
        }
        if (in_array($entryType, ['shortening', 'overdrawn', 'payout'], true) && $minutesDelta > 0) {
            throw new \InvalidArgumentException('Krácení, přečerpání a proplacení musí snižovat zůstatek.');
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($entryType === 'shortening') {
                $this->assertShorteningAllowed($supplierId, $employmentId, $year, -$minutesDelta);
            }
            $entry = $this->append(
                $supplierId,
                $employmentId,
                $year,
                $effectiveDate,
                $entryType,
                $minutesDelta,
                $sourceAbsenceId,
                null,
                $reason,
                $userId,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $entry;
    }

    /**
     * Pojistky § 223 ZP pro krácení dovolené.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Odstavec 1 — DŮVOD a ROZSAH krácení
     * ─────────────────────────────────────────────────────────────────────────
     * „Zaměstnavatel může dovolenou krátit jen za neomluveně zameškanou směnu,
     * a to o počet neomluveně zameškaných hodin; neomluvená zameškání kratších
     * částí jednotlivých směn lze sčítat."
     *
     * Krácení je tedy shora omezené počtem hodin, které zaměstnanec neomluveně
     * zameškal — ne úvahou účetní. Modul to donedávna vynutit nemohl, protože
     * `payroll_absences.absence_type` hodnotu pro neomluvenou nepřítomnost
     * neměl; přidala ji migrace 1636. Rozsah se počítá z PUBLIKOVANÝCH SMĚN
     * schválených absencí typu `unexcused` — tedy z těch hodin, které měl
     * zaměstnanec podle rozvrhu odpracovat a neodpracoval. Částečné zameškání
     * nese absence přes `partial_first_minutes` / `partial_last_minutes`
     * a sčítá se, přesně jak žádá věta za středníkem.
     *
     * Není-li neomluvená absence evidovaná vůbec, krátit nelze. Je to
     * fail-closed záměrně: § 348 odst. 3 svěřuje určení, co je neomluvené
     * zameškání, zaměstnavateli po projednání s odborovou organizací — je to
     * právní úkon, který má být zapsaný, ne dopočítaný ze záporného čísla
     * v knize dovolené.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Odstavec 2 — jen z důvodu vzniklého v TOMTÉŽ roce
     * ─────────────────────────────────────────────────────────────────────────
     * „Dovolená, na kterou vzniklo právo v příslušném kalendářním roce, se krátí
     * pouze z důvodu podle odstavce 1, který vznikl v tomto roce." Do rozsahu se
     * proto berou jen neomluvené absence ležící v roce nároku.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Odstavec 3 — minimum dvou týdnů
     * ─────────────────────────────────────────────────────────────────────────
     * „Při krácení dovolené musí být zaměstnanci, jehož pracovní poměr k témuž
     * zaměstnavateli trval po celý kalendářní rok, poskytnuta dovolená alespoň
     * v délce 2 týdnů."
     *
     * Základem je stanovený nárok na daný rok, ne aktuální zůstatek: zákon mluví
     * o dovolené, která má být poskytnuta, a už vyčerpané dny na tom nic nemění.
     * Bez stanoveného nároku se nekrátí vůbec — krátit nestanovenou výměru
     * nejde spočítat, a mlčky povolit to znamená připustit libovolné číslo.
     */
    private function assertShorteningAllowed(
        int $supplierId,
        int $employmentId,
        int $year,
        int $shortenedMinutes,
    ): void {
        if ($shortenedMinutes <= 0) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                 COALESCE(SUM(CASE WHEN entry_type = 'entitlement' THEN minutes_delta ELSE 0 END), 0)
                   AS entitlement_minutes,
                 COALESCE(SUM(CASE WHEN entry_type = 'shortening' THEN -minutes_delta ELSE 0 END), 0)
                   AS shortened_minutes
               FROM payroll_leave_ledger
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?"
        );
        $stmt->execute([$supplierId, $employmentId, $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $entitlement = (int) ($row['entitlement_minutes'] ?? 0);
        $alreadyShortened = (int) ($row['shortened_minutes'] ?? 0);
        if ($entitlement <= 0) {
            throw new \InvalidArgumentException(
                'Krátit dovolenou lze až po stanovení nároku na daný rok.'
            );
        }
        $remaining = $entitlement - $alreadyShortened - $shortenedMinutes;
        if ($remaining < 0) {
            throw new \InvalidArgumentException(
                'Krácení nesmí přesáhnout stanovený nárok na dovolenou.'
            );
        }
        // § 223 odst. 1 a 2: krátit lze jen o neomluveně zameškané hodiny
        // vzniklé v roce, za který se dovolená poskytuje.
        $unexcusedMinutes = $this->unexcusedMinutes($supplierId, $employmentId, $year);
        if ($alreadyShortened + $shortenedMinutes > $unexcusedMinutes) {
            throw new \InvalidArgumentException(sprintf(
                'Dovolenou lze krátit jen o neomluveně zameškané hodiny (§ 223 odst. 1 ZP).'
                . ' Za rok %d je evidováno %d minut neomluvené nepřítomnosti,'
                . ' krácení by dosáhlo %d minut.',
                $year,
                $unexcusedMinutes,
                $alreadyShortened + $shortenedMinutes,
            ));
        }
        if (!$this->employmentCoveredWholeYear($supplierId, $employmentId, $year)) {
            return;
        }
        $weeklyMinutes = $this->weeklyMinutesOn($supplierId, $employmentId, sprintf('%04d-12-31', $year));
        if ($weeklyMinutes === null) {
            throw new \InvalidArgumentException(
                'Krácení dovolené vyžaduje evidovanou stanovenou týdenní pracovní dobu'
                . ' — bez ní nelze ověřit zákonné minimum dvou týdnů.'
            );
        }
        $floor = 2 * $weeklyMinutes;
        if ($remaining < $floor) {
            throw new \InvalidArgumentException(sprintf(
                'Po krácení musí zaměstnanci zůstat aspoň 2 týdny dovolené (%d minut),'
                . ' zbylo by %d minut.',
                $floor,
                $remaining,
            ));
        }
    }

    /**
     * Neomluveně zameškané minuty za rok nároku (§ 223 odst. 1 a 2 ZP).
     *
     * Počítají se jen SCHVÁLENÉ absence typu `unexcused` — neschválená absence
     * je návrh, ne zaměstnavatelovo určení podle § 348 odst. 3 — a jen ta část,
     * která leží v roce nároku. Rozsah dává rozvrh: `publishedShiftSegments`
     * vrací minuty publikovaných směn, které do absence spadají, včetně
     * omezení částečně zameškaných směn (`partial_*_minutes`). Bez publikované
     * směny je zameškaných hodin nula — nelze zameškat směnu, která nebyla
     * rozvržená.
     */
    private function unexcusedMinutes(int $supplierId, int $employmentId, int $year): int
    {
        $yearFrom = sprintf('%04d-01-01', $year);
        $yearTo = sprintf('%04d-12-31', $year);
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ? AND status = 'approved'
                AND absence_type = 'unexcused'
                AND date_from <= ? AND date_to >= ?
              ORDER BY date_from, id"
        );
        $stmt->execute([$supplierId, $employmentId, $yearTo, $yearFrom]);

        $minutes = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $absence) {
            foreach ($this->absences->publishedShiftSegments($absence, false) as $segment) {
                // Absence smí přesáhnout přes Silvestra; do krácení za tenhle
                // rok patří jen směny ležící v něm (§ 223 odst. 2).
                $localDate = (string) $segment['local_date'];
                if ($localDate < $yearFrom || $localDate > $yearTo) {
                    continue;
                }
                $minutes += (int) $segment['eligible_minutes'];
            }
        }

        return $minutes;
    }

    private function employmentCoveredWholeYear(int $supplierId, int $employmentId, int $year): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(actual_start_date, start_date) AS started, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }
        $started = (string) ($row['started'] ?? '');
        $ended = $row['end_date'] === null ? null : (string) $row['end_date'];

        return $started !== ''
            && $started <= sprintf('%04d-01-01', $year)
            && ($ended === null || $ended >= sprintf('%04d-12-31', $year));
    }

    private function weeklyMinutesOn(int $supplierId, int $employmentId, string $date): ?int
    {
        if (!$this->db->hasTable('payroll_employment_terms')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT weekly_hours
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $date, $date]);

        return PayrollAbsenceRepository::weeklyMinutesFromHours($stmt->fetchColumn());
    }

    /** @return array<string,mixed> */
    public function recordEntitlement(
        int $supplierId,
        int $employmentId,
        int $year,
        string $relationType,
        int $entitlementWeeks,
        int $continuousCalendarDays,
        int $workedEquivalentMinutes,
        string $rationale,
        LeaveEntitlementResult $result,
        ?int $userId,
        string $calculationMode = 'manual',
        ?string $sourceSnapshotHash = null,
    ): array {
        if (!in_array($calculationMode, ['manual', 'automatic'], true)) {
            throw new \InvalidArgumentException('Režim výpočtu nároku není platný.');
        }
        if ($calculationMode === 'automatic' && ($sourceSnapshotHash === null || strlen($sourceSnapshotHash) !== 32)) {
            throw new \InvalidArgumentException('Automatický výpočet vyžaduje platný otisk schválených podkladů.');
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->lockEmployment($supplierId, $employmentId);
            $revisionStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(revision_no), 0) + 1
                   FROM payroll_leave_entitlement_snapshots
                  WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?'
            );
            $revisionStmt->execute([$supplierId, $employmentId, $year]);
            $revision = (int) $revisionStmt->fetchColumn();
            $previous = $pdo->prepare(
                'SELECT snapshot.leave_ledger_entry_id, ledger.minutes_delta
                   FROM payroll_leave_entitlement_snapshots snapshot
                   JOIN payroll_leave_ledger ledger
                     ON ledger.supplier_id = snapshot.supplier_id
                    AND ledger.id = snapshot.leave_ledger_entry_id
                  WHERE snapshot.supplier_id = ? AND snapshot.employment_id = ?
                    AND snapshot.leave_year = ?
                  ORDER BY snapshot.revision_no DESC
                  LIMIT 1 FOR UPDATE'
            );
            $previous->execute([$supplierId, $employmentId, $year]);
            $previousRow = $previous->fetch(PDO::FETCH_ASSOC);
            if (is_array($previousRow)) {
                $this->append(
                    $supplierId,
                    $employmentId,
                    $year,
                    sprintf('%04d-01-01', $year),
                    'reversal',
                    -(int) $previousRow['minutes_delta'],
                    null,
                    (int) $previousRow['leave_ledger_entry_id'],
                    "Reverze nároku nahrazeného revizí {$revision}.",
                    $userId,
                );
            }
            $input = [
                'continuous_calendar_days' => $continuousCalendarDays,
                'entitlement_weeks' => $entitlementWeeks,
                'relation_type' => $relationType,
                'rationale' => $rationale,
                'worked_equivalent_minutes' => $workedEquivalentMinutes,
                'year' => $year,
                'calculation_mode' => $calculationMode,
                'source_snapshot_hash' => $sourceSnapshotHash === null ? null : bin2hex($sourceSnapshotHash),
            ];
            $inputHash = hash('sha256', CanonicalJson::encode($input), true);
            $entry = $this->append(
                $supplierId,
                $employmentId,
                $year,
                sprintf('%04d-01-01', $year),
                'entitlement',
                $result->entitlementMinutes,
                null,
                null,
                "Nárok dovolené – revize {$revision}: {$rationale}",
                $userId,
                $result->supportStatus,
            );
            $insert = $pdo->prepare(
                'INSERT INTO payroll_leave_entitlement_snapshots
                    (supplier_id, employment_id, leave_year, revision_no, relation_type,
                     weekly_minutes, entitlement_weeks, continuous_calendar_days,
                     worked_equivalent_minutes, worked_week_multiples, entitlement_minutes,
                     rationale, support_status, calculation_mode, source_snapshot_hash,
                     input_hash, calculation_trace,
                     leave_ledger_entry_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId, $employmentId, $year, $revision, $relationType,
                $result->weeklyMinutes, $entitlementWeeks, $continuousCalendarDays,
                $workedEquivalentMinutes, $result->workedWeekMultiples,
                $result->entitlementMinutes, $rationale, $result->supportStatus,
                $calculationMode, $sourceSnapshotHash, $inputHash,
                CanonicalJson::encode($result->trace), $entry['id'], $userId,
            ]);
            $snapshotId = (int) $pdo->lastInsertId();
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return [
            'snapshot_id' => $snapshotId,
            'ledger_entry' => $entry,
            'balance_minutes' => $this->balance($supplierId, $employmentId, $year),
            'support_status' => $result->supportStatus,
            'calculation_trace' => $result->trace,
        ];
    }

    /**
     * Zápis schváleného čerpání dovolené.
     *
     * Rozlišuje tři stavy, protože nulový zůstatek znamená pokaždé něco jiného:
     *
     *  1. nárok je určený a zůstatek stačí → projde,
     *  2. nárok je určený a zůstatek nestačí → skutečné přečerpání, viz
     *     {@see PayrollLeaveOverdrawException}; potvrzení je tu na místě, protože
     *     přeplatek by se jinak poznal až při vypořádání a strhl by se zaměstnanci,
     *  3. nárok pro daný rok a vztah určený není → zůstatek NENÍ nula, je neznámý,
     *     a ptát se na potvrzení by znamenalo tvrdit uživateli nepravdu. Čerpání
     *     projde a odpověď nese nezávazné upozornění.
     *
     * @param array<string,mixed> $absence
     * @param bool $overdrawConfirmed vědomé poskytnutí dovolené nad rámec zůstatku
     */
    public function recordTaken(
        array $absence,
        int $minutes,
        ?int $userId,
        bool $overdrawConfirmed = false,
    ): array {
        if ($minutes <= 0) {
            throw new \InvalidArgumentException('Čerpání dovolené vyžaduje publikované směny.');
        }
        $supplierId = (int) $absence['supplier_id'];
        $employmentId = (int) $absence['employment_id'];
        $year = (int) substr((string) $absence['date_from'], 0, 4);

        $entitlementDetermined = $this->hasDeterminedEntitlement($supplierId, $employmentId, $year);
        if ($entitlementDetermined && !$overdrawConfirmed) {
            // Zámek vztahu drží až append; zůstatek se tu čte jen jako brána,
            // takže souběžné schválení dvou dovolených může minus stejně vyrobit.
            // Proti tichému přečerpání to ale stačí a zamykat kvůli čtení dřív
            // by prodloužilo transakci schvalování o celý výpočet segmentů.
            $balance = $this->balance($supplierId, $employmentId, $year);
            if ($minutes > $balance) {
                throw new PayrollLeaveOverdrawException($balance, $minutes);
            }
        }

        $entry = $this->append(
            $supplierId,
            $employmentId,
            $year,
            (string) $absence['date_from'],
            'taken',
            -$minutes,
            (int) $absence['id'],
            null,
            'Schválené čerpání dovolené.',
            $userId,
        );
        $entry['warnings'] = $entitlementDetermined
            ? []
            : [self::WARNING_ENTITLEMENT_NOT_DETERMINED];

        return $entry;
    }

    /**
     * Existuje pro daný rok a vztah stanovený nárok?
     *
     * Rozhoduje výhradně položka `entitlement`. Převod z minulého roku ani ruční
     * úprava nárok nestanoví — jsou to pohyby nad výměrou, která pořád chybí, a
     * brát je jako doklad nároku by z neurčeného stavu udělalo tvrzený zůstatek.
     */
    private function hasDeterminedEntitlement(int $supplierId, int $employmentId, int $year): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1
               FROM payroll_leave_ledger
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?
                AND entry_type = 'entitlement'
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $employmentId, $year]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $absence */
    public function reverseTaken(array $absence, ?int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM payroll_leave_ledger
              WHERE supplier_id = ? AND source_absence_id = ? AND entry_type = 'taken'
              FOR UPDATE"
        );
        $stmt->execute([$absence['supplier_id'], $absence['id']]);
        $taken = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($taken)) {
            return null;
        }
        return $this->append(
            (int) $absence['supplier_id'],
            (int) $absence['employment_id'],
            (int) $taken['leave_year'],
            (string) $absence['date_from'],
            'reversal',
            -(int) $taken['minutes_delta'],
            (int) $absence['id'],
            (int) $taken['id'],
            'Reverze zrušeného čerpání dovolené.',
            $userId,
        );
    }

    /** @return array<string,mixed> */
    private function append(
        int $supplierId,
        int $employmentId,
        int $year,
        string $effectiveDate,
        string $entryType,
        int $minutesDelta,
        ?int $absenceId,
        ?int $reversalOfId,
        string $reason,
        ?int $userId,
        string $supportStatus = 'manual_review',
    ): array {
        if ($minutesDelta === 0 || trim($reason) === '') {
            throw new \InvalidArgumentException('Položka dovolené vyžaduje nenulové minuty a důvod.');
        }
        $this->lockEmployment($supplierId, $employmentId);
        $hash = hash('sha256', CanonicalJson::encode([
            'effective_date' => $effectiveDate,
            'employment_id' => $employmentId,
            'entry_type' => $entryType,
            'leave_year' => $year,
            'minutes_delta' => $minutesDelta,
            'reason' => trim($reason),
            'reversal_of_id' => $reversalOfId,
            'source_absence_id' => $absenceId,
            'supplier_id' => $supplierId,
            'support_status' => $supportStatus,
        ]), true);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_leave_ledger
                (supplier_id, employment_id, leave_year, effective_date, entry_type,
                 minutes_delta, source_absence_id, reversal_of_id, reason,
                 support_status, source_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId, $employmentId, $year, $effectiveDate, $entryType,
            $minutesDelta, $absenceId, $reversalOfId, trim($reason),
            $supportStatus, $hash, $userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $find = $this->db->pdo()->prepare('SELECT * FROM payroll_leave_ledger WHERE supplier_id = ? AND id = ?');
        $find->execute([$supplierId, $id]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::cast($row) : throw new \RuntimeException('Položka dovolené nebyla nalezena.');
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

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'employment_id', 'leave_year', 'minutes_delta',
            'source_absence_id', 'reversal_of_id'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        unset($row['source_hash']);
        return $row;
    }
}
