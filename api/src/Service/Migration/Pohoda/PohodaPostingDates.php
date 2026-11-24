<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use DateTimeImmutable;
use MyInvoice\Http\IsoDate;
use MyInvoice\Service\Accounting\FiscalCalendar;

final class PohodaPostingDates
{
    /** @param list<int> $skipYears @param list<int>|null $selectedYears */
    public function __construct(
        private readonly FiscalCalendar $calendar,
        private readonly int $agendaYear,
        private readonly array $skipYears = [],
        private readonly ?array $selectedYears = null,
    ) {}

    public function needsCorrection(?string $accountingDate, ?string $documentDate): bool
    {
        if ($accountingDate === null || $documentDate === null || $accountingDate === $documentDate || !IsoDate::isValid($documentDate)) {
            return false;
        }
        if (!IsoDate::isValid($accountingDate)) {
            return true;
        }

        $earlier = min($accountingDate, $documentDate);
        $later = max($accountingDate, $documentDate);
        if (substr($earlier, 0, 4) !== substr($later, 0, 4)
            && (new DateTimeImmutable($earlier))->modify('+1 year')->format('Y-m-d') < $later) {
            return true;
        }

        $accountingYear = $this->calendar->fiscalYearOfDate($accountingDate);
        $documentYear = $this->calendar->fiscalYearOfDate($documentDate);
        return $accountingYear !== $documentYear && $this->isTransferred($documentYear) && !$this->isTransferred($accountingYear);
    }

    private function isTransferred(int $year): bool
    {
        return $year === $this->agendaYear || ($year > $this->agendaYear
            && !in_array($year, $this->skipYears, true)
            && ($this->selectedYears === null || in_array($year, $this->selectedYears, true)));
    }
}
