<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PostingRuleRepository;
use PDO;

/**
 * Nedotčená směrná osnova 500 nové firmy a její výměna za osnovu 504.
 *
 * Firma založená jako právnická osoba dostane osnovu 500 hned při založení
 * ({@see \MyInvoice\Service\Supplier\SupplierInitializer}). Dokud ji nikdo
 * nezměnil a nic ji nepoužívá, nese jen šablonu, takže ji lze bez převodu
 * zůstatků nahradit šablonou 504. Jakákoli stopa práce (zápis v deníku,
 * vlastní či upravený účet, datovaná definice, odkaz z dokladu, předkontace
 * nebo nastavení) výměnu zakáže a firma jde přes řízený převod rámce.
 *
 * Seznamy odkazů níže hlídá `PristineChart500CoverageTest` proti
 * information_schema: nový sloupec s kódem účtu nebo cizí klíč na osnovu
 * test shodí, dokud se sem nedoplní.
 */
final class PristineChart500
{
    /** Cizí klíče na chart_of_accounts.id: tabulka => sloupce. */
    public const ACCOUNT_ID_REFERENCES = [
        'accounting_account_definition_history' => ['account_id'],
        'card_clearing_settings' => ['writeoff_account_id', 'holder_account_id', 'rounding_gain_account_id', 'fx_gain_account_id', 'rounding_loss_account_id', 'fx_loss_account_id'],
        'credit_card_settings' => ['repayment_account_id', 'interest_account_id', 'reward_account_id', 'opening_account_id', 'writeoff_nontax_account_id', 'cash_account_id', 'private_account_id', 'writeoff_tax_account_id', 'fee_account_id'],
        'dimension_account_map' => ['analytic_account_id', 'synthetic_account_id'],
        'gopay_settings' => ['destination_bank_account_id', 'fee_account_id', 'gopay_account_id', 'clearing_account_id', 'receivable_account_id'],
        'invoice_settlements' => ['account_id'],
        'journal_entry_lines' => ['account_id'],
        'journal_line_pairings' => ['account_id'],
        'journal_settlement_allocations' => ['account_id'],
        'nonprofit_membership_charge_operations' => ['receivable_account_id', 'revenue_account_id'],
    ];

    /** Kódy účtů v tabulkách se sloupcem supplier_id: tabulka => sloupce. */
    public const ACCOUNT_CODE_REFERENCES = [
        'accounting_balance_inventory_items' => ['account_code'],
        'accounting_opening_balances' => ['account_code'],
        'assets' => ['asset_account_code', 'accumulated_account_code', 'acquisition_account_code', 'summary_account_code'],
        'bank_posting_rules' => ['debit_account_code', 'credit_account_code'],
        'bank_posting_suggestions' => ['debit_account_code', 'credit_account_code'],
        'cash_documents' => ['counter_account_code'],
        'cash_registers' => ['account_code'],
        'expense_classification_rules' => ['target_account_code'],
        'revenue_classification_rules' => ['target_account_code'],
        'internal_document_lines' => ['account_code'],
        'other_items' => ['account_code', 'counter_account_code'],
        'other_item_posting_lines' => ['account_code'],
        'other_item_posting_profiles' => ['debit_account_code', 'credit_account_code'],
        'other_item_posting_overrides' => ['debit_account_code', 'credit_account_code'],
        'payroll_dimensions' => ['default_account_code'],
        'payroll_employees' => ['net_settlement_account_code'],
        'payroll_employee_profiles' => ['partner_settlement_account_code'],
        'payroll_posting_allocations' => ['account_code'],
        'property_funding_effects' => ['account_code'],
        'purchase_invoice_vat_allocations' => ['account_code'],
        'stock_categories' => ['revenue_account_code', 'expense_account_code'],
        'stock_items' => ['revenue_account_code', 'expense_account_code'],
        'unit_charge_components' => ['account_code'],
    ];

    /**
     * Kódy účtů ve firemním nastavení (tabulka supplier, klíčem je id firmy).
     * Vyplněný účet je použití osnovy, takže osnova už nedotčená není.
     */
    public const SUPPLIER_CODE_REFERENCES = ['receipt_payable_account_code'];

    /** Kódy účtů v řádcích dokladů bez supplier_id: tabulka => [sloupec, rodič, FK na rodiče]. */
    public const CHILD_CODE_REFERENCES = [
        'invoice_items' => ['revenue_account_code', 'invoices', 'invoice_id'],
        'purchase_invoice_items' => ['expense_account_code', 'purchase_invoices', 'purchase_invoice_id'],
        'recurring_invoice_template_items' => ['revenue_account_code', 'recurring_invoice_templates', 'template_id'],
        'journal_entry_template_lines' => ['account_code', 'journal_entry_templates', 'template_id'],
    ];

    /**
     * Sloupce s kódem účtu, které nejsou odkazem na osnovu firmy: vlastní
     * osnova, globální katalog bez firmy, kód pro export do POHODY a nastavení
     * účetnictví, které se kontroluje zvlášť proti předvolbám seederu.
     */
    public const NOT_CHART_REFERENCES = [
        'chart_of_accounts.account_code',
        'expense_keyword_catalog.target_account_code',
        'supplier.pohoda_account_code',
        'accounting_supplier_settings.fuel_account_code',
        'accounting_supplier_settings.vehicle_repair_account_code',
        'posting_rules.debit_account_code',
        'posting_rules.credit_account_code',
    ];

    public function __construct(private readonly Connection $db) {}

