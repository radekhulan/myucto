<?php

declare(strict_types=1);

namespace MyInvoice\Service\Settings\CompanyProfile;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\DimensionDefaultRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\StatementOverrideRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingRuleValidator;
use MyInvoice\Service\Accounting\Bank\BankRuleTemplateValidator;
use MyInvoice\Service\Accounting\Dimension\DimensionAccountMask;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\DimensionValueHistoryGuard;
use MyInvoice\Service\Accounting\Learning\RulePromotionService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\StatementOverrideService;
use MyInvoice\Service\Tax\Return\TaxpayerTypeCodebook;
use MyInvoice\Service\Supplier\SupplierPersonTitle;
use PDO;

/**
 * Nahraje profil firmy ({@see CompanyProfileFormat}) do firmy.
 *
 * Každá sekce jde přes validaci, kterou používá i běžné UI (výjimky mapování přes
 * {@see StatementOverrideService::validateSet()}, dimenze přes {@see DimensionService},
 * pravidla dimenzí podle účtu přes {@see DimensionRuleService}, pravidla banky přes {@see BankPostingRuleValidator}, šablony přes
 * {@see BankRuleTemplateValidator}); automatiku pravidla banky zapíná jen auditované
 * povýšení {@see RulePromotionService::promote()}.
 *
 * Celé nahrání běží v jedné transakci (uvnitř cizí transakce v savepointu): chyba
 * v kterékoli sekci nezapíše nic. Zkouška nanečisto provede totéž a na konci vrátí
 * změny zpět, takže náhled ukazuje přesně to, co by udělalo ostré nahrání.
 */
final class CompanyProfileImporter
{
    private const SAVEPOINT = 'company_profile_import';

    private const TAX_PROFILE_TEXT_LENGTHS = [
        'financial_office_code' => 8, 'workplace_code' => 8, 'cz_nace_code' => 8,
        'sest_jmeno' => 100, 'sest_prijmeni' => 100, 'sest_telefon' => 40, 'sest_email' => 120, 'sest_funkce' => 80,
        'opr_jmeno' => 60, 'opr_prijmeni' => 60, 'opr_postaveni' => 60,
    ];

    private const TAX_PROFILE_FLAGS = [
        'tax_investment_incentive', 'tax_atad_cfc', 'tax_public_benefit', 'tax_cooperating_person', 'tax_foreign_income_credit',
    ];

    private const SETTINGS_FLAGS = [
        'statutory_audit', 'manual_doc_series', 'fx_reversal_at_open', 'comparative_from_prior_year',
        'tax_authority_offset', 'single_analytic_redirect',
    ];

    /** Pole pravidla banky porovnávaná při úpravě existujícího pravidla. */
    private const BANK_RULE_FIELDS = [
        'name', 'debit_account_code', 'credit_account_code', 'description', 'priority', 'operation_type',
        'auto_amount_cap', 'applies_currency', 'amount_min', 'amount_max', 'is_active',
    ];

    /** @var array<string,array{created:int,updated:int,unchanged:int,removed:int,changes:list<string>,warnings:list<string>}> */
    private array $report = [];

    public function __construct(
        private readonly Connection $db,
        private readonly StatementOverrideRepository $overrides,
        private readonly StatementOverrideService $overrideService,
        private readonly DimensionRepository $dimensionRepo,
        private readonly DimensionService $dimensions,
        private readonly DimensionDefaultRepository $dimensionDefaults,
        private readonly BankPostingRuleValidator $bankRules,
        private readonly BankPostingRuleRepository $bankRuleRepo,
        private readonly RulePromotionService $promotion,
        private readonly BankRuleTemplateValidator $templates,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly DimensionRuleService $dimensionRules,
    ) {}

