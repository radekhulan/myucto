<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets\Strategy;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;

/**
 * Účetní odpisy = daňové odpisy (`acc_method='by_tax'`). Zrcadlo
 * {@see TaxByAccountingStrategy}: tam je daňový plán kopií účetního, tady účetní
 * plán kopií daňového. Žádná doba použitelnosti, žádné měsíce — daňový odpis je
 * roční sazba z VC (§31/§32), měsíc zařazení do ní nevstupuje.
 *
 * PROČ to existuje: §28 ZoÚ velí, aby účetní odpisy vyjadřovaly skutečné opotřebení,
 * a rovnoměrný měsíční odpis je proto default. U malých účetních jednotek je ale
 * běžná a přípustná zjednodušující politika „účetní = daňový" — jednotka obětuje
 * vypovídací schopnost výkazů za to, že vede jedinou evidenci odpisů místo dvou.
 * Není to default, je to vědomá volba účetní jednotky.
 *
 * Zrcadlí se `full_amount`, ne `amount`: §30e (M1 > 2 mil.) krátí jen DAŇOVĚ
 * uznatelnou část odpisu, na výši účetního nákladu nemá vliv. Účetní odpis proto
 * kopíruje nekrácený odpis a rozdíl zůstane úpravou základu daně.
 */
final class AccountingByTaxStrategy implements AccountingDepreciationStrategyInterface
{
    private const COVERED = 'covered';
    private const CATCH_UP = 'catch_up';
    private const REGULAR = 'regular';

    private const NOT_COVERED = 'not_covered';
    private const AFTER_COVERED = 'after_covered';
    private const CAUGHT_UP = 'caught_up';

    public function __construct(
        private readonly ?TaxDepreciationStrategyInterface $tax,
    ) {}

    public function plan(DepreciationContext $ctx): array
    {
        $confirmed = $this->confirmedAccounting($ctx);
        $gap = $this->openingGap($ctx);
        $rows = [];
        $state = self::NOT_COVERED;
        foreach ($this->tax?->plan($ctx) ?? [] as $tax) {
            $entry = $confirmed[$tax['fiscal_year']] ?? null;
            $row = $this->mirror($tax, $entry, $gap, self::mode($ctx, (int) $tax['fiscal_year'], $state));
            $gap = round($gap + (float) $row['amount'] - (float) $tax['full_amount'], 2);
            $rows[] = $row;
        }

        return $rows;
    }

    public function yearRow(DepreciationContext $ctx, int $fiscalYear): ?array
    {
        $tax = $this->tax?->yearRow($ctx, $fiscalYear);
        if ($tax === null) {
            return null;
        }
        $gap = $this->openingGap($ctx);
        $confirmed = $this->confirmedAccounting($ctx);
        $state = self::NOT_COVERED;
        foreach ($this->tax?->plan($ctx) ?? [] as $prior) {
            if ($prior['fiscal_year'] >= $fiscalYear) {
                break;
            }
            $row = $this->mirror($prior, $confirmed[$prior['fiscal_year']] ?? null, $gap, self::mode($ctx, (int) $prior['fiscal_year'], $state));
            $gap = round($gap + (float) $row['amount'] - (float) $prior['full_amount'], 2);
        }
        $mode = self::mode($ctx, $fiscalYear, $state);
        // Rok s potvrzeným nebo zaúčtovaným účetním odpisem se dorovnáním nepřepočítává.
        if ($mode === self::CATCH_UP && isset($confirmed[$fiscalYear])) {
            $mode = self::REGULAR;
        }

        return $this->mirror($tax, null, $gap, $mode);
    }