    /** Current read: je osnova firmy přesně nedotčená šablona 500 bez jakéhokoli použití? */
    public function isPristineForUpdate(int $supplierId): bool
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Kontrola nedotčené osnovy vyžaduje transakci.');
        }

        if ($this->exists('SELECT id FROM journal_entries WHERE supplier_id = ? LIMIT 1 FOR UPDATE', [$supplierId])) {
            return false;
        }
        if (!$this->chartEqualsTemplate($supplierId)) {
            return false;
        }
        foreach (self::ACCOUNT_ID_REFERENCES as $table => $columns) {
            foreach ($columns as $column) {
                $sql = "SELECT 1 FROM {$table}
                         WHERE {$column} IN (SELECT id FROM chart_of_accounts WHERE supplier_id = ?)
                         LIMIT 1";
                if ($this->exists($sql, [$supplierId])) {
                    return false;
                }
            }
        }
        foreach (self::ACCOUNT_CODE_REFERENCES as $table => $columns) {
            $any = implode(' OR ', array_map(static fn (string $c): string => "{$c} IS NOT NULL", $columns));
            if ($this->exists("SELECT 1 FROM {$table} WHERE supplier_id = ? AND ({$any}) LIMIT 1", [$supplierId])) {
                return false;
            }
        }
        foreach (self::CHILD_CODE_REFERENCES as $table => [$column, $parent, $foreignKey]) {
            $sql = "SELECT 1 FROM {$parent} p JOIN {$table} c ON c.{$foreignKey} = p.id
                     WHERE p.supplier_id = ? AND c.{$column} IS NOT NULL LIMIT 1";
            if ($this->exists($sql, [$supplierId])) {
                return false;
            }
        }
        $any = implode(' OR ', array_map(static fn (string $c): string => "{$c} IS NOT NULL", self::SUPPLIER_CODE_REFERENCES));
        if ($this->exists("SELECT 1 FROM supplier WHERE id = ? AND ({$any}) LIMIT 1", [$supplierId])) {
            return false;
        }

        return $this->onlySeededPostingRules($supplierId) && $this->onlySeededSettings($supplierId);
    }

    /**
     * Smaže nedotčenou osnovu 500 i předvolby, které k ní založil seeder,
     * a naseeduje osnovu podle rámce účinného k datu (volající ho už přepnul na 504).
     */
    public function replaceWithFrameworkTemplate(int $supplierId, string $date): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Výměna osnovy vyžaduje transakci.');
        }
        foreach (ChartOfAccountsSeeder::seededPostingRuleOverrides() as $ruleKey => [$debit, $credit]) {
            $pdo->prepare(
                'DELETE FROM posting_rules
                  WHERE supplier_id = ? AND rule_key = ? AND debit_account_code = ? AND credit_account_code = ?'
            )->execute([$supplierId, $ruleKey, $debit, $credit]);
        }
        foreach (ChartOfAccountsSeeder::SEEDED_SETTINGS_CODES as $column => $code) {
            $pdo->prepare(
                "UPDATE accounting_supplier_settings SET {$column} = NULL WHERE supplier_id = ? AND {$column} = ?"
            )->execute([$supplierId, $code]);
        }
        // Analytiky před syntetikami: parent_id je ON DELETE SET NULL, pořadí jen šetří přepisy.
        $pdo->prepare('DELETE FROM chart_of_accounts WHERE supplier_id = ? AND parent_id IS NOT NULL')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM chart_of_accounts WHERE supplier_id = ?')->execute([$supplierId]);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($supplierId, $date);
    }

    private function chartEqualsTemplate(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.account_code, c.name, c.account_type, c.normal_side, c.is_synthetic, c.is_active,
                    c.tax_deductibility, c.is_clearing, p.account_code AS parent_code
               FROM chart_of_accounts c
               LEFT JOIN chart_of_accounts p ON p.id = c.parent_id AND p.supplier_id = c.supplier_id
              WHERE c.supplier_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId]);
        $actual = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $actual[(string) $row['account_code']] = [
                (string) $row['name'],
                (string) $row['account_type'],
                $row['normal_side'] === null ? null : (string) $row['normal_side'],
                (int) $row['is_synthetic'],
                (int) $row['is_active'],
                (string) $row['tax_deductibility'],
                (int) $row['is_clearing'],
                $row['parent_code'] === null ? null : (string) $row['parent_code'],
            ];
        }
        $expected = [];
        foreach (ChartOfAccountsTemplate::ACCOUNTS as $account) {
            $parent = $account['parent_code'] ?? null;
            $expected[$account['code']] = [
                $account['name'],
                $account['type'],
                $account['normal_side'],
                $parent === null ? 1 : 0,
                1,
                ChartOfAccountsTemplate::taxDeductibility($account['code']),
                ChartOfAccountsSeeder::isClearingCode($account['code']) ? 1 : 0,
                $parent,
            ];
        }
        ksort($actual, SORT_STRING);
        ksort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function onlySeededPostingRules(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT rule_key, debit_account_code, credit_account_code, priority, is_active
               FROM posting_rules WHERE supplier_id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId]);
        $seeded = ChartOfAccountsSeeder::seededPostingRuleOverrides();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $expected = $seeded[(string) $row['rule_key']] ?? null;
            if ($expected === null
                || [(string) $row['debit_account_code'], (string) $row['credit_account_code']] !== $expected
                || (int) $row['priority'] !== PostingRuleRepository::OVERRIDE_PRIORITY
                || (int) $row['is_active'] !== 1
            ) {
                return false;
            }
        }
        return true;
    }

    private function onlySeededSettings(int $supplierId): bool
    {
        $columns = array_keys(ChartOfAccountsSeeder::SEEDED_SETTINGS_CODES);
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . implode(', ', $columns) . ' FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (ChartOfAccountsSeeder::SEEDED_SETTINGS_CODES as $column => $code) {
                if ($row[$column] !== null && (string) $row[$column] !== $code) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param list<int|string> $params */
    private function exists(string $sql, array $params): bool
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }
}
