<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Kde všude visí kód účtu z osnovy — podklad pro smazání analytiky.
 *
 * Kód účtu je napříč aplikací TEXTOVÝ klíč (ne FK): kontace, bankovní pravidla,
 * pokladny, karty majetku, mzdové nastavení i pravidla klasifikace nákladů drží
 * `account_code` jako řetězec. Tenhle seznam je proto jediná pojistka proti tomu,
 * aby po smazání účtu zůstal někde odkaz na neexistující kód — a musí odpovídat
 * migraci 1322, která tytéž sloupce přepisovala na tečkovaný tvar.
 *
 * Kontroluje se jak vazba přes id (`journal_entry_lines.account_id`), tak přes
 * text (`account_code`) — mazat se smí jen účet, na kterém nevisí ani jedno.
 */
final class ChartAccountUsage
{
    /**
     * Sloupce s kódem účtu jako textem: tabulka => [sloupce].
     * Zdroj pravdy je migrace 1322 (tytéž sloupce), plus počáteční stavy aktivace.
     */
    private const CODE_COLUMNS = [
        'posting_rules'                    => ['debit_account_code', 'credit_account_code'],
        'bank_posting_rules'               => ['debit_account_code', 'credit_account_code'],
        'bank_posting_suggestions'         => ['debit_account_code', 'credit_account_code'],
        'cash_registers'                   => ['account_code'],
        'cash_documents'                   => ['counter_account_code'],
        'assets'                           => ['acquisition_account_code', 'accumulated_account_code', 'asset_account_code'],
        'expense_classification_rules'     => ['target_account_code'],
        'revenue_classification_rules'     => ['target_account_code'],
        'accounting_opening_balances'      => ['account_code'],
        'accounting_balance_inventory_items' => ['account_code'],
        'accounting_supplier_settings'     => ['fuel_account_code', 'vehicle_repair_account_code'],
        'purchase_invoice_vat_allocations' => ['account_code'],
        'other_items'                      => ['account_code', 'counter_account_code'],
        'other_item_posting_lines'        => ['account_code'],
        'other_item_posting_profiles'     => ['debit_account_code', 'credit_account_code'],
        'other_item_posting_overrides'    => ['debit_account_code', 'credit_account_code'],
        'payroll_dimensions'               => ['default_account_code'],
        'payroll_employees'                => ['net_settlement_account_code'],
        'payroll_employee_profiles'        => ['partner_settlement_account_code'],
        'payroll_posting_allocations'      => ['account_code'],
        'payroll_employer_settings'        => [
            'employment_gross_debit_account', 'employment_gross_credit_account',
            'statutory_gross_debit_account', 'statutory_gross_credit_account',
            'partner_gross_debit_account', 'partner_gross_credit_account',
            'social_insurance_credit_account', 'health_insurance_credit_account',
            'income_tax_credit_account', 'withholding_tax_credit_account',
            'other_deductions_credit_account', 'enforcement_deductions_credit_account',
            'enforcement_fee_revenue_credit_account',
            'employer_insurance_debit_account', 'partner_settlement_credit_account',
            'risky_savings_debit_account', 'risky_savings_credit_account',
            'employee_receivable_debit_account',
            'non_deductible_benefit_debit_account', 'travel_expense_debit_account',
        ],
    ];

    /**
     * Cizí klíče na účet přes id: tabulka => [sloupce, název místa]. Nastavení GoPay se
     * neuvolňuje samo, tiše by přestalo fungovat zaúčtování plateb.
     *
     * @var array<string, array{0:list<string>, 1:string}>
     */
    private const ID_COLUMNS = [
        'gopay_settings' => [
            ['clearing_account_id', 'destination_bank_account_id', 'fee_account_id', 'gopay_account_id', 'receivable_account_id'],
            'nastavení GoPay',
        ],
        'invoice_settlements' => [['account_id'], 'zápočty faktur'],
        'nonprofit_membership_charge_operations' => [['receivable_account_id', 'revenue_account_id'], 'členské příspěvky'],
    ];

