<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * JEDINÉ pravidlo „co je se zařazením mzdové složky do JMHZ špatně".
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Rozhodnutí, kdy složka chybí v zařazení a kdy má neplatné nastavení, žilo
 * jenom uvnitř {@see JmhzPreparationSnapshotBuilder} — tedy až v okamžiku
 * ZMRAZENÍ hlášení. Kontrola před zahájením běhu se na totéž ptát nemohla, aniž
 * by si pravidlo opsala, a dvě kopie by se rozešly.
 *
 * ── Co to NEDĚLÁ ────────────────────────────────────────────────────────────
 * Nekouká na složky bez pohybu. Volající sem posílá jen složky, které v období
 * skutečně mají vstup — nezařazená složka, kterou firma v měsíci nepoužila, je
 * normální stav (u sporných složek je zařazení úsudek účetní a předvyplnit se
 * nesmí), ne nález.
 */
final class JmhzComponentSourceRule
{
    /**
     * Kód nálezu, nebo `null`, když je složka v pořádku.
     *
     * `$mapping` je snímek zařazení složky; `null` znamená, že složka zařazení
     * nemá (nebo není aktivní v připnutém balíku specifikace).
     *
     * @param array<string,mixed>|null $mapping
     * @param mixed $taxTreatment daňové zacházení téže složky
     */
    public static function issueCode(
        mixed $treatment,
        ?array $mapping,
        mixed $taxTreatment = null,
    ): ?string {
        if ($treatment === 'manual_review') {
            return 'component_jmhz_manual_review';
        }
        if ($treatment === 'included') {
            /*
             * Osvobozený příjem se do hlášení nezařazuje, ale VYKAZUJE se —
             * jako osvobozená část zúčtovaného příjmu (10289). Vlastní kolonku
             * v rozpadu mzdy nemá a mít nesmí: rozpad popisuje mzdu za práci
             * a náhrady, kdežto stravenka ani přechodné ubytování ani jedním
             * nejsou. Žádat po účetní zařazení do rozpadu by ji nutilo vybrat
             * kolonku, do které plnění nepatří.
             *
             * Úhrn osvobozených příjmů proto vzniká ODVOZENÍM z daňového
             * zacházení složek, ne jejich zařazením; složka s `exempt` je tím
             * pádem v pořádku i bez zařazení.
             */
            if ($taxTreatment === 'exempt') {
                return null;
            }

            return $mapping === null ? 'component_jmhz_mapping_missing' : null;
        }
        if ($treatment === 'excluded') {
            return null;
        }

        return 'component_jmhz_treatment_invalid';
    }
}
