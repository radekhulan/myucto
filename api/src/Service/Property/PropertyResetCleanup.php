<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

use MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion;
use PDO;

final class PropertyResetCleanup
{
    public const TABLES = ['property_module_profiles', 'property_objects', 'property_revisions', 'property_revision_groups',
        'property_revision_members', 'property_revision_roles', 'property_revision_identifiers', 'property_revision_services', 'property_audit'];

    public static function plan(PDO $pdo, bool $currentRead = false): array
    {
        $suffix = $currentRead ? ' FOR UPDATE' : '';
        $types = $pdo->query("SELECT * FROM dimension_types ORDER BY id{$suffix}")->fetchAll(PDO::FETCH_ASSOC);
        $moduleTypes = array_column(array_filter($types, static fn (array $row): bool => in_array($row['system_key'], ['unit', 'service'], true)), null, 'id');
        if ($moduleTypes === []) return ['type_ids' => [], 'value_ids' => [], 'key_ids' => [], 'fingerprint' => null];
        $values = $pdo->query("SELECT * FROM dimension_values ORDER BY id{$suffix}")->fetchAll(PDO::FETCH_ASSOC);
        $moduleValues = array_column(array_filter($values, static fn (array $row): bool => isset($moduleTypes[$row['type_id']])), null, 'id');
        foreach ($values as $row) {
            if (!isset($moduleValues[$row['id']]) && $row['parent_id'] !== null && isset($moduleValues[$row['parent_id']])) {
                throw new \RuntimeException('Reset registru SVJ vyžaduje nejprve změnit nadřazenou hodnotu zachované vlastní dimenze.');
            }
        }
        $remaining = $moduleValues;
        $ordered = [];
        while ($remaining !== []) {
            $parents = array_fill_keys(array_filter(array_column($remaining, 'parent_id'), static fn ($id): bool => $id !== null), true);
            $leaves = array_diff_key($remaining, $parents);
            if ($leaves === []) throw new \RuntimeException('Hierarchie dimenzí registru SVJ obsahuje cyklus. Reset nebyl zahájen.');
            foreach ($leaves as $id => $row) { $ordered[] = (int) $id; unset($remaining[$id]); }
        }
        $versions = $pdo->query("SELECT * FROM joint_allocation_key_versions ORDER BY key_id, id{$suffix}")->fetchAll(PDO::FETCH_ASSOC);
        $keys = [];
        foreach ($versions as $row) {
            $version = JointAllocationKeyVersion::decodeCells($row);
            foreach ($version['cells'] as $cell) {
                foreach ($cell['additional_dimensions'] ?? [] as $type => $value) {
                    if (isset($moduleTypes[$type]) || isset($moduleValues[$value])) $keys[(int) $row['key_id']] = (int) $row['key_id'];
                }
            }
        }
        ksort($keys, SORT_NUMERIC);
        return ['type_ids' => array_map('intval', array_keys($moduleTypes)), 'value_ids' => $ordered, 'key_ids' => array_values($keys),
            'fingerprint' => hash('sha256', json_encode([$types, $values, $versions], JSON_THROW_ON_ERROR))];
    }

    public static function apply(PDO $pdo, array $plan): void
    {
        if ($plan['type_ids'] === []) return;
        if ($pdo->inTransaction() || !(bool) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()) {
            throw new \LogicException('Property reset cleanup requires its own transaction with foreign keys enabled.');
        }
        $pdo->beginTransaction();
        try {
            foreach ([...\MyInvoice\Service\Property\Funds\FundingUseGuard::TABLES, \MyInvoice\Service\Anonymization\ServiceLifecycleCloneScrubber::COMMANDS, ...\MyInvoice\Service\Anonymization\ServiceLifecycleCloneScrubber::TABLES, ...self::TABLES, 'nonprofit_legal_profiles', 'nonprofit_tax_saving_sources', 'nonprofit_tax_saving_commands', 'nonprofit_tax_saving_events', 'nonprofit_tax_saving_cost_bindings', ...\MyInvoice\Repository\Property\Services\Settlement\SettlementDossierRepository::TABLES, ...\MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard::TABLES, 'journal_entries', 'joint_allocation_batches'] as $table) {
                if ($pdo->query("SELECT 1 FROM {$table} LIMIT 1 FOR UPDATE")->fetchColumn() !== false) {
                    throw new \RuntimeException('Reset registru SVJ nebyl dokončen. Související dimenze zůstaly zachované.');
                }
            }
            if (self::plan($pdo, true) !== $plan) throw new \RuntimeException('Konfigurace dimenzí se během resetu změnila. Související dimenze zůstaly zachované.');
            $deleteKey = $pdo->prepare('DELETE FROM joint_allocation_keys WHERE id = ?');
            foreach ($plan['key_ids'] as $id) $deleteKey->execute([$id]);
            $deleteRule = $pdo->prepare('DELETE FROM dimension_account_rules WHERE dimension_type_id = ?');
            $deleteMap = $pdo->prepare('DELETE FROM dimension_account_map WHERE dimension_type_id = ?');
            foreach ($plan['type_ids'] as $id) { $deleteRule->execute([$id]); $deleteMap->execute([$id]); }
            $unlinkPayroll = $pdo->prepare('UPDATE payroll_dimensions SET dimension_value_id = NULL, row_version = row_version + 1 WHERE dimension_value_id = ?');
            $deleteValue = $pdo->prepare('DELETE FROM dimension_values WHERE id = ?');
            foreach ($plan['value_ids'] as $id) { $unlinkPayroll->execute([$id]); $deleteValue->execute([$id]); }
            $deleteType = $pdo->prepare('DELETE FROM dimension_types WHERE id = ?');
            foreach ($plan['type_ids'] as $id) $deleteType->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
