<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Services\Settlement\SettlementEvidence as E;
use PDO;

/**
 * Profil účtování služby (rozvahový / výsledkový) je účetní politika SVJ pro danou službu
 * a období, ne volba u jednotlivé jednotky. Verze platí od valid_from do začátku další
 * verze téže služby; zúčtovací období případu musí ležet celé v jedné verzi.
 */
final class ServiceLifecyclePolicyRepository
{
    public const POLICIES = ['service_balance_sheet', 'service_profit_and_loss'];

    public function __construct(private readonly Connection $db) {}

    private function q(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->db->pdo()->prepare($sql);
        $q->execute($args);
        return $q;
    }

    /** Verze, které se dotýkají období [from, to], seřazené podle platnosti. */
    public function governing(int $supplier, int $service, string $from, string $to): array
    {
        $rows = $this->q(
            'SELECT id,valid_from,policy FROM property_service_policy_versions'
            .' WHERE supplier_id=? AND service_id=? AND valid_from<=?'
            .' ORDER BY valid_from DESC',
            [$supplier, $service, $to]
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int)$row['id'], 'valid_from' => $row['valid_from'], 'policy' => $row['policy']];
            if ($row['valid_from'] <= $from) break;
        }
        return array_reverse($out);
    }

    /**
     * Případ musí odpovídat platné verzi politiky služby. Pokud služba politiku ještě nemá,
     * první případ ji založí (původ first_case) a další jednotky se jí musí držet.
     */
    public function assertForCase(int $supplier, int $service, string $from, string $to, string $policy, string $evidence, int $actor): void
    {
        $versions = $this->governing($supplier, $service, $from, $to);
        if (count($versions) > 1 || ($versions !== [] && $versions[0]['valid_from'] > $from)) E::reject('lifecycle_policy_period_split');
        if ($versions !== []) {
            if ($versions[0]['policy'] !== $policy) E::reject('lifecycle_policy_mismatch');
            return;
        }
        $this->insert($supplier, $service, $from, $policy, $evidence, 'first_case', $actor);
    }

    public function declare(int $supplier, int $service, string $validFrom, string $policy, string $evidence, int $actor): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $service, $validFrom, $policy, $evidence, $actor): array {
            (new PaymentLocks($this->db))->acquire($supplier);
            PropertyInput::id($supplier, 'supplier_id');
            PropertyInput::id($service, 'service_id');
            PropertyInput::id($actor, 'actor');
            E::date($validFrom);
            if (!in_array($policy, self::POLICIES, true)) PropertyInput::invalid('policy');
            $evidence = PropertyInput::text($evidence, 'evidence', 4000);
            $object = $this->q("SELECT id FROM property_objects WHERE supplier_id=? AND id=? AND kind='service' FOR UPDATE", [$supplier, $service])->fetchColumn();
            if ($object === false) PropertyInput::invalid('service_id');
            if ($this->q('SELECT id FROM property_service_policy_versions WHERE supplier_id=? AND service_id=? AND valid_from=? FOR UPDATE', [$supplier, $service, $validFrom])->fetchColumn() !== false) {
                E::reject('lifecycle_policy_version_exists');
            }
            $until = $this->q(
                'SELECT MIN(valid_from) FROM property_service_policy_versions WHERE supplier_id=? AND service_id=? AND valid_from>?',
                [$supplier, $service, $validFrom]
            )->fetchColumn();
            $sql = 'SELECT period_from,period_to,policy FROM property_service_lifecycle_cases'
                .' WHERE supplier_id=? AND service_id=? AND period_to>=?';
            $args = [$supplier, $service, $validFrom];
            if (is_string($until)) {
                $sql .= ' AND period_from<?';
                $args[] = $until;
            }
            foreach ($this->q($sql.' FOR UPDATE', $args)->fetchAll(PDO::FETCH_ASSOC) as $case) {
                if ($case['period_from'] < $validFrom || (is_string($until) && $case['period_to'] >= $until)) E::reject('lifecycle_policy_period_split');
                if ($case['policy'] !== $policy) E::reject('lifecycle_policy_conflict');
            }
            $id = $this->insert($supplier, $service, $validFrom, $policy, $evidence, 'declared', $actor);
            return $this->find($supplier, $id);
        });
    }

    public function list(int $supplier, ?int $service = null, int $after = 0, int $limit = 100): array
    {
        $sql = 'SELECT v.id,v.service_id,v.valid_from,v.policy,v.evidence,v.origin,v.recorded_at,o.code AS service_label'
            .' FROM property_service_policy_versions v'
            .' JOIN property_objects o ON o.supplier_id=v.supplier_id AND o.id=v.service_id'
            .' WHERE v.supplier_id=? AND v.id>?';
        $args = [$supplier, $after];
        if ($service !== null) {
            $sql .= ' AND v.service_id=?';
            $args[] = $service;
        }
        $rows = $this->q($sql.' ORDER BY v.id LIMIT '.($limit + 1), $args)->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) > $limit ? (int)$rows[$limit - 1]['id'] : null;
        return ['items' => array_map(self::row(...), array_slice($rows, 0, $limit)), 'next_cursor' => $next];
    }

    private function find(int $supplier, int $id): array
    {
        $row = $this->q(
            'SELECT v.id,v.service_id,v.valid_from,v.policy,v.evidence,v.origin,v.recorded_at,o.code AS service_label'
            .' FROM property_service_policy_versions v'
            .' JOIN property_objects o ON o.supplier_id=v.supplier_id AND o.id=v.service_id'
            .' WHERE v.supplier_id=? AND v.id=?',
            [$supplier, $id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) E::reject('lifecycle_policy_missing');
        return self::row($row);
    }

    private static function row(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'service_id' => (int)$row['service_id'],
            'service_label' => (string)$row['service_label'],
            'valid_from' => $row['valid_from'],
            'policy' => $row['policy'],
            'evidence' => $row['evidence'],
            'origin' => $row['origin'],
            'recorded_at' => $row['recorded_at'],
        ];
    }

    private function insert(int $supplier, int $service, string $validFrom, string $policy, string $evidence, string $origin, int $actor): int
    {
        $this->q(
            'INSERT INTO property_service_policy_versions(supplier_id,service_id,valid_from,policy,evidence,origin,created_by,recorded_at)'
            .' VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',
            [$supplier, $service, $validFrom, $policy, $evidence, $origin, $actor]
        );
        return (int)$this->db->pdo()->lastInsertId();
    }
}
