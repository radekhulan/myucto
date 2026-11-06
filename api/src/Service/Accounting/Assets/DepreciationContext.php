<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

use MyInvoice\Service\Accounting\FiscalCalendar;

/**
 * Vstupní DTO výpočetního jádra odpisů (Epic F3, spec §2.1) — čistá data karty
 * majetku bez DB závislostí. Strategie z něj počítají kompletní průběh daňových
 * i účetních odpisů; minulost kotví `confirmedEntries`, budoucnost se dopočítává.
 *
 * `calendar` mapuje datum ↔ label období — pro kalendářní poplatníky (default)
 * je to `year(date)`, pro hospodářský rok posun dle §21a (Epic DP v2).
 */
final class DepreciationContext
{
    public function __construct(
        public readonly float $inputPrice,               // VC §29 (CZK)
        public readonly ?int $taxGroup,                  // 1..6 | null
        public readonly string $firstYearIncrease,       // none|p10|p15|p20
        public readonly bool $isFirstOwner,
        public readonly bool $isM1Vehicle,
        public readonly bool $m1LimitException,
        public readonly ?string $putIntoUseDate,         // Y-m-d | null
        public readonly ?string $disposalDate,           // Y-m-d | null
        public readonly ?int $accUsefulLifeMonths,
        public readonly float $accResidualValue,
        public readonly int $openingTaxYears,
        public readonly float $openingTaxAmount,
        public readonly int $openingAccMonths,
        public readonly float $openingAccAmount,
        /** @var list<array{completed_on:string, amount:float}> TZ vzestupně dle data */
        public readonly array $improvements = [],
        /** @var list<array{fiscal_year:int, kind:string, amount:float, full_amount:float, is_paused:bool, is_half:bool}> potvrzené řádky vzestupně */
        public readonly array $confirmedEntries = [],
        public readonly FiscalCalendar $calendar = new FiscalCalendar(),
        /** @var array<string,mixed>|null přesný budoucí plán převzaté karty */
        public readonly ?array $importedPlan = null,
    ) {}
    public function firstSystemYear(int $fallback): int
    {
        return $this->importedPlan['tax'][0]['fiscal_year']
            ?? (($this->putIntoUseDate !== null ? $this->calendar->fiscalYearOfDate($this->putIntoUseDate) : $fallback)
                + $this->openingTaxYears);
    }

    /** Jediný převod normalizované cílové karty do výpočetního kontextu. */
    public static function fromAsset(array $asset, FiscalCalendar $calendar, array $improvements = [], array $confirmed = []): self
    {
        return new self(
            inputPrice: (float) $asset['input_price'],
            taxGroup: $asset['tax_group'] === null ? null : (int) $asset['tax_group'],
            firstYearIncrease: (string) $asset['tax_first_year_increase'],
            isFirstOwner: (bool) $asset['is_first_owner'],
            // §30e platí jen pro vozidla pořízená od 1. 1. 2024 (zákon 349/2023 Sb.);
            // u starších M1 se limit 2 mil. neaplikuje.
            isM1Vehicle: (bool) $asset['is_m1_vehicle'] && (string) $asset['acquisition_date'] >= '2024-01-01',
            m1LimitException: (bool) $asset['m1_limit_exception'],
            putIntoUseDate: $asset['put_into_use_date'] === null ? null : (string) $asset['put_into_use_date'],
            disposalDate: ($asset['disposal_date'] ?? null) === null ? null : (string) $asset['disposal_date'],
            accUsefulLifeMonths: $asset['acc_useful_life_months'] === null ? null : (int) $asset['acc_useful_life_months'],
            accResidualValue: (float) $asset['acc_residual_value'],
            openingTaxYears: (int) $asset['opening_tax_years'],
            openingTaxAmount: (float) $asset['opening_tax_amount'],
            openingAccMonths: (int) $asset['opening_acc_months'],
            openingAccAmount: (float) $asset['opening_acc_amount'],
            improvements: $improvements,
            confirmedEntries: $confirmed,
            calendar: $calendar,
            importedPlan: $asset['imported_depreciation_plan'] ?? null,
        );
    }
}
