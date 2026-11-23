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
     * Zdaněná plnění, která NEMAJÍ charakter mzdy, a proto do rozpadu mzdy
     * v měsíčním hlášení nepatří.
     *
     * Pokyny MPSV k 10328 (Mzda za práci zúčtovaná): „Nezahrnují se náhrady
     * mzdy …, odstupné, cestovní náhrady … a obecně mzdová plnění, která
     * nemají charakter mzdy." Do náhrad mzdy (10337) odstupné také nepatří.
     * Do zúčtovaného příjmu celkem (10286) a do základu daně ale jde, protože
     * je to zdanitelný příjem § 6 ZDP; to zajišťuje samotné `jmhz_treatment =
     * included`, ne zařazení. Údaje o odstupném (10531, 10378) nese až
     * odhláška REGZEC A2.
     *
     * Dřív chybějící zařazení hlásilo `component_jmhz_mapping_missing` a každé
     * skončení s odstupným zablokovalo hlášení za poslední měsíc, přičemž
     * katalog cílů správný atribut ani nenabízel.
     *
     * Totéž platí pro zdaněnou (nadlimitní) cestovní náhradu, kterou pokyny
     * k 10328 jmenují výslovně („Nezahrnují se … cestovní náhrady“). Katalog
     * cílů pro ni nenabízí nic než kolonky mzdy, příplatků a náhrad mzdy,
     * takže účetní musela vybrat kolonku, do které plnění nepatří.
     *
     * Bezplatné užívání služebního vozidla k soukromým účelům (§ 6 odst. 6
     * ZDP) sem NEPATŘÍ: pokyny ho mezi vyloučenými plněními nejmenují
     * a 10328 zahrnuje i „plnění peněžité hodnoty (naturální mzda)“. Zda jde
     * o naturální mzdu, nebo o plnění bez charakteru mzdy, je výklad, který
     * aplikace za účetní neudělá: složka proto zařazení vyžaduje jako každá
     * jiná a účetní ji zařadí (typicky přímo na úhrn 10328).
     */
    private const TAXED_KINDS_OUTSIDE_WAGE_BREAKDOWN = [
        'severance',
        'travel_reimbursement',
    ];

    /**
     * Složka se do JMHZ zahrnuje (10286), ale zařazení do rozpadu mzdy nemá
     * a mít nemusí. Čte ji i obrazovka zařazení, aby u ní neukazovala
     * „Chybí mapování".
     */
    public static function belongsOutsideWageBreakdown(
        mixed $treatment,
        mixed $taxTreatment,
        mixed $componentKind,
    ): bool {
        if ($treatment !== 'included') {
            return false;
        }
        if ($taxTreatment === 'exempt' && in_array($componentKind, self::EXEMPT_KINDS_WITHOUT_DETAIL, true)) {
            return true;
        }

        return $taxTreatment === 'included'
            && in_array($componentKind, self::TAXED_KINDS_OUTSIDE_WAGE_BREAKDOWN, true);
    }

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
                && self::belongsOutsideWageBreakdown($treatment, $taxTreatment, $componentKind)
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
