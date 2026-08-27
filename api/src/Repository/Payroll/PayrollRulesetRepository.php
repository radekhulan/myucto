<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetConflictException;
use PDO;

/**
 * DB override legislativních rulesetů (tabulka `payroll_rulesets`, migrace 1306)
 * plus append-only auditní stopa.
 *
 * Stejný záměr jako {@see \MyInvoice\Repository\TaxConstantsRepository}: kód drží
 * ověřený default, sem se ukládá jen to, co admin změnil. Merge s defaultem dělá
 * {@see \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry} — repozitář
 * vrací syrový override, aby merge zůstala na jednom místě.
 *
 * Globální data (národní legislativa), proto bez `supplier_id`.
 */
final class PayrollRulesetRepository
{
    private const COLUMNS = <<<'SQL'
        ruleset_id, domain, version, effective_from, effective_to, lifecycle,
        capability, data, content_hash, reason, created_by, updated_by,
        reviewed_by, reviewed_at, approved_by, approved_at, activated_by,
        activated_at, superseded_by, superseded_at, row_version,
        created_at, updated_at
        SQL;

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable('payroll_rulesets');
    }

    /** @return array<string, array<string, mixed>> klíčováno ruleset_id */
    public function all(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $stmt = $this->db->pdo()->query(
            'SELECT ' . self::COLUMNS . ' FROM payroll_rulesets ORDER BY domain, ruleset_id',
        );
        $result = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = self::hydrate($fetched);
            $result[self::str($row, 'ruleset_id')] = $row;
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function find(string $rulesetId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM payroll_rulesets WHERE ruleset_id = ?',
        );
        $stmt->execute([$rulesetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Uloží override pod optimistickým zámkem. `$expectedRowVersion === 0`
     * znamená „řádek zatím neexistuje" (editace čistého defaultu z kódu).
     *
     * `$touchUpdatedBy = false` používají stavové příkazy: `updated_by` musí
     * zůstat posledním EDITOREM obsahu, jinak by schválení samo sebe zapsalo
     * jako editora a kontrola čtyř očí by hlásila konflikt vždy.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function save(
        string $rulesetId,
        string $domain,
        array $values,
        int $expectedRowVersion,
        ?int $actorUserId,
        bool $touchUpdatedBy = true,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $lock = $pdo->prepare(
                'SELECT row_version FROM payroll_rulesets WHERE ruleset_id = ? FOR UPDATE',
            );
            $lock->execute([$rulesetId]);
            $current = $lock->fetchColumn();
            $currentVersion = $current === false ? 0 : (int) $current;
            if ($currentVersion !== $expectedRowVersion) {
                throw new PayrollRulesetConflictException($currentVersion);
            }

            if ($currentVersion === 0) {
                $columns = ['ruleset_id', 'domain', 'created_by', 'updated_by', ...array_keys($values)];
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $stmt = $pdo->prepare(
                    'INSERT INTO payroll_rulesets (' . implode(', ', $columns) . ')
                     VALUES (' . $placeholders . ')',
                );
                $stmt->execute([
                    $rulesetId,
                    $domain,
                    $actorUserId,
                    $actorUserId,
                    ...array_values($values),
                ]);
            } else {
                $assignments = ['row_version = row_version + 1'];
                $params = [];
                if ($touchUpdatedBy) {
                    $assignments[] = 'updated_by = ?';
                    $params[] = $actorUserId;
                }
                foreach ($values as $column => $value) {
                    $assignments[] = "{$column} = ?";
                    $params[] = $value;
                }
                $params[] = $rulesetId;
                $params[] = $expectedRowVersion;
                $stmt = $pdo->prepare(
                    'UPDATE payroll_rulesets SET ' . implode(', ', $assignments) . '
                      WHERE ruleset_id = ? AND row_version = ?',
                );
                $stmt->execute($params);
                if ($stmt->rowCount() !== 1) {
                    throw new PayrollRulesetConflictException($currentVersion);
                }
            }

            $row = $this->find($rulesetId)
                ?? throw new \RuntimeException('Uložený override rulesetu se nepodařilo načíst.');

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

    /** Reset na default z kódu. Vrací true, pokud override existoval. */
    public function reset(string $rulesetId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM payroll_rulesets WHERE ruleset_id = ?');
        $stmt->execute([$rulesetId]);

        return $stmt->rowCount() > 0;
    }

    public function appendAudit(
        string $rulesetId,
        string $domain,
        string $action,
        string $lifecycle,
        string $reason,
        string $snapshotJson,
        ?string $previousHash,
        ?int $actorUserId,
    ): void {
        if (!$this->db->hasTable('payroll_ruleset_audit')) {
            return;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_ruleset_audit
                (ruleset_id, domain, action, reason, snapshot_json, snapshot_hash,
                 previous_hash, lifecycle, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $rulesetId,
            $domain,
            $action,
            $reason,
            $snapshotJson,
            hash('sha256', $snapshotJson),
            $previousHash,
            $lifecycle,
            $actorUserId,
        ]);
    }

    /**
     * @return list<array{
     *   id:int, action:string, reason:string, lifecycle:string,
     *   snapshot_hash:string, previous_hash:?string, actor_user_id:?int,
     *   created_at:string
     * }>
     */
    public function auditTrail(string $rulesetId, int $limit = 100): array
    {
        if (!$this->db->hasTable('payroll_ruleset_audit')) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, action, reason, lifecycle, snapshot_hash, previous_hash,
                    actor_user_id, created_at
               FROM payroll_ruleset_audit
              WHERE ruleset_id = ?
              ORDER BY id DESC
              LIMIT ' . max(1, min(500, $limit)),
        );
        $stmt->execute([$rulesetId]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = self::row($fetched);
            $result[] = [
                'id' => self::int($row, 'id'),
                'action' => self::str($row, 'action'),
                'reason' => self::str($row, 'reason'),
                'lifecycle' => self::str($row, 'lifecycle'),
                'snapshot_hash' => self::str($row, 'snapshot_hash'),
                'previous_hash' => self::nullableStr($row, 'previous_hash'),
                'actor_user_id' => self::nullableInt($row, 'actor_user_id'),
                'created_at' => self::str($row, 'created_at'),
            ];
        }

        return $result;
    }

    /**
     * Poslední provozní hranice téhož rulesetu. Audit je append-only, takže
     * nečteme z aktuálního override řádku, který už mohl být změněn do dalšího
     * kandidáta. Reset a supersede zároveň ukončují platnost starší aktivace.
     *
     * @return array{action:string,lifecycle:string,snapshot_json:string,snapshot_hash:string}|null
     */
    public function latestActivationBoundary(string $rulesetId): ?array
    {
        if (!$this->db->hasTable('payroll_ruleset_audit')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT action, lifecycle, snapshot_json, snapshot_hash
               FROM payroll_ruleset_audit
              WHERE ruleset_id = ?
                AND action IN (?, ?, ?)
              ORDER BY id DESC
              LIMIT 1',
        );
        $stmt->execute([$rulesetId, 'activate', 'reset', 'supersede']);
        $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fetched === false) {
            return null;
        }
        $row = self::row($fetched);

        return [
            'action' => self::str($row, 'action'),
            'lifecycle' => self::str($row, 'lifecycle'),
            'snapshot_json' => self::str($row, 'snapshot_json'),
            'snapshot_hash' => self::str($row, 'snapshot_hash'),
        ];
    }

    /** @return array<string, mixed> */
    private static function hydrate(mixed $value): array
    {
        $row = self::row($value);
        $row['row_version'] = self::int($row, 'row_version');
        foreach ([
            'created_by', 'updated_by', 'reviewed_by', 'approved_by',
            'activated_by', 'superseded_by',
        ] as $field) {
            $row[$field] = self::nullableInt($row, $field);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Řádek override rulesetu není pole.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Řádek override rulesetu nemá textové klíče.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Pole {$field} override rulesetu není text.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function nullableStr(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Pole {$field} override rulesetu není text.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \UnexpectedValueException("Pole {$field} override rulesetu není celé číslo.");
    }

    /** @param array<string, mixed> $row */
    private static function nullableInt(array $row, string $field): ?int
    {
        if (($row[$field] ?? null) === null) {
            return null;
        }

        return self::int($row, $field);
    }
}
