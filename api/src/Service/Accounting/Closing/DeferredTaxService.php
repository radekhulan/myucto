<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountRoleResolver;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Service\Accounting\FrameworkOperationPolicy;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Odložená daň — ČÚS 003 a § 59 vyhlášky 500/2002 Sb. (účty 481 a 592).
 *
 * Systém o odložené dani neúčtoval vůbec: účty 481 a 592 byly jen řádky v šabloně osnovy
 * a výkazy je měly namapované, ale nic na ně nikdy nepřistálo — žádný výpočet přechodných
 * rozdílů, žádná kontace, žádný krok uzávěrky. Splatná daň 591/341 přitom hotová byla.
 *
 * ── Co se počítá ────────────────────────────────────────────────────────────────────
 * Přechodný rozdíl k rozvahovému dni, tedy rozdíl mezi ÚČETNÍ a DAŇOVOU hodnotou aktiv
 * a závazků, který se v budoucnu projeví v základu daně:
 *
 *   1. Dlouhodobý majetek — účetní zůstatková cena minus daňová (KUMULATIVNĚ za celé
 *      portfolio, ne roční rozdíl odpisů). Účetní ZC vyšší než daňová znamená, že daňové
 *      odpisy předběhly účetní: v budoucnu se odečte méně, základ daně bude vyšší →
 *      odložený daňový ZÁVAZEK.
 *   2. Daňová ztráta k převedení (§ 34 odst. 1 ZDP) — v budoucnu sníží základ daně
 *      → odložená daňová POHLEDÁVKA.
 *   3. Ruční tituly (opravné položky, rezervy nad rámec ZoR, …) — systém je z dat
 *      spolehlivě neodliší, proto se zadávají.
 *
 * Trvalé rozdíly (nedaňové náklady) do odložené daně NEPATŘÍ — nikdy se v základu daně
 * neprojeví, takže by je zahrnutí systematicky nadhodnotilo.
 *
 * ── Sazba ───────────────────────────────────────────────────────────────────────────
 * § 59 odst. 2: použije se sazba období, ve kterém se rozdíl uplatní, tedy sazba
 * NÁSLEDUJÍCÍHO roku, ne roku běžného. Není-li pro něj ještě ověřená sada konstant,
 * spadne se na sazbu běžného roku a služba to hlásí — tiché použití staré sazby by
 * u roku se změnou sazby dalo špatné číslo.
 *
 * ── Opatrnost u pohledávky (§ 59 odst. 4) ───────────────────────────────────────────
 * Odložená daňová POHLEDÁVKA se zaúčtuje jen tehdy, je-li pravděpodobné, že bude v budoucnu
 * dosaženo základu daně, o který ji lze uplatnit. Tuhle pravděpodobnost systém posoudit
 * nemůže, proto ji NIKDY neúčtuje sám: {@see compute()} ji vrátí s příznakem
 * `requires_prudence_check` a rozhodnutí zůstává na účetní. Závazek se naopak účtuje vždy.
 *
 * Read-only: nic neúčtuje, jen počítá podklad pro krok uzávěrky.
 */
