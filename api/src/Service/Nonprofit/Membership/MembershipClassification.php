<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionAssignmentOrigin;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipClassification
{
    public const AXES = ['activity', 'activity_kind', 'tax_regime'];
    public const MAX_BATCH = 500;

    public function __construct(private readonly Connection $db) {}

    public function resolve(int $supplier, array $selection, bool $current = false): array
    {
        if ($current && !$this->db->pdo()->inTransaction()) throw new \LogicException('Membership classification current reads require a transaction.');
        $expected = ['activity_value_id', 'activity_kind_value_id', 'tax_regime_value_id'];
        if (count($selection) !== count($expected) || array_diff(array_keys($selection), $expected) !== []) self::invalid();
        $ids = [];
        foreach ($expected as $field) {
            if (!is_int($selection[$field]) || $selection[$field] <= 0) self::invalid();
            $ids[] = $selection[$field];
        }
        if (count(array_unique($ids)) !== 3) self::invalid();

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->db->pdo()->prepare("SELECT t.id type_id,t.system_key,t.is_active type_active,v.id value_id,v.code,v.semantic_key,v.is_active value_active
            FROM dimension_values v JOIN dimension_types t ON t.supplier_id=v.supplier_id AND t.id=v.type_id
            WHERE v.supplier_id=? AND v.id IN ({$marks}) ORDER BY t.id,v.id" . ($current ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, ...$ids]);
        $byAxis = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $axis = (string) ($row['system_key'] ?? '');
            if (!in_array($axis, self::AXES, true) || !(bool) $row['type_active'] || !(bool) $row['value_active'] || isset($byAxis[$axis])) self::invalid();
            $byAxis[$axis] = $row;
        }
        $out = [];
        foreach (self::AXES as $axis) {
            $row = $byAxis[$axis] ?? null;
            if ($row === null || (int) $row['value_id'] !== $selection[$axis . '_value_id']) self::invalid();
            $out[$axis] = self::proofRow($axis, $row);
        }
        return $out;
    }

    public function rebase(int $supplier, array $proof, bool $current = false): array
    {
        return $this->rebaseMany($supplier, [0 => $proof], $current)[0];
    }

    public function rebaseMany(int $supplier, array $proofs, bool $current = false): array
    {
        if ($current && !$this->db->pdo()->inTransaction()) throw new \LogicException('Membership classification current reads require a transaction.');
        if ($supplier <= 0 || $proofs === [] || count($proofs) > self::MAX_BATCH) throw new \InvalidArgumentException('Membership classification batch must contain one tenant and at most 500 proofs.');
        $codes = [];
        foreach ($proofs as $key => $proof) {
            if ((!is_int($key) && !is_string($key)) || !is_array($proof)) self::changed();
            foreach (self::AXES as $axis) {
                $row = $proof[$axis] ?? null;
                $identity = $axis === 'activity_kind' ? ($row['code'] ?? null) : ($row['semantic_key'] ?? null);
                if (!is_array($row) || !is_string($identity) || $identity === '') self::changed();
                if ($axis === 'activity_kind') $codes[$identity] = $identity;
            }
        }
        if ($codes === []) self::changed();
        $marks = implode(',', array_fill(0, count($codes), '?'));
        $sql = "SELECT t.id type_id,t.system_key,t.is_active type_active,v.id value_id,v.code,v.semantic_key,v.is_active value_active
            FROM dimension_types t JOIN dimension_values v ON v.supplier_id=t.supplier_id AND v.type_id=t.id
            WHERE t.supplier_id=? AND ((t.system_key='activity' AND v.semantic_key IN ('main','economic'))
                OR (t.system_key='tax_regime' AND v.semantic_key IN ('subject','out_of_scope','exempt'))
                OR (t.system_key='activity_kind' AND v.code IN ({$marks}))) ORDER BY t.id,v.id" . ($current ? ' FOR UPDATE' : '');
        $q = $this->db->pdo()->prepare($sql);
        $q->execute([$supplier, ...array_values($codes)]);
        $owners = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $axis = (string) $row['system_key'];
            $identity = $axis === 'activity_kind' ? (string) $row['code'] : (string) $row['semantic_key'];
            if (!in_array($axis, self::AXES, true) || !(bool) $row['type_active'] || isset($owners[$axis][$identity])) self::changed();
            $owners[$axis][$identity] = $row;
        }
        $out = [];
        foreach ($proofs as $key => $proof) {
            foreach (self::AXES as $axis) {
                $identity = $axis === 'activity_kind' ? (string) $proof[$axis]['code'] : (string) $proof[$axis]['semantic_key'];
                $row = $owners[$axis][$identity] ?? null;
                if ($row === null) self::changed();
                $out[$key][$axis] = self::proofRow($axis, $row);
            }
        }
        return $out;
    }

    public function assertStored(int $supplier, array $proof, bool $current = false): void
    {
        $this->assertStoredMany($supplier, [0 => $proof], $current);
    }

    public function assertStoredMany(int $supplier, array $proofs, bool $current = false): void
    {
        $actual = $this->rebaseMany($supplier, $proofs, $current);
        foreach ($proofs as $key => $proof) {
            if (!hash_equals(NonprofitTaxRules::hash($actual[$key]), NonprofitTaxRules::hash($proof))) self::changed();
        }
    }

    public static function line(array $proof): array
    {
        $dimensions = [];
        $origins = [];
        foreach (self::AXES as $axis) {
            $row = $proof[$axis] ?? null;
            if (!is_array($row) || !is_int($row['type_id'] ?? null) || !is_int($row['value_id'] ?? null)
                || $row['type_id'] <= 0 || $row['value_id'] <= 0 || !is_array($row['origin'] ?? null)) self::changed();
            $dimensions[$row['type_id']] = $row['value_id'];
            try { $origins[$row['type_id']] = DimensionAssignmentOrigin::normalize($row['origin']); }
            catch (\InvalidArgumentException) { self::changed(); }
        }
        if (count($dimensions) !== 3) self::changed();
        ksort($dimensions, SORT_NUMERIC);
        ksort($origins, SORT_NUMERIC);
        return ['dimensions' => $dimensions, 'dimension_origins' => $origins];
    }

    private static function proofRow(string $axis, array $row): array
    {
        $origin = DimensionAssignmentOrigin::normalize(['origin' => 'explicit', 'ref' => null]);
        if ($axis === 'activity') {
            if (!in_array($row['semantic_key'], ['main', 'economic'], true)) self::invalid();
            return ['type_id' => (int) $row['type_id'], 'value_id' => (int) $row['value_id'], 'semantic_key' => $row['semantic_key'], 'origin' => $origin];
        }
        if ($axis === 'tax_regime') {
            if (!in_array($row['semantic_key'], ['subject', 'out_of_scope', 'exempt'], true)) self::invalid();
            return ['type_id' => (int) $row['type_id'], 'value_id' => (int) $row['value_id'], 'semantic_key' => $row['semantic_key'], 'origin' => $origin];
        }
        if (!is_string($row['code']) || trim($row['code']) === '') self::invalid();
        return ['type_id' => (int) $row['type_id'], 'value_id' => (int) $row['value_id'], 'code' => $row['code'], 'origin' => $origin];
    }

    private static function invalid(): never
    {
        throw new MembershipException('nonprofit_membership_classification_invalid', 'Vyberte všechny tři platné systémové dimenze členského příspěvku.', 422);
    }

    private static function changed(): never
    {
        throw new MembershipException('nonprofit_membership_classification_changed', 'Zachycené systémové dimenze členského příspěvku nejsou průkazné.', 409);
    }
}
