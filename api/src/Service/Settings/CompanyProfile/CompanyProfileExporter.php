<?php

declare(strict_types=1);

namespace MyInvoice\Service\Settings\CompanyProfile;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use PDO;

/**
 * Profil firmy ({@see CompanyProfileFormat}) z aktuálního nastavení firmy.
 *
 * Každý dotaz nese `supplier_id` firmy. Odkazy mezi záznamy se převádějí na přirozené
 * klíče (kód verze výkazu, kód dimenze, registrační značka vozu, kód střediska, IČO
 * klienta), ať profil jde nahrát do znovu založené firmy.
 */
final class CompanyProfileExporter
{
    private const SUPPLIER_BOOL_COLUMNS = [
        'stock_enabled', 'stock_auto_issue', 'dimensions_enabled',
        'tax_investment_incentive', 'tax_atad_cfc', 'tax_public_benefit', 'tax_cooperating_person',
        'tax_foreign_income_credit',
    ];

    private const SETTINGS_BOOL_COLUMNS = [
        'statutory_audit', 'manual_doc_series', 'fx_reversal_at_open', 'comparative_from_prior_year',
        'tax_authority_offset', 'single_analytic_redirect',
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<string>|null $sections jen tyto sekce (null = všechny)
     * @return array<string,mixed>
     */
    public function export(int $supplierId, ?array $sections = null): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($company === false) {
            throw new CompanyProfileException('not_found', 'Firma nenalezena.', null, 404);
        }

        $out = [];
        foreach (CompanyProfileFormat::SECTIONS as $name) {
            if ($sections !== null && !in_array($name, $sections, true)) {
                continue;
            }
            $data = match ($name) {
                'company' => $this->supplierColumns($supplierId, CompanyProfileFormat::COMPANY_COLUMNS),
                'tax_profile' => $this->supplierColumns($supplierId, CompanyProfileFormat::TAX_PROFILE_COLUMNS),
                'accounting_settings' => $this->accountingSettings($supplierId),
                'statement_overrides' => $this->statementOverrides($supplierId),
                'dimensions' => $this->dimensions($supplierId),
                'dimension_defaults' => $this->dimensionDefaults($supplierId),
                'dimension_rules' => $this->dimensionRules($supplierId),
                'posting_rules' => $this->postingRules($supplierId),
                'bank_rule_templates' => $this->bankRuleTemplates($supplierId),
                'bank_posting_rules' => $this->bankPostingRules($supplierId),
            };
            if ($data !== null) {
                $out[$name] = $data;
            }
        }

        $version = @file_get_contents(Bootstrap::rootDir() . '/VERSION');

        return [
            'format' => CompanyProfileFormat::FORMAT,
            'version' => CompanyProfileFormat::VERSION,
            'exported_at' => date('c'),
            'app_version' => $version === false ? null : (trim($version) ?: null),
            'company' => ['name' => (string) $company['company_name'], 'ic' => $company['ic'] === null ? null : (string) $company['ic']],
            'sections' => $out,
        ];
    }

