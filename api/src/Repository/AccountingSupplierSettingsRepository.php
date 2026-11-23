<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro accounting_supplier_settings (Epic F2) — ručně zadávané atributy
 * per firma: průměrný přepočtený počet zaměstnanců (R10) a admin override rozsahu
 * výkazů (R11). Epic F4 (migrace 1017) přidává flagy: povinný audit §20 ZoÚ
 * (statutory_audit, R18), opt-in číselná řada ručních zápisů (manual_doc_series,
 * R13) a FX storno k 1. dni nového období (fx_reversal_at_open, R11 F4).
 */
final class AccountingSupplierSettingsRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{avg_employees: ?int, statement_scope_override: ?string,
     *               statutory_audit: bool, manual_doc_series: bool, fx_reversal_at_open: bool,
     *               fx_rate_mode: string, small_asset_accrual_mode: string,
     *               small_asset_accrual_pct: ?float,
     *               net_turnover_extra_rows: array{income_statement: list<string>, income_statement_purpose: list<string>},
     *               comparative_from_prior_year: bool, tax_authority_offset: bool,
     *               tax_authority_offset_from_year: ?int}
     */
    public function get(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT avg_employees, statement_scope_override, statutory_audit,
                    manual_doc_series, fx_reversal_at_open, fx_rate_mode,
                    small_asset_accrual_mode, small_asset_accrual_pct, net_turnover_extra_rows,
                    comparative_from_prior_year, tax_authority_offset,
                    tax_authority_offset_from_year
               FROM accounting_supplier_settings
              WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [
                'avg_employees'            => null,
                'statement_scope_override' => null,
                'statutory_audit'          => false,
                'manual_doc_series'        => false,
                'fx_reversal_at_open'      => true,
                'fx_rate_mode'             => 'daily',
                'small_asset_accrual_mode' => 'none',
                'small_asset_accrual_pct'  => null,
                'net_turnover_extra_rows'  => self::decodeTurnoverRows(null),
                'comparative_from_prior_year' => false,
                'tax_authority_offset'     => false,
                'tax_authority_offset_from_year' => null,
            ];
        }
        return [
            'avg_employees' => $row['avg_employees'] === null ? null : (int) $row['avg_employees'],
            'statement_scope_override' => $row['statement_scope_override'],
            'statutory_audit'          => (bool) $row['statutory_audit'],
            'manual_doc_series'        => (bool) $row['manual_doc_series'],
            'fx_reversal_at_open'      => (bool) $row['fx_reversal_at_open'],
            'fx_rate_mode'             => (string) ($row['fx_rate_mode'] ?? 'daily'),
            'small_asset_accrual_mode' => (string) ($row['small_asset_accrual_mode'] ?? 'none'),
            'small_asset_accrual_pct'  => $row['small_asset_accrual_pct'] === null ? null : (float) $row['small_asset_accrual_pct'],
            'net_turnover_extra_rows'  => self::decodeTurnoverRows($row['net_turnover_extra_rows'] ?? null),
            'comparative_from_prior_year' => (bool) ($row['comparative_from_prior_year'] ?? false),
            'tax_authority_offset'     => (bool) ($row['tax_authority_offset'] ?? false),
            'tax_authority_offset_from_year' => isset($row['tax_authority_offset_from_year']) ? (int) $row['tax_authority_offset_from_year'] : null,
        ];
    }

    /**
     * Řádky VZZ, které firma podle § 35 vyhl. 500/2002 Sb. počítá k výnosům obchodního
     * modelu, tedy do čistého obratu nad výchozí I. + II. (účelové členění I.). Čte
     * {@see \MyInvoice\Service\Accounting\Reports\FinancialStatementService}.
     *
     * @return array{income_statement: list<string>, income_statement_purpose: list<string>}
     */
    public function getNetTurnoverExtraRows(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT net_turnover_extra_rows FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $v = $stmt->fetchColumn();
        return self::decodeTurnoverRows($v === false ? null : $v);
    }

    /**
     * Partial upsert jen tohoto sloupce; oba seznamy prázdné = NULL (výchozí I. + II.).
     * Kódy řádků musí volající předem ověřit proti povoleným volbám.
     *
     * @param array{income_statement?: list<string>, income_statement_purpose?: list<string>} $rows
     */
    public function setNetTurnoverExtraRows(int $supplierId, array $rows): void
    {
        $clean = self::decodeTurnoverRows(json_encode($rows));
        $stored = ($clean['income_statement'] === [] && $clean['income_statement_purpose'] === [])
            ? null
            : json_encode($clean, JSON_UNESCAPED_UNICODE);
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, net_turnover_extra_rows)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE net_turnover_extra_rows = VALUES(net_turnover_extra_rows)'
        )->execute([$supplierId, $stored]);
    }

    /** @return array{income_statement: list<string>, income_statement_purpose: list<string>} */
    private static function decodeTurnoverRows(mixed $json): array
    {
        $out = ['income_statement' => [], 'income_statement_purpose' => []];
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return $out;
        }
        foreach (array_keys($out) as $type) {
            foreach ((array) ($data[$type] ?? []) as $code) {
                if (is_string($code) && $code !== '' && !in_array($code, $out[$type], true)) {
                    $out[$type][] = $code;
                }
            }
        }
        return $out;
    }

    /**
     * Sloupec minulého období výkazů se počítá s výjimkami mapování platnými v minulém
     * roce (jako uzavřený výkaz minulého roku). Výchozí false = přepočet podle zařazení
     * běžného roku. Čte {@see \MyInvoice\Service\Accounting\Reports\FinancialStatementService}.
     */
    public function getComparativeFromPriorYear(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT comparative_from_prior_year FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $v = $stmt->fetchColumn();
        return $v !== false && $v !== null && (int) $v === 1;
    }

    /** Partial upsert jen tohoto sloupce. */
    public function setComparativeFromPriorYear(int $supplierId, bool $enabled): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, comparative_from_prior_year)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE comparative_from_prior_year = VALUES(comparative_from_prior_year)'
        )->execute([$supplierId, $enabled ? 1 : 0]);
    }

    /**
     * Souhrnné vykázání daňových pohledávek a závazků vůči finančnímu úřadu v rozvaze
     * (§ 58 odst. 2 vyhl. 500/2002 Sb.) v účetním období `$fiscalYear`. Výchozí false =
     * nekompenzuje se; zapnuté platí od roku `tax_authority_offset_from_year` (NULL = pro
     * všechna období). Čte {@see \MyInvoice\Service\Accounting\Reports\FinancialStatementService}.
     */
    public function taxAuthorityOffsetAppliesIn(int $supplierId, int $fiscalYear): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT tax_authority_offset, tax_authority_offset_from_year FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['tax_authority_offset'] !== 1) {
            return false;
        }

        return $row['tax_authority_offset_from_year'] === null || (int) $row['tax_authority_offset_from_year'] <= $fiscalYear;
    }

    /** Partial upsert jen těchto dvou sloupců. */
    public function setTaxAuthorityOffset(int $supplierId, bool $enabled, ?int $fromYear = null): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, tax_authority_offset, tax_authority_offset_from_year)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE tax_authority_offset = VALUES(tax_authority_offset),
                                     tax_authority_offset_from_year = VALUES(tax_authority_offset_from_year)'
        )->execute([$supplierId, $enabled ? 1 : 0, $enabled ? $fromYear : null]);
    }

    /**
     * Task 11 (§7 ZoÚ): účetní politika časového rozlišení drobného majetku per firma —
     * 'none' (default) / 'pro_rata' (poměrně dle data pořízení) / 'flat_pct' (paušál %).
     * Partial upsert (jen tyto dva sloupce, vzor {@see setFxRateMode}); pct se u režimů
     * mimo flat_pct ukládá jako NULL, ať v DB nezůstane matoucí hodnota.
     *
     * @param 'none'|'pro_rata'|'flat_pct' $mode
     */
    public function setSmallAssetAccrual(int $supplierId, string $mode, ?float $pct): void
    {
        $storedPct = $mode === 'flat_pct' ? $pct : null;
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, small_asset_accrual_mode, small_asset_accrual_pct)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE small_asset_accrual_mode = VALUES(small_asset_accrual_mode),
                                     small_asset_accrual_pct  = VALUES(small_asset_accrual_pct)'
        )->execute([$supplierId, $mode, $storedPct]);
    }

    /**
     * Fáze F (§24/7 ZoÚ): režim kurzu firmy — 'daily' (default) / 'fixed_monthly' /
     * 'fixed_annual'. Čte {@see \MyInvoice\Service\Currency\FixedExchangeRateService}.
     */
    public function getFxRateMode(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT fx_rate_mode FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? 'daily' : (string) $v;
    }

    /**
     * Nastaví režim kurzu (partial upsert — ostatní sloupce beze změny). Přepnutí
     * platí jen do budoucna (nově ukládané doklady); už zaúčtované si drží kurz na
     * hlavičce dokladu.
     *
     * @param 'daily'|'fixed_monthly'|'fixed_annual' $mode
     */
    public function setFxRateMode(int $supplierId, string $mode): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, fx_rate_mode)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE fx_rate_mode = VALUES(fx_rate_mode)'
        )->execute([$supplierId, $mode]);
    }

    /**
     * F4 flagy: null = nezměnit (u INSERTu platí DB default 0/0/1) — stávající
     * volající bez flagů se chovají beze změny.
     *
     * @param 'full'|'small'|'micro'|null $scopeOverride
     */
    public function upsert(
        int $supplierId,
        ?int $avgEmployees,
        ?string $scopeOverride,
        ?bool $statutoryAudit = null,
        ?bool $manualDocSeries = null,
        ?bool $fxReversalAtOpen = null,
    ): void {
        $sa  = $statutoryAudit === null ? null : (int) $statutoryAudit;
        $mds = $manualDocSeries === null ? null : (int) $manualDocSeries;
        $fx  = $fxReversalAtOpen === null ? null : (int) $fxReversalAtOpen;
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings
                (supplier_id, avg_employees, statement_scope_override,
                 statutory_audit, manual_doc_series, fx_reversal_at_open)
             VALUES (?, ?, ?, COALESCE(?, 0), COALESCE(?, 0), COALESCE(?, 1))
             ON DUPLICATE KEY UPDATE avg_employees = VALUES(avg_employees),
                                     statement_scope_override = VALUES(statement_scope_override),
                                     statutory_audit = COALESCE(?, statutory_audit),
                                     manual_doc_series = COALESCE(?, manual_doc_series),
                                     fx_reversal_at_open = COALESCE(?, fx_reversal_at_open)'
        )->execute([$supplierId, $avgEmployees, $scopeOverride, $sa, $mds, $fx, $sa, $mds, $fx]);
    }

    /**
     * B8 (audit 2026-07): měkký zámek účtování k datu. NULL = žádný zámek.
     */
    public function getLockedUntil(int $supplierId, bool $currentRead = false): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?' . ($currentRead ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? null : (string) $v;
    }

    /**
     * Explicitní nastavení zámku (admin) — smí zámek posunout i ZPĚT nebo zrušit (NULL),
     * proto přímý zápis. Ostatní sloupce se nedotýká (partial upsert jen locked_until).
     */
    public function setLockedUntil(int $supplierId, ?string $lockedUntil): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$supplierId, $lockedUntil]);
    }

    /**
     * Posun zámku VPŘED (auto-lock po archivaci DPH podání, VAT-lock/H7). Nikdy nezmenší
     * existující pozdější zámek — GREATEST(COALESCE(current, new), new): NULL → new, jinak
     * max(current, new). Idempotentní vůči opakované archivaci téhož období.
     */
    public function advanceLockedUntil(int $supplierId, string $lockedUntil): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE
                 locked_until = GREATEST(COALESCE(locked_until, VALUES(locked_until)), VALUES(locked_until))'
        )->execute([$supplierId, $lockedUntil]);
    }
}
