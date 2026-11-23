<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Service\Accounting\Dimension\DimensionFilter;

/**
 * Zúžení opisu účtu na řádky, které hlavní kniha sčítá do obratu jednoho svého řádku.
 * Rozbalený měsíc hlavní knihy ho posílá, aby součet řádků seděl na obrat měsíce:
 *
 *   • `filters` — dodavatel, odběratel, položka a dimenze jako v hlavní knize; zúží řádky
 *     i PS (u řádku s rozpadem dimenze jen díl hodnoty),
 *   • `openingDate` — den, jehož otevírací zápis patří do PS (hlavní kniha posílá začátek
 *     svého rozsahu); ostatní otevírací zápisy pak jsou pohyby jako v knize. Bez něj platí
 *     výchozí pravidlo opisu ({@see \MyInvoice\Repository\LedgerReportRepository::technicalEntryFilter()}),
 *   • `withAnalytics` — false = jen řádky účtu samotného, bez analytik (řádek syntetiky
 *     v knize s rozpadem po analytikách).
 */
final class AccountStatementScope
{
    /**
     * @param array{vendor?:string, client?:string, item?:string, dimension?:DimensionFilter} $filters
     */
    public function __construct(
        public readonly array $filters = [],
        public readonly ?string $openingDate = null,
        public readonly bool $withAnalytics = true,
    ) {}

    /** Výchozí opis: celý účet včetně analytik, bez filtrů. */
    public function isDefault(): bool
    {
        return $this->filters === [] && $this->openingDate === null && $this->withAnalytics;
    }

    /**
     * Druhý parametr podmínky `(l.account_id = ? OR parent_id = ?)`: bez analytik id,
     * které žádný účet nemá za nadřízený.
     */
    public function parentId(int $accountId): int
    {
        return $this->withAnalytics ? $accountId : 0;
    }
}