    /**
     * Rok plánu pokrytý počátečním účetním stavem má odpis 0 ({@see coveredByOpening()}):
     * to je karta převzatá z jiného programu, jejíž počáteční účetní oprávky obsahují i odpis
     * roku, který daňově ještě odepsaný není (převzatý deník). Jen u takové karty první další
     * rok plánu srovná účetní oprávky s daňovými, tedy dožene i zaostávání, aby karta na konci
     * plánu neskončila s účetní zůstatkovou cenou. Karta bez pokrytého roku plánu (ručně
     * zadaný historický majetek) zaostávání nedohání, jako dřív.
     *
     * @param self::NOT_COVERED|self::AFTER_COVERED|self::CAUGHT_UP $state stav průchodu plánem (nastaví se tady)
     */
    private static function mode(DepreciationContext $ctx, int $fiscalYear, string &$state): string
    {
        if (self::coveredByOpening($ctx, $fiscalYear)) {
            if ($state === self::NOT_COVERED) {
                $state = self::AFTER_COVERED;
            }
            return self::COVERED;
        }
        if ($state !== self::AFTER_COVERED) {
            return self::REGULAR;
        }
        $state = self::CAUGHT_UP;
        return self::CATCH_UP;
    }

    /**
     * Celý rok leží v počátečních účetních oprávkách: měsíce od zařazení do posledního
     * měsíce počátečního stavu (stejné počítání jako {@see AccountingStraightLineStrategy},
     * převod z POHODY aj. sem dává poslední měsíc zaúčtovaný v převzatém deníku). Odpis
     * takového roku nese převzatý deník; modul ho znovu neplánuje ani nedorovnává na daňový.
     */
    private static function coveredByOpening(DepreciationContext $ctx, int $fiscalYear): bool
    {
        if ($ctx->openingAccMonths <= 0 || $ctx->putIntoUseDate === null) {
            return false;
        }
        $inUse = ((int) substr($ctx->putIntoUseDate, 0, 4)) * 12 + ((int) substr($ctx->putIntoUseDate, 5, 2)) - 1;
        return $ctx->calendar->lastMonthIndex($fiscalYear) <= $inUse + $ctx->openingAccMonths;
    }

    /** @return array<int,array<string,mixed>> */
    private function confirmedAccounting(DepreciationContext $ctx): array
    {
        $confirmed = [];
        foreach ($ctx->confirmedEntries as $entry) {
            if (($entry['kind'] ?? '') === 'accounting') {
                $confirmed[(int) $entry['fiscal_year']] = $entry;
            }
        }
        return $confirmed;
    }

    /**
     * O kolik účetní oprávky předbíhají daňové před prvním rokem plánu. Převzatá karta
     * (POHODA aj.) nese v počátečních účetních oprávkách často i odpis běžného roku,
     * který už je v deníku, zatímco počáteční daňové oprávky končí minulým rokem.
     */
    private function openingGap(DepreciationContext $ctx): float
    {
        return round($ctx->openingAccAmount - $ctx->openingTaxAmount, 2);
    }

    /**
     * Účetní odpis roku = daňový, ale kumulované účetní oprávky nepředběhnou kumulované
     * daňové: náskok z počátečního stavu ($gap > 0) se odečte od odpisů dalších let,
     * jinak by se odpis zdvojil a oprávky přerostly vstupní cenu. Zaostávání ($gap < 0)
     * dožene jen první rok po roce plánu pokrytém počátečním stavem ({@see mode()}), jinak
     * zůstane odpis daňový. Rok celý pokrytý počátečním stavem má odpis nulový.
     *
     * @param array<string,mixed> $tax
     * @param array<string,mixed>|null $entry
     * @param self::COVERED|self::CATCH_UP|self::REGULAR $mode
     * @return array<string,mixed>
     */
    private function mirror(array $tax, ?array $entry, float $gap = 0.0, string $mode = self::REGULAR): array
    {
        $full = (float) $tax['full_amount'];
        $amount = match (true) {
            $entry !== null => (float) $entry['amount'],
            $mode === self::COVERED => 0.0,
            $mode === self::CATCH_UP => round(max(0.0, $full - $gap), 2),
            default => round(max(0.0, $full - max(0.0, $gap)), 2),
        };
        $gapAfter = round($gap + $amount - $full, 2);

        return [
            'fiscal_year' => $tax['fiscal_year'],
            'amount' => $amount,
            'full_amount' => $amount,
            'residual_start' => round((float) $tax['residual_start'] - $gap, 2),
            'residual_end' => round((float) $tax['residual_end'] - $gapAfter, 2),
            'is_half' => false,
            'is_paused' => false,
            'months_count' => null,
            'months' => null,
            'source' => $entry !== null ? 'confirmed' : 'computed',
            'note' => null,
        ];
    }
}
