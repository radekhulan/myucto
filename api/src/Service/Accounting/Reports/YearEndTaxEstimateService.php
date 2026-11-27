<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Tax\Return\DppoBooksFingerprint;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\Return\TaxReturnService;

/**
 * Odhad výsledku a daně z příjmů do konce otevřeného roku pro pohled výsledovky po účtech.
 *
 * Nic nepočítá sám, jen skládá čísla existujících služeb:
 *   - VH, nezaúčtované uzávěrkové operace, základ a daň: náhled DPPO
 *     ({@see TaxReturnService::previewReadOnly()} → DppoReturnDataProvider +
 *     ClosingProjectionCalculator + DppoReturnCalculator), tedy stejná čísla jako stránka
 *     náhledu přiznání včetně ručních úprav, ztráty, darů a slev;
 *   - zaplacené zálohy: tatáž hodnota, se kterou počítá náhled (ruční vstup přiznání,
 *     jinak jisté spárované zálohy z evidence §38a);
 *   - nezaúčtované odpisy roku jsou položkou téže projekce uzávěrky (účetní snižují VH,
 *     rozdíl proti daňovým jde do ř. 50/150), takže je blok i náhled DPPO mají stejně.
 *
 *   - můstek mezi VH k datu výkazu (tabulka výsledovky) a zaúčtovaným VH celého období:
 *     {@see DppoBooksFingerprint::profitBeforeTaxBridge()}, stejné vymezení zápisů jako
 *     ř. 10 náhledu, takže součet můstku je `vh_posted` na haléř.
 *
 * Blok má smysl jen pro právnickou osobu v roce, který ještě nemá zaúčtovanou daň
 * z příjmů (591) a není uzavřený. Daň fyzické osoby není nákladem podniku, takže
 * „VH po zdanění" u ní nedává smysl a blok se nevrací.
 */