    /**
     * @param list<string>|null $only jen tyto sekce (null = všechny, které profil má)
     * @return array{dry_run:bool, changed:int, warnings:list<string>, sections:array<string,array<string,mixed>>}
     */
    public function import(int $supplierId, mixed $profile, bool $dryRun, ?array $only = null, ?int $userId = null): array
    {
        $sections = CompanyProfileFormat::sections($profile, $only);
        $warnings = [];
        foreach (CompanyProfileFormat::unknownSections($profile) as $name) {
            $warnings[] = sprintf('Sekci „%s" tahle verze aplikace nezná, přeskočena.', $name);
        }
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ic = $stmt->fetchColumn();
        if ($ic === false) {
            throw new CompanyProfileException('not_found', 'Firma nenalezena.', null, 404);
        }
        $profileIc = trim((string) ($profile['company']['ic'] ?? ''));
        if ($profileIc !== '' && $profileIc !== trim((string) $ic)) {
            $warnings[] = sprintf('Profil je z firmy s IČO %s, nahrává se do firmy s IČO %s.', $profileIc, trim((string) $ic) ?: '(bez IČO)');
        }

        $this->report = [];
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        $nested ? $pdo->exec('SAVEPOINT ' . self::SAVEPOINT) : $pdo->beginTransaction();
        try {
            if (isset($sections['dimensions'])) {
                // První průchod upravuje metadata hodnot, druhý jejich rodiče. Období musí předcházet oběma.
                (new DimensionValueHistoryGuard($this->db))->lockPeriods();
            }
            foreach ($sections as $name => $data) {
                $this->report[$name] = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'changes' => [], 'warnings' => []];
                try {
                    match ($name) {
                        'company' => $this->importCompany($supplierId, $data),
                        'tax_profile' => $this->importTaxProfile($supplierId, $data),
                        'accounting_settings' => $this->importAccountingSettings($supplierId, $data),
                        'statement_overrides' => $this->importStatementOverrides($supplierId, $data, $userId),
                        'dimensions' => $this->importDimensions($supplierId, $data),
                        'dimension_defaults' => $this->importDimensionDefaults($supplierId, $data),
                        'dimension_rules' => $this->importDimensionRules($supplierId, $data, $userId),
                        'posting_rules' => $this->importPostingRules($supplierId, $data),
                        'bank_rule_templates' => $this->importBankRuleTemplates($supplierId, $data),
                        'bank_posting_rules' => $this->importBankPostingRules($supplierId, $data, $userId),
                    };
                } catch (ReportException | DimensionException | PostingException $e) {
                    throw new CompanyProfileException($e->errorCode, $e->getMessage(), $name, $e->httpStatus);
                } catch (CompanyProfileException $e) {
                    throw $e->section !== null ? $e : new CompanyProfileException($e->errorCode, $e->getMessage(), $name, $e->httpStatus);
                }
            }
            if ($dryRun) {
                $nested ? $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT) : $pdo->rollBack();
            } else {
                $nested ? $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT) : $pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $pdo->inTransaction()) {
                $nested ? $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT) : $pdo->rollBack();
            }
            throw $e;
        }

        $changed = 0;
        foreach ($this->report as $r) {
            $changed += $r['created'] + $r['updated'] + $r['removed'];
        }

        return ['dry_run' => $dryRun, 'changed' => $changed, 'warnings' => $warnings, 'sections' => $this->report];
    }

    // ── volby firmy, daňový profil, účetní nastavení ────────────────────────────

    /** @param array<string,mixed> $data */
    private function importCompany(int $supplierId, array $data): void
    {
        $values = [];
        foreach ($this->known('company', $data, CompanyProfileFormat::COMPANY_COLUMNS) as $column => $value) {
            if ($column === 'stock_in_transit_from') {
                if (!in_array($value, ['sent', 'confirmed'], true)) {
                    throw new CompanyProfileException('validation_failed', "stock_in_transit_from musí být 'sent' nebo 'confirmed'.");
                }
                $values[$column] = $value;
            } else {
                $values[$column] = self::flag($value, $column) ? 1 : 0;
            }
        }
        $this->updateSupplier($supplierId, 'company', $values);
    }

    /** @param array<string,mixed> $data */
    private function importTaxProfile(int $supplierId, array $data): void
    {
        $values = [];
        foreach ($this->known('tax_profile', $data, CompanyProfileFormat::TAX_PROFILE_COLUMNS) as $column => $value) {
            if (in_array($column, self::TAX_PROFILE_FLAGS, true)) {
                $values[$column] = self::flag($value, $column) ? 1 : 0;
                continue;
            }
            if ($column === 'person_title') {
                try {
                    $values[$column] = SupplierPersonTitle::normalize($value);
                } catch (\InvalidArgumentException $e) {
                    throw new CompanyProfileException('validation_failed', $e->getMessage());
                }
                continue;
            }
            $text = trim((string) ($value ?? ''));
            switch ($column) {
                case 'epo_taxpayer_code':
                    if ($text !== '' && !TaxpayerTypeCodebook::isValidTaxpayerType($text)) {
                        throw new CompanyProfileException('validation_failed', 'epo_taxpayer_code musí být číslice 0-9 podle číselníku typ_popldpp.');
                    }
                    $values[$column] = $text === '' ? null : $text;
                    break;
                case 'tax_entity_status':
                    if (!array_key_exists($text, TaxpayerTypeCodebook::ENTITY_STATUSES)) {
                        throw new CompanyProfileException('validation_failed', "tax_entity_status musí být 'normal', 'liquidation', 'insolvency' nebo 'transformation'.");
                    }
                    $values[$column] = $text;
                    break;
                case 'tax_accounting_decree':
                    if (!array_key_exists($text, TaxpayerTypeCodebook::ACCOUNTING_DECREES)) {
                        throw new CompanyProfileException('validation_failed', 'tax_accounting_decree musí být číslo účetní vyhlášky (500/501/502/503/504/325/410).');
                    }
                    $values[$column] = $text;
                    break;
                case 'tax_entity_status_date':
                    if ($text !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
                        throw new CompanyProfileException('validation_failed', 'tax_entity_status_date musí být datum ve tvaru RRRR-MM-DD.');
                    }
                    $values[$column] = $text === '' ? null : $text;
                    break;
                default:
                    $max = self::TAX_PROFILE_TEXT_LENGTHS[$column];
                    if (mb_strlen($text) > $max) {
                        throw new CompanyProfileException('validation_failed', sprintf('%s je delší než %d znaků.', $column, $max));
                    }
                    $values[$column] = $text === '' ? null : $text;
            }
        }
        $this->updateSupplier($supplierId, 'tax_profile', $values);
    }

    /** @param array<string,mixed> $data */
    private function importAccountingSettings(int $supplierId, array $data): void
    {
        $section = 'accounting_settings';
        $data = $this->known($section, $data, CompanyProfileFormat::ACCOUNTING_SETTINGS_COLUMNS);
        $values = [];
        foreach ($data as $column => $value) {
            if (in_array($column, self::SETTINGS_FLAGS, true)) {
                $values[$column] = self::flag($value, $column) ? 1 : 0;
            }
        }
        if (array_key_exists('avg_employees', $data)) {
            $v = $data['avg_employees'];
            if ($v !== null && $v !== '' && (!is_numeric($v) || (int) $v != $v || (int) $v < 0)) {
                throw new CompanyProfileException('validation_failed', 'avg_employees musí být celé číslo ≥ 0, nebo null.');
            }
            $values['avg_employees'] = $v === null || $v === '' ? null : (int) $v;
        }
        if (array_key_exists('statement_scope_override', $data)) {
            $v = $data['statement_scope_override'];
            if ($v !== null && $v !== '' && !in_array($v, ['full', 'small', 'micro'], true)) {
                throw new CompanyProfileException('validation_failed', "statement_scope_override musí být 'full', 'small', 'micro', nebo null.");
            }
            $values['statement_scope_override'] = $v === '' ? null : $v;
        }
        if (array_key_exists('fx_rate_mode', $data)) {
            if (!in_array($data['fx_rate_mode'], ['daily', 'fixed_monthly', 'fixed_annual'], true)) {
                throw new CompanyProfileException('validation_failed', "fx_rate_mode musí být 'daily', 'fixed_monthly' nebo 'fixed_annual'.");
            }
            $values['fx_rate_mode'] = $data['fx_rate_mode'];
        }
        if (array_key_exists('small_asset_accrual_mode', $data)) {
            $mode = $data['small_asset_accrual_mode'];
            if (!in_array($mode, ['none', 'pro_rata', 'flat_pct'], true)) {
                throw new CompanyProfileException('validation_failed', "small_asset_accrual_mode musí být 'none', 'pro_rata' nebo 'flat_pct'.");
            }
            $pct = null;
            if ($mode === 'flat_pct') {
                $raw = $data['small_asset_accrual_pct'] ?? null;
                if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
                    throw new CompanyProfileException('validation_failed', 'small_asset_accrual_pct je u režimu flat_pct povinné (0–100).');
                }
                $pct = round((float) $raw, 2);
            }
            $values['small_asset_accrual_mode'] = $mode;
            $values['small_asset_accrual_pct'] = $pct;
        }
        if (array_key_exists('tax_authority_offset_from_year', $data)) {
            $v = $data['tax_authority_offset_from_year'];
            if ($v !== null && $v !== '' && (!is_numeric($v) || (int) $v != $v || (int) $v < 1900 || (int) $v > 2999)) {
                throw new CompanyProfileException('validation_failed', 'tax_authority_offset_from_year musí být rok (1900–2999), nebo null.');
            }
            $values['tax_authority_offset_from_year'] = $v === null || $v === '' ? null : (int) $v;
        }
        // Rok souhrnného vykázání bez zapnuté volby nemá význam (stejně jako Nastavení uzávěrky).
        if (array_key_exists('tax_authority_offset', $values) && $values['tax_authority_offset'] === 0) {
            $values['tax_authority_offset_from_year'] = null;
        }
        $chart = null;
        foreach (['fuel_account_code', 'vehicle_repair_account_code'] as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $code = trim((string) ($data[$column] ?? ''));
            if ($code === '') {
                $values[$column] = null;
                continue;
            }
            $chart ??= $this->accounts->codeToIdMap($supplierId);
            if (!isset($chart[$code])) {
                $this->warn($section, sprintf('%s: účet %s není v účtové osnově firmy, volba se nezměnila.', $column, $code));
                continue;
            }
            $values[$column] = $code;
        }

        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT IGNORE INTO accounting_supplier_settings (supplier_id) VALUES (?)')->execute([$supplierId]);
        if ($values !== []) {
            $stmt = $pdo->prepare(
                'SELECT ' . implode(', ', array_keys($values)) . ' FROM accounting_supplier_settings WHERE supplier_id = ?'
            );
            $stmt->execute([$supplierId]);
            $current = (array) $stmt->fetch(PDO::FETCH_ASSOC);
            $this->applyColumns($section, 'accounting_supplier_settings', 'supplier_id', $supplierId, $current, $values);
        }

        // Řádky čistého obratu: seznam kódů validovaný proti povoleným volbám, uložený
        // přes repository (jediné místo, které zná tvar JSON ve sloupci).
        if (array_key_exists('net_turnover_extra_rows', $data)) {
            $raw = $data['net_turnover_extra_rows'];
            if (!is_array($raw)) {
                throw new CompanyProfileException('validation_failed', 'net_turnover_extra_rows musí být objekt se seznamy řádků.');
            }
            $clean = [];
            foreach (FinancialStatementService::NET_TURNOVER_EXTRA_ROW_OPTIONS as $type => $allowed) {
                $list = $raw[$type] ?? [];
                if (!is_array($list)) {
                    throw new CompanyProfileException('validation_failed', "net_turnover_extra_rows.{$type} musí být seznam řádků.");
                }
                foreach ($list as $code) {
                    if (!is_string($code) || !in_array($code, $allowed, true)) {
                        throw new CompanyProfileException('validation_failed', "Řádek {$type} „" . (is_scalar($code) ? (string) $code : '?') . '" do čistého obratu přičíst nelze.');
                    }
                }
                $clean[$type] = array_values(array_unique($list));
            }
            $current = $this->settings->getNetTurnoverExtraRows($supplierId);
            if ($current === $clean) {
                $this->report[$section]['unchanged']++;
            } else {
                $this->settings->setNetTurnoverExtraRows($supplierId, $clean);
                $this->changed($section, 'updated', sprintf(
                    'net_turnover_extra_rows: %s → %s',
                    json_encode($current, JSON_UNESCAPED_UNICODE),
                    json_encode($clean, JSON_UNESCAPED_UNICODE),
                ));
            }
        }
    }

    /** @param array<string,int|string|null> $values */
    private function updateSupplier(int $supplierId, string $section, array $values): void
    {
        if ($values === []) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", array_keys($values))) . ' FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $this->applyColumns($section, 'supplier', 'id', $supplierId, (array) $stmt->fetch(PDO::FETCH_ASSOC), $values);
    }

    /**
     * Zapíše sloupce, které se od současného stavu liší (každý sloupec = jedna položka reportu).
     *
     * @param array<string,mixed> $current
     * @param array<string,int|float|string|null> $values
     */
    private function applyColumns(string $section, string $table, string $keyColumn, int $supplierId, array $current, array $values): void
    {
        $changed = [];
        foreach ($values as $column => $value) {
            $old = $current[$column] ?? null;
            if (self::same($old, $value)) {
                $this->report[$section]['unchanged']++;
                continue;
            }
            $changed[$column] = $value;
            $this->changed($section, 'updated', sprintf('%s: %s → %s', $column, self::show($old), self::show($value)));
        }
        if ($changed === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($changed)));
        $this->db->pdo()->prepare("UPDATE `{$table}` SET {$sets} WHERE `{$keyColumn}` = ?")
            ->execute([...array_values($changed), $supplierId]);
    }

    // ── výjimky mapování výkazů ─────────────────────────────────────────────────

    /** @param array<int|string,mixed> $data */
    private function importStatementOverrides(int $supplierId, array $data, ?int $userId): void
    {
        $section = 'statement_overrides';
        $find = $this->db->pdo()->prepare('SELECT * FROM statement_versions WHERE statement_type = ? AND version_code = ?');
        foreach (array_values($data) as $i => $group) {
            if (!is_array($group) || !is_array($group['items'] ?? null)) {
                throw new CompanyProfileException('invalid_profile', sprintf('Skupina výjimek č. %d nemá seznam items.', $i + 1));
            }
            $type = (string) ($group['statement_type'] ?? '');
            $code = (string) ($group['version_code'] ?? '');
            if (!in_array($type, StatementOverrideService::TYPES, true)) {
                throw new CompanyProfileException('validation_failed', sprintf('Neznámý výkaz „%s".', $type));
            }
            $find->execute([$type, $code]);
            $version = $find->fetch(PDO::FETCH_ASSOC);
            if ($version === false) {
                throw new CompanyProfileException('statement_version_missing', sprintf(
                    'Verze výkazu %s „%s" v této instalaci není, aktualizujte aplikaci a spusťte migrace.',
                    $type,
                    $code,
                ));
            }
            $versionId = (int) $version['id'];
            $items = array_values(array_filter($group['items'], 'is_array'));
            $new = $this->overrideService->validateSet($supplierId, $version, $items);

            $current = [];
            foreach ($this->overrides->forVersion($supplierId, $versionId) as $o) {
                $current[self::overrideKey($o)] = self::overrideComparable($o);
            }
            $desired = [];
            foreach ($new as $o) {
                $desired[self::overrideKey($o)] = self::overrideComparable($o);
            }
            $label = $type . ' ' . $code;
            $diff = 0;
            foreach ($desired as $key => $o) {
                if (!isset($current[$key])) {
                    $diff++;
                    $this->changed($section, 'created', sprintf('%s: + %s → %s', $label, self::overrideLabel($key), $o['row_code']));
                } elseif ($current[$key] !== $o) {
                    $diff++;
                    $this->changed($section, 'updated', sprintf('%s: ~ %s → %s', $label, self::overrideLabel($key), $o['row_code']));
                } else {
                    $this->report[$section]['unchanged']++;
                }
            }
            foreach ($current as $key => $o) {
                if (!isset($desired[$key])) {
                    $diff++;
                    $this->changed($section, 'removed', sprintf('%s: - %s → %s', $label, self::overrideLabel($key), $o['row_code']));
                }
            }
            if ($diff > 0) {
                $this->overrideService->save($supplierId, $versionId, $items, $userId);
            }
        }
    }

    /** @param array<string,mixed> $o */
    private static function overrideKey(array $o): string
    {
        return $o['account_prefix'] . '|' . $o['balance_condition'] . '|' . ($o['valid_from_year'] ?? '');
    }

    private static function overrideLabel(string $key): string
    {
        [$prefix, $condition, $from] = explode('|', $key);

        return $prefix . ($condition === 'any' ? '' : ' (' . $condition . ')') . ($from === '' ? '' : ' od ' . $from);
    }

    /**
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private static function overrideComparable(array $o): array
    {
        return [
            'row_code' => (string) $o['row_code'],
            'target' => (string) ($o['target'] ?? 'gross'),
            'follows_prefix' => ($o['follows_prefix'] ?? null) === null || $o['follows_prefix'] === '' ? null : (string) $o['follows_prefix'],
            'sign' => (int) ($o['sign'] ?? 1),
            'valid_to_year' => ($o['valid_to_year'] ?? null) === null ? null : (int) $o['valid_to_year'],
            'note' => ($o['note'] ?? null) === null || $o['note'] === '' ? null : (string) $o['note'],
        ];
    }

    // ── dimenze ─────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    private function importDimensions(int $supplierId, array $data): void
    {
        $section = 'dimensions';
        $pdo = $this->db->pdo();
        $findType = $pdo->prepare('SELECT id FROM dimension_types WHERE supplier_id = ? AND code = ?');
        foreach (array_values((array) ($data['types'] ?? [])) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $code = strtolower(trim((string) ($t['code'] ?? '')));
            $findType->execute([$supplierId, $code]);
            $typeId = $findType->fetchColumn();
            $wanted = [
                'name' => trim((string) ($t['name'] ?? '')),
                'is_active' => (bool) ($t['is_active'] ?? true),
                'show_on_documents' => (bool) ($t['show_on_documents'] ?? true),
                'sort_order' => (int) ($t['sort_order'] ?? 100),
            ];
            if ($typeId === false) {
                $type = $this->dimensions->createType($supplierId, [
                    'code' => $code,
                    'name' => $wanted['name'],
                    'kind' => (string) ($t['kind'] ?? 'custom'),
                    'level' => 'company',
                    'show_on_documents' => $wanted['show_on_documents'],
                    'sort_order' => $wanted['sort_order'],
                ]);
                $typeId = (int) $type['id'];
                if (!$wanted['is_active']) {
                    $this->dimensions->updateType($supplierId, $typeId, ['is_active' => false]);
                }
                $this->changed($section, 'created', sprintf('typ %s (%s)', $code, $wanted['name']));
            } else {
                $typeId = (int) $typeId;
                $type = (array) $this->dimensionRepo->findType($supplierId, $typeId);
                if (($t['kind'] ?? $type['kind']) !== $type['kind']) {
                    $this->warn($section, sprintf('Typ %s má ve firmě druh %s, v profilu %s, druh se nemění.', $code, $type['kind'], (string) $t['kind']));
                }
                $changes = [];
                foreach ($wanted as $field => $value) {
                    if ($type[$field] !== $value) {
                        $changes[$field] = $value;
                    }
                }
                if ($changes === []) {
                    $this->report[$section]['unchanged']++;
                } else {
                    $this->dimensions->updateType($supplierId, $typeId, $changes);
                    $this->changed($section, 'updated', sprintf('typ %s: %s', $code, implode(', ', array_keys($changes))));
                }
            }
            $this->importDimensionValues($supplierId, $typeId, $code, array_values(array_filter((array) ($t['values'] ?? []), 'is_array')));
        }
    }

    /** @param list<array<string,mixed>> $values */
    private function importDimensionValues(int $supplierId, int $typeId, string $typeCode, array $values): void
    {
        $section = 'dimensions';
        $ids = [];
        foreach ($values as $v) {
            $code = trim((string) ($v['code'] ?? ''));
            $body = [
                'name' => (string) ($v['name'] ?? ''),
                'is_active' => (bool) ($v['is_active'] ?? true),
                'responsible_note' => $v['responsible_note'] ?? null,
                'note' => $v['note'] ?? null,
                'sort_order' => (int) ($v['sort_order'] ?? 0),
            ];
            $label = $typeCode . '/' . $code;
            foreach ($this->valueLinks($supplierId, $v, $label) as $field => $id) {
                $body[$field] = $id;
            }
            $existing = $this->dimensionRepo->findValueByCode($typeId, $code);
            if ($existing === null) {
                $created = $this->dimensions->createValue($supplierId, $typeId, $body + ['code' => $code]);
                $ids[$code] = (int) $created['id'];
                $this->changed($section, 'created', 'hodnota ' . $label);
                continue;
            }
            $ids[$code] = (int) $existing['id'];
            $changes = [];
            foreach ($body as $field => $value) {
                $old = $existing[$field] ?? null;
                if (in_array($field, ['responsible_note', 'note'], true)) {
                    $value = trim((string) ($value ?? '')) === '' ? null : trim((string) $value);
                    $body[$field] = $value;
                }
                if (!self::same($old, $value)) {
                    $changes[$field] = $value;
                }
            }
            if ($changes === []) {
                $this->report[$section]['unchanged']++;
            } else {
                $this->dimensions->updateValue($supplierId, $ids[$code], $changes);
                $this->changed($section, 'updated', sprintf('hodnota %s: %s', $label, implode(', ', array_keys($changes))));
            }
        }

        // Nadřízené hodnoty až po založení všech hodnot typu (strom může odkazovat dopředu).
        foreach ($values as $v) {
            $code = trim((string) ($v['code'] ?? ''));
            if (!isset($ids[$code])) {
                continue;
            }
            $parentCode = trim((string) ($v['parent_code'] ?? ''));
            $parentId = null;
            if ($parentCode !== '') {
                $parent = $this->dimensionRepo->findValueByCode($typeId, $parentCode);
                if ($parent === null) {
                    $this->warn($section, sprintf('Hodnota %s/%s: nadřízená hodnota %s neexistuje.', $typeCode, $code, $parentCode));
                    continue;
                }
                $parentId = (int) $parent['id'];
            }
            $current = $this->dimensionRepo->findValue($supplierId, $ids[$code]);
            if ($current !== null && ($current['parent_id'] ?? null) !== $parentId) {
                $this->dimensions->updateValue($supplierId, $ids[$code], ['parent_id' => $parentId]);
                $this->changed($section, 'updated', sprintf('hodnota %s/%s: nadřízená %s', $typeCode, $code, $parentCode === '' ? '(žádná)' : $parentCode));
            }
        }
    }

    /**
     * Vazby hodnoty dimenze na vůz, středisko a zakázku podle přirozených klíčů. Co ve
     * firmě není, se ohlásí a vazba zůstane, jak je.
     *
     * @param array<string,mixed> $v
     * @return array<string,int>
     */
    private function valueLinks(int $supplierId, array $v, string $label): array
    {
        $pdo = $this->db->pdo();
        $out = [];
        $plate = strtoupper(str_replace(' ', '', trim((string) ($v['car_registration'] ?? ''))));
        if ($plate !== '') {
            $stmt = $pdo->prepare(
                "SELECT id FROM cars WHERE supplier_id = ? AND REPLACE(UPPER(registration), ' ', '') = ? ORDER BY is_archived, id LIMIT 1"
            );
            $stmt->execute([$supplierId, $plate]);
            $id = $stmt->fetchColumn();
            $id === false
                ? $this->warn('dimensions', sprintf('Hodnota %s: vůz %s ve firmě není, vazba se nenastavila.', $label, $plate))
                : $out['car_id'] = (int) $id;
        }
        $center = trim((string) ($v['cost_center_code'] ?? ''));
        if ($center !== '') {
            $stmt = $pdo->prepare('SELECT id FROM cost_centers WHERE supplier_id = ? AND code = ?');
            $stmt->execute([$supplierId, $center]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                $out['cost_center_id'] = (int) $id;
            }
            // Chybějící středisko založí DimensionService u hodnoty typu Středisko sama.
        }
        if (is_array($v['project'] ?? null)) {
            $id = $this->projectId($supplierId, $v['project']);
            $id === null
                ? $this->warn('dimensions', sprintf('Hodnota %s: zakázka „%s" ve firmě není, vazba se nenastavila.', $label, (string) ($v['project']['name'] ?? '')))
                : $out['project_id'] = $id;
        }

        return $out;
    }

    /** @param array<int|string,mixed> $data */
    private function importDimensionDefaults(int $supplierId, array $data): void
    {
        $section = 'dimension_defaults';
        if ($data === []) {
            return;
        }
        if (!$this->dimensionRepo->enabled($supplierId)) {
            $this->warn($section, 'Firma nemá zapnuté dimenze, výchozí dimenze klientů, zakázek, produktů a bankovních účtů se nenahrály.');
            return;
        }
        $types = $this->dimensionTypesByCode($supplierId);
        $mode = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);
        $doubleEntry = $mode->fetchColumn() === 'double_entry';
        $wanted = [];
        foreach (array_values($data) as $d) {
            if (!is_array($d)) {
                continue;
            }
            $entity = (string) ($d['entity'] ?? '');
            $typeCode = (string) ($d['type_code'] ?? '');
            $valueCode = (string) ($d['value_code'] ?? '');
            if ($entity === 'bank_account' && !$doubleEntry) {
                // Výchozí dimenze účtu platí jen v podvojném účetnictví (Kontace účtů).
                $this->warn($section, sprintf('Firma nevede podvojné účetnictví, výchozí dimenze bankovního účtu %s přeskočena.', (string) ($d['bank_account']['account'] ?? '?')));
                continue;
            }
            $entityId = match ($entity) {
                'client' => $this->clientId($supplierId, (array) ($d['client'] ?? [])),
                'project' => $this->projectId($supplierId, (array) ($d['project'] ?? [])),
                'product' => $this->codeId('SELECT id FROM stock_items WHERE supplier_id = ? AND sku = ?', $supplierId, (string) ($d['product']['sku'] ?? '')),
                'product_category' => $this->codeId('SELECT id FROM stock_categories WHERE supplier_id = ? AND code = ?', $supplierId, (string) ($d['product_category']['code'] ?? '')),
                'bank_account' => $this->bankAccountId($supplierId, (array) ($d['bank_account'] ?? [])),
                default => null,
            };
            $who = match ($entity) {
                'client' => 'klient ' . (string) ($d['client']['name'] ?? '?'),
                'product' => 'produkt ' . (string) ($d['product']['sku'] ?? '?'),
                'product_category' => 'kategorie ' . (string) ($d['product_category']['code'] ?? '?'),
                'bank_account' => 'bankovní účet ' . (string) ($d['bank_account']['account'] ?? '?')
                    . ((string) ($d['bank_account']['bank_code'] ?? '') !== '' ? '/' . (string) $d['bank_account']['bank_code'] : ''),
                default => 'zakázka ' . (string) ($d['project']['name'] ?? '?'),
            };
            if ($entityId === null) {
                $this->warn($section, sprintf('%s ve firmě není, výchozí dimenze %s přeskočena.', ucfirst($who), $typeCode));
                continue;
            }
            $typeId = $types[$typeCode] ?? null;
            $value = $typeId === null ? null : $this->dimensionRepo->findValueByCode($typeId, $valueCode);
            if ($value === null) {
                $this->warn($section, sprintf('%s: hodnota %s/%s ve firmě není, přeskočena.', ucfirst($who), $typeCode, $valueCode));
                continue;
            }
            $wanted[$entity][$entityId]['label'] = $who;
            $wanted[$entity][$entityId]['dims'][$typeId] = (int) $value['id'];
        }
        foreach ($wanted as $entity => $byId) {
            foreach ($byId as $entityId => ['label' => $label, 'dims' => $dims]) {
                $current = $this->dimensionDefaults->forEntity($supplierId, $entity, $entityId);
                $merged = array_replace($current, $dims);
                ksort($merged);
                if ($merged === $current) {
                    $this->report[$section]['unchanged']++;
                    continue;
                }
                $this->dimensions->saveEntityDefaults($supplierId, $entity, $entityId, $merged);
                $this->changed($section, $current === [] ? 'created' : 'updated', $label);
            }
        }
    }

    /**
     * Pravidla dimenzí podle účtu. Klíčem je typ dimenze, maska účtů a začátek platnosti;
     * zápis i veškerá validace (maska, vynucení, výchozí hodnota, vozidlo podle karty,
     * platnost) jde přes {@see DimensionRuleService}.
     *
     * @param array<int|string,mixed> $data
     */
    private function importDimensionRules(int $supplierId, array $data, ?int $userId): void
    {
        $section = 'dimension_rules';
        if ($data === []) {
            return;
        }
        if (!$this->dimensionRepo->enabled($supplierId)) {
            $this->warn($section, 'Firma nemá zapnuté dimenze, pravidla dimenzí podle účtu se nenahrála.');
            return;
        }
        $types = [];
        foreach ($this->dimensionRepo->listTypes($supplierId) as $t) {
            // Firemní typ má přednost před globálním se stejným kódem.
            if (!isset($types[$t['code']]) || $t['level'] === 'company') {
                $types[$t['code']] = $t;
            }
        }
        $existing = $this->dimensionRules->list($supplierId);
        foreach (array_values($data) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $typeCode = trim((string) ($r['type_code'] ?? ''));
            $mask = DimensionAccountMask::parse((string) ($r['account_mask'] ?? ''))->normalized();
            $validFrom = trim((string) ($r['valid_from'] ?? '')) ?: null;
            $label = $typeCode . ' ' . $mask . ($validFrom === null ? '' : ' od ' . $validFrom);
            $type = $types[$typeCode] ?? null;
            if ($type === null) {
                $this->warn($section, sprintf('Pravidlo %s: typ dimenze %s ve firmě není, přeskočeno.', $label, $typeCode));
                continue;
            }
            $typeId = (int) $type['id'];
            $current = null;
            foreach ($existing as $e) {
                if ($e['dimension_type_id'] === $typeId && $e['account_mask'] === $mask && $e['valid_from'] === $validFrom) {
                    $current = $e;
                    break;
                }
            }
            $valueId = $this->ruleDefaultValue($supplierId, $typeId, $r, $label);
            if ($valueId === false) {
                continue;
            }
            if ($current === null && !$type['is_active']) {
                $this->warn($section, sprintf('Pravidlo %s: typ dimenze je neaktivní, přeskočeno.', $label));
                continue;
            }
            if ($valueId !== null && ($current === null || $current['default_value_id'] !== $valueId)) {
                $value = $this->dimensionRepo->findValue($supplierId, $valueId);
                if ($value !== null && !$value['is_active']) {
                    $this->warn($section, sprintf('Pravidlo %s: výchozí hodnota %s je uzavřená, přeskočeno.', $label, (string) $value['code']));
                    continue;
                }
            }
            $body = [
                'dimension_type_id' => $typeId,
                'account_mask' => $mask,
                'enforcement' => (string) ($r['enforcement'] ?? 'error'),
                'default_value_id' => $valueId,
                'default_from_card' => self::flag($r['default_from_card'] ?? false, 'default_from_card'),
                'valid_from' => $validFrom,
                'valid_to' => trim((string) ($r['valid_to'] ?? '')) ?: null,
                'is_active' => self::flag($r['is_active'] ?? true, 'is_active'),
                'note' => $r['note'] ?? null,
            ];
            if ($current === null) {
                $existing[] = $this->dimensionRules->create($supplierId, $body, $userId);
                $this->changed($section, 'created', $label);
                continue;
            }
            $note = trim((string) ($body['note'] ?? ''));
            $body['note'] = $note === '' ? null : $note;
            $diff = array_values(array_filter(
                ['enforcement', 'default_value_id', 'default_from_card', 'valid_to', 'is_active', 'note'],
                static fn (string $f): bool => !self::same($current[$f], $body[$f]),
            ));
            if ($diff === []) {
                $this->report[$section]['unchanged']++;
                continue;
            }
            $this->dimensionRules->update($supplierId, (int) $current['id'], $body);
            $this->changed($section, 'updated', sprintf('%s: %s', $label, implode(', ', $diff)));
        }
    }

    /**
     * Výchozí hodnota pravidla podle kódu, u vozidla podle registrační značky vozu.
     *
     * @param array<string,mixed> $r
     * @return int|null|false null = pravidlo výchozí hodnotu nemá, false = hodnota ve firmě není (přeskočit)
     */
    private function ruleDefaultValue(int $supplierId, int $typeId, array $r, string $label): int|null|false
    {
        $code = trim((string) ($r['default_value_code'] ?? ''));
        $plate = strtoupper(str_replace(' ', '', trim((string) ($r['default_value_car_registration'] ?? ''))));
        if ($code === '' && $plate === '') {
            return null;
        }
        $value = $code === '' ? null : $this->dimensionRepo->findValueByCode($typeId, $code);
        if ($value === null && $plate !== '') {
            $stmt = $this->db->pdo()->prepare(
                "SELECT v.id FROM dimension_values v
                   JOIN cars c ON c.id = v.car_id AND c.supplier_id = v.supplier_id
                  WHERE v.type_id = ? AND v.supplier_id = ? AND REPLACE(UPPER(c.registration), ' ', '') = ?
                  ORDER BY v.is_active DESC, v.id LIMIT 1"
            );
            $stmt->execute([$typeId, $supplierId, $plate]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        if ($value === null) {
            $this->warn('dimension_rules', sprintf(
                'Pravidlo %s: výchozí hodnota %s ve firmě není, přeskočeno.',
                $label,
                $code !== '' ? $code : $plate,
            ));
            return false;
        }

        return (int) $value['id'];
    }

    /** @param array<string,mixed> $client */
    private function clientId(int $supplierId, array $client): ?int
    {
        $ic = trim((string) ($client['ic'] ?? ''));
        $name = trim((string) ($client['name'] ?? ''));
        $pdo = $this->db->pdo();
        if ($ic !== '') {
            $stmt = $pdo->prepare('SELECT id FROM clients WHERE supplier_id = ? AND ic = ? ORDER BY id');
            $stmt->execute([$supplierId, $ic]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) {
                return (int) $ids[0];
            }
        }
        if ($name === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id FROM clients WHERE supplier_id = ? AND company_name = ?' . ($ic !== '' ? ' AND ic = ?' : '') . ' ORDER BY id');
        $stmt->execute($ic !== '' ? [$supplierId, $name, $ic] : [$supplierId, $name]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    /**
     * Vlastní bankovní účet firmy podle čísla účtu a kódu banky, stejně jako ho najde
     * zaúčtování pohybu ({@see SupplierBankAccountRepository::matchCounterparty()}): číslo
     * i kód banky se kanonizují a účet registru bez kódu banky se spáruje taky. Neaktivní
     * účet se najde, jeho nastavení se přenáší jako ostatní vlastnosti účtu.
     *
     * @param array<string,mixed> $key
     */
    private function bankAccountId(int $supplierId, array $key): ?int
    {
        $bank = trim((string) ($key['bank_code'] ?? ''));
        $account = (new SupplierBankAccountRepository($this->db, new BankStatementOwnershipResolver($this->db)))
            ->matchCounterparty($supplierId, (string) ($key['account'] ?? ''), $bank === '' ? null : $bank, true);
        return $account === null ? null : (int) $account['id'];
    }

    /** @param array<string,mixed> $project */
    /** Id záznamu firmy podle unikátního kódu (SKU produktu, kód kategorie), jinak null. */
    private function codeId(string $sql, int $supplierId, string $code): ?int
    {
        if (trim($code) === '') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, trim($code)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function projectId(int $supplierId, array $project): ?int
    {
        $clientId = $this->clientId($supplierId, (array) ($project['client'] ?? []));
        if ($clientId === null) {
            return null;
        }
        $number = trim((string) ($project['number'] ?? ''));
        $name = trim((string) ($project['name'] ?? ''));
        $stmt = $number !== ''
            ? $this->db->pdo()->prepare('SELECT id FROM projects WHERE client_id = ? AND project_number = ? ORDER BY id')
            : $this->db->pdo()->prepare('SELECT id FROM projects WHERE client_id = ? AND name = ? ORDER BY id');
        $stmt->execute([$clientId, $number !== '' ? $number : $name]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    // ── předkontace a banka ─────────────────────────────────────────────────────

    /** @param array<int|string,mixed> $data */
    private function importPostingRules(int $supplierId, array $data): void
    {
        $section = 'posting_rules';
        $pdo = $this->db->pdo();
        $chart = $this->accounts->codeToIdMap($supplierId);
        $find = $pdo->prepare(
            'SELECT id, description, debit_account_code, credit_account_code, is_active
               FROM posting_rules WHERE supplier_id = ? AND rule_key = ? AND priority = ?'
        );
        foreach (array_values($data) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $key = trim((string) ($r['rule_key'] ?? ''));
            // Předkontace převzaté z jiného programu nesou jeho zkratku (diakritika, mezery),
            // proto jen délka sloupce a žádné řídicí znaky.
            if ($key === '' || mb_strlen($key) > 64 || preg_match('/[\x00-\x1F\x7F]/u', $key) === 1) {
                throw new CompanyProfileException('validation_failed', sprintf('Neplatný klíč předkontace „%s".', $key));
            }
            $priority = filter_var($r['priority'] ?? 0, FILTER_VALIDATE_INT);
            if ($priority === false) {
                throw new CompanyProfileException('validation_failed', sprintf('Předkontace %s: priorita musí být celé číslo.', $key));
            }
            $description = mb_substr(trim((string) ($r['description'] ?? '')), 0, 255) ?: $key;
            $debit = BankPostingRuleValidator::nn($r['debit_account_code'] ?? null);
            $credit = BankPostingRuleValidator::nn($r['credit_account_code'] ?? null);
            $missing = array_filter([$debit, $credit], static fn (?string $c): bool => $c !== null && !isset($chart[$c]));
            if ($missing !== []) {
                $this->warn($section, sprintf('Předkontace %s: účet %s není v účtové osnově firmy, přeskočena.', $key, implode(', ', $missing)));
                continue;
            }
            $active = self::flag($r['is_active'] ?? true, 'is_active') ? 1 : 0;
            $find->execute([$supplierId, $key, $priority]);
            $current = $find->fetch(PDO::FETCH_ASSOC);
            if ($current === false) {
                $pdo->prepare(
                    'INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$supplierId, $key, $description, $debit, $credit, $priority, $active]);
                $this->changed($section, 'created', sprintf('%s (%s/%s)', $key, $debit ?? '-', $credit ?? '-'));
                continue;
            }
            if ((string) $current['description'] === $description && $current['debit_account_code'] === $debit
                && $current['credit_account_code'] === $credit && (int) $current['is_active'] === $active) {
                $this->report[$section]['unchanged']++;
                continue;
            }
            $pdo->prepare(
                'UPDATE posting_rules SET description = ?, debit_account_code = ?, credit_account_code = ?, is_active = ?
                  WHERE id = ? AND supplier_id = ?'
            )->execute([$description, $debit, $credit, $active, (int) $current['id'], $supplierId]);
            $this->changed($section, 'updated', sprintf('%s (%s/%s%s)', $key, $debit ?? '-', $credit ?? '-', $active === 1 ? '' : ', vypnutá'));
        }
    }

    /** @param array<int|string,mixed> $data */
    private function importBankRuleTemplates(int $supplierId, array $data): void
    {
        $section = 'bank_rule_templates';
        $pdo = $this->db->pdo();
        $find = $pdo->prepare('SELECT * FROM bank_rule_templates WHERE supplier_id = ? AND template_key = ?');
        $ruleExists = $pdo->prepare('SELECT 1 FROM posting_rules WHERE (supplier_id = ? OR supplier_id IS NULL) AND rule_key = ? LIMIT 1');
        $columns = ['name_cs', 'name_en', 'direction', 'operation_type', 'counterparty_bank', 'counterparty_prefix',
            'vs_placeholder', 'message_contains', 'rule_key', 'default_priority', 'sort_order', 'is_active'];
        foreach (array_values($data) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $t = $this->templates->normalize($raw);
            $ruleExists->execute([$supplierId, $t['rule_key']]);
            if ($ruleExists->fetchColumn() === false) {
                $this->warn($section, sprintf('Šablona %s: předkontace %s neexistuje, přeskočena.', $t['template_key'], $t['rule_key']));
                continue;
            }
            $t['is_active'] = $t['is_active'] ? 1 : 0;
            $find->execute([$supplierId, $t['template_key']]);
            $current = $find->fetch(PDO::FETCH_ASSOC);
            if ($current === false) {
                $pdo->prepare(
                    'INSERT INTO bank_rule_templates (supplier_id, template_key, ' . implode(', ', $columns) . ')
                     VALUES (?, ?, ' . implode(', ', array_fill(0, count($columns), '?')) . ')'
                )->execute([$supplierId, $t['template_key'], ...array_map(static fn (string $c): mixed => $t[$c], $columns)]);
                $this->changed($section, 'created', $t['template_key']);
                continue;
            }
            $diff = array_values(array_filter($columns, static fn (string $c): bool => !self::same($current[$c], $t[$c])));
            if ($diff === []) {
                $this->report[$section]['unchanged']++;
                continue;
            }
            $pdo->prepare(
                'UPDATE bank_rule_templates SET ' . implode(', ', array_map(static fn (string $c): string => "{$c} = ?", $columns))
                . ' WHERE supplier_id = ? AND id = ?'
            )->execute([...array_map(static fn (string $c): mixed => $t[$c], $columns), $supplierId, (int) $current['id']]);
            $this->changed($section, 'updated', sprintf('%s: %s', $t['template_key'], implode(', ', $diff)));
        }
    }

    /** @param array<int|string,mixed> $data */
    private function importBankPostingRules(int $supplierId, array $data, ?int $userId): void
    {
        $section = 'bank_posting_rules';
        foreach (array_values($data) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            // Vlastní účet pravidla podle čísla, id z cizí instance se nepoužije. Pravidlo
            // omezené na účet, který ve firmě není, se přeskočí: bez omezení by platilo
            // pro pohyby všech účtů.
            $ownAccount = $raw['bank_account'] ?? null;
            $profileHasAccount = array_key_exists('bank_account', $raw);
            $raw['bank_account_id'] = null;
            if (is_array($ownAccount)) {
                $raw['bank_account_id'] = $this->bankAccountId($supplierId, $ownAccount);
                if ($raw['bank_account_id'] === null) {
                    $this->warn($section, sprintf(
                        'Pravidlo %s: bankovní účet %s%s ve firmě není, pravidlo přeskočeno.',
                        (string) ($raw['name'] ?? '?'),
                        (string) ($ownAccount['account'] ?? '?'),
                        (string) ($ownAccount['bank_code'] ?? '') !== '' ? '/' . (string) $ownAccount['bank_code'] : '',
                    ));
                    continue;
                }
            }
            $rule = $this->bankRules->normalizeRule($supplierId, $raw);
            $rule['is_active'] = self::flag($raw['is_active'] ?? true, 'is_active');
            // Starší profil sekci dimenzí pravidla nemá: dimenze existujícího pravidla zůstanou.
            $dims = array_key_exists('dimensions', $raw) ? $this->bankRuleDimensions($supplierId, $raw['dimensions'], $rule['name']) : null;
            $templateKey = BankPostingRuleValidator::nn($raw['system_template_key'] ?? null);
            if ($templateKey !== null && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $templateKey) !== 1) {
                throw new CompanyProfileException('validation_failed', sprintf('Pravidlo %s: neplatný klíč šablony.', $rule['name']));
            }
            $wantAuto = ($raw['mode'] ?? 'suggest') === 'auto';
            $label = $rule['name'];

            $current = $this->findBankRule($supplierId, $rule, $templateKey);
            if ($current === null) {
                $id = $this->bankRuleRepo->insert($supplierId, $rule + ['system_template_key' => $templateKey], $userId);
                if ($dims !== null && $dims !== []) {
                    $this->bankRuleRepo->replaceDimensions($supplierId, $id, $dims);
                }
                if ($wantAuto && $rule['is_active']) {
                    $this->promotion->promote($supplierId, $id, $userId);
                }
                $this->changed($section, 'created', $label . ($wantAuto ? ' (automaticky)' : ''));
                continue;
            }
            $id = (int) $current['id'];
            $fields = [];
            foreach (self::BANK_RULE_FIELDS as $field) {
                if (!self::same($current[$field] ?? null, $rule[$field])) {
                    $fields[$field] = $rule[$field];
                }
            }
            // Instance šablony se hledá jen podle klíče, omezení na účet z profilu se jí proto
            // promítne tady (u ostatních pravidel je účet součástí hledání). Starší profil
            // bez klíče `bank_account` omezení nezná a nesmí ho zrušit.
            if ($templateKey !== null && $profileHasAccount
                && (int) ($current['bank_account_id'] ?? 0) !== (int) ($rule['bank_account_id'] ?? 0)) {
                $fields['bank_account_id'] = $rule['bank_account_id'] ?? null;
            }
            if ($fields !== []) {
                $this->bankRuleRepo->update($supplierId, $id, $fields);
            }
            if ($dims !== null && $dims !== $this->bankRuleRepo->dimensions($supplierId, $id)) {
                $this->bankRuleRepo->replaceDimensions($supplierId, $id, $dims);
                $fields['dimensions'] = $dims;
            }
            $mode = (string) $current['mode'];
            $modeChanged = false;
            if ($wantAuto && $mode !== 'auto' && $rule['is_active']) {
                $this->promotion->promote($supplierId, $id, $userId);
                $modeChanged = true;
            } elseif (!$wantAuto && $mode === 'auto') {
                $this->promotion->demote($supplierId, $id, $userId, 'manual');
                $modeChanged = true;
            }
            if ($fields === [] && !$modeChanged) {
                $this->report[$section]['unchanged']++;
            } else {
                $this->changed($section, 'updated', sprintf('%s: %s', $label, implode(', ', [...array_keys($fields), ...($modeChanged ? ['mode'] : [])])));
            }
        }
    }

    /**
     * Dimenze pravidla z profilu (typ a hodnota kódem) jako typ => hodnota. Hodnotu,
     * která ve firmě není, ohlásí a vynechá. Bez zapnutých dimenzí nenahraje žádnou
     * a vrátí null, aby import dosavadní dimenze pravidla nesmazal.
     *
     * @return array<int,int>|null
     */
    private function bankRuleDimensions(int $supplierId, mixed $raw, string $label): ?array
    {
        $entries = array_values(array_filter(is_array($raw) ? $raw : [], 'is_array'));
        if (!$this->dimensionRepo->enabled($supplierId)) {
            // Ani prázdný seznam z profilu nesmí smazat dimenze, které firma má jen vypnuté.
            if ($entries !== []) {
                $this->warn('bank_posting_rules', sprintf('Pravidlo %s: firma nemá zapnuté dimenze, dimenze pravidla se nenahrály.', $label));
            }
            return null;
        }
        if ($entries === []) {
            return [];
        }
        $types = $this->dimensionTypesByCode($supplierId);
        $out = [];
        foreach ($entries as $d) {
            $typeCode = (string) ($d['type_code'] ?? '');
            $valueCode = (string) ($d['value_code'] ?? '');
            $typeId = $types[$typeCode] ?? null;
            $value = $typeId === null ? null : $this->dimensionRepo->findValueByCode($typeId, $valueCode);
            if ($value === null) {
                $this->warn('bank_posting_rules', sprintf('Pravidlo %s: hodnota %s/%s ve firmě není, přeskočena.', $label, $typeCode, $valueCode));
                continue;
            }
            $out[$typeId] = (int) $value['id'];
        }
        ksort($out);
        return $out;
    }

    /**
     * Typy dimenzí firmy podle kódu; firemní typ má přednost před globálním se stejným kódem.
     *
     * @return array<string,int>
     */
    private function dimensionTypesByCode(int $supplierId): array
    {
        $types = [];
        foreach ($this->dimensionRepo->listTypes($supplierId) as $t) {
            if (!isset($types[$t['code']]) || $t['level'] === 'company') {
                $types[$t['code']] = (int) $t['id'];
            }
        }
        return $types;
    }

    /**
     * Existující pravidlo, které profilové pravidlo popisuje: instance šablony podle klíče
     * šablony, jinak stejný směr, měna a stejná kritéria shody.
     *
     * @param array<string,mixed> $rule
     * @return array<string,mixed>|null
     */
    private function findBankRule(int $supplierId, array $rule, ?string $templateKey): ?array
    {
        $pdo = $this->db->pdo();
        if ($templateKey !== null) {
            $stmt = $pdo->prepare('SELECT * FROM bank_posting_rules WHERE supplier_id = ? AND system_template_key = ?');
            $stmt->execute([$supplierId, $templateKey]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT * FROM bank_posting_rules
                  WHERE supplier_id = ? AND system_template_key IS NULL AND direction = ? AND applies_currency = ?
                    AND bank_account_id <=> ?
                    AND counterparty_account <=> ? AND counterparty_bank <=> ? AND counterparty_prefix <=> ?
                    AND variable_symbol <=> ? AND message_contains <=> ?
                    AND amount_min <=> ? AND amount_max <=> ?
                  ORDER BY id LIMIT 1'
            );
            $stmt->execute([
                $supplierId, $rule['direction'], $rule['applies_currency'], $rule['bank_account_id'],
                $rule['counterparty_account'], $rule['counterparty_bank'], $rule['counterparty_prefix'],
                $rule['variable_symbol'], $rule['message_contains'], $rule['amount_min'], $rule['amount_max'],
            ]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    // ── pomocné ─────────────────────────────────────────────────────────────────

    /**
     * Klíče sekce, které aplikace zná; ostatní ohlásí.
     *
     * @param array<int|string,mixed> $data
     * @param list<string> $allowed
     * @return array<string,mixed>
     */
    private function known(string $section, array $data, array $allowed): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $allowed, true)) {
                $out[(string) $key] = $value;
            } else {
                $this->warn($section, sprintf('Volbu „%s" tahle verze aplikace nezná, přeskočena.', (string) $key));
            }
        }

        return $out;
    }

    private static function flag(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new CompanyProfileException('validation_failed', "{$field} musí být true nebo false.");
        }

        return $parsed;
    }

    /** Hodnoty z databáze (řetězce) proti hodnotám profilu (int, float, bool, null). */
    private static function same(mixed $old, mixed $new): bool
    {
        if ($old === null || $new === null) {
            return $old === null && $new === null;
        }
        if (is_bool($new) || is_bool($old)) {
            return (bool) $old === (bool) $new;
        }
        if (is_numeric($old) && is_numeric($new)) {
            return round((float) $old, 4) === round((float) $new, 4);
        }

        return (string) $old === (string) $new;
    }

    private static function show(mixed $value): string
    {
        return $value === null ? '(prázdné)' : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }

    private function changed(string $section, string $kind, string $line): void
    {
        $this->report[$section][$kind]++;
        $this->report[$section]['changes'][] = $line;
    }

    private function warn(string $section, string $message): void
    {
        $this->report[$section]['warnings'][] = $message;
    }
}
