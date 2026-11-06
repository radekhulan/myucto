<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

final class StatementNotes504Definition
{
    public const LEGAL = '§ 18 odst. 1 písm. c) zákona č. 563/1991 Sb., § 29 a § 30 vyhlášky č. 504/2002 Sb.';

    public static function sections(): array
    {
        $rows = [
            'identification' => ['Základní údaje účetní jednotky', '§ 29 odst. 2; § 18 odst. 3 ZoÚ', true],
            'mission' => ['Právní forma, poslání, činnosti a orgány', '§ 30 odst. 1 písm. a)', false,
                'Uveďte hlavní, hospodářské a další činnosti, statutární orgány a organizační složky s právní osobností.'],
            'founders' => ['Zakladatelé, zřizovatelé a vklady', '§ 30 odst. 1 písm. b)', false],
            'period' => ['Účetní období', '§ 30 odst. 1 písm. c)', true],
            'category' => ['Kategorie a rozsah účetní závěrky', '§ 3a; § 1b až § 1e ZoÚ', true],
            'principles' => ['Účetní zásady, metody a jejich změny', '§ 30 odst. 1 písm. d)', false,
                'Popište odchylky a jejich dopad na majetek, závazky, finanční situaci a výsledek hospodaření.'],
            'valuation' => ['Oceňování majetku a závazků', '§ 30 odst. 1 písm. d) bod 1', false],
            'depreciation' => ['Odpisování a opravné položky', '§ 30 odst. 1 písm. d) bod 2', false],
            'fx' => ['Přepočet cizích měn', '§ 30 odst. 1 písm. d) bod 3', false],
            'fair_value' => ['Stanovení reálné hodnoty', '§ 30 odst. 1 písm. d) bod 4', false],
            'valuation_model' => ['Model ocenění, deriváty a změny reálné hodnoty', '§ 30 odst. 1 písm. e)', false],
            'extraordinary' => ['Významné mimořádné náklady a výnosy', '§ 30 odst. 1 písm. f)', false],
            'unlimited_liability' => ['Účasti s neomezeným ručením', '§ 30 odst. 1 písm. g)', false],
            'fixed_assets' => ['Dlouhodobý majetek a jeho změny', '§ 30 odst. 1 písm. h)', false,
                'Uveďte počáteční a konečné stavy, přírůstky a úbytky majetku, oprávek a opravných položek i úroky zahrnuté do ocenění.'],
            'auditor_fees' => ['Odměna auditora podle druhu služby', '§ 30 odst. 1 písm. i)', false],
            'holdings' => ['Majetkové účasti a cenné papíry', '§ 30 odst. 1 písm. j) a l)', false],
            'overdue_public_debt' => ['Splatné pojistné a daňové nedoplatky', '§ 30 odst. 1 písm. k)', false],
            'long_debt_guarantees' => ['Dluhy nad pět let a poskytnuté záruky', '§ 30 odst. 1 písm. m)', false],
            'off_balance' => ['Dluhy nevykázané v rozvaze', '§ 30 odst. 1 písm. n)', false],
            'result' => ['Výsledek hlavní a hospodářské činnosti a daňové posouzení', '§ 30 odst. 1 písm. o)', false,
                'Účetní výsledek není základem daně. Daňové posouzení a úpravy doplňte samostatně.'],
            'employees' => ['Zaměstnanci a osobní náklady', '§ 30 odst. 1 písm. p)', false,
                'Uveďte přepočtený počet podle kategorií, osobní náklady a zaměstnance působící současně v orgánech jednotky.'],
            'board_benefits' => ['Odměny a požitky členů orgánů', '§ 30 odst. 1 písm. q)', false],
            'related_parties' => ['Smluvní vztahy se členy orgánů a jejich rodinami', '§ 30 odst. 1 písm. r)', false],
            'board_advances' => ['Zálohy, úvěry a záruky členům orgánů', '§ 30 odst. 1 písm. s)', false],
            'tax_reliefs' => ['Základ daně, daňové úlevy a použití úspor', '§ 30 odst. 1 písm. t)', false,
                'Uveďte způsob stanovení základu daně a použití prostředků z úlev v členění podle zdaňovacích období.'],
            'significant_items' => ['Významné položky rozvahy a výsledovky', '§ 30 odst. 1 písm. u)', false],
            'grants' => ['Dotace a investiční transfery', '§ 30 odst. 1 písm. u)', false,
                'Rozlište provozní dotace a dotace na pořízení majetku, částky a zdroje. U investičních dotací vysvětlete vlastní jmění a jeho rozpouštění do výnosů (účty 901 a 649), nikoli okamžité zahrnutí celé dotace do výnosů.'],
            'funds' => ['Fondy a jejich čerpání', '§ 29 odst. 2; § 30 odst. 1 písm. u)', false,
                'Popište počáteční stav, tvorbu, čerpání a konečný stav fondů. Rozlište fond (účet 911) a jeho čerpání do výnosů (účet 648).'],
            'gifts' => ['Přijaté a poskytnuté dary', '§ 30 odst. 1 písm. v)', false],
            'collections' => ['Veřejné sbírky', '§ 30 odst. 1 písm. w)', false],
            'result_distribution' => ['Vypořádání výsledků minulých období', '§ 30 odst. 1 písm. x)', false],
            'quotas' => ['Produkční kvóty a obdobná práva', '§ 30 odst. 1 písm. y)', false],
            'heritage_assets' => ['Kulturní památky, sbírky a obdobný majetek', '§ 30 odst. 2', false],
            'forests' => ['Lesní pozemky a ocenění porostů', '§ 30 odst. 3 a 4', false],
            'currency_change' => ['Změna měny účetnictví', '§ 30 odst. 8', false],
            'registered_equity' => ['Rozdíl vlastního jmění proti veřejnému rejstříku', '§ 30 odst. 9', false],
            'subsequent_events' => ['Události po rozvahovém dni a další významné informace', '§ 29 odst. 3; § 19 odst. 6 ZoÚ', false],
        ];
        $out = [];
        foreach ($rows as $key => $row) {
            $out['n504_' . $key] = ['label' => $row[0], 'legal' => $row[1], 'auto' => $row[2],
                'scope' => in_array($key, ['fair_value', 'valuation_model', 'extraordinary', 'auditor_fees'], true) ? 'full504' : 'all',
                'hint' => ($row[3] ?? 'Pokud se údaj jednotky netýká, uveďte tuto skutečnost výslovně.')
                    . (in_array($key, ['board_benefits', 'related_parties', 'board_advances', 'gifts'], true)
                        ? ' Podle § 30 odst. 6 nesmí údaje umožnit určit částku týkající se jednotlivé osoby.' : '')];
        }
        return $out;
    }
}
