<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Projekce dosud NEZAÚČTOVANÝCH závěrkových operací do výsledku hospodaření (VH) pro
 * náhled DPPO (Epic DP). Čistá, testovatelná třída bez DB — dostává preview struktury
 * z {@see \MyInvoice\Service\Accounting\Closing\ClosingService} a skládá z nich:
 *   vh_posted    = VH z už zaúčtovaného deníku (Σ 6xx − 5xx, viz DppoReturnDataProvider)
 *   items        = per-krok dopad NEzaúčtované operace na VH (+/−) s příznakem `optional`
 *   vh_projected = vh_posted + Σ dopadů (mimo `optional` = informativní návrhy účetní)
 *
 * Zohledňují se JEN kroky, které ještě NEJSOU zaúčtované (jinak už jsou ve vh_posted),
 * aby nedošlo k dvojímu započtení. Rozhodnutí „zaúčtováno?“ dělá volající:
 *   • drobný majetek 381 (defer)       → +VH (snižuje náklad 501)  [preview.existing===null]
 *   • náklady příštích období 381      → +VH (snižuje náklad 5xx)  [preview.existing===null]
 *   • kurzové rozdíly 563/663          → ±VH (zisk − ztráta)        [volající: null, když už zaúčtováno]
 *   • rozpuštění 381 z minulého období → −VH (zvyšuje náklad 5xx)  [volající: jen pending částka]
 *   • opravné položky 558/559          → −VH (INFORMATIVNÍ — potvrzuje účetní per pohledávka)
 *   • dohadné položky pasivní 5xx/389  → −VH (INFORMATIVNÍ — potvrzuje účetní per dodavatel)
 *   • konečný stav zásob (způsob B)    → +VH (112/132/123 proti 501/504/583) [volající: jen nezaúčtovaný]
 *   • odpisy roku 551                  → −VH, daňový odpis do `depreciation` [volající: jen pending část]
 *   • agenda Časové rozlišení          → náklad 381 −VH, výnos 384 +VH [volající: jen nezaúčtované řádky plánu]
 *
 * Opravné položky a dohady jsou návrhy, které účetní teprve potvrdí/upraví/zamítne — proto
 * `optional=true` a do vh_projected (a odvozené daně) se NEzapočítávají, jen se zobrazí.
 */
