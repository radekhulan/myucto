<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Service\Report\VatLedgerService;

/**
 * Skutečný nárok na odpočet a členění DPH přijatého dokladu (jediné místo pravdy).
 *
 * Evidence DPH ({@see VatLedgerService::fetchPurchases()}) bere u dokladu s rozpisem DPH
 * (`purchase_invoice_vat_allocations`) nárok i kód z řádků rozpisu a hlavičku ani položky
 * nečte. Bez rozpisu rozhoduje nárok hlavičky a kód `COALESCE(položka, hlavička)`.
 * Tahle třída to říká uživateli stejnými pravidly: detail i editor ukazují nárok, se
 * kterým doklad do evidence opravdu jde, ne jen hodnotu v hlavičce.
 *
 * Druhá polovina je změna nároku v hlavičce u dokladu s rozpisem
 * ({@see syncAllocationsWithHeader()}): promítne se jen tam, kde je to jednoznačné,
 * jinak se uložení odmítne. Hlavička a rozpis se tak tiše nerozejdou.
 */
final class PurchaseVatTreatment
{
    public const MODES = ['full', 'none', 'proportional', 'reduced'];

    /**
     * Kód přijatého plnění, který do přiznání ani KH nevstupuje (42 „bez nároku", „mimo"
     * a jejich per-tenant obdoby): nemá řádek přiznání, oddíl KH a není samovyměřením.
     *
     * @param array<string, array<string,mixed>> $classMap {@see VatLedgerService::classificationMapFor()}
     */
    public static function isNoClaimCode(?string $code, array $classMap): bool
    {
        $code = trim((string) $code);
        if ($code === '' || !isset($classMap[$code])) {
            return false;
        }
        $c = $classMap[$code];

        return empty($c['is_reverse_charge'])
            && trim((string) ($c['dphdp3_line'] ?? '')) === ''
            && trim((string) ($c['dphdp3_line_secondary'] ?? '')) === ''
            && trim((string) ($c['kh_section'] ?? '')) === '';
    }

    /**
     * Kód, který jde do přiznání jako ODPOČET tuzemské daně (40, 41 …): má řádek přiznání
     * a není samovyměřením. U dokladu bez nároku takový kód do evidence nevstoupí.
     *
     * @param array<string, array<string,mixed>> $classMap
     */
    public static function isClaimCode(?string $code, array $classMap): bool
    {
        $code = trim((string) $code);
        if ($code === '' || !isset($classMap[$code])) {
            return false;
        }

        return empty($classMap[$code]['is_reverse_charge']) && !self::isNoClaimCode($code, $classMap);
    }

    /** Porovnatelný klíč režimu odpočtu; procento rozhoduje jen u poměrného odpočtu. */
    public static function deductionKey(mixed $mode, mixed $percent): string
    {
        $mode = in_array($mode, self::MODES, true) ? (string) $mode : 'full';
        if ($mode !== 'proportional') {
            return $mode;
        }
        $pct = max(0.0, min(100.0, is_numeric($percent) ? (float) $percent : 100.0));

        return 'proportional:' . number_format($pct, 2, '.', '');
    }

    /**
     * Souhrn pro detail a editor.
     *
     * @param array<string,mixed> $invoice doklad z {@see \MyInvoice\Repository\PurchaseInvoiceRepository::find()}
     * @param array<string, array<string,mixed>> $classMap
     * @return array{source:string, effective:string,
     *               parts:list<array{vat_deduction:string, vat_deduction_percent:float, base_amount:float, vat_amount:float}>,
     *               items:list<array{id:int, effective_code:?string, state:string}>,
     *               warnings:list<string>}
     */
    public static function summarize(array $invoice, array $classMap): array
    {
        $allocations = array_values((array) ($invoice['vat_allocations'] ?? []));
        $headerMode = in_array($invoice['vat_deduction'] ?? null, self::MODES, true) ? (string) $invoice['vat_deduction'] : 'full';
        $headerPercent = (float) ($invoice['vat_deduction_percent'] ?? 100);
        $headerKey = self::deductionKey($headerMode, $headerPercent);
        $headerCode = trim((string) ($invoice['vat_classification_code'] ?? '')) ?: null;
        $warnings = [];

        if ($allocations !== []) {
            $groups = [];
            foreach ($allocations as $a) {
                $key = self::deductionKey($a['vat_deduction'] ?? 'full', $a['vat_deduction_percent'] ?? 100);
                $groups[$key] ??= [
                    'vat_deduction' => in_array($a['vat_deduction'] ?? null, self::MODES, true) ? (string) $a['vat_deduction'] : 'full',
                    'vat_deduction_percent' => (float) ($a['vat_deduction_percent'] ?? 100),
                    'base_amount' => 0.0,
                    'vat_amount' => 0.0,
                ];
                $groups[$key]['base_amount'] = round($groups[$key]['base_amount'] + (float) ($a['base_amount'] ?? 0), 2);
                $groups[$key]['vat_amount'] = round($groups[$key]['vat_amount'] + (float) ($a['vat_amount'] ?? 0), 2);
            }
            $parts = array_values($groups);
            $effective = count($groups) === 1 ? $parts[0]['vat_deduction'] : 'mixed';
            // Smíšený nárok hlavička vyjádřit neumí (editor ji v tu chvíli zamkne), takže
            // nesoulad hlásí jen rozpis s jediným nárokem, který hlavička nenese.
            if (count($groups) === 1 && array_keys($groups) !== [$headerKey]) {
                $warnings[] = 'header_differs_from_allocations';
            }
            $items = array_map(static fn (array $it): array => [
                'id' => (int) ($it['id'] ?? 0),
                'effective_code' => trim((string) ($it['vat_classification_code'] ?? '')) ?: null,
                'state' => 'allocations',
            ], array_values((array) ($invoice['items'] ?? [])));

            return ['source' => 'allocations', 'effective' => $effective, 'parts' => $parts, 'items' => $items, 'warnings' => $warnings];
        }

        $items = [];
        $claimItemUnderNone = false;
        $claimItemUnderNoClaimHeader = false;
        foreach ((array) ($invoice['items'] ?? []) as $it) {
            $own = trim((string) ($it['vat_classification_code'] ?? '')) ?: null;
            $code = $own ?? $headerCode;
            $state = match (true) {
                $headerMode === 'none' => 'no_claim_header',
                $code === null => 'unclassified',
                self::isNoClaimCode($code, $classMap) => 'no_claim_code',
                default => 'claim',
            };
            if ($headerMode === 'none' && self::isClaimCode($code, $classMap)) {
                $claimItemUnderNone = true;
            }
            if ($own !== null && self::isNoClaimCode($headerCode, $classMap) && self::isClaimCode($own, $classMap)) {
                $claimItemUnderNoClaimHeader = true;
            }
            $items[] = ['id' => (int) ($it['id'] ?? 0), 'effective_code' => $code, 'state' => $state];
        }
        if ($claimItemUnderNone) {
            $warnings[] = 'items_claim_code_without_header_claim';
        }
        if ($claimItemUnderNoClaimHeader) {
            $warnings[] = 'header_no_claim_code_with_claim_items';
        }

        return [
            'source' => 'header',
            'effective' => $headerMode,
            'parts' => [[
                'vat_deduction' => $headerMode,
                'vat_deduction_percent' => $headerMode === 'proportional' ? $headerPercent : ($headerMode === 'none' ? 0.0 : 100.0),
                'base_amount' => round((float) ($invoice['total_without_vat'] ?? 0), 2),
                'vat_amount' => round((float) ($invoice['total_vat'] ?? 0), 2),
            ]],
            'items' => $items,
            'warnings' => $warnings,
        ];
    }