    /**
     * Tabulky bez vlastního `supplier_id` — firmu drží až rodič. Bez JOINu by
     * kontrola hlásila použití cizí firmy, nebo naopak žádné.
     *
     * @var array<int, array{sql:string, label:string}>
     */
    private const JOINED_CHECKS = [
        [
            'sql'   => 'SELECT EXISTS (SELECT 1 FROM journal_entry_template_lines l
                                         JOIN journal_entry_templates t ON t.id = l.template_id
                                        WHERE t.supplier_id = ? AND l.account_code = ?)',
            'label' => 'šablony účetních zápisů',
        ],
        [
            'sql'   => 'SELECT EXISTS (SELECT 1 FROM purchase_invoice_items i
                                         JOIN purchase_invoices p ON p.id = i.purchase_invoice_id
                                        WHERE p.supplier_id = ? AND i.expense_account_code = ?)',
            'label' => 'položky přijatých faktur',
        ],
        // Protiúčet řádku (zaúčtování při zadání faktury) drží účet stejně jako účet nákladu.
        [
            'sql'   => 'SELECT EXISTS (SELECT 1 FROM purchase_invoice_items i
                                         JOIN purchase_invoices p ON p.id = i.purchase_invoice_id
                                        WHERE p.supplier_id = ? AND i.counter_account_code = ?)',
            'label' => 'položky přijatých faktur',
        ],
        [
            'sql'   => 'SELECT EXISTS (SELECT 1 FROM invoice_items i
                                         JOIN invoices v ON v.id = i.invoice_id
                                        WHERE v.supplier_id = ? AND i.counter_account_code = ?)',
            'label' => 'položky vydaných faktur',
        ],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Vrátí seznam míst, která účet drží. Prázdné pole = účet jde smazat.
     *
     * @return list<string> lidsky čitelné názvy míst (bez duplicit)
     */
    public function usages(int $supplierId, int $accountId, string $accountCode): array
    {
        $pdo = $this->db->pdo();
        $found = [];

        $stmt = $pdo->prepare(
            'SELECT EXISTS (SELECT 1 FROM journal_entry_lines l
                              JOIN journal_entries e ON e.id = l.entry_id
                             WHERE e.supplier_id = ? AND l.account_id = ?)'
        );
        $stmt->execute([$supplierId, $accountId]);
        if ((bool) $stmt->fetchColumn()) {
            $found[] = 'účetní deník';
        }

        // Dítě v osnově drží účet přes parent_id — smazáním by osiřelo.
        $stmt = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? AND parent_id = ?)');
        $stmt->execute([$supplierId, $accountId]);
        if ((bool) $stmt->fetchColumn()) {
            $found[] = 'podřízené analytiky';
        }

        foreach (self::ID_COLUMNS as $table => [$columns, $label]) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $conditions = implode(' OR ', array_map(static fn (string $c): string => $c . ' = ?', $columns));
            $stmt = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM {$table} WHERE supplier_id = ? AND ({$conditions}))");
            $stmt->execute(array_merge([$supplierId], array_fill(0, count($columns), $accountId)));
            if ((bool) $stmt->fetchColumn()) {
                $found[] = $label;
            }
        }

        foreach (self::CODE_COLUMNS as $table => $columns) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $conditions = implode(' OR ', array_map(static fn (string $c): string => $c . ' = ?', $columns));
            $stmt = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM {$table} WHERE supplier_id = ? AND ({$conditions}))");
            $stmt->execute(array_merge([$supplierId], array_fill(0, count($columns), $accountCode)));
            if ((bool) $stmt->fetchColumn()) {
                $found[] = self::label($table);
            }
        }

        foreach (self::JOINED_CHECKS as $check) {
            $stmt = $pdo->prepare($check['sql']);
            $stmt->execute([$supplierId, $accountCode]);
            if ((bool) $stmt->fetchColumn()) {
                $found[] = $check['label'];
            }
        }

        return array_values(array_unique($found));
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM information_schema.tables
                             WHERE table_schema = DATABASE() AND table_name = ?)'
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    private static function label(string $table): string
    {
        return match ($table) {
            'posting_rules'                => 'kontace',
            'bank_posting_rules'           => 'kontace bankovních pohybů',
            'cash_registers'               => 'pokladna',
            'assets'                       => 'karty majetku',
            'expense_classification_rules' => 'pravidla klasifikace nákladů',
            'revenue_classification_rules' => 'pravidla výnosů',
            'accounting_opening_balances'  => 'počáteční stavy',
            'payroll_employer_settings', 'payroll_dimensions', 'payroll_employees',
            'payroll_employee_profiles', 'payroll_posting_allocations' => 'mzdy',
            'bank_posting_suggestions'     => 'návrhy zaúčtování banky',
            'cash_documents'               => 'pokladní doklady',
            'accounting_balance_inventory_items' => 'inventura zůstatků',
            'accounting_supplier_settings' => 'nastavení účetnictví',
            'purchase_invoice_vat_allocations' => 'rozpad DPH přijatých faktur',
            'other_items'                 => 'ostatní pohledávky a závazky',
            'other_item_posting_lines'    => 'kontace ostatních pohledávek a závazků',
            'other_item_posting_profiles' => 'účetní profily druhů ostatních položek',
            'other_item_posting_overrides' => 'vlastní kontace úhrad ostatních položek',
            default                       => $table,
        };
    }
}
