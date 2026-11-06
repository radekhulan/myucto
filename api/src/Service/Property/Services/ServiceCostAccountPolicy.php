<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Services;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\AccountRoleResolver;
use MyInvoice\Service\Property\Opening\OpeningRolePolicy;
use PDO;

/**
 * Účet nákladu služby SVJ podle profilu služby k datu zaúčtování.
 *
 * Náklad služby, kterou SVJ vyúčtovává vlastníkům v rozvahovém profilu, není nákladem
 * SVJ: patří na 315.2xx, kde čeká na vyúčtování. Výsledkový profil i služba bez
 * politiky ponechávají běžnou předkontaci. Platí jen v rámci vyhlášky 504.
 */
final class ServiceCostAccountPolicy
{
    public function __construct(private readonly Connection $db) {}

    /** Levná předběžná kontrola, ať firmy mimo rámec 504 nenačítají dimenze položek. */
    public function appliesTo(int $supplierId, string $date): bool
    {
        return AccountRoleResolver::fromConnection($this->db)->forDate($supplierId, $date)->framework === AccountingFrameworkService::CZ_504;
    }

    /**
     * @param array<int,array<int,int>> $itemDimensions id položky => typ dimenze => hodnota
     * @return array<int,string> id položky => účet 315.2xx (jen položky služeb v rozvahovém profilu)
     */
    public function forItems(int $supplierId, string $date, array $itemDimensions): array
    {
        $valueIds = [];
        foreach ($itemDimensions as $dims) {
            foreach ($dims as $valueId) {
                if ((int) $valueId > 0) $valueIds[(int) $valueId] = true;
            }
        }
        if ($valueIds === [] || !$this->appliesTo($supplierId, $date)) return [];

        $services = $this->servicesByDimension($supplierId, array_keys($valueIds));
        if ($services === []) return [];
        $accounts = [];
        foreach (array_unique($services) as $serviceId) {
            $account = $this->balanceSheetAccount($supplierId, $serviceId, $date);
            if ($account !== null) $accounts[$serviceId] = $account;
        }

        $out = [];
        foreach ($itemDimensions as $itemId => $dims) {
            $found = [];
            foreach ($dims as $valueId) {
                $serviceId = $services[(int) $valueId] ?? null;
                if ($serviceId !== null && isset($accounts[$serviceId])) $found[$accounts[$serviceId]] = true;
            }
            // Položka nesoucí dvě služby s různým účtem nemá jednoznačný cíl a zůstane na předkontaci.
            if (count($found) === 1) $out[(int) $itemId] = (string) array_key_first($found);
        }
        return $out;
    }

    /** @param list<int> $valueIds @return array<int,int> hodnota dimenze => služba */
    private function servicesByDimension(int $supplierId, array $valueIds): array
    {
        $marks = implode(',', array_fill(0, count($valueIds), '?'));
        $q = $this->db->pdo()->prepare(
            "SELECT dimension_value_id, id FROM property_objects
              WHERE supplier_id = ? AND kind = 'service' AND dimension_value_id IN ($marks)"
        );
        $q->execute([$supplierId, ...$valueIds]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['dimension_value_id']] = (int) $row['id'];
        return $out;
    }

    /**
     * Rozvahový profil platný k datu a účet 315.2xx z posledního případu služby; bez případu
     * jen jediná aktivní analytika 315.2xx osnovy, jinak účet nelze odvodit.
     */
    private function balanceSheetAccount(int $supplierId, int $serviceId, string $date): ?string
    {
        $q = $this->db->pdo()->prepare(
            'SELECT policy FROM property_service_policy_versions
              WHERE supplier_id = ? AND service_id = ? AND valid_from <= ?
              ORDER BY valid_from DESC LIMIT 1'
        );
        $q->execute([$supplierId, $serviceId, $date]);
        if ($q->fetchColumn() !== 'service_balance_sheet') return null;

        $pattern = OpeningRolePolicy::ROLES['service_cost'][2];
        $q = $this->db->pdo()->prepare(
            "SELECT JSON_VALUE(c.facts_json, '$.service_cost_account') AS account
               FROM property_service_lifecycle_cases c
              WHERE c.supplier_id = ? AND c.service_id = ? AND c.period_from <= ?
              ORDER BY c.period_to DESC, c.id DESC LIMIT 1"
        );
        $q->execute([$supplierId, $serviceId, $date]);
        $account = $q->fetchColumn();
        $candidates = is_string($account) && preg_match($pattern, $account) === 1 ? [$account] : [];
        if ($candidates === []) {
            $q = $this->db->pdo()->prepare(
                "SELECT account_code FROM chart_of_accounts
                  WHERE supplier_id = ? AND account_code LIKE '315.2%' AND account_type = 'asset' AND is_active = 1"
            );
            $q->execute([$supplierId]);
            $candidates = array_values(array_filter($q->fetchAll(PDO::FETCH_COLUMN), static fn (string $c): bool => preg_match($pattern, $c) === 1));
        }
        return count($candidates) === 1 ? $candidates[0] : null;
    }
}
