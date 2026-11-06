<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Volby jednoho běhu převodu.
 */
final class ImportOptions
{
    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_IMPORT = 'import';

    /** Daňový odpis hmotného majetku v roce vyřazení: poloviční podle §26 odst. 7 ZDP. */
    public const DISPOSAL_YEAR_TAX_HALF = 'half';
    /** Daňový odpis v roce vyřazení se neuplatní (firma ho v přiznání neuplatňovala). */
    public const DISPOSAL_YEAR_TAX_NONE = 'none';

    /**
     * @param list<string> $relatedPartyIcos IČO partnerů, které se v adresáři označí jako spřízněné osoby
     * @param array<int,string> $moneyReports rok => cesta k obratové předvaze vyexportované z Money (CSV)
     * @param bool $confirmedIco uživatel výslovně potvrdil, že záloha patří firmě, i když to IČO ověřit nejde
     * @param int|null $fromYear první převáděný účetní rok; starší roky zálohy (a jejich doklady) se vynechají
     * @param string $disposalYearTax daňový odpis majetku v roce vyřazení (half|none)
     * @param bool $acceptDifferences ostrý převod přijme rozdíly k přijetí ({@see ImportProtocol::difference()})
     * @param bool $relinkOnly opravný převod vazeb už převedené firmy: projde zálohu, ale nic
     *                         nového nezaloží (zápis, doklad, pohyb, výpis, partnera, kartu, období) -
     *                         jen u převzatých záznamů znovu dopočítá úhrady, zápočty a zálohy
     *                         a doplní valuty valutových pokladen, jejich dokladů a řádků deníku
     *                         otevřených let.
     *                         Firma se po převodu účtuje v MyÚčtu a novější doklady z Money by se
     *                         zdvojily s tím, co uživatel zadal.
     */
    public function __construct(
        public readonly string $mode = self::MODE_DRY_RUN,
        public readonly bool $closeHistory = true,
        public readonly ?string $firstPeriodStart = null,
        public readonly array $relatedPartyIcos = [],
        public readonly array $moneyReports = [],
        public readonly bool $confirmedIco = false,
        public readonly ?int $fromYear = null,
        public readonly string $disposalYearTax = self::DISPOSAL_YEAR_TAX_HALF,
        public readonly bool $acceptDifferences = false,
        public readonly bool $relinkOnly = false,
    ) {
        if (!in_array($mode, [self::MODE_DRY_RUN, self::MODE_IMPORT], true)) {
            throw new MoneyS3Exception('invalid_mode', 'Neznámý režim převodu.');
        }
        if (!in_array($disposalYearTax, [self::DISPOSAL_YEAR_TAX_HALF, self::DISPOSAL_YEAR_TAX_NONE], true)) {
            throw new MoneyS3Exception('invalid_disposal_year_tax', 'Daňový odpis v roce vyřazení čeká „half" (poloviční) nebo „none" (bez odpisu).');
        }
        if ($firstPeriodStart !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstPeriodStart) !== 1) {
            throw new MoneyS3Exception('invalid_first_period_start', 'Začátek prvního účetního období čeká datum RRRR-MM-DD.');
        }
        if ($fromYear !== null && ($fromYear < 1990 || $fromYear > 2100)) {
            throw new MoneyS3Exception('invalid_from_year', 'První převáděný rok musí být mezi lety 1990 a 2100.');
        }
    }

    public function isDryRun(): bool
    {
        return $this->mode === self::MODE_DRY_RUN;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'close_history' => $this->closeHistory,
            'first_period_start' => $this->firstPeriodStart,
            'related_party_icos' => $this->relatedPartyIcos,
            'money_reports' => array_map('intval', array_keys($this->moneyReports)),
            'confirmed_ico' => $this->confirmedIco,
            'from_year' => $this->fromYear,
            'disposal_year_tax' => $this->disposalYearTax,
        ] + ($this->acceptDifferences && !$this->isDryRun() ? ['accept_differences' => true] : [])
          + ($this->relinkOnly ? ['relink_only' => true] : []);
    }
}
