<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use DomainException;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

/**
 * Čtení podkladů pro zákonné příplatky § 114 až § 118 ZP.
 *
 * Vlastní repozitář, ne rozšíření {@see PayrollTimeRepository}: ten vrací
 * zápisy docházky ZA CELOU FIRMU v okně a volající si je sám seskupuje, protože
 * ho staví přehled měsíce. Výpočet příplatku se naopak ptá na JEDEN pracovní
 * vztah a načítat kvůli němu celou firmu by u větší firmy znamenalo desítky
 * tisíc řádků na jeden mzdový list.
 */
final class PayrollSurchargeRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $employmentIds @return array<int,list<array<string,mixed>>> */
    public function policiesForMany(int $supplierId, array $employmentIds, string $from, string $through, bool $lock = false): array
    {
        if ($employmentIds === []) return [];
        $ids = array_values(array_unique(array_map('intval', $employmentIds)));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT * FROM payroll_employment_surcharge_policies FORCE INDEX (idx_payroll_surcharge_policy_effective)
            WHERE supplier_id = ? AND employment_id IN ($in) AND valid_from <= ?
              AND (valid_to IS NULL OR valid_to >= ?)
            ORDER BY employment_id, valid_from, id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$ids, $through, $from]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int) $row['employment_id']][] = self::policyRow($row);
        return $result;
    }

    /** Druh vztahu s hodinovými podmínkami zasahujícími do měněné zásady. */
    public function hourlyPolicyRelation(int $supplierId, int $employmentId, string $from): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT employment.relation_type FROM payroll_employments employment
            WHERE employment.supplier_id = ? AND employment.id = ? AND EXISTS (
                SELECT 1 FROM payroll_employment_terms term FORCE INDEX (idx_payroll_employment_term_effective)
                WHERE term.supplier_id = employment.supplier_id AND term.employment_id = employment.id
                  AND term.remuneration_type = 'hourly' AND (term.effective_to IS NULL OR term.effective_to >= ?)
            )");
        $stmt->execute([$supplierId, $employmentId, $from]);
        $type = $stmt->fetchColumn();
        return is_string($type) ? $type : null;
    }

    /**
     * Zápisy docházky jednoho vztahu v UTC okně.
     *
     * Filtruje se `status <> 'superseded'` stejně jako v {@see PayrollTimeRepository::entries()}:
     * schvalování docházky je na úrovni MĚSÍCE (`payroll_time_months`), ne
     * jednotlivého zápisu, takže `draft` řádky jsou platná evidence, jen dosud
     * neuzavřená. Uzavřenost měsíce si hlídá volající.
     *
     * @return list<array<string,mixed>>
     */
    public function entries(
        int $supplierId,
        int $employmentId,
        string $startsAtUtc,
        string $endsAtUtc,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, category, starts_at_utc, ends_at_utc, timezone_name,
                    break_minutes, difficulty_factor_count, status
               FROM payroll_time_entries
              WHERE supplier_id = ?
                AND employment_id = ?
                AND status <> \'superseded\'
                AND starts_at_utc >= ?
                AND starts_at_utc < ?
              ORDER BY starts_at_utc, id'
        );
        $stmt->execute([$supplierId, $employmentId, $startsAtUtc, $endsAtUtc]);

        return PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'payroll_time_entries',
        );
    }

    /**
     * Náhradní volno za přesčas podle dne PŘESČASU (migrace 1492).
     *
     * @return list<array<string,mixed>>
     */
    public function overtimeCompensations(
        int $supplierId,
        int $employmentId,
        string $fromDate,
        string $toDate,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT overtime_date, minutes, granted_on
               FROM payroll_overtime_compensations
              WHERE supplier_id = ?
                AND employment_id = ?
                AND overtime_date BETWEEN ? AND ?
              ORDER BY overtime_date'
        );
        $stmt->execute([$supplierId, $employmentId, $fromDate, $toDate]);

        return PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'payroll_overtime_compensations',
        );
    }

    /**
     * Verze zásady účinná k danému dni, nebo `null`, není-li sjednána žádná.
     *
     * `null` NENÍ chyba a nesmí se překládat na nulu — výchozí zákonný režim
     * dosadí {@see \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargePolicy::statutoryDefault()}.
     *
     * @return array<string,mixed>|null
     */
    public function policy(int $supplierId, int $employmentId, string $effectiveOn): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_employment_surcharge_policies
              WHERE supplier_id = ?
                AND employment_id = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $effectiveOn, $effectiveOn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::policyRow($row);
    }

    /**
     * Firemní výchozí sazby příplatků účinné k danému dni (migrace 1846).
     *
     * `null` znamená, že firma k tomu dni nemá účinnou mzdovou politiku;
     * prázdné sloupce v ní znamenají, že výchozí sazbu nesjednala. Obojí vede
     * na zákonné minimum, ale je to jiný stav a volající je rozlišuje.
     *
     * Překryv politik se tu ZÁMĚRNĚ neřeší výjimkou jako v
     * {@see PayrollEmployerPolicyRepository::findEffective()}: výpočet
     * příplatku není místo, kde se má běh zastavit na nastavení. Bere se
     * poslední účinná a o překryvu už mluví nastavení zaměstnavatele.
     *
     * @return array<string,mixed>|null
     */
    public function employerSurchargeDefaults(int $supplierId, string $effectiveOn): ?array
    {
        if (!$this->db->hasTable('payroll_employer_policies')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT overtime_rate_bp, holiday_rate_bp, night_rate_bp,
                    weekend_rate_bp, difficult_environment_rate_bp,
                    overtime_fixed_hourly_minor, holiday_fixed_hourly_minor,
                    night_fixed_hourly_minor, weekend_fixed_hourly_minor,
                    difficult_environment_fixed_hourly_minor
               FROM payroll_employer_policies
              WHERE supplier_id = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $effectiveOn, $effectiveOn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : PayrollTimeValue::row($row, 'payroll_employer_policy_surcharges');
    }

    public function employmentExists(int $supplierId, int $employmentId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Všechny verze zásady jednoho vztahu, od nejnovější.
     *
     * Historie se ukazuje celá, ne jen účinná verze: mzda spočítaná podle staré
     * zásady na ni dál ukazuje, takže „proč vyšel příplatek zrovna takhle" se
     * bez ní nedá zodpovědět.
     *
     * @return list<array<string,mixed>>
     */
    public function policiesForEmployment(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_employment_surcharge_policies
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY valid_from DESC, id DESC'
        );
        $stmt->execute([$supplierId, $employmentId]);

        return array_map(self::policyRow(...), PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'payroll_employment_surcharge_policies',
        ));
    }

    /**
     * Založí novou verzi zásady a předchozí OTEVŘENÉ verzi dopočítá `valid_to`
     * na den před účinností nové.
     *
     * Historie se nepřepisuje: mzda spočítaná podle staré zásady na ni dál
     * ukazuje. Stejný vzor jako u verzí mzdových složek
     * ({@see PayrollComponentRepository::ensureDefaults()}).
     *
     * @param array<string,mixed> $data
     */
    public function savePolicy(
        int $supplierId,
        int $employmentId,
        string $validFrom,
        array $data,
        ?int $userId,
        ?callable $beforeWrite = null,
    ): int {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($beforeWrite !== null) {
                $lock = $pdo->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ? FOR UPDATE');
                $lock->execute([$supplierId, $employmentId]);
                if ($lock->fetchColumn() === false) throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
                $beforeWrite();
            }
            $close = $pdo->prepare(
                'UPDATE payroll_employment_surcharge_policies
                    SET valid_to = DATE_SUB(?, INTERVAL 1 DAY),
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND employment_id = ?
                    AND valid_from < ? AND valid_to IS NULL'
            );
            $close->execute([$validFrom, $supplierId, $employmentId, $validFrom]);

            $exists = $pdo->prepare(
                'SELECT 1 FROM payroll_employment_surcharge_policies
                  WHERE supplier_id = ? AND employment_id = ? AND valid_from >= ?
                  LIMIT 1'
            );
            $exists->execute([$supplierId, $employmentId, $validFrom]);
            if ($exists->fetchColumn() !== false) {
                throw new DomainException(
                    'Pro tento pracovní vztah už existuje zásada příplatků platná od téhož '
                    . 'nebo pozdějšího dne. Upravte ji místo zakládání nové.',
                );
            }

            $stmt = $pdo->prepare(
                'INSERT INTO payroll_employment_surcharge_policies
                    (supplier_id, employment_id, valid_from, overtime_mode, holiday_mode,
                     difficult_environment_factors, overtime_rate_bp, holiday_rate_bp,
                     night_rate_bp, weekend_rate_bp, difficult_environment_rate_bp,
                     overtime_fixed_hourly_minor, holiday_fixed_hourly_minor,
                     night_fixed_hourly_minor, weekend_fixed_hourly_minor,
                     difficult_environment_fixed_hourly_minor, standby_rate_bp,
                     agreement_reference, note, created_by, hourly_terms_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $employmentId,
                $validFrom,
                $data['overtime_mode'] ?? 'surcharge',
                $data['holiday_mode'] ?? 'compensatory_time_off',
                $data['difficult_environment_factors'] ?? null,
                $data['overtime_rate_bp'] ?? null,
                $data['holiday_rate_bp'] ?? null,
                $data['night_rate_bp'] ?? null,
                $data['weekend_rate_bp'] ?? null,
                $data['difficult_environment_rate_bp'] ?? null,
                $data['overtime_fixed_hourly_minor'] ?? null,
                $data['holiday_fixed_hourly_minor'] ?? null,
                $data['night_fixed_hourly_minor'] ?? null,
                $data['weekend_fixed_hourly_minor'] ?? null,
                $data['difficult_environment_fixed_hourly_minor'] ?? null,
                $data['standby_rate_bp'] ?? null,
                $data['agreement_reference'] ?? null,
                $data['note'] ?? null,
                $userId,
                isset($data['hourly_terms']) ? CanonicalJson::encode($data['hourly_terms']) : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $id;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function findPolicy(int $supplierId, int $employmentId, int $policyId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_employment_surcharge_policies
              WHERE supplier_id = ? AND employment_id = ? AND id = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $policyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::policyRow($row);
    }

    /**
     * Oprava OTEVŘENÉ a zároveň POSLEDNÍ verze zásady.
     *
     * Mění se jen obsah sjednání (režimy, sazby, počet vlivů, odkaz, poznámka).
     * `valid_from` ani `valid_to` sem nepatří — kde přesně vede hranice mezi
     * opravou a přepisem historie a proč, popisuje
     * {@see PayrollSurchargePolicyHistoryLockedException}.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function updatePolicy(
        int $supplierId,
        int $employmentId,
        int $policyId,
        array $data,
        int $expectedVersion,
        ?callable $beforeWrite = null,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($beforeWrite !== null) {
                $lock = $pdo->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ? FOR UPDATE');
                $lock->execute([$supplierId, $employmentId]);
                if ($lock->fetchColumn() === false) throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
                $beforeWrite();
            }
            $current = $this->lockPolicy($supplierId, $employmentId, $policyId);
            $currentVersion = (int) $current['row_version'];
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollSurchargePolicyConflictException($currentVersion);
            }
            $this->assertOpenTail($supplierId, $employmentId, $current);

            $stmt = $pdo->prepare(
                'UPDATE payroll_employment_surcharge_policies
                    SET overtime_mode = ?,
                        holiday_mode = ?,
                        difficult_environment_factors = ?,
                        overtime_rate_bp = ?,
                        holiday_rate_bp = ?,
                        night_rate_bp = ?,
                        weekend_rate_bp = ?,
                        difficult_environment_rate_bp = ?,
                        overtime_fixed_hourly_minor = ?,
                        holiday_fixed_hourly_minor = ?,
                        night_fixed_hourly_minor = ?,
                        weekend_fixed_hourly_minor = ?,
                        difficult_environment_fixed_hourly_minor = ?,
                        standby_rate_bp = ?,
                        agreement_reference = ?,
                        note = ?,
                        hourly_terms_json = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND employment_id = ? AND id = ? AND row_version = ?'
            );
            $stmt->execute([
                $data['overtime_mode'] ?? 'surcharge',
                $data['holiday_mode'] ?? 'compensatory_time_off',
                $data['difficult_environment_factors'] ?? null,
                $data['overtime_rate_bp'] ?? null,
                $data['holiday_rate_bp'] ?? null,
                $data['night_rate_bp'] ?? null,
                $data['weekend_rate_bp'] ?? null,
                $data['difficult_environment_rate_bp'] ?? null,
                $data['overtime_fixed_hourly_minor'] ?? null,
                $data['holiday_fixed_hourly_minor'] ?? null,
                $data['night_fixed_hourly_minor'] ?? null,
                $data['weekend_fixed_hourly_minor'] ?? null,
                $data['difficult_environment_fixed_hourly_minor'] ?? null,
                $data['standby_rate_bp'] ?? null,
                $data['agreement_reference'] ?? null,
                $data['note'] ?? null,
                isset($data['hourly_terms']) ? CanonicalJson::encode($data['hourly_terms']) : null,
                $supplierId,
                $employmentId,
                $policyId,
                $expectedVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollSurchargePolicyConflictException($currentVersion);
            }
            $row = $this->findPolicy($supplierId, $employmentId, $policyId)
                ?? throw new \RuntimeException('Upravenou zásadu příplatků se nepodařilo načíst.');

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $row;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Ukončení platnosti otevřené verze.
     *
     * Zásada nemá „mazání": co platilo, platilo. Konec platnosti je jediný
     * způsob, jak sjednání ukončit — po něm se od následujícího dne vrací
     * zákonný výchozí stav, tedy u svátku náhradní volno a u ztíženého
     * prostředí chybějící podklad.
     *
     * @return array<string,mixed>
     */
    public function closePolicy(
        int $supplierId,
        int $employmentId,
        int $policyId,
        string $validTo,
        int $expectedVersion,
        ?callable $beforeWrite = null,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($beforeWrite !== null) {
                $lock = $pdo->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ? FOR UPDATE');
                $lock->execute([$supplierId, $employmentId]);
                if ($lock->fetchColumn() === false) throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
                $beforeWrite();
            }
            $current = $this->lockPolicy($supplierId, $employmentId, $policyId);
            $currentVersion = (int) $current['row_version'];
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollSurchargePolicyConflictException($currentVersion);
            }
            $validFrom = (string) $current['valid_from'];
            if ($current['valid_to'] !== null) {
                throw new PayrollSurchargePolicyHistoryLockedException(
                    'Tahle verze zásady příplatků už má konec platnosti. '
                    . 'Posunout ho zpětně by změnilo období, podle kterého se počítaly hotové mzdy.',
                );
            }
            if ($validTo < $validFrom) {
                throw new \InvalidArgumentException(
                    'Konec platnosti nesmí předcházet začátku platnosti zásady.'
                );
            }

            // Díra ani překryv: mezi dvěma verzemi nesmí zbýt den bez zásady ani
            // den se dvěma. Nástupkyně u otevřené verze vzniknout nemá
            // (`savePolicy()` jí předtím konec dopočítá), ale kdyby ji tam
            // zanechal starší zápis, ticho by znamenalo dva dny se dvěma zásadami.
            $next = $pdo->prepare(
                'SELECT MIN(valid_from)
                   FROM payroll_employment_surcharge_policies
                  WHERE supplier_id = ? AND employment_id = ? AND valid_from > ?'
            );
            $next->execute([$supplierId, $employmentId, $validFrom]);
            $nextValidFrom = $next->fetchColumn();
            if (is_string($nextValidFrom) && $nextValidFrom !== '') {
                $required = (new \DateTimeImmutable($nextValidFrom))
                    ->modify('-1 day')
                    ->format('Y-m-d');
                if ($validTo !== $required) {
                    throw new \InvalidArgumentException(
                        'Na tuhle verzi navazuje další zásada od ' . $nextValidFrom
                        . ', takže její platnost musí skončit ' . $required . '.'
                    );
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE payroll_employment_surcharge_policies
                    SET valid_to = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND employment_id = ? AND id = ? AND row_version = ?'
            );
            $stmt->execute([$validTo, $supplierId, $employmentId, $policyId, $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollSurchargePolicyConflictException($currentVersion);
            }
            $row = $this->findPolicy($supplierId, $employmentId, $policyId)
                ?? throw new \RuntimeException('Ukončenou zásadu příplatků se nepodařilo načíst.');

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $row;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Posun začátku platnosti verze, podle které se ještě nepočítalo.
     *
     * Účinnost je hranice proti předchozí verzi. Když na ni předchozí verze
     * navazovala (končila den před ní), posune se její konec s ní, takže mezi
     * verzemi nevznikne díra ani překryv. Když mezi nimi díra byla (předchozí
     * verze byla ukončena dřív), zůstane předchozí verze, jak je, a nový začátek
     * do ní nesmí zasáhnout. Konec posouvané verze ani následující verze se
     * nemění.
     *
     * Hranice se nesmí dotknout měsíce, který už zmrazila neodmítnutá revize
     * běhu: ani dnů posouvané verze, ani dnů, které přechází mezi ní
     * a předchozí verzí. Zmrazená mzda tak dál odpovídá zásadě, podle které
     * vznikla.
     *
     * @return array<string,mixed>
     */
    public function movePolicyStart(
        int $supplierId,
        int $employmentId,
        int $policyId,
        string $validFrom,
        int $expectedVersion,
        ?callable $beforeWrite = null,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$supplierId, $employmentId]);
            if ($lock->fetchColumn() === false) {
                throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
            }
            $versions = $pdo->prepare(
                'SELECT id, valid_from, valid_to, row_version
                   FROM payroll_employment_surcharge_policies
                  WHERE supplier_id = ? AND employment_id = ?
                  ORDER BY valid_from, id
                  FOR UPDATE'
            );
            $versions->execute([$supplierId, $employmentId]);
            $current = null;
            $previous = null;
            $next = null;
            foreach ($versions->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row = PayrollTimeValue::row($row, 'payroll_employment_surcharge_policy');
                if ((int) $row['id'] === $policyId) {
                    $current = $row;
                    continue;
                }
                if ($current === null) {
                    $previous = $row;
                } elseif ($next === null) {
                    $next = $row;
                }
            }
            if ($current === null) {
                throw new PayrollSurchargePolicyNotFoundException();
            }
            $currentVersion = (int) $current['row_version'];
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollSurchargePolicyConflictException($currentVersion);
            }
            $oldFrom = (string) $current['valid_from'];
            $validTo = $current['valid_to'] === null ? null : (string) $current['valid_to'];
            if ($validFrom === $oldFrom) {
                return $this->findPolicy($supplierId, $employmentId, $policyId)
                    ?? throw new PayrollSurchargePolicyNotFoundException();
            }
            if ($validTo !== null && $validFrom > $validTo) {
                throw new \InvalidArgumentException(
                    'Začátek platnosti nesmí být po konci platnosti této verze (' . $validTo . ').',
                );
            }
            if ($next !== null && $validFrom >= (string) $next['valid_from']) {
                throw new \InvalidArgumentException(
                    'Začátek platnosti musí být před začátkem následující verze (' . $next['valid_from'] . ').',
                );
            }
            $dayBefore = static fn (string $date): string => (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
            $joined = false;
            if ($previous !== null) {
                if ($validFrom <= (string) $previous['valid_from']) {
                    throw new \InvalidArgumentException(
                        'Začátek platnosti musí být po začátku předchozí verze (' . $previous['valid_from'] . ').',
                    );
                }
                $previousTo = $previous['valid_to'] === null ? null : (string) $previous['valid_to'];
                $joined = $previousTo === null || $previousTo >= $dayBefore($oldFrom);
                if (!$joined && $validFrom <= $previousTo) {
                    throw new \InvalidArgumentException(
                        'Začátek platnosti by zasáhl do předchozí verze, která platí do ' . $previousTo . '.',
                    );
                }
            }
            $this->assertPolicyRangeUnfrozen($supplierId, $employmentId, min($validFrom, $oldFrom), $validTo);
            if ($beforeWrite !== null) {
                $beforeWrite();
            }

            if ($joined) {
                $boundary = $pdo->prepare(
                    'UPDATE payroll_employment_surcharge_policies
                        SET valid_to = ?, row_version = row_version + 1
                      WHERE supplier_id = ? AND employment_id = ? AND id = ? AND row_version = ?'
                );
                $boundary->execute([
                    $dayBefore($validFrom),
                    $supplierId,
                    $employmentId,
                    (int) $previous['id'],
                    (int) $previous['row_version'],
                ]);
                if ($boundary->rowCount() !== 1) {
                    throw new PayrollSurchargePolicyConflictException($currentVersion);
                }
            }
            $stmt = $pdo->prepare(
                'UPDATE payroll_employment_surcharge_policies
                    SET valid_from = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND employment_id = ? AND id = ? AND row_version = ?'
            );
            $stmt->execute([$validFrom, $supplierId, $employmentId, $policyId, $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollSurchargePolicyConflictException($currentVersion);
            }
            $row = $this->findPolicy($supplierId, $employmentId, $policyId)
                ?? throw new \RuntimeException('Posunutou zásadu příplatků se nepodařilo načíst.');

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $row;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Žádná neodmítnutá revize nezrušeného běhu nezmrazila vztah za měsíc,
     * který zasahuje do dnů `$from` až `$through` (`null` = bez konce).
     */
    private function assertPolicyRangeUnfrozen(int $supplierId, int $employmentId, string $from, ?string $through): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MIN(run.period_start)
               FROM payroll_run_employments run_employment
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run_employment.supplier_id
                AND revision.id = run_employment.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE run_employment.supplier_id = ?
                AND run_employment.employment_id = ?
                AND revision.status <> "abandoned"
                AND run.status <> "cancelled"
                AND LAST_DAY(run.period_start) >= ?
                AND (? IS NULL OR run.period_start <= ?)'
        );
        $stmt->execute([$supplierId, $employmentId, $from, $through, $through]);
        $frozen = $stmt->fetchColumn();
        if (is_string($frozen) && $frozen !== '') {
            throw new PayrollSurchargePolicyHistoryLockedException(sprintf(
                'Začátek zásady příplatků nejde posunout: mzdový běh za %s už podle ní počítal. '
                . 'Posunout jde jen začátek, který se nedotkne spočítaného měsíce; jinak založte novou verzi.',
                substr($frozen, 5, 2) . '/' . substr($frozen, 0, 4),
            ));
        }
    }

    /** @return array<string,mixed> */
    private function lockPolicy(int $supplierId, int $employmentId, int $policyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, valid_from, valid_to, row_version
               FROM payroll_employment_surcharge_policies
              WHERE supplier_id = ? AND employment_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId, $policyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new PayrollSurchargePolicyNotFoundException();
        }

        return PayrollTimeValue::row($row, 'payroll_employment_surcharge_policy');
    }

    /** @param array<string,mixed> $policy */
    private function assertOpenTail(int $supplierId, int $employmentId, array $policy): void
    {
        if ($policy['valid_to'] !== null) {
            throw new PayrollSurchargePolicyHistoryLockedException();
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_employment_surcharge_policies
              WHERE supplier_id = ? AND employment_id = ? AND valid_from > ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, (string) $policy['valid_from']]);
        if ($stmt->fetchColumn() !== false) {
            throw new PayrollSurchargePolicyHistoryLockedException();
        }
    }

    /** Nový sloupec nepřidává prázdné klíče do dosavadních podkladů příplatků. @param array<string,mixed> $row @return array<string,mixed> */
    private static function policyRow(array $row): array
    {
        if (($row['hourly_terms_json'] ?? null) !== null) {
            $row['hourly_terms'] = json_decode((string) $row['hourly_terms_json'], true, flags: JSON_THROW_ON_ERROR);
        }
        unset($row['hourly_terms_json']);
        return PayrollTimeValue::row($row, 'payroll_employment_surcharge_policy');
    }
}
