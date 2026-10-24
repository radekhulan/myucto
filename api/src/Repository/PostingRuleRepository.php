<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro posting_rules — kontační pravidla (Epic F1).
 *
 * Precedent vat_classifications: globální šablona (supplier_id NULL) + per-tenant
 * override (supplier_id = X). Per-tenant vyhrává. PostingService podle rule_key
 * získá MD/D account CODE a přeloží je přes {@see ChartOfAccountsRepository}.
 */
final class PostingRuleRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Efektivní pravidlo pro rule_key — per-tenant override má přednost před
     * globální šablonou (supplier_id NULL). Nejvyšší priority vyhrává.
     */
    public function resolve(int $supplierId, string $ruleKey): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, rule_key, description, debit_account_code, credit_account_code,
                    priority, is_active
               FROM posting_rules
              WHERE rule_key = ? AND is_active = 1 AND (supplier_id = ? OR supplier_id IS NULL)
              ORDER BY (supplier_id IS NULL) ASC, priority DESC
              LIMIT 1'
        );
        $stmt->execute([$ruleKey, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Efektivní sada pravidel pro firmu (globální + per-tenant override).
     * rule_key => pravidlo (per-tenant vyhrává).
     *
     * @return array<string,array<string,mixed>>
     */
    public function effectiveMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, rule_key, description, debit_account_code, credit_account_code,
                    priority, is_active
               FROM posting_rules
              WHERE is_active = 1 AND (supplier_id = ? OR supplier_id IS NULL)
              ORDER BY (supplier_id IS NULL) DESC, priority ASC'
        );
        $stmt->execute([$supplierId]);
        // Globální jdou první → per-tenant je přepíší (poslední zápis vyhrává).
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['rule_key']] = $this->cast($r);
        }
        return $map;
    }

    /**
     * Priorita per-tenant override — vyšší než globální seed (0), takže resolve()
     * (ORDER BY (supplier_id IS NULL) ASC, priority DESC) ho vybere přednostně.
     * Fixní hodnota je zároveň součástí unique klíče (supplier_id, rule_key, priority)
     * → umožňuje ON DUPLICATE KEY UPDATE (jedna override řádka na rule_key a firmu).
     */
    public const OVERRIDE_PRIORITY = 100;

    /**
     * Vloží / aktualizuje per-tenant override kontačního pravidla. Vrací id řádku.
     */
    public function upsertOverride(
        int $supplierId,
        string $ruleKey,
        ?string $debitCode,
        ?string $creditCode,
        string $description,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO posting_rules
                (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                debit_account_code = VALUES(debit_account_code),
                credit_account_code = VALUES(credit_account_code),
                is_active = 1'
        )->execute([$supplierId, $ruleKey, $description, $debitCode, $creditCode, self::OVERRIDE_PRIORITY]);

        $stmt = $pdo->prepare(
            'SELECT id FROM posting_rules WHERE supplier_id = ? AND rule_key = ? AND priority = ?'
        );
        $stmt->execute([$supplierId, $ruleKey, self::OVERRIDE_PRIORITY]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Potvrzené nastavení převodu zapisujeme jednou dávkou; počet předkontací není
     * důvod k opakovanému načítání jejich ID.
     * @param list<array{rule_key:string,debit_account_code:?string,credit_account_code:?string,description:string}> $rules
     */
    public function upsertOverrides(int $supplierId, array $rules): void
    {
        if ($rules === []) return;
        $params = [];
        foreach ($rules as $rule) {
            array_push($params, $supplierId, $rule['rule_key'], $rule['description'],
                $rule['debit_account_code'], $rule['credit_account_code'], self::OVERRIDE_PRIORITY);
        }
        $this->db->pdo()->prepare('INSERT INTO posting_rules
            (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
            VALUES ' . implode(', ', array_fill(0, count($rules), '(?, ?, ?, ?, ?, ?, 1)')) . '
            ON DUPLICATE KEY UPDATE description=VALUES(description),
            debit_account_code=VALUES(debit_account_code), credit_account_code=VALUES(credit_account_code), is_active=1')
            ->execute($params);
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = $r['supplier_id'] === null ? null : (int) $r['supplier_id'];
        $r['priority'] = (int) $r['priority'];
        $r['is_active'] = (bool) $r['is_active'];
        return $r;
    }
}
