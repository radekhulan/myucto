<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\AccountingFrameworkService;

/**
 * Kroky průvodce uzávěrkou na jednom místě: které existují, v jakém pořadí jdou
 * a co s kterým jde udělat. Služba, akce API i převody z jiných systémů berou seznamy
 * odsud, aby se nerozešly (dřív měl každý vlastní kopii a pořadí 504 platilo jen v průvodci).
 */
final class ClosingSteps
{
    // `deferred_tax` následuje AŽ po `income_tax`: odložená daň stojí na rozdílech, které
    // jsou známé teprve po zaúčtování odpisů a po vyčíslení splatné daně.
    public const ALL = ['precheck', 'depreciation', 'fx_revaluation', 'estimates', 'deferrals', 'provisions', 'income_tax', 'deferred_tax', 'stock', 'close_books', 'open_next'];

    /** Kroky, které spouští akce `run` (uzavření knih a otevření roku mají vlastní akce). */
    public const RUNNABLE = ['precheck', 'depreciation', 'fx_revaluation', 'estimates', 'deferrals', 'provisions', 'income_tax', 'deferred_tax', 'stock'];

    public const REVERTIBLE = self::ALL;

    /** Kroky, které jde ručně potvrdit jako provedené nebo přeskočit. */
    public const CONFIRMABLE = ['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax', 'deferred_tax'];

    /** Kroky s asistovanými zápisy (dohady, časové rozlišení, opravné položky). */
    public const ASSIST = ['estimates', 'deferrals', 'provisions'];

    /**
     * Pořadí kroků průvodce. U neziskové organizace (504) jde krok Zásoby před daň: zápis
     * zásob mění náklady 5xx, ze kterých vychází daňové posouzení a z něj DPPO, takže daň
     * spočítaná před zásobami by se po kroku Zásoby musela opravovat (#401). U ostatních
     * firem zůstává zavedené pořadí; výpočet DPPO tam nezaúčtované zásoby promítne
     * projekcí uzávěrky. Frontend přebírá pořadí ze stavu (`step_order`).
     *
     * Odložená daň (ČÚS 003) je u ostatních firem VOLITELNÝ krok hned po splatné dani:
     * § 59 vyhlášky 500/2002 Sb. ji ukládá jen účetním jednotkám s povinným auditem
     * (a konsolidačním celkům), ostatní o ní účtují dobrovolně. Proto ji uzavření knih
     * nevyžaduje ({@see ClosingService::preCloseStepKeys()} ji nezná).
     *
     * @return list<string>
     */
    public static function order(?string $framework): array
    {
        return $framework === AccountingFrameworkService::CZ_504
            ? ['precheck', 'depreciation', 'fx_revaluation', 'estimates', 'deferrals', 'provisions', 'stock', 'income_tax', 'deferred_tax', 'close_books', 'open_next']
            : ['precheck', 'depreciation', 'fx_revaluation', 'estimates', 'deferrals', 'provisions', 'income_tax', 'deferred_tax', 'stock', 'close_books', 'open_next'];
    }
}
