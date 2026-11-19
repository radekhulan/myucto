<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollEmploymentDimensionRepository
{
    private const JOINED_COLUMNS = <<<'SQL'
        ed.id, ed.supplier_id, ed.employment_id, ed.dimension_id,
        ed.share_percent, ed.valid_from, ed.valid_to, ed.created_by,
        ed.updated_by, ed.row_version, ed.created_at, ed.updated_at,
        d.dimension_type, d.code AS dimension_code, d.name AS dimension_name
        SQL;

    /** Celý podíl v setinách procenta. */
    private const FULL_SHARE_BP = 10_000;

    public function __construct(private readonly Connection $db) {}

    public function employmentExists(int $supplierId, int $employmentId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $employmentId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::JOINED_COLUMNS . '
               FROM payroll_employment_dimensions ed
               JOIN payroll_dimensions d
                 ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
              WHERE ed.supplier_id = ? AND ed.id = ?',
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForEmployment(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::JOINED_COLUMNS . '
               FROM payroll_employment_dimensions ed
               JOIN payroll_dimensions d
                 ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
              WHERE ed.supplier_id = ? AND ed.employment_id = ?
              ORDER BY d.dimension_type, ed.valid_from DESC, ed.id DESC',
        );
        $stmt->execute([$supplierId, $employmentId]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = self::hydrate($row);
        }

        return $result;
    }

    /**
     * Dimenze firmy (Firma → Dimenze), na které vztah ke dni ukazuje přes mzdové dimenze
     * s navázanou hodnotou. Jen aktivní hodnoty; podíly jednoho typu v procentech.
     *
     * @return array<int,array<int,float>> typ dimenze firmy => hodnota => podíl v %
     */
    public function companyDimensionsOn(int $supplierId, int $employmentId, string $date): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT v.type_id, v.id AS value_id, ed.share_percent
               FROM payroll_employment_dimensions ed
               JOIN payroll_dimensions d
                 ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
               JOIN dimension_values v
                 ON v.supplier_id = ed.supplier_id AND v.id = d.dimension_value_id
              WHERE ed.supplier_id = ? AND ed.employment_id = ?
                AND ed.valid_from <= ? AND (ed.valid_to IS NULL OR ed.valid_to >= ?)
                AND v.is_active = 1
              ORDER BY v.type_id, v.id',
        );
        $stmt->execute([$supplierId, $employmentId, $date, $date]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $typeId = (int) $row['type_id'];
            $valueId = (int) $row['value_id'];
            $out[$typeId][$valueId] = ($out[$typeId][$valueId] ?? 0.0) + (float) $row['share_percent'];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function create(
        int $supplierId,
        int $employmentId,
        int $dimensionId,
        string $validFrom,
        ?string $validTo,
        ?int $actorUserId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->lockTenant($supplierId);
            if (!$this->employmentExists($supplierId, $employmentId)) {
                throw new \RuntimeException('Pracovní vztah pro přiřazení dimenze nebyl nalezen.');
            }
            $dimension = $this->lockDimension($supplierId, $dimensionId);
            $this->assertDimensionEffective($dimension, $validFrom, $validTo);
            $this->assertNoOverlap(
                $supplierId,
                $employmentId,
                (string) $dimension['dimension_type'],
                $validFrom,
                $validTo,
                null,
                $dimensionId,
                self::FULL_SHARE_BP,
            );

            $stmt = $pdo->prepare(
                'INSERT INTO payroll_employment_dimensions
                    (supplier_id, employment_id, dimension_id, valid_from,
                     valid_to, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
            );
            $stmt->execute([
                $supplierId, $employmentId, $dimensionId, $validFrom, $validTo,
                $actorUserId, $actorUserId,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($id <= 0) {
                throw new \RuntimeException('Přiřazení dimenze se nepodařilo založit.');
            }
            $row = $this->find($supplierId, $id)
                ?? throw new \RuntimeException('Založené přiřazení dimenze se nepodařilo načíst.');

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function update(
        int $supplierId,
        int $id,
        int $employmentId,
        int $dimensionId,
        string $validFrom,
        ?string $validTo,
        int $expectedVersion,
        ?int $actorUserId,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->lockTenant($supplierId);
            $lock = $pdo->prepare(
                'SELECT row_version, employment_id, share_percent
                   FROM payroll_employment_dimensions
                  WHERE supplier_id = ? AND id = ?
                  FOR UPDATE',
            );
            $lock->execute([$supplierId, $id]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if ($current === false) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return null;
            }
            if ((int) $current['employment_id'] !== $employmentId) {
                throw new \InvalidArgumentException('Přiřazení dimenze nepatří k danému pracovnímu vztahu.');
            }
            $currentVersion = (int) $current['row_version'];
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollEmploymentDimensionConflictException($currentVersion);
            }

            $dimension = $this->lockDimension($supplierId, $dimensionId);
            $this->assertDimensionEffective($dimension, $validFrom, $validTo);
            $shareBp = self::shareBasisPoints($current['share_percent']);
            $this->assertNoOverlap(
                $supplierId,
                $employmentId,
                (string) $dimension['dimension_type'],
                $validFrom,
                $validTo,
                $id,
                $dimensionId,
                $shareBp,
            );

            $stmt = $pdo->prepare(
                'UPDATE payroll_employment_dimensions
                    SET dimension_id = ?,
                        valid_from = ?,
                        valid_to = ?,
                        updated_by = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?',
            );
            $stmt->execute([
                $dimensionId, $validFrom, $validTo, $actorUserId,
                $supplierId, $id, $expectedVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollEmploymentDimensionConflictException($currentVersion);
            }
            if ($shareBp !== self::FULL_SHARE_BP) {
                $this->assertSharesComplete($supplierId, $employmentId);
            }
            $row = $this->find($supplierId, $id)
                ?? throw new \RuntimeException('Upravené přiřazení dimenze se nepodařilo načíst.');

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $row;
    }

    private function lockTenant(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        );
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('Firma pro přiřazení dimenze neexistuje.');
        }
    }

    /** @return array<string,mixed> */
    private function lockDimension(int $supplierId, int $dimensionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, dimension_type, is_active, valid_from, valid_to
               FROM payroll_dimensions
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $stmt->execute([$supplierId, $dimensionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \InvalidArgumentException('Přiřazovaná mzdová dimenze neexistuje.');
        }

        return $row;
    }

    /** @param array<string,mixed> $dimension */
    private function assertDimensionEffective(
        array $dimension,
        string $validFrom,
        ?string $validTo,
    ): void {
        if ((int) $dimension['is_active'] !== 1) {
            throw new \InvalidArgumentException('Přiřazovaná mzdová dimenze není aktivní.');
        }
        if ((string) $dimension['valid_from'] > $validFrom) {
            throw new \InvalidArgumentException(
                'Dimenze není účinná po celou dobu přiřazení — začíná až po jeho začátku.',
            );
        }
        $dimensionValidTo = $dimension['valid_to'];
        if ($dimensionValidTo !== null) {
            if ($validTo === null || $validTo > (string) $dimensionValidTo) {
                throw new \InvalidArgumentException(
                    'Dimenze není účinná po celou dobu přiřazení — končí dřív, než přiřazení.',
                );
            }
        }
    }

    /**
     * Procentní rozpad vztahu mezi víc hodnot jednoho typu dimenze.
     *
     * Celý rozpad se ukládá najednou: součet 100 % musí platit v každém dni,
     * takže jednotlivé řádky po jednom uložit nejde. Dosavadní přiřazení
     * téhož typu, které začalo dřív a trvá do `valid_from`, se ukončí den
     * před ním — rozpad tak jde nastavit i změnit „od data". Přiřazení, které
     * začíná až v období rozpadu (100 % od téhož dne, chybně zadaný rozpad),
     * rozpad nahradí. Obojí jen tehdy, když se tím nemění měsíc už schválený
     * ve mzdové revizi; jinak uložení srozumitelně odmítne.
     *
     * Jediná hodnota se 100 % je platný „rozpad" — vrací vztah k jednomu
     * středisku.
     *
     * @param list<array{dimension_id:int,share_bp:int}> $shares
     * @return list<array<string,mixed>> uložené řádky rozpadu
     */
    public function saveSplit(
        int $supplierId,
        int $employmentId,
        string $dimensionType,
        string $validFrom,
        ?string $validTo,
        array $shares,
        ?int $actorUserId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->lockTenant($supplierId);
            if (!$this->employmentExists($supplierId, $employmentId)) {
                throw new \RuntimeException('Pracovní vztah pro přiřazení dimenze nebyl nalezen.');
            }
            $total = 0;
            $seen = [];
            foreach ($shares as $share) {
                if (isset($seen[$share['dimension_id']])) {
                    throw new \InvalidArgumentException('Rozpad obsahuje stejnou dimenzi vícekrát.');
                }
                $seen[$share['dimension_id']] = true;
                $dimension = $this->lockDimension($supplierId, $share['dimension_id']);
                if ((string) $dimension['dimension_type'] !== $dimensionType) {
                    throw new \InvalidArgumentException('Všechny dimenze rozpadu musí být stejného typu.');
                }
                $this->assertDimensionEffective($dimension, $validFrom, $validTo);
                $total += $share['share_bp'];
            }
            if ($shares === [] || $total !== self::FULL_SHARE_BP) {
                throw new \InvalidArgumentException('Podíly rozpadu musí dát dohromady přesně 100 %.');
            }

            $existing = $pdo->prepare(
                'SELECT ed.id, ed.valid_from, ed.valid_to
                   FROM payroll_employment_dimensions ed
                   JOIN payroll_dimensions d
                     ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
                  WHERE ed.supplier_id = ?
                    AND ed.employment_id = ?
                    AND d.dimension_type = ?
                    AND ed.valid_from <= COALESCE(?, "9999-12-31")
                    AND COALESCE(ed.valid_to, "9999-12-31") >= ?
                  FOR UPDATE',
            );
            $existing->execute([$supplierId, $employmentId, $dimensionType, $validTo, $validFrom]);
            $closeOn = (new \DateTimeImmutable($validFrom))->modify('-1 day')->format('Y-m-d');
            $close = $pdo->prepare(
                'UPDATE payroll_employment_dimensions
                    SET valid_to = ?, updated_by = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ?',
            );
            $delete = $pdo->prepare(
                'DELETE FROM payroll_employment_dimensions WHERE supplier_id = ? AND id = ?',
            );
            foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rowFrom = (string) $row['valid_from'];
                $rowTo = $row['valid_to'] === null ? null : (string) $row['valid_to'];
                $endsAfterSplit = $validTo !== null && ($rowTo === null || $rowTo > $validTo);
                if ($endsAfterSplit) {
                    throw new PayrollEmploymentDimensionOverlapException(
                        'Přiřazení tohoto typu trvá i po konci rozpadu. Zadejte rozpad bez data '
                        . 'konce, nebo nejdřív upravte platnost dosavadního přiřazení.',
                    );
                }
                // Od data rozpadu se mění, co dosavadní přiřazení tvrdí. Měsíc už
                // schválený podle něj se přepsat nesmí — oprava jde opravnou revizí
                // po změně od data, které schválené měsíce nezasahuje.
                $affectedFrom = max($rowFrom, $validFrom);
                if ($this->usedInApprovedRevision($supplierId, $employmentId, $affectedFrom, $rowTo)) {
                    throw new PayrollEmploymentDimensionOverlapException(sprintf(
                        'Přiřazení platné od %s je od %s použité ve schválené mzdové revizi. '
                        . 'Rozpad zadejte od data, které schválené měsíce nezasahuje.',
                        $rowFrom,
                        $affectedFrom,
                    ));
                }
                if ($rowFrom >= $validFrom) {
                    // Přiřazení začíná až v období rozpadu (třeba chybně zadaný
                    // rozpad nebo 100 % od téhož dne) — rozpad ho nahradí.
                    $delete->execute([$supplierId, (int) $row['id']]);
                    continue;
                }
                $close->execute([$closeOn, $actorUserId, $supplierId, (int) $row['id']]);
            }

            $insert = $pdo->prepare(
                'INSERT INTO payroll_employment_dimensions
                    (supplier_id, employment_id, dimension_id, share_percent,
                     valid_from, valid_to, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $ids = [];
            foreach ($shares as $share) {
                $insert->execute([
                    $supplierId,
                    $employmentId,
                    $share['dimension_id'],
                    self::sharePercent($share['share_bp']),
                    $validFrom,
                    $validTo,
                    $actorUserId,
                    $actorUserId,
                ]);
                $ids[] = (int) $pdo->lastInsertId();
            }
            $this->assertSharesComplete($supplierId, $employmentId);

            $rows = [];
            foreach ($ids as $id) {
                $rows[] = $this->find($supplierId, $id)
                    ?? throw new \RuntimeException('Uložený rozpad dimenze se nepodařilo načíst.');
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $rows;
    }

    /** Byl pracovní vztah v období [od, do] ve schválené mzdové revizi? */
    private function usedInApprovedRevision(
        int $supplierId,
        int $employmentId,
        string $from,
        ?string $to,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_run_employments rune
               JOIN payroll_run_revisions rev
                 ON rev.supplier_id = rune.supplier_id
                AND rev.id = rune.revision_id
                AND rev.status = "approved"
               JOIN payroll_runs run
                 ON run.supplier_id = rev.supplier_id
                AND run.id = rev.run_id
              WHERE rune.supplier_id = ?
                AND rune.employment_id = ?
                AND run.period_start >= ?
                AND run.period_start <= COALESCE(?, "9999-12-31")
              LIMIT 1',
        );
        $stmt->execute([$supplierId, $employmentId, $from, $to]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Součet podílů každého typu dimenze je v každém dni buď 0 (bez
     * přiřazení), nebo přesně 100 %.
     *
     * Kontroluje se po uložení, uvnitř transakce: trigger vidí jen jeden
     * řádek a rozpad vzniká víc řádky najednou.
     */
    private function assertSharesComplete(int $supplierId, int $employmentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.dimension_type, ed.share_percent, ed.valid_from, ed.valid_to
               FROM payroll_employment_dimensions ed
               JOIN payroll_dimensions d
                 ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
              WHERE ed.supplier_id = ? AND ed.employment_id = ?',
        );
        $stmt->execute([$supplierId, $employmentId]);
        $byType = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byType[(string) $row['dimension_type']][] = [
                'share' => self::shareBasisPoints($row['share_percent']),
                'from' => (string) $row['valid_from'],
                'to' => $row['valid_to'] === null ? '9999-12-31' : (string) $row['valid_to'],
            ];
        }
        foreach ($byType as $rows) {
            // Součet se mění jen na začátku přiřazení a den po jeho konci.
            $points = [];
            foreach ($rows as $row) {
                $points[] = $row['from'];
                if ($row['to'] !== '9999-12-31') {
                    $points[] = (new \DateTimeImmutable($row['to']))->modify('+1 day')->format('Y-m-d');
                }
            }
            foreach (array_unique($points) as $day) {
                $sum = 0;
                foreach ($rows as $row) {
                    if ($row['from'] <= $day && $row['to'] >= $day) {
                        $sum += $row['share'];
                    }
                }
                if ($sum !== 0 && $sum !== self::FULL_SHARE_BP) {
                    throw new \InvalidArgumentException(
                        "Podíly dimenzí pracovního vztahu nedávají ke dni {$day} dohromady 100 %.",
                    );
                }
            }
        }
    }

    /** Podíl ze sloupce DECIMAL(5,2) v setinách procenta. */
    private static function shareBasisPoints(mixed $value): int
    {
        $text = is_string($value) ? $value : (string) $value;
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $match) !== 1) {
            throw new \UnexpectedValueException("Podíl dimenze {$text} není platné procento.");
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '0', 2, '0');
    }

    private static function sharePercent(int $basisPoints): string
    {
        return intdiv($basisPoints, 100) . '.' . str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }

    private function assertNoOverlap(
        int $supplierId,
        int $employmentId,
        string $dimensionType,
        string $validFrom,
        ?string $validTo,
        ?int $exceptId,
        int $dimensionId,
        int $shareBp,
    ): void {
        // Překrývat se smí jen části rozpadu: obě s podílem pod 100 %
        // a s různou dimenzí. Shodně s triggerem z migrace 1948.
        $sql = 'SELECT ed.id
                  FROM payroll_employment_dimensions ed
                  JOIN payroll_dimensions d
                    ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
                 WHERE ed.supplier_id = ?
                   AND ed.employment_id = ?
                   AND d.dimension_type = ?
                   AND ed.valid_from <= COALESCE(?, "9999-12-31")
                   AND COALESCE(ed.valid_to, "9999-12-31") >= ?
                   AND (? >= ' . self::FULL_SHARE_BP . '
                        OR ed.share_percent >= 100
                        OR ed.dimension_id = ?)';
        $params = [
            $supplierId, $employmentId, $dimensionType, $validTo, $validFrom,
            $shareBp, $dimensionId,
        ];
        if ($exceptId !== null) {
            $sql .= ' AND ed.id <> ?';
            $params[] = $exceptId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) {
            throw new PayrollEmploymentDimensionOverlapException(
                'Pracovní vztah už má v tomto období přiřazenou jinou dimenzi stejného typu.',
            );
        }
    }

    /** @return array<string,mixed> */
    private static function hydrate(mixed $value): array
    {
        $row = self::databaseRow($value);
        $row['id'] = self::requiredInt($row, 'id');
        $row['supplier_id'] = self::requiredInt($row, 'supplier_id');
        $row['employment_id'] = self::requiredInt($row, 'employment_id');
        $row['dimension_id'] = self::requiredInt($row, 'dimension_id');
        $row['share_percent'] = self::shareBasisPoints($row['share_percent'] ?? '100.00') / 100;
        $row['row_version'] = self::requiredInt($row, 'row_version');
        foreach (['created_by', 'updated_by'] as $field) {
            $row[$field] = self::nullableInt($row, $field);
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private static function databaseRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Databázový řádek přiřazení dimenze není pole.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Databázový řádek přiřazení dimenze nemá textové klíče.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function requiredInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \UnexpectedValueException("Pole {$field} přiřazení dimenze není celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private static function nullableInt(array $row, string $field): ?int
    {
        if (($row[$field] ?? null) === null) {
            return null;
        }

        return self::requiredInt($row, $field);
    }
}
