<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;

/**
 * Účetní rok agendy POHODY a účetní období firmy, do kterého agenda patří.
 *
 * POHODA vede každý účetní rok jako samostatnou agendu, hranice roku ale export nenese
 * (přehled účetních jednotek ani MDB převod mají jen rok). Začátek roku agendy proto
 * určuje deník:
 *  - počáteční stavy účtů - POHODA je datuje prvním dnem účetního období;
 *  - bez nich u firmy s kalendářním rokem 1. 1. roku agendy (beze změny proti dřívějšku),
 *    u firmy s hospodářským rokem první den měsíce nejstaršího zápisu.
 * Rok začínající prvním dnem jiného měsíce než ledna je hospodářský rok (§ 21a ZDP)
 * a trvá dvanáct měsíců; jinak je to kalendářní rok (i zkrácený první rok firmy) do 31. 12.
 * Zápisy po konci roku agendy jsou doklady pozdějšího roku, které POHODA vede v agendě
 * dál ({@see ChartJournalImporter}).
 *
 * Období firmy (label = rok začátku období) určuje kalendář firmy podle začátku roku
 * agendy. Firma s kalendářním rokem bere rok agendy ze složky exportu beze změny. Jestli
 * POHODA pojmenuje agendu hospodářského roku rokem jeho začátku, nebo konce, převod proto
 * neovlivní. Jediná nejednoznačnost: kalendářní firma založená prvním dnem měsíce
 * s počátečními stavy a s doklady dalšího roku v agendě vypadá jako hospodářský rok
 * a převod se zastaví s návrhem nastavení.
 */
final class PohodaAgendaYear
{
    /**
     * @param list<string> $sourceDates data zápisů roku agendy (s počátečními stavy) ke kontrole tvaru roku
     */
    private function __construct(
        public readonly int $label,
        public readonly string $sourceStart,
        public readonly string $sourceEnd,
        public readonly FiscalCalendar $calendar,
        private readonly int $exportYear,
        private readonly array $sourceDates,
        private readonly PohodaExport $export,
    ) {}

    public static function of(PohodaExport $export, FiscalCalendar $calendar): self
    {
        $opening = null;
        $earliest = null;
        $dates = [];
        foreach ($export->records('journal', 'accountingItem') as $item) {
            if (PohodaJournal::isYearEndClosing($item)) {
                continue;
            }
            $date = PohodaXml::date($item, 'date');
            if ($date === null) {
                continue;
            }
            if (PohodaJournal::isOpening($item)) {
                $opening = $opening === null || $date < $opening ? $date : $opening;
                continue;
            }
            $earliest = $earliest === null || $date < $earliest ? $date : $earliest;
            $dates[] = $date;
        }
        if ($opening !== null) {
            $start = $opening;
        } elseif ($calendar->isCalendar() || $earliest === null) {
            $start = sprintf('%04d-01-01', $export->year);
        } else {
            $start = substr($earliest, 0, 8) . '01';
        }
        $fiscal = substr($start, 8, 2) === '01' && substr($start, 5, 2) !== '01';
        $end = $fiscal
            ? (new \DateTimeImmutable($start))->modify('+1 year')->modify('-1 day')->format('Y-m-d')
            : substr($start, 0, 4) . '-12-31';
        $sourceDates = array_values(array_filter($dates, static fn (string $d): bool => $d >= $start && $d <= $end));
        if ($opening !== null) {
            $sourceDates[] = $opening;
        }
        $label = $calendar->isCalendar() ? $export->year : $calendar->fiscalYearOfDate($start);
        return new self($label, $start, $end, $calendar, $export->year, $sourceDates, $export);
    }

    /**
     * Rok agendy musí padnout do jednoho období firmy - jinak se převod zastaví
     * s návrhem nastavení „Účetní rok začíná" ({@see MigrationPeriods::assertSourceYear()}).
     *
     * @throws FiscalYearMismatchException
     */
    public function assertMatchesCompany(): void
    {
        MigrationPeriods::assertSourceYear($this->calendar, $this->sourceDates, sprintf('%d (agenda POHODY)', $this->exportYear));
    }

    /** Období firmy po období agendy, do kterého datum padá; `null` = období agendy nebo dřív. */
    public function laterYear(?string $date): ?int
    {
        if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) {
            return null;
        }
        $year = $this->calendar->fiscalYearOfDate(substr($date, 0, 10));
        return $year > $this->label ? $year : null;
    }

    /**
     * Období firmy po období agendy, do kterých padají zápisy, doklady a úhrady.
     *
     * @return list<int>
     */
    public function laterYears(?PohodaExport $export = null): array
    {
        return ($export ?? $this->export)->counts($this->calendar, $this->label)['later_years'];
    }
}
