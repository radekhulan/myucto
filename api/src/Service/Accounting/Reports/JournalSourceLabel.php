<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

/**
 * Český název zdroje účetního zápisu (`journal_entries.source_type`) pro exporty deníku.
 * Názvy odpovídají `accounting.journal.source` v `web/src/i18n/cs.json`; neznámý zdroj
 * se vrátí beze změny, ať export nikdy nespadne na novém druhu.
 */
final class JournalSourceLabel
{
    private const LABELS = [
        'manual'                      => 'Ruční',
        'invoice'                     => 'Vydaná faktura',
        'purchase_invoice'            => 'Přijatá faktura',
        'bank'                        => 'Banka',
        'cash'                        => 'Pokladna',
        'asset'                       => 'Zařazení majetku',
        'asset_disposal'              => 'Vyřazení majetku',
        'depreciation'                => 'Odpisy majetku',
        'closing'                     => 'Uzavření knih',
        'opening'                     => 'Otevření knih',
        'fx_revaluation'              => 'Kurzové přecenění',
        'stock'                       => 'Sklad',
        'provision'                   => 'Rezervy',
        'income_tax'                  => 'Daň z příjmů',
        'profit_distribution'         => 'Rozdělení výsledku hospodaření',
        'offset'                      => 'Vzájemný zápočet',
        'settlement'                  => 'Zápočet proti účtu',
        'small_asset_accrual'         => 'Časové rozlišení drobného majetku',
        'prepaid_expense_accrual'     => 'Časové rozlišení nákladů',
        'deferred_revenue_accrual'    => 'Časové rozlišení výnosů',
        'deferred_tax'                => 'Odložená daň',
        'payroll'                     => 'Mzdy',
        'payroll_payment'             => 'Výplata mezd',
        'payroll_travel'              => 'Vyúčtování pracovní cesty',
        'payroll_accident_insurance'  => 'Zákonné pojištění odpovědnosti',
        'vat_clearing'                => 'Zúčtování DPH',
        'gopay'                       => 'GoPay',
        'card_settlement'             => 'Vypořádání platby kartou',
        'card_writeoff'               => 'Uzavření platby kartou',
        'other_item'                  => 'Ostatní pohledávka nebo závazek',
        'unit_charge'                 => 'Předpis SVJ',
        'unit_payment'                => 'Platba předpisu SVJ',
        'unit_payment_revision'       => 'Oprava platby předpisu SVJ',
        'property_service_settlement' => 'Vyúčtování služeb SVJ',
        'property_service_lifecycle'  => 'Služby SVJ',
        'property_funding'            => 'Fond oprav SVJ',
        'nonprofit_capital'           => 'Vlastní zdroje spolku',
        'nonprofit_membership'        => 'Členské příspěvky',
    ];

    public static function label(string $sourceType): string
    {
        return self::LABELS[$sourceType] ?? $sourceType;
    }

    /** @return list<string> */
    public static function known(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * Sloupec Původ exportu deníku: automatické zaúčtování a potvrzený návrh podle stopy
     * automatiky, ruční zápis „ručně", jinak zdroj zápisu (faktura, banka, pokladna…).
     *
     * @param array<string,mixed>|null $automation stopa z {@see \MyInvoice\Service\Accounting\AutomationProvenanceService}
     */
    public static function origin(string $sourceType, ?array $automation): string
    {
        return match ($automation['mode'] ?? null) {
            'auto' => 'automaticky',
            'approved' => 'potvrzeno',
            default => $sourceType === 'manual' || $sourceType === '' ? 'ručně' : self::label($sourceType),
        };
    }

    /**
     * Popis období v hlavičce exportu: „1. 1. – 31. 12." podle filtru, s jednou mezí
     * „od …" / „do …", bez filtru „vše".
     */
    public static function period(?string $fromCz, ?string $toCz): string
    {
        $from = trim((string) $fromCz);
        $to = trim((string) $toCz);
        return match (true) {
            $from !== '' && $to !== '' => $from . ' – ' . $to,
            $from !== ''               => 'od ' . $from,
            $to !== ''                 => 'do ' . $to,
            default                    => 'vše',
        };
    }
}