final class YearEndTaxEstimateService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly TaxReturnService $taxReturns,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function estimate(int $supplierId, int $periodId, ?string $asOf = null): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        if ($asOf !== null && ($asOf < (string) $period['starts_on'] || $asOf > (string) $period['ends_on'])) {
            throw new ReportException('validation_failed', 'as_of musí ležet uvnitř zvoleného období.', 422);
        }
        $fiscalYear = (int) $period['fiscal_year'];
        $out = [
            'applicable' => false,
            'reason' => null,
            'period' => [
                'id' => (int) $period['id'],
                'fiscal_year' => $fiscalYear,
                'starts_on' => (string) $period['starts_on'],
                'ends_on' => (string) $period['ends_on'],
                'status' => (string) $period['status'],
            ],
        ];

        if (AccountingPeriodStatus::isClosed((string) $period['status'])) {
            return ['reason' => 'period_closed'] + $out;
        }
        if ($this->taxpayerType($supplierId) === 'fo') {
            return ['reason' => 'taxpayer_fo'] + $out;
        }
        if ($this->incomeTaxPosted($supplierId, (string) $period['starts_on'], (string) $period['ends_on'])) {
            return ['reason' => 'income_tax_posted'] + $out;
        }

        try {
            $preview = $this->taxReturns->previewReadOnly($supplierId, $fiscalYear, 'po');
        } catch (TaxReturnException $e) {
            return ['reason' => 'tax_unavailable', 'message' => $e->getMessage()] + $out;
        }

        $result = $preview['result'];
        $summary = (array) ($result['summary'] ?? []);
        $projection = is_array($result['projection'] ?? null) ? $result['projection'] : null;
        $closing = is_array($preview['podklady']['closing_projection'] ?? null) ? $preview['podklady']['closing_projection'] : null;
        $isProjection = $projection !== null && ($projection['is_projection'] ?? false) === true;

        $vhPosted = round((float) ($preview['podklady']['vh'] ?? 0), 2);
        $vhBeforeTax = $isProjection ? round((float) $projection['vh_projected'], 2) : $vhPosted;
        $tax = round((float) ($isProjection ? $projection['projected_tax'] : ($result['tax'] ?? 0)), 2);
        $base = round((float) ($isProjection ? $projection['projected_base'] : ($summary['base'] ?? 0)), 2);
        $advances = round((float) ($result['advances_paid'] ?? 0), 2);
        $bridge = ($preview['podklady']['accounting_decree'] ?? '500') === '504'
            ? null
            : $this->bridge($supplierId, $period, $asOf, $vhPosted);
        $closingItems = array_values((array) ($closing['items'] ?? []));
        $vhAfterTax = round($vhBeforeTax - $tax, 2);
        $balance = self::balanceEffects($closingItems, $tax, round($vhAfterTax - $vhPosted, 2));

        return [
            'applicable' => true,
            'reason' => null,
            'period' => $out['period'],
            'return_status' => $preview['status'],
            'vh_posted' => $vhPosted,
            'bridge' => $bridge,
            'closing_items' => $closingItems,
            // Kroky uzávěrky, jejichž náhled selhal: projekce je bez nich neúplná.
            'projection_warnings' => array_values((array) ($closing['warnings'] ?? [])),
            // Rozdíl účetních a daňových odpisů: karty v konceptu, odpisy na 551 mimo karty apod.
            'depreciation_warnings' => array_values(array_map('strval', (array) ($preview['podklady']['depreciation_warnings'] ?? []))),
            'is_projection' => $isProjection,
            'vh_before_tax' => $vhBeforeTax,
            'increases' => round((float) ($isProjection ? ($projection['projected_increases'] ?? $this->lineValue($result, 70)) : $this->lineValue($result, 70)), 2),
            'decreases' => round((float) ($isProjection ? ($projection['projected_decreases'] ?? $this->lineValue($result, 170)) : $this->lineValue($result, 170)), 2),
            'tax_base' => $base,
            'tax' => $tax,
            'advances_paid' => $advances,
            'advances_source' => $preview['advances_source'],
            'balance_due' => round($tax - $advances, 2),
            'vh_after_tax' => $vhAfterTax,
            'balance_effects' => $balance['items'],
            'balance_check' => $balance['check'],
        ];
    }

    /**
     * Rozvahový protiúčet kroků projekce uzávěrky ({@see \MyInvoice\Service\Tax\Return\ClosingProjectionCalculator}):
     * každý krok je dvojice výsledkový účet ↔ jeden rozvahový účet, takže dopad na VH (znaménko
     * × částka) je zároveň dopad na rozvahu. `asset` = mění aktiva o dopad na VH, `liability`
     * = mění pasiva opačně, `net` = netto více rozvahových účtů (aktiva − pasiva), které
     * projekce po účtech nerozkládá (kurzové rozdíly, rozpuštění 381/384).
     */
    private const BALANCE_EFFECTS = [
        'small_asset_accrual' => ['381', 'asset'],
        'prepaid_expense_accrual' => ['381', 'asset'],
        'deferred_revenue_accrual' => ['384', 'liability'],
        'prior_deferral_release' => ['381/384', 'net'],
        'accrual_agenda_release' => ['381/384', 'net'],
        'fx_revaluation' => ['21x/22x/31x/32x/46x', 'net'],
        'stock_closing' => ['112/123/132', 'asset'],
        'depreciation' => ['07x/08x', 'asset'],
        'provision' => ['39x', 'asset'],
        'estimate' => ['389', 'liability'],
    ];

    /**
     * Odhadované změny rozvahy z návrhů uzávěrky a daně z příjmů (341). Návrhy k potvrzení
     * (`optional`) se ukazují, ale nesčítají, stejně jako ve VH. Kontrola: Σ změn aktiv −
     * Σ změn pasiv = odhad VH po zdanění − zaúčtovaný VH, takže rozvaha po odhadu zůstane
     * vyrovnaná.
     *
     * @param list<array<string,mixed>> $closingItems
     * @return array{items:list<array<string,mixed>>, check:array{assets:float,liabilities:float,difference:float,expected:float,matches:bool}}
     */
    private static function balanceEffects(array $closingItems, float $tax, float $expected): array
    {
        $items = [];
        $assets = 0;
        $liabilities = 0;
        foreach ($closingItems as $item) {
            $key = (string) $item['key'];
            [$accounts, $side] = self::BALANCE_EFFECTS[$key] ?? [null, 'net'];
            $vhCents = (int) round((float) $item['amount'] * 100) * ((int) $item['sign'] >= 0 ? 1 : -1);
            $assetCents = $side === 'liability' ? 0 : $vhCents;
            $liabilityCents = $side === 'liability' ? -$vhCents : 0;
            $optional = (bool) ($item['optional'] ?? false);
            if (!$optional) {
                $assets += $assetCents;
                $liabilities += $liabilityCents;
            }
            $items[] = ['key' => $key, 'label_key' => (string) ($item['label_key'] ?? ''), 'accounts' => $accounts, 'side' => $side,
                'assets' => round($assetCents / 100, 2), 'liabilities' => round($liabilityCents / 100, 2), 'optional' => $optional];
        }
        $taxCents = (int) round($tax * 100);
        if ($taxCents !== 0) {
            $liabilities += $taxCents;
            $items[] = ['key' => 'income_tax', 'label_key' => 'accounting.statement_accounts.estimate.balance_income_tax', 'accounts' => '341',
                'side' => 'liability', 'assets' => 0.0, 'liabilities' => round($taxCents / 100, 2), 'optional' => false];
        }
        $expectedCents = (int) round($expected * 100);

        return ['items' => $items, 'check' => [
            'assets' => round($assets / 100, 2),
            'liabilities' => round($liabilities / 100, 2),
            'difference' => round(($assets - $liabilities) / 100, 2),
            'expected' => round($expectedCents / 100, 2),
            'matches' => $assets - $liabilities === $expectedCents,
        ]];
    }

    /**
     * Můstek od VH k datu výkazu k `vh_posted`. Bez data výkazu, nebo když den výkazu je
     * konec období, není co přemosťovat (null). Rámec 504 má VH z vlastního zdroje
     * posouzení neziskové organizace, proto můstek nemá (volající vrací null).
     *
     * @param array<string,mixed> $period
     * @return array{as_of:string, ends_on:string, vh_as_of:float, groups:list<array{key:string,amount:float}>, vh_posted:float}|null
     */
    private function bridge(int $supplierId, array $period, ?string $asOf, float $vhPosted): ?array
    {
        $endsOn = (string) $period['ends_on'];
        if ($asOf === null || $asOf >= $endsOn) {
            return null;
        }
        $raw = (new DppoBooksFingerprint($this->db))->profitBeforeTaxBridge($supplierId, (string) $period['starts_on'], $asOf, $endsOn);
        $totalCents = (int) round($raw['as_of'] * 100);
        $groups = [];
        foreach ($raw['groups'] as $key => $amount) {
            $cents = (int) round($amount * 100);
            $totalCents += $cents;
            if ($cents !== 0) {
                $groups[] = ['key' => $key, 'amount' => $amount];
            }
        }
        // Součet musí sedět na haléř. Nesedí jen při zaúčtování mezi dotazy: pak se můstek
        // radši nevrací, než aby blok ukázal nesouhlasící řádky.
        if ($totalCents !== (int) round($vhPosted * 100)) {
            error_log(sprintf('Odhad do konce roku (firma %d): můstek %.2f nesouhlasí s VH %.2f, vynechán.', $supplierId, $totalCents / 100, $vhPosted));
            return null;
        }

        return [
            'as_of' => $asOf,
            'ends_on' => $endsOn,
            'vh_as_of' => $raw['as_of'],
            'groups' => $groups,
            'vh_posted' => $vhPosted,
        ];
    }

    /** @param array<string,mixed> $result */
    private function lineValue(array $result, int $line): float
    {
        foreach ((array) ($result['lines'] ?? []) as $l) {
            if ((int) ($l['line'] ?? 0) === $line) {
                return round((float) $l['value'], 2);
            }
        }

        return 0.0;
    }

    private function taxpayerType(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT taxpayer_type FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);

        return strtolower(trim((string) $stmt->fetchColumn()));
    }

    /**
     * Leží v období zaúčtovaná daň z příjmů (591)? Uzávěrkový zápis se nepočítá — převádí
     * zůstatek 591 na 710, takže by jinak zaúčtovanou daň vynuloval.
     */
    private function incomeTaxPosted(int $supplierId, string $startsOn, string $endsOn): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND a.account_code LIKE '591%'
                AND (e.source_type IS NULL OR e.source_type <> 'closing' OR e.source_id >= ?)"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE]);

        return (int) round((float) $stmt->fetchColumn() * 100) !== 0;
    }
}
