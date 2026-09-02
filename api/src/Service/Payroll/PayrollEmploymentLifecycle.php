<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollEmploymentLifecycle
{
    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        /*
         * `planned → active` je zkratka pro nástup, který se prostě stal.
         *
         * Předregistrace odpovídá akci 9 – Předpokládaný nástup a dává smysl
         * u nástupu v BUDOUCNU. Jako povinná mezizastávka pro nástup starý rok
         * a půl znamenala, že vztah zůstal „plánovaný", nedostal skutečné datum
         * nástupu, a tím vypadl i z výplatní listiny — aniž by kdokoli řekl proč.
         */
        'planned' => ['preregistered', 'active', 'no_show'],
        'preregistered' => ['active', 'no_show'],
        'active' => ['suspended', 'ended'],
        'suspended' => ['active', 'ended'],
        /*
         * `ended → active` je NÁVRAT z omylem zapsaného ukončení.
         *
         * Ukončení se zapisuje jedním tlačítkem a s datem, které formulář
         * nabídne sám. Splést se v něm — ukončit špatnou osobu, trefit špatný
         * den — je běžné; jenže datum konce se pak nedalo opravit (podmínky
         * ukončeného vztahu se editovat nesmí) a jediná cesta ven byla smazat
         * celý vztah, což u vztahu s navázanou mzdou nejde vůbec. Vztah, ke
         * kterému už je vydaný výstupní doklad, se takhle vrátit nedá — ten
         * doklad je neměnný a odešel ven; hlídá to zápisová cesta.
         */
        'ended' => ['archived', 'active'],
        'no_show' => ['archived'],
        // Archiv není slepá ulička. Archivace je úklid, ne rozhodnutí o osudu
        // vztahu — omylem archivovaný vztah šel dřív jen smazat, a to u vztahu
        // s navázanými mzdami nejde vůbec. Vrací se do stavu, ze kterého se
        // archivovalo; „obnovit do aktivního" tu schválně NENÍ, protože takový
        // vztah má vyplněné datum konce a oživit ho znamená založit nový.
        'archived' => ['ended', 'no_show'],
    ];

    /** @return list<string> */
    public function allowedTargets(string $status): array
    {
        return self::TRANSITIONS[$status]
            ?? throw new \InvalidArgumentException('Neznámý stav pracovního vztahu.');
    }

    public function assertTransition(string $from, string $to): void
    {
        if (!in_array($to, $this->allowedTargets($from), true)) {
            throw new \DomainException("Přechod pracovního vztahu {$from} → {$to} není povolen.");
        }
    }
}
