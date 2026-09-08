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
    /** @var list<string> */
    private const EXEMPT_KINDS_WITHOUT_DETAIL = [
        'benefit_meal',
        'benefit_accommodation',
        'benefit_education',
        'benefit_recreation',
        'benefit_health',
    ];

    /**
     * Kód nálezu, nebo `null`, když je složka v pořádku.
     *
     * `$mapping` je snímek zařazení složky; `null` znamená, že složka zařazení
     * nemá (nebo není aktivní v připnutém balíku specifikace).
     *
     * @param array<string,mixed>|null $mapping
     * @param mixed $taxTreatment daňové zacházení téže složky
     * @param mixed $componentKind věcný druh mzdové složky
     */
    public static function issueCode(
        mixed $treatment,
        ?array $mapping,
        mixed $taxTreatment = null,
        mixed $componentKind = null,
    ): ?string {
        if ($treatment === 'manual_review') {
            return 'component_jmhz_manual_review';
        }
        if ($treatment === 'included') {
            /*
             * Některé osvobozené benefity se vykazují jen jako osvobozená část
             * zúčtovaného příjmu (10289) a vlastní kolonku nemají. Náhrada při
             * DPN a příspěvky zaměstnavatele však vlastní detailní atribut mají,
             * takže i při osvobození potřebují zařazení.
             *
             * Úhrn osvobozených příjmů proto vzniká ODVOZENÍM z daňového
             * zacházení složek, ne jejich zařazením. Výjimka je proto svázaná
             * jen s druhy benefitů, pro které detailní atribut neexistuje.
             */
            if ($mapping === null
                && $taxTreatment === 'exempt'
                && in_array($componentKind, self::EXEMPT_KINDS_WITHOUT_DETAIL, true)
            ) {
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