    /**
     * Promítne změnu nároku v hlavičce do rozpisu DPH, je-li to jednoznačné.
     *
     *  - nárok v hlavičce se nemění, nebo doklad rozpis nemá → `null` (nic nedělat);
     *  - uživatel v témže požadavku změnil nárok v rozpisu → `null`, rozpis je jeho volba;
     *  - rozpis už má celý nový nárok → `null`;
     *  - celý rozpis měl dosavadní nárok hlavičky → vrátí rozpis s novým nárokem;
     *  - jinak (smíšený rozpis, nebo řádek, jehož využití nový nárok nepřipouští)
     *    → {@see PurchaseVatTreatmentConflict}.
     *
     * @param array{vat_deduction:mixed, vat_deduction_percent:mixed} $oldHeader
     * @param array{vat_deduction:mixed, vat_deduction_percent:mixed} $newHeader
     * @param list<array<string,mixed>> $stored uložený rozpis
     * @param list<array<string,mixed>>|null $sent rozpis z požadavku (null = neposlán)
     * @return list<array<string,mixed>>|null rozpis k uložení
     */
    public static function syncAllocationsWithHeader(array $oldHeader, array $newHeader, array $stored, ?array $sent): ?array
    {
        $oldKey = self::deductionKey($oldHeader['vat_deduction'] ?? null, $oldHeader['vat_deduction_percent'] ?? null);
        $newKey = self::deductionKey($newHeader['vat_deduction'] ?? null, $newHeader['vat_deduction_percent'] ?? null);
        if ($oldKey === $newKey) {
            return null;
        }
        $rows = array_values($sent ?? $stored);
        if ($rows === []) {
            return null;
        }
        if ($sent !== null && self::deductionKeys(array_values($sent)) !== self::deductionKeys(array_values($stored))) {
            return null;
        }
        $keys = array_values(array_unique(self::deductionKeys($rows)));
        if ($keys === [$newKey]) {
            return null;
        }

        $newMode = in_array($newHeader['vat_deduction'] ?? null, self::MODES, true) ? (string) $newHeader['vat_deduction'] : 'full';
        $newPercent = match ($newMode) {
            'none' => 0.0,
            'proportional' => max(0.0, min(100.0, (float) ($newHeader['vat_deduction_percent'] ?? 100))),
            default => 100.0,
        };
        if ($keys === [$oldKey]) {
            $out = [];
            foreach ($rows as $row) {
                $usage = (string) ($row['usage_type'] ?? 'business');
                if (($usage === 'personal' && $newMode !== 'none') || ($usage === 'mixed' && $newMode !== 'proportional')) {
                    throw new PurchaseVatTreatmentConflict(self::conflictMessage());
                }
                $row['vat_deduction'] = $newMode;
                $row['vat_deduction_percent'] = $newPercent;
                $out[] = $row;
            }

            return $out;
        }

        throw new PurchaseVatTreatmentConflict(self::conflictMessage());
    }

    public static function conflictMessage(): string
    {
        return 'Doklad má rozpis DPH, jehož části mají jiný nárok na odpočet než hlavička. Změnu nároku '
            . 'v hlavičce nelze jednoznačně promítnout do rozpisu, protože není jasné, kterých částí se týká. '
            . 'Upravte nárok na odpočet přímo v rozpisu DPH.';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private static function deductionKeys(array $rows): array
    {
        return array_map(
            static fn (array $r): string => self::deductionKey($r['vat_deduction'] ?? null, $r['vat_deduction_percent'] ?? null),
            $rows,
        );
    }
}
