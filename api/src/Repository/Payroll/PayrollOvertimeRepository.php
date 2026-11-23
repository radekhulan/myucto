<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeCompensation;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeConsentWindow;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeEmploymentProfile;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeProtectionWindow;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeSegment;
use PDO;

/**
 * Podklady pro hlídání limitů § 93: evidovaný přesčas z docházky a evidované
 * souhlasy zaměstnanců.
 *
 * Zdrojem přesčasu je `payroll_time_entries` s kategorií `overtime` — tedy
 * přesně evidence, kterou zaměstnavateli ukládá vést § 96 odst. 1 písm. a)
 * bod 2 zákoníku práce. Peněžní stránka (`payroll_inputs` / `PREMIE_PRIPLATKY`)
 * se schválně nečte: limit je o odpracovaném čase, ne o vyplacené částce, a
 * kontrola tak nemůže nijak zasáhnout do výplaty.
 *
 * Revize: `status <> 'superseded'` stejně jako
 * {@see PayrollTimeRepository::entries()} — nahrazená revize záznamu by se
 * jinak počítala dvakrát a přepsaný přesčas by nafoukl roční součet.
 */
final class PayrollOvertimeRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Přesčas podle MÍSTNÍHO data začátku intervalu.
     *
     * Do SQL jde širší UTC okno (±2 dny), protože `starts_at_utc` a místní datum
     * se můžou lišit o hodiny; zúžení na požadovaný rozsah proběhne až v PHP nad
     * `timezone_name` řádku — tak to dělá i zbytek modulu docházky.
     *
     * @param list<int> $employmentIds
     * @return array<int,list<OvertimeSegment>>
     */
    public function segmentsForMany(
        int $supplierId,
        array $employmentIds,
        string $fromDate,
        string $toDate,
    ): array {
        return $this->segmentsAndManualMonthsForMany($supplierId, $employmentIds, $fromDate, $toDate)['segments'];
    }

    /**
     * Jediná dávka pro intervaly i výslovně nahrazující ruční měsíce.
     * Ruční součet nemá denní rozpad; staré intervaly daného místního měsíce
     * proto nelze použít ani pro týdenní, ani pro roční kontrolu. Schválená
     * výslovná nula ve všech segmentech rozpad nepotřebuje. Otevřený souhrn
     * zatím nevylučuje přesčas; vyžaduje potvrzení či ruční posouzení.
     *
     * @param list<int> $employmentIds
     * @return array{segments:array<int,list<OvertimeSegment>>,manual_months:array<int,list<string>>}
     */
    public function segmentsAndManualMonthsForMany(int $supplierId, array $employmentIds, string $fromDate, string $toDate): array
    {
        $result = array_fill_keys($employmentIds, []);
        if ($employmentIds === []) {
            return ['segments' => [], 'manual_months' => []];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment_id, starts_at_utc, ends_at_utc,
                    timezone_name, break_minutes, NULL AS manual_period, id, NULL AS manual_zero
               FROM payroll_time_entries FORCE INDEX (idx_payroll_time_entry_month)
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
                AND category = 'overtime'
                AND status <> 'superseded'
                AND starts_at_utc >= ?
                AND starts_at_utc < ?
              UNION ALL
             SELECT month_row.employment_id, NULL, NULL, NULL, NULL, month_row.period_start, month_row.id,
                    CASE WHEN month_row.status = 'approved' AND COUNT(segment.id) > 0
                              AND COUNT(segment.overtime_duration_units) = COUNT(segment.id)
                              AND MAX(segment.overtime_duration_units) = 0 THEN 1 ELSE 0 END
               FROM payroll_time_months month_row FORCE INDEX (uq_payroll_time_month_period)
               LEFT JOIN payroll_time_month_manual_summaries summary
                 ON summary.supplier_id = month_row.supplier_id AND summary.time_month_id = month_row.id
                AND summary.time_month_revision_no = month_row.revision_no
               LEFT JOIN payroll_time_month_manual_segments segment
                 ON segment.supplier_id = summary.supplier_id AND segment.summary_id = summary.id
              WHERE month_row.supplier_id = ? AND month_row.employment_id IN ({$placeholders})
                AND month_row.period_start >= ? AND month_row.period_start <= ? AND month_row.work_source = 'manual_summary'
              GROUP BY month_row.employment_id, month_row.period_start, month_row.id, month_row.status
              ORDER BY starts_at_utc, id"
        );
        $stmt->execute([
            $supplierId,
            ...$employmentIds,
            (new \DateTimeImmutable($fromDate . ' 00:00:00', new \DateTimeZone('UTC')))
                ->modify('-2 days')->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable($toDate . ' 00:00:00', new \DateTimeZone('UTC')))
                ->modify('+3 days')->format('Y-m-d H:i:s'),
            $supplierId, ...$employmentIds, substr($fromDate, 0, 7) . '-01', substr($toDate, 0, 7) . '-01',
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $manualMonths = [];
        $unknownMonths = [];
        foreach ($rows as $row) {
            if ($row['manual_period'] !== null) {
                $manualMonths[(int) $row['employment_id']][] = $row['manual_period'];
                if ((int) $row['manual_zero'] !== 1) {
                    $unknownMonths[(int) $row['employment_id']][] = $row['manual_period'];
                }
            }
        }
        foreach ($rows as $row) {
            if ($row['manual_period'] !== null) {
                continue;
            }
            $timezone = new \DateTimeZone((string) $row['timezone_name']);
            $start = new \DateTimeImmutable(
                (string) $row['starts_at_utc'],
                new \DateTimeZone('UTC'),
            );
            $end = new \DateTimeImmutable(
                (string) $row['ends_at_utc'],
                new \DateTimeZone('UTC'),
            );
            $date = $start->setTimezone($timezone)->format('Y-m-d');
            if ($date < $fromDate || $date > $toDate
                || in_array(substr($date, 0, 7) . '-01', $manualMonths[(int) $row['employment_id']] ?? [], true)) {
                continue;
            }
            $minutes = max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60)
                - (int) $row['break_minutes']);
            if ($minutes === 0) {
                continue;
            }
            $result[(int) $row['employment_id']][] = new OvertimeSegment($date, $minutes);
        }

        return ['segments' => $result, 'manual_months' => $unknownMonths];
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,list<OvertimeConsentWindow>>
     */
    public function consentsForMany(int $supplierId, array $employmentIds): array
    {
        $result = array_fill_keys($employmentIds, []);
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, employment_id, valid_from, valid_to
               FROM payroll_overtime_consents
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
              ORDER BY valid_from, id"
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']][] = new OvertimeConsentWindow(
                (string) $row['valid_from'],
                $row['valid_to'] === null ? null : (string) $row['valid_to'],
                (int) $row['id'],
            );
        }

        return $result;
    }

    /**
     * Souhlasy v podobě, ve které je čte obrazovka docházky — na rozdíl od
     * {@see self::consentsForMany()} nesou i doklad, poznámku a `row_version`.
     *
     * @param list<int> $employmentIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function consentRowsForMany(int $supplierId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $result = array_fill_keys($employmentIds, []);
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, employment_id, valid_from, valid_to,
                    document_reference, note, row_version, created_at
               FROM payroll_overtime_consents
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
              ORDER BY valid_from, id"
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['employment_id'] = (int) $row['employment_id'];
            $row['row_version'] = (int) $row['row_version'];
            $result[$row['employment_id']][] = $row;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function consents(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employment_id, valid_from, valid_to,
                    document_reference, note, row_version, created_at
               FROM payroll_overtime_consents
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY valid_from, id'
        );
        $stmt->execute([$supplierId, $employmentId]);

        return array_values(array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['employment_id'] = (int) $row['employment_id'];
                $row['row_version'] = (int) $row['row_version'];

                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return array<string,mixed> */
    public function saveConsent(
        int $supplierId,
        int $employmentId,
        ?int $consentId,
        string $validFrom,
        ?string $validTo,
        ?string $documentReference,
        ?string $note,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        if ($consentId === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_overtime_consents
                    (supplier_id, employment_id, valid_from, valid_to,
                     document_reference, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $employmentId,
                $validFrom,
                $validTo,
                $documentReference,
                $note,
                $userId,
            ]);
            $consentId = (int) $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare(
                'UPDATE payroll_overtime_consents
                    SET valid_from = ?, valid_to = ?, document_reference = ?,
                        note = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND employment_id = ?
                    AND row_version = ?'
            );
            $stmt->execute([
                $validFrom,
                $validTo,
                $documentReference,
                $note,
                $supplierId,
                $consentId,
                $employmentId,
                $expectedVersion,
            ]);
            if ($stmt->rowCount() === 0) {
                throw new \DomainException(
                    'Souhlas s prací přesčas mezitím změnil někdo jiný, načtěte ho znovu.',
                );
            }
        }

        $loaded = $pdo->prepare(
            'SELECT id, employment_id, valid_from, valid_to,
                    document_reference, note, row_version, created_at
               FROM payroll_overtime_consents
              WHERE supplier_id = ? AND id = ?'
        );
        $loaded->execute([$supplierId, $consentId]);
        $row = $loaded->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Uložený souhlas s prací přesčas se nepodařilo načíst.');
        }
        $row['id'] = (int) $row['id'];
        $row['employment_id'] = (int) $row['employment_id'];
        $row['row_version'] = (int) $row['row_version'];

        return $row;
    }

    /**
     * Vyrovnávací období podle § 93 odst. 4 platné k danému dni.
     *
     * Bez nastavení platí zákonných 26 týdnů — a to je i jediné, co se stane,
     * když firma nastavení nikdy nezaložila. Delší období vrací jen řádek,
     * který se opírá o kolektivní smlouvu; databázový CHECK jiný ani
     * nepřipustí.
     *
     * @return array{weeks:int,basis:string,reference:?string}|null
     */
    public function averagingPeriodFor(int $supplierId, string $date): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT weeks, basis, collective_agreement_reference
               FROM payroll_overtime_averaging_periods
              WHERE supplier_id = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $date, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'weeks' => (int) $row['weeks'],
            'basis' => (string) $row['basis'],
            'reference' => $row['collective_agreement_reference'] === null
                ? null
                : (string) $row['collective_agreement_reference'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function averagingPeriods(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, valid_from, valid_to, weeks, basis,
                    collective_agreement_reference, note, row_version, created_at
               FROM payroll_overtime_averaging_periods
              WHERE supplier_id = ?
              ORDER BY valid_from DESC, id DESC'
        );
        $stmt->execute([$supplierId]);

        return array_values(array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['weeks'] = (int) $row['weeks'];
                $row['row_version'] = (int) $row['row_version'];

                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return array<string,mixed> */
    public function saveAveragingPeriod(
        int $supplierId,
        ?int $id,
        string $validFrom,
        ?string $validTo,
        int $weeks,
        string $basis,
        ?string $reference,
        ?string $note,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        if ($id === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_overtime_averaging_periods
                    (supplier_id, valid_from, valid_to, weeks, basis,
                     collective_agreement_reference, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $validFrom,
                $validTo,
                $weeks,
                $basis,
                $reference,
                $note,
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare(
                'UPDATE payroll_overtime_averaging_periods
                    SET valid_from = ?, valid_to = ?, weeks = ?, basis = ?,
                        collective_agreement_reference = ?, note = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $stmt->execute([
                $validFrom,
                $validTo,
                $weeks,
                $basis,
                $reference,
                $note,
                $supplierId,
                $id,
                $expectedVersion,
            ]);
            if ($stmt->rowCount() === 0) {
                throw new \DomainException(
                    'Vyrovnávací období mezitím změnil někdo jiný, načtěte ho znovu.',
                );
            }
        }

        $loaded = $pdo->prepare(
            'SELECT id, valid_from, valid_to, weeks, basis,
                    collective_agreement_reference, note, row_version, created_at
               FROM payroll_overtime_averaging_periods
              WHERE supplier_id = ? AND id = ?'
        );
        $loaded->execute([$supplierId, $id]);
        $row = $loaded->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Uložené vyrovnávací období se nepodařilo načíst.');
        }
        $row['id'] = (int) $row['id'];
        $row['weeks'] = (int) $row['weeks'];
        $row['row_version'] = (int) $row['row_version'];

        return $row;
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,list<OvertimeProtectionWindow>>
     */
    public function protectionsForMany(int $supplierId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $result = array_fill_keys($employmentIds, []);
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, employment_id, protection, valid_from, valid_to
               FROM payroll_overtime_protections
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
              ORDER BY valid_from, id"
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']][] = new OvertimeProtectionWindow(
                (string) $row['protection'],
                (string) $row['valid_from'],
                $row['valid_to'] === null ? null : (string) $row['valid_to'],
                (int) $row['id'],
            );
        }

        return $result;
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function protectionRowsForMany(int $supplierId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $result = array_fill_keys($employmentIds, []);
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, employment_id, protection, valid_from, valid_to,
                    document_reference, note, row_version, created_at
               FROM payroll_overtime_protections
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
              ORDER BY valid_from, id"
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['employment_id'] = (int) $row['employment_id'];
            $row['row_version'] = (int) $row['row_version'];
            $result[$row['employment_id']][] = $row;
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function saveProtection(
        int $supplierId,
        int $employmentId,
        ?int $protectionId,
        string $protection,
        string $validFrom,
        ?string $validTo,
        ?string $documentReference,
        ?string $note,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        if ($protectionId === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_overtime_protections
                    (supplier_id, employment_id, protection, valid_from, valid_to,
                     document_reference, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $employmentId,
                $protection,
                $validFrom,
                $validTo,
                $documentReference,
                $note,
                $userId,
            ]);
            $protectionId = (int) $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare(
                'UPDATE payroll_overtime_protections
                    SET protection = ?, valid_from = ?, valid_to = ?,
                        document_reference = ?, note = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND employment_id = ?
                    AND row_version = ?'
            );
            $stmt->execute([
                $protection,
                $validFrom,
                $validTo,
                $documentReference,
                $note,
                $supplierId,
                $protectionId,
                $employmentId,
                $expectedVersion,
            ]);
            if ($stmt->rowCount() === 0) {
                throw new \DomainException(
                    'Ochranu před prací přesčas mezitím změnil někdo jiný, načtěte ji znovu.',
                );
            }
        }

        $loaded = $pdo->prepare(
            'SELECT id, employment_id, protection, valid_from, valid_to,
                    document_reference, note, row_version, created_at
               FROM payroll_overtime_protections
              WHERE supplier_id = ? AND id = ?'
        );
        $loaded->execute([$supplierId, $protectionId]);
        $row = $loaded->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Uloženou ochranu se nepodařilo načíst.');
        }
        $row['id'] = (int) $row['id'];
        $row['employment_id'] = (int) $row['employment_id'];
        $row['row_version'] = (int) $row['row_version'];

        return $row;
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,list<OvertimeCompensation>>
     */
    public function compensationsForMany(
        int $supplierId,
        array $employmentIds,
        string $fromDate,
        string $toDate,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $result = array_fill_keys($employmentIds, []);
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment_id, overtime_date, minutes
               FROM payroll_overtime_compensations
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
                AND overtime_date BETWEEN ? AND ?
              ORDER BY overtime_date, id"
        );
        $stmt->execute([$supplierId, ...$employmentIds, $fromDate, $toDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']][] = new OvertimeCompensation(
                (string) $row['overtime_date'],
                (int) $row['minutes'],
            );
        }

        return $result;
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function compensationRowsForMany(
        int $supplierId,
        array $employmentIds,
        string $fromDate,
        string $toDate,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $result = array_fill_keys($employmentIds, []);
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, employment_id, overtime_date, minutes, granted_on,
                    document_reference, note, row_version, created_at
               FROM payroll_overtime_compensations
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
                AND overtime_date BETWEEN ? AND ?
              ORDER BY overtime_date, id"
        );
        $stmt->execute([$supplierId, ...$employmentIds, $fromDate, $toDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['employment_id'] = (int) $row['employment_id'];
            $row['minutes'] = (int) $row['minutes'];
            $row['row_version'] = (int) $row['row_version'];
            $result[$row['employment_id']][] = $row;
        }

        return $result;
    }

    /**
     * Podklad pro porovnání dvou evidencí náhradního volna za jeden měsíc.
     *
     * Náhradní volno žije ve dvou tabulkách, protože každá odpovídá na jinou
     * otázku a je klíčovaná jiným dnem:
     *
     *  - `payroll_absences` (typ `compensatory_time_off`) je zdroj pravdy
     *    o tom, KDY zaměstnanec nepracoval — vstup do docházky a mzdy
     *    (§ 114 odst. 3: za dobu čerpání mzda nepřísluší);
     *  - `payroll_overtime_compensations` je zdroj pravdy o tom, KTERÝ PŘESČAS
     *    se tím vyrovnal — vstup do vyrovnávacího období (§ 93 odst. 4 a 5).
     *
     * Odvodit jedno z druhého nejde: absence nenese den přesčasu a jeden den
     * čerpání může vyrovnat přesčas z několika dnů. Rozpor mezi nimi ale nesmí
     * zůstat tichý — proto tenhle dotaz vrací OBĚ strany za měsíc naráz.
     *
     * `granted_on` je nepovinné; zápis bez něj do měsíce nepatří ani jedním
     * směrem a vrací se zvlášť, aby se z chybějícího údaje nestal tichý
     * předpoklad „vybráno v tomhle měsíci".
     *
     * @param list<int> $employmentIds
     * @return array<int,array{granted_minutes:int,granted_rows:int,ungranted_rows:int,absence_rows:int}>
     */
    public function compensatoryTimeOffReconciliationForMany(
        int $supplierId,
        array $employmentIds,
        string $monthFrom,
        string $monthLastDay,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $result = [];
        foreach ($employmentIds as $employmentId) {
            $result[$employmentId] = [
                'granted_minutes' => 0,
                'granted_rows' => 0,
                'ungranted_rows' => 0,
                'absence_rows' => 0,
            ];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));

        $compensations = $this->db->pdo()->prepare(
            "SELECT employment_id,
                    COALESCE(SUM(CASE WHEN granted_on BETWEEN ? AND ?
                                      THEN minutes ELSE 0 END), 0) AS granted_minutes,
                    SUM(CASE WHEN granted_on BETWEEN ? AND ?
                             THEN 1 ELSE 0 END) AS granted_rows,
                    SUM(CASE WHEN granted_on IS NULL THEN 1 ELSE 0 END) AS ungranted_rows
               FROM payroll_overtime_compensations
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
              GROUP BY employment_id"
        );
        $compensations->execute([
            $monthFrom,
            $monthLastDay,
            $monthFrom,
            $monthLastDay,
            $supplierId,
            ...$employmentIds,
        ]);
        foreach ($compensations->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employmentId = (int) $row['employment_id'];
            if (!isset($result[$employmentId])) {
                continue;
            }
            $result[$employmentId]['granted_minutes'] = (int) $row['granted_minutes'];
            $result[$employmentId]['granted_rows'] = (int) $row['granted_rows'];
            $result[$employmentId]['ungranted_rows'] = (int) $row['ungranted_rows'];
        }

        // Zamítnutá a zrušená absence není čerpání — do porovnání nepatří.
        $absences = $this->db->pdo()->prepare(
            "SELECT employment_id, COUNT(*) AS absence_rows
               FROM payroll_absences
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
                AND absence_type = 'compensatory_time_off'
                AND status IN ('requested', 'approved')
                AND date_from <= ?
                AND date_to >= ?
              GROUP BY employment_id"
        );
        $absences->execute([
            $supplierId,
            ...$employmentIds,
            $monthLastDay,
            $monthFrom,
        ]);
        foreach ($absences->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employmentId = (int) $row['employment_id'];
            if (!isset($result[$employmentId])) {
                continue;
            }
            $result[$employmentId]['absence_rows'] = (int) $row['absence_rows'];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function saveCompensation(
        int $supplierId,
        int $employmentId,
        ?int $compensationId,
        string $overtimeDate,
        int $minutes,
        ?string $grantedOn,
        ?string $documentReference,
        ?string $note,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        if ($compensationId === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_overtime_compensations
                    (supplier_id, employment_id, overtime_date, minutes, granted_on,
                     document_reference, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $employmentId,
                $overtimeDate,
                $minutes,
                $grantedOn,
                $documentReference,
                $note,
                $userId,
            ]);
            $compensationId = (int) $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare(
                'UPDATE payroll_overtime_compensations
                    SET overtime_date = ?, minutes = ?, granted_on = ?,
                        document_reference = ?, note = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND employment_id = ?
                    AND row_version = ?'
            );
            $stmt->execute([
                $overtimeDate,
                $minutes,
                $grantedOn,
                $documentReference,
                $note,
                $supplierId,
                $compensationId,
                $employmentId,
                $expectedVersion,
            ]);
            if ($stmt->rowCount() === 0) {
                throw new \DomainException(
                    'Náhradní volno mezitím změnil někdo jiný, načtěte ho znovu.',
                );
            }
        }

        $loaded = $pdo->prepare(
            'SELECT id, employment_id, overtime_date, minutes, granted_on,
                    document_reference, note, row_version, created_at
               FROM payroll_overtime_compensations
              WHERE supplier_id = ? AND id = ?'
        );
        $loaded->execute([$supplierId, $compensationId]);
        $row = $loaded->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Uložené náhradní volno se nepodařilo načíst.');
        }
        $row['id'] = (int) $row['id'];
        $row['employment_id'] = (int) $row['employment_id'];
        $row['minutes'] = (int) $row['minutes'];
        $row['row_version'] = (int) $row['row_version'];

        return $row;
    }

    /**
     * Datum narození a úseky sjednané pracovní doby — podklad pro § 245 odst. 1
     * a pro § 78 odst. 1 písm. i) větu druhou.
     *
     * Úvazek se čte z celé historie `payroll_employment_terms`, protože se
     * v průběhu vyrovnávacího období mění a zákaz nařizovat přesčas platí ke
     * dni výkonu práce, ne ke dni posouzení.
     *
     * @param list<int> $employmentIds
     * @return array<int,OvertimeEmploymentProfile>
     */
    public function profilesForMany(int $supplierId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment.id AS employment_id, employee.birth_date
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.id IN ({$placeholders})"
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        $birthDates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $birthDates[(int) $row['employment_id']] = $row['birth_date'] === null
                ? null
                : (string) $row['birth_date'];
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT employment_id, effective_from, effective_to, workload_basis_points
               FROM payroll_employment_terms
              WHERE supplier_id = ?
                AND employment_id IN ({$placeholders})
              ORDER BY employment_id, effective_from, id"
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        $workloads = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $workloads[(int) $row['employment_id']][] = [
                'from' => (string) $row['effective_from'],
                'to' => $row['effective_to'] === null ? null : (string) $row['effective_to'],
                'basis_points' => (int) $row['workload_basis_points'],
            ];
        }

        $result = [];
        foreach ($employmentIds as $employmentId) {
            $result[$employmentId] = new OvertimeEmploymentProfile(
                $birthDates[$employmentId] ?? null,
                $workloads[$employmentId] ?? [],
            );
        }

        return $result;
    }
}