final class DeferredTaxService
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * Podklad odložené daně k rozvahovému dni.
     *
     * @param array<string,float> $manual ruční tituly: popis => přechodný rozdíl
     *        (kladný = budoucí zvýšení základu daně → závazek)
     *
     * @return array{
     *   fiscal_year:int, as_of:string, rate:float, rate_year:int, rate_is_fallback:bool,
     *   titles:list<array{key:string, label:string, difference:float, kind:string}>,
     *   net_difference:float, deferred_tax:float, kind:string,
     *   requires_prudence_check:bool, warnings:list<string>
     * }
     */
    public function compute(int $supplierId, int $fiscalYear, string $asOf, array $manual = []): array
    {
        try {
            FrameworkOperationPolicy::assertAllowed(AccountRoleResolver::fromConnection($this->db)->forDate($supplierId, $asOf), 'deferred_tax');
        } catch (AccountingFrameworkException $e) {
            throw new ClosingException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        [$rate, $rateYear, $rateFallback] = $this->rateFor($fiscalYear);
        $warnings = [];
        if ($rateFallback) {
            $warnings[] = sprintf(
                'Pro rok %d nejsou ověřené daňové konstanty — použita sazba roku %d (%s %%). '
                    . 'Podle § 59 odst. 2 vyhlášky se má použít sazba období, ve kterém se rozdíl uplatní.',
                $fiscalYear + 1,
                $rateYear,
                number_format($rate * 100, 2, ',', ' '),
            );
        }

        $titles = [];

        $assets = $this->assetDifference($supplierId, $fiscalYear);
        if (round($assets, 2) != 0.0) {
            $titles[] = [
                'key'        => 'assets',
                'label'      => 'Rozdíl účetní a daňové zůstatkové ceny dlouhodobého majetku',
                'difference' => round($assets, 2),
                'kind'       => $assets > 0 ? 'liability' : 'asset',
            ];
        }

        $loss = $this->taxLossCarryforward($supplierId, $fiscalYear);
        if (round($loss, 2) > 0.0) {
            // Ztráta budoucí základ SNÍŽÍ → záporný přechodný rozdíl → pohledávka.
            $titles[] = [
                'key'        => 'tax_loss',
                'label'      => 'Daňová ztráta k převedení (§ 34 odst. 1 ZDP)',
                'difference' => round(-$loss, 2),
                'kind'       => 'asset',
            ];
        }

        foreach ($manual as $label => $difference) {
            $difference = round((float) $difference, 2);
            if ($difference == 0.0) {
                continue;
            }
            $titles[] = [
                'key'        => 'manual',
                'label'      => (string) $label,
                'difference' => $difference,
                'kind'       => $difference > 0 ? 'liability' : 'asset',
            ];
        }

        $net = round(array_sum(array_column($titles, 'difference')), 2);
        // § 59: odložená daňová pohledávka i závazek se vykazují jedinou částkou na 481,
        // takže rozhoduje ČISTÝ rozdíl, ne jednotlivé tituly.
        $deferred = round($net * $rate, 2);
        $kind = $deferred > 0.0 ? 'liability' : ($deferred < 0.0 ? 'asset' : 'none');

        if ($kind === 'asset') {
            $warnings[] = 'Vychází odložená daňová POHLEDÁVKA. Podle § 59 odst. 4 vyhlášky se '
                . 'účtuje jen tehdy, je-li pravděpodobné, že bude dosaženo základu daně, o který '
                . 'ji lze uplatnit — to systém posoudit nemůže, posuďte to před zaúčtováním.';
        }

        return [
            'fiscal_year'             => $fiscalYear,
            'as_of'                   => $asOf,
            'rate'                    => $rate,
            'rate_year'               => $rateYear,
            'rate_is_fallback'        => $rateFallback,
            'titles'                  => $titles,
            'net_difference'          => $net,
            'deferred_tax'            => $deferred,
            'kind'                    => $kind,
            'requires_prudence_check' => $kind === 'asset',
            'warnings'                => $warnings,
        ];
    }

    /**
     * Sazba pro odloženou daň — § 59 odst. 2: sazba období, ve kterém se rozdíl uplatní,
     * tedy NÁSLEDUJÍCÍHO roku. Chybí-li pro něj konstanty, spadne se na běžný rok.
     *
     * @return array{0:float, 1:int, 2:bool} [sazba, rok sazby, použit fallback]
     */
    private function rateFor(int $fiscalYear): array
    {
        try {
            $next = $this->taxConstants->forExactYear($fiscalYear + 1);
            if ($next !== [] && isset($next['corporate_tax_rate'])) {
                return [(float) $next['corporate_tax_rate'], $fiscalYear + 1, false];
            }
        } catch (\Throwable) {
            // Konstanty pro následující rok ještě nejsou — fallback níž.
        }

        return [(float) $this->taxConstants->forYear($fiscalYear)['corporate_tax_rate'], $fiscalYear, true];
    }

    /**
     * Kumulativní rozdíl účetní a daňové zůstatkové ceny majetku k rozvahovému dni.
     *
     * Bere se POSLEDNÍ evidovaný odpis každého druhu do daného roku včetně — majetek, který
     * se v běžném roce už neodepisuje (dokončený, pozastavený), by při filtru na jediný rok
     * z výpočtu vypadl a rozdíl by tiše zmizel, ačkoli přechodný rozdíl trvá dál.
     *
     * Kladný výsledek = účetní ZC > daňová ZC (daňové odpisy předběhly účetní) → závazek.
     */
    private function assetDifference(int $supplierId, int $fiscalYear): float
    {
        $sql =
            "SELECT de.kind, SUM(de.residual_value_end) AS residual
               FROM depreciation_entries de
               JOIN (
                   SELECT asset_id, kind, MAX(fiscal_year) AS last_year
                     FROM depreciation_entries
                    WHERE supplier_id = ? AND fiscal_year <= ?
                 GROUP BY asset_id, kind
               ) last
                 ON last.asset_id = de.asset_id
                AND last.kind = de.kind
                AND last.last_year = de.fiscal_year
              WHERE de.supplier_id = ?
           GROUP BY de.kind";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $fiscalYear, $supplierId]);

        $byKind = ['accounting' => 0.0, 'tax' => 0.0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $byKind[(string) $r['kind']] = (float) $r['residual'];
        }

        return $byKind['accounting'] - $byKind['tax'];
    }

    /** Neuplatněná daňová ztráta právnické osoby k rozvahovému dni (§ 34 odst. 1 ZDP). */
    private function taxLossCarryforward(int $supplierId, int $fiscalYear): float
    {
        if (!$this->db->hasTable('tax_losses')) {
            return 0.0;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(amount), 0)
               FROM tax_losses
              WHERE supplier_id = ? AND taxpayer_type = 'po' AND origin_year <= ?"
        );
        $stmt->execute([$supplierId, $fiscalYear]);

        return round((float) $stmt->fetchColumn(), 2);
    }
}
