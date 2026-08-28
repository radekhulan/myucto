<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Dodaná legislativní sada jako CELEK — všechny ročníky, které aplikace veze
 * v kódu.
 *
 * ── Proč to není jedna třída na všechno ──────────────────────────────────────
 * Ročník je immutable historický fakt. {@see CzechPayrollRulesets2026} drží
 * hodnoty pro rok 2026 a nesmí se hnout ani o bajt, protože na jeho otiscích
 * visí zmrazené snapshoty výplat a integritní piny
 * ({@see VendorRulesetManifest}). Doplnění staršího ročníku proto NESMÍ být
 * editace té třídy — je to nová třída vedle ní ({@see CzechPayrollRulesets2025})
 * a tenhle soubor je jediné místo, kde se ročníky skládají dohromady.
 *
 * Praktický důsledek: `CzechPayrollRulesets2026::provider()` dál vrací výhradně
 * rok 2026 a jeho `canonicalManifestJson()` je bajtově tentýž jako předtím.
 * Runtime registry ({@see PayrollRulesetRegistry::defaults()}) sahá sem.
 *
 * ── Co který ročník pokrývá ──────────────────────────────────────────────────
 * 2026 — všech deset domén.
 * 2025 — sedm domén. Lhůty, číselníky a podání ({@see PayrollRulesetDomain})
 *        jsou navázané na jednotné měsíční hlášení zaměstnavatele, které zavedl
 *        až zákon č. 323/2025 Sb. s účinností od 1. 1. 2026. Pro rok 2025 tedy
 *        žádnou sadu nemají ZÁMĚRNĚ — v roce 2025 se podávalo přehledy ČSSZ
 *        a ELDP podle starých pravidel, která tenhle modul neumí a nebude
 *        předstírat, že umí. Pokus o JMHZ za období roku 2025 skončí fail-closed
 *        na chybějícím rulesetu, což je správná odpověď.
 */
final class CzechPayrollRulesets
{
    public static function provider(): PayrollRulesetProvider
    {
        return new PayrollRulesetProvider([
            ...CzechPayrollRulesets2025::provider()->versions(),
            ...CzechPayrollRulesets2026::provider()->versions(),
        ]);
    }
}