    /**
     * @param list<string> $columns
     * @return array<string,mixed>
     */
    private function supplierColumns(int $supplierId, array $columns): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)) . ' FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = (array) $stmt->fetch(PDO::FETCH_ASSOC);
        foreach ($row as $column => $value) {
            if (in_array($column, self::SUPPLIER_BOOL_COLUMNS, true)) {
                $row[$column] = (bool) $value;
            }
        }

        return $row;
    }

    /** @return array<string,mixed>|null null = firma nastavení účetnictví nemá */
    private function accountingSettings(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . implode(', ', CompanyProfileFormat::ACCOUNTING_SETTINGS_COLUMNS)
            . ' FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach (self::SETTINGS_BOOL_COLUMNS as $column) {
            $row[$column] = (bool) $row[$column];
        }
        $row['avg_employees'] = $row['avg_employees'] === null ? null : (int) $row['avg_employees'];
        $row['small_asset_accrual_pct'] = $row['small_asset_accrual_pct'] === null ? null : (float) $row['small_asset_accrual_pct'];
        $row['tax_authority_offset_from_year'] = $row['tax_authority_offset_from_year'] === null ? null : (int) $row['tax_authority_offset_from_year'];
        $rows = is_string($row['net_turnover_extra_rows']) ? json_decode($row['net_turnover_extra_rows'], true) : null;
        $row['net_turnover_extra_rows'] = [
            'income_statement' => array_values((array) ($rows['income_statement'] ?? [])),
            'income_statement_purpose' => array_values((array) ($rows['income_statement_purpose'] ?? [])),
        ];

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function statementOverrides(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT v.statement_type, v.version_code, o.account_prefix, o.row_code, o.target, o.follows_prefix,
                    o.balance_condition, o.sign, o.valid_from_year, o.valid_to_year, o.note
               FROM statement_account_overrides o
               JOIN statement_versions v ON v.id = o.version_id
              WHERE o.supplier_id = ?
              ORDER BY v.statement_type, v.version_code, o.account_prefix, o.balance_condition, o.valid_from_year'
        );
        $stmt->execute([$supplierId]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = $r['statement_type'] . '|' . $r['version_code'];
            $groups[$key] ??= ['statement_type' => (string) $r['statement_type'], 'version_code' => (string) $r['version_code'], 'items' => []];
            $groups[$key]['items'][] = [
                'account_prefix' => (string) $r['account_prefix'],
                'row_code' => (string) $r['row_code'],
                'target' => (string) $r['target'],
                'follows_prefix' => $r['follows_prefix'] === null ? null : (string) $r['follows_prefix'],
                'balance_condition' => (string) $r['balance_condition'],
                'sign' => (int) $r['sign'],
                'valid_from_year' => $r['valid_from_year'] === null ? null : (int) $r['valid_from_year'],
                'valid_to_year' => $r['valid_to_year'] === null ? null : (int) $r['valid_to_year'],
                'note' => $r['note'],
            ];
        }

        return array_values($groups);
    }

    /**
     * Firemní typy dimenzí s hodnotami. Globální typy patří skupině firem, ne firmě:
     * smazání firmy je nezasáhne, proto v profilu nejsou.
     *
     * @return array{types: list<array<string,mixed>>}
     */
    private function dimensions(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $types = $pdo->prepare(
            'SELECT id, code, name, kind, is_active, show_on_documents, sort_order
               FROM dimension_types WHERE supplier_id = ? ORDER BY sort_order, code'
        );
        $types->execute([$supplierId]);
        $values = $pdo->prepare(
            "SELECT v.code, v.name, pv.code AS parent_code, v.is_active, v.responsible_note, v.note, v.sort_order,
                    c.registration AS car_registration, cc.code AS cost_center_code,
                    p.name AS project_name, p.project_number, cl.ic AS client_ic, cl.company_name AS client_name
               FROM dimension_values v
          LEFT JOIN dimension_values pv ON pv.id = v.parent_id
          LEFT JOIN cars c ON c.id = v.car_id AND c.supplier_id = v.supplier_id
          LEFT JOIN cost_centers cc ON cc.id = v.cost_center_id AND cc.supplier_id = v.supplier_id
          LEFT JOIN projects p ON p.id = v.project_id
          LEFT JOIN clients cl ON cl.id = p.client_id AND cl.supplier_id = v.supplier_id
              WHERE v.type_id = ? AND v.supplier_id = ?
              ORDER BY v.sort_order, v.code"
        );
        $out = [];
        foreach ($types->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $values->execute([(int) $t['id'], $supplierId]);
            $items = [];
            foreach ($values->fetchAll(PDO::FETCH_ASSOC) as $v) {
                $items[] = [
                    'code' => (string) $v['code'],
                    'name' => (string) $v['name'],
                    'parent_code' => $v['parent_code'] === null ? null : (string) $v['parent_code'],
                    'is_active' => (bool) $v['is_active'],
                    'responsible_note' => $v['responsible_note'],
                    'note' => $v['note'],
                    'sort_order' => (int) $v['sort_order'],
                    'car_registration' => $v['car_registration'],
                    'cost_center_code' => $v['cost_center_code'],
                    'project' => $v['project_name'] === null || $v['client_name'] === null ? null : [
                        'name' => (string) $v['project_name'],
                        'number' => $v['project_number'],
                        'client' => self::clientKey($v['client_ic'], (string) $v['client_name']),
                    ],
                ];
            }
            $out[] = [
                'code' => (string) $t['code'],
                'name' => (string) $t['name'],
                'kind' => (string) $t['kind'],
                'is_active' => (bool) $t['is_active'],
                'show_on_documents' => (bool) $t['show_on_documents'],
                'sort_order' => (int) $t['sort_order'],
                'values' => $items,
            ];
        }

        return ['types' => $out];
    }

    /** @return list<array<string,mixed>> */
    private function dimensionDefaults(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.code AS type_code, v.code AS value_code,
                    c.ic AS client_ic, c.company_name AS client_name,
                    p.name AS project_name, p.project_number, pc.ic AS project_client_ic, pc.company_name AS project_client_name,
                    si.sku AS product_sku, sc.code AS category_code,
                    sba.account_canonical AS bank_account, sba.bank_code_norm AS bank_code
               FROM dimension_defaults d
               JOIN dimension_types t ON t.id = d.dimension_type_id
               JOIN dimension_values v ON v.id = d.dimension_value_id
          LEFT JOIN clients c ON c.id = d.client_id AND c.supplier_id = d.supplier_id
          LEFT JOIN projects p ON p.id = d.project_id
          LEFT JOIN clients pc ON pc.id = p.client_id AND pc.supplier_id = d.supplier_id
          LEFT JOIN stock_items si ON si.id = d.product_id AND si.supplier_id = d.supplier_id
          LEFT JOIN stock_categories sc ON sc.id = d.product_category_id AND sc.supplier_id = d.supplier_id
          LEFT JOIN supplier_bank_accounts sba ON sba.id = d.supplier_bank_account_id AND sba.supplier_id = d.supplier_id
              WHERE d.supplier_id = ?
              ORDER BY d.client_id IS NULL, c.company_name, p.name, si.sku, sc.code, sba.account_canonical, sba.bank_code_norm, t.code"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['client_name'] !== null) {
                $out[] = [
                    'entity' => 'client',
                    'client' => self::clientKey($r['client_ic'], (string) $r['client_name']),
                    'type_code' => (string) $r['type_code'],
                    'value_code' => (string) $r['value_code'],
                ];
            } elseif ($r['project_name'] !== null && $r['project_client_name'] !== null) {
                $out[] = [
                    'entity' => 'project',
                    'project' => [
                        'name' => (string) $r['project_name'],
                        'number' => $r['project_number'],
                        'client' => self::clientKey($r['project_client_ic'], (string) $r['project_client_name']),
                    ],
                    'type_code' => (string) $r['type_code'],
                    'value_code' => (string) $r['value_code'],
                ];
            } elseif ($r['product_sku'] !== null) {
                // Produkt a kategorie (F1) se párují kódem — SKU je ve firmě unikátní.
                $out[] = [
                    'entity' => 'product',
                    'product' => ['sku' => (string) $r['product_sku']],
                    'type_code' => (string) $r['type_code'],
                    'value_code' => (string) $r['value_code'],
                ];
            } elseif ($r['category_code'] !== null) {
                $out[] = [
                    'entity' => 'product_category',
                    'product_category' => ['code' => (string) $r['category_code']],
                    'type_code' => (string) $r['type_code'],
                    'value_code' => (string) $r['value_code'],
                ];
            } elseif ($r['bank_account'] !== null) {
                // Vlastní bankovní účet se páruje číslem účtu a kódem banky (unikátní ve firmě).
                $out[] = [
                    'entity' => 'bank_account',
                    'bank_account' => ['account' => (string) $r['bank_account'], 'bank_code' => (string) $r['bank_code']],
                    'type_code' => (string) $r['type_code'],
                    'value_code' => (string) $r['value_code'],
                ];
            }
        }

        return $out;
    }

    /**
     * Pravidla dimenzí podle účtu z {@see DimensionRuleService::list()}. Výchozí hodnota
     * se odkazuje kódem, u hodnoty navázané na vůz navíc registrační značkou (vůz
     * znovu založený převodem může dostat hodnotu s jiným kódem).
     *
     * @return list<array<string,mixed>>
     */
    private function dimensionRules(int $supplierId): array
    {
        $plate = $this->db->pdo()->prepare(
            'SELECT c.registration FROM dimension_values v
               JOIN cars c ON c.id = v.car_id AND c.supplier_id = v.supplier_id
              WHERE v.id = ? AND v.supplier_id = ?'
        );
        $out = [];
        foreach ((new DimensionRuleService($this->db))->list($supplierId) as $r) {
            $registration = null;
            if ($r['default_value_id'] !== null) {
                $plate->execute([$r['default_value_id'], $supplierId]);
                $registration = $plate->fetchColumn();
                $registration = $registration === false || $registration === null ? null : (string) $registration;
            }
            $out[] = [
                'type_code' => $r['type_code'],
                'account_mask' => $r['account_mask'],
                'enforcement' => $r['enforcement'],
                'default_value_code' => $r['default_value_code'],
                'default_value_car_registration' => $registration,
                'default_from_card' => $r['default_from_card'],
                'valid_from' => $r['valid_from'],
                'valid_to' => $r['valid_to'],
                'is_active' => $r['is_active'],
                'note' => $r['note'],
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function postingRules(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT rule_key, priority, description, debit_account_code, credit_account_code, is_active
               FROM posting_rules WHERE supplier_id = ? ORDER BY rule_key, priority'
        );
        $stmt->execute([$supplierId]);

        return array_map(static fn (array $r): array => [
            'rule_key' => (string) $r['rule_key'],
            'priority' => (int) $r['priority'],
            'description' => (string) $r['description'],
            'debit_account_code' => $r['debit_account_code'],
            'credit_account_code' => $r['credit_account_code'],
            'is_active' => (bool) $r['is_active'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function bankRuleTemplates(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT template_key, name_cs, name_en, direction, operation_type, counterparty_bank, counterparty_prefix,
                    vs_placeholder, message_contains, rule_key, default_priority, sort_order, is_active
               FROM bank_rule_templates WHERE supplier_id = ? ORDER BY sort_order, template_key'
        );
        $stmt->execute([$supplierId]);

        return array_map(static function (array $r): array {
            $r['default_priority'] = (int) $r['default_priority'];
            $r['sort_order'] = (int) $r['sort_order'];
            $r['is_active'] = (bool) $r['is_active'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function bankPostingRules(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT name, direction, counterparty_account, counterparty_bank, counterparty_prefix, variable_symbol,
                    message_contains, amount_min, amount_max, applies_currency, debit_account_code, credit_account_code,
                    description, priority, operation_type, auto_amount_cap, system_template_key, mode, is_active
               FROM bank_posting_rules WHERE supplier_id = ? ORDER BY priority, id'
        );
        $stmt->execute([$supplierId]);

        return array_map(static function (array $r): array {
            foreach (['amount_min', 'amount_max', 'auto_amount_cap'] as $k) {
                $r[$k] = $r[$k] === null ? null : (float) $r[$k];
            }
            $r['priority'] = (int) $r['priority'];
            $r['is_active'] = (bool) $r['is_active'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{ic:?string,name:string} */
    private static function clientKey(mixed $ic, string $name): array
    {
        $ic = trim((string) ($ic ?? ''));

        return ['ic' => $ic === '' ? null : $ic, 'name' => $name];
    }
}