final class ClosingProjectionCalculator
{
    /**
     * @param array{
     *   small_asset?:array<string,mixed>|null,
     *   prepaid?:array<string,mixed>|null,
     *   deferred_revenue?:array<string,mixed>|null,
     *   fx?:array<string,mixed>|null,
     *   prior_release?:array<string,mixed>|null,
     *   provisions?:array<string,mixed>|null,
     *   estimates?:array<string,mixed>|null,
     *   depreciation?:array{pending_accounting?:float,pending_tax?:float}|null,
     *   stock?:array{total?:float}|null,
     *   accrual_agenda?:array{expense?:float,revenue?:float}|null
     * } $sources
     * @return array{
     *   vh_posted:float,
     *   items:list<array{key:string,label_key:string,amount:float,sign:int,optional:bool}>,
     *   vh_projected:float,
     *   depreciation:array{accounting:float,tax:float},
     *   is_projection:bool
     * }
     */
    public function project(float $vhPosted, array $sources): array
    {
        $items = [];

        // Drobný majetek 381 (§DM): defer sníží náklad 501 → +VH. Jen když ještě není zaúčtováno.
        $sa = $sources['small_asset'] ?? null;
        if (is_array($sa) && ($sa['existing'] ?? null) === null) {
            $amount = round((float) ($sa['total'] ?? 0), 2);
            if (self::cents($amount) !== 0) {
                $items[] = self::item('small_asset_accrual', 'taxReturn.proj_small_asset', $amount, 1, false);
            }
        }

        // Náklady příštích období 381 (§DČR): defer sníží náklad 5xx → +VH.
        $pe = $sources['prepaid'] ?? null;
        if (is_array($pe) && ($pe['existing'] ?? null) === null) {
            $amount = round((float) ($pe['total'] ?? 0), 2);
            if (self::cents($amount) !== 0) {
                $items[] = self::item('prepaid_expense_accrual', 'taxReturn.proj_prepaid', $amount, 1, false);
            }
        }

        // Výnosy příštích období 384: odklad sníží výnos 6xx → −VH. Dobropisy s obdobím
        // mohou návrh otočit do záporu (+VH).
        $dr = $sources['deferred_revenue'] ?? null;
        if (is_array($dr) && ($dr['existing'] ?? null) === null) {
            $amount = round((float) ($dr['total'] ?? 0), 2);
            if (self::cents($amount) !== 0) {
                $items[] = self::item('deferred_revenue_accrual', 'taxReturn.proj_deferred_revenue', abs($amount), $amount >= 0 ? -1 : 1, false);
            }
        }

        // Kurzové rozdíly: zisk 663 (+VH) − ztráta 563 (−VH). Volající předá null, když je fx k
        // rozvahovému dni už zaúčtováno (jinak už je ve vh_posted).
        $fx = $sources['fx'] ?? null;
        if (is_array($fx)) {
            $gain = round((float) ($fx['totals']['gain'] ?? 0), 2);
            $loss = round((float) ($fx['totals']['loss'] ?? 0), 2);
            $delta = round($gain - $loss, 2);
            if (self::cents($delta) !== 0) {
                $items[] = self::item('fx_revaluation', 'taxReturn.proj_fx', abs($delta), $delta >= 0 ? 1 : -1, false);
            }
        }

        // Rozpuštění 381 z minulého období do tohoto (MD 5xx / D 381) zvýší náklad → −VH. Volající
        // předá jen část, kterou open_next do tohoto období JEŠTĚ nerozpustil (pending).
        $rel = $sources['prior_release'] ?? null;
        if (is_array($rel)) {
            $amount = round((float) ($rel['total'] ?? 0), 2);
            if (self::cents($amount) !== 0) {
                // Kladný total = rozpuštěný náklad (−VH); záporný = převažuje rozpuštěný výnos 384 (+VH).
                $items[] = self::item('prior_deferral_release', 'taxReturn.proj_prior_release', abs($amount), $amount >= 0 ? -1 : 1, false);
            }
        }

        // Opravné položky (558/559) → −VH. INFORMATIVNÍ: jen část dosud nezaúčtovaná nad rámec už
        // vytvořených OP; do vh_projected se nezapočítává (účetní ji per pohledávka potvrdí).
        $pr = $sources['provisions'] ?? null;
        if (is_array($pr)) {
            $suggested = round((float) ($pr['totals']['suggested_legal'] ?? 0), 2);
            $existing = round((float) ($pr['totals']['existing_legal'] ?? 0), 2);
            $pending = round($suggested - $existing, 2);
            if ($pending > 0.005) {
                $items[] = self::item('provision', 'taxReturn.proj_provision', $pending, -1, true);
            }
        }

        // Dohadné položky pasivní (5xx/389) → −VH. INFORMATIVNÍ: návrh k potvrzení per dodavatel.
        $es = $sources['estimates'] ?? null;
        if (is_array($es)) {
            $amount = round((float) ($es['totals']['suggested_amount'] ?? 0), 2);
            if (self::cents($amount) !== 0) {
                $items[] = self::item('estimate', 'taxReturn.proj_estimate', $amount, -1, true);
            }
        }

        // Konečný stav zásob (způsob B, MD 112/132/123 proti 501/504/583) → +VH. Volající předá
        // jen nezaúčtovaný krok.
        $st = $sources['stock'] ?? null;
        if (is_array($st)) {
            $amount = round((float) ($st['total'] ?? 0), 2);
            if (self::cents($amount) !== 0) {
                $items[] = self::item('stock_closing', 'taxReturn.proj_stock', abs($amount), $amount >= 0 ? 1 : -1, false);
            }
        }

        // Agenda Časové rozlišení: nezaúčtované rozpuštění položek do konce období. Náklad 381
        // (MD 5xx) sníží VH, výnos 384 (D 6xx) ho zvýší; netto jednou položkou.
        $ag = $sources['accrual_agenda'] ?? null;
        if (is_array($ag)) {
            $net = round((float) ($ag['revenue'] ?? 0) - (float) ($ag['expense'] ?? 0), 2);
            if (self::cents($net) !== 0) {
                $items[] = self::item('accrual_agenda_release', 'taxReturn.proj_accrual_agenda', abs($net), $net >= 0 ? 1 : -1, false);
            }
        }

        // Nezaúčtované odpisy roku (DepreciationPostingService::previewYear): účetní odpis
        // sníží VH (551), daňový jde do rozdílu odpisů ř. 50/150, který z `depreciation`
        // dopočte DppoReturnCalculator stejně jako u zaúčtovaných odpisů.
        $dep = $sources['depreciation'] ?? null;
        $pendingAcc = is_array($dep) ? round((float) ($dep['pending_accounting'] ?? 0), 2) : 0.0;
        $pendingTax = is_array($dep) ? round((float) ($dep['pending_tax'] ?? 0), 2) : 0.0;
        if (self::cents($pendingAcc) !== 0) {
            $items[] = self::item('depreciation', 'taxReturn.proj_depreciation', abs($pendingAcc), $pendingAcc > 0 ? -1 : 1, false);
        }

        $vhProjected = round($vhPosted, 2);
        $hasProjection = self::cents($pendingTax) !== 0;
        foreach ($items as $it) {
            if (!$it['optional']) {
                $vhProjected = round($vhProjected + $it['sign'] * $it['amount'], 2);
                $hasProjection = true;
            }
        }

        return [
            'vh_posted' => round($vhPosted, 2),
            'items' => $items,
            'vh_projected' => $vhProjected,
            'depreciation' => ['accounting' => $pendingAcc, 'tax' => $pendingTax],
            'is_projection' => $hasProjection,
        ];
    }

    /**
     * @return array{key:string,label_key:string,amount:float,sign:int,optional:bool}
     */
    private static function item(string $key, string $labelKey, float $amount, int $sign, bool $optional): array
    {
        return [
            'key' => $key,
            'label_key' => $labelKey,
            'amount' => round($amount, 2),
            'sign' => $sign >= 0 ? 1 : -1,
            'optional' => $optional,
        ];
    }

    private static function cents(float $v): int
    {
        return (int) round($v * 100);
    }
}
