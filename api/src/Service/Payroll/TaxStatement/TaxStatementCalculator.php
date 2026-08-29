<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxStatement;

/**
 * Ze zmrazených ročních úhrnů sestaví obě vyúčtování.
 *
 * ## Proč sloupec 1 a sloupec 2 vycházejí stejně
 *
 * Sl. 1 je „zálohy, které MĚLY být sraženy", sl. 2 „zálohy, které BYLY sraženy".
 * Rozejít se mohou jedině tehdy, když plátce do podání vyúčtování zjistí, že
 * srazil špatně, a opraví to postupem podle § 38i — tedy dodatečnou srážkou
 * nebo vrácením, které se vykazuje v příloze č. 3. Modul takový záznam nevede:
 * chybný měsíc se opravuje přepočtem revize, takže zmrazený výsledek už nese
 * SPRÁVNOU částku a druhá hodnota neexistuje. Dosadit sem rozdíl by znamenalo
 * vymyslet si opravu, kterou nikdo neprovedl.
 *
 * Praktický důsledek: sl. 8 vychází stejně, ať se počítá ze sl. 1 nebo sl. 2,
 * takže volba mezi nimi tady nemůže vyrobit nesprávné číslo.
 */
final class TaxStatementCalculator
{
    /**
     * @param array{variant?:string} $meta
     */
    public function dependentActivity(
        TaxStatementBasis $basis,
        array $meta = [],
    ): DependentActivityStatement {
        $warnings = $basis->warnings;
        $variant = (string) ($meta['variant'] ?? DependentActivityStatement::TYP_RADNE);

        $months = [];
        $headcounts = [];
        $payouts = [];
        $overpaymentTotal = 0;
        $bonusTopUpTotal = 0;
        foreach ($basis->months as $month) {
            $overpayment = $this->toCzk(
                $month->annualOverpaymentMinor,
                'přeplatek z ročního zúčtování',
                $warnings,
            );
            $overpaymentTotal += $overpayment;
            $bonusTopUpTotal += $this->toCzk(
                $month->annualBonusTopUpMinor,
                'doplatek na daňovém bonusu',
                $warnings,
            );
            if ($overpayment > 0) {
                $payouts[] = ['month' => $month->month, 'amount' => $overpayment];
            }
            if (!$month->hasApprovedRun) {
                continue;
            }
            $advance = $this->toCzk(
                $month->advanceTaxMinor,
                'úhrn záloh na daň',
                $warnings,
            );
            $months[$month->month] = new DependentActivityRow(
                $advance,
                $advance,
                0,
                $overpayment,
                $this->toCzk(
                    $month->bonusColumnMinor(),
                    'úhrn vyplacených bonusů',
                    $warnings,
                ),
                0,
                $this->toCzk(
                    $month->remittedAdvanceMinor,
                    'odvedená zálohová daň',
                    $warnings,
                ),
            );
            $headcounts[$month->month] = $month->headcount;
        }

        $this->appendCoverageWarnings($basis, $warnings);
        if ($basis->nonResidentCount > 0) {
            $warnings[] = sprintf(
                'V roce byl(o) evidován(o) %d daňový(ch) nerezident(ů). '
                . 'Příloha č. 2 vyžaduje číslo a typ dokladu totožnosti a typ '
                . 'zahraničního daňového identifikátoru, které aplikace nevede — '
                . 'doplňte ji ručně v EPO.',
                $basis->nonResidentCount,
            );
        }
        foreach ($basis->workplaces as $place) {
            if (!$place->isComplete() && $place->headcount > 0) {
                $warnings[] = sprintf(
                    '%d zaměstnanc(ů) nemá u vztahu vyplněnou obec místa výkonu '
                    . 'práce — do přílohy č. 1 se nedostali.',
                    $place->headcount,
                );
            }
        }

        return new DependentActivityStatement(
            $basis->year,
            $variant,
            $months,
            $headcounts,
            array_values(array_filter(
                $basis->workplaces,
                static fn (WorkplaceHeadcount $place): bool
                    => $place->isComplete() && $place->headcount > 0,
            )),
            $payouts,
            $overpaymentTotal,
            $bonusTopUpTotal,
            $basis->nonResidentCount,
            array_values(array_unique($warnings)),
        );
    }

    /**
     * @param array{variant?:string} $meta
     */
    public function withholdingTax(
        TaxStatementBasis $basis,
        array $meta = [],
    ): WithholdingTaxStatement {
        $warnings = $basis->warnings;
        $variant = (string) ($meta['variant'] ?? DependentActivityStatement::TYP_RADNE);

        $months = [];
        foreach ($basis->months as $month) {
            if (!$month->hasApprovedRun) {
                continue;
            }
            $row = new WithholdingTaxRow(
                $month->withholdingTaxMinor,
                $month->withholdingTaxMinor,
                0,
                0,
                0,
                0,
                $month->remittedWithholdingMinor,
            );
            // Měsíc bez jediné srážky by do tiskopisu poslal řádek samých nul.
            // Vyúčtování se podává i tehdy, když se za rok nesrazilo nic, ale
            // prázdné měsíce se do části I. nepíšou.
            if (!$row->isEmpty()) {
                $months[$month->month] = $row;
            }
        }

        $this->appendCoverageWarnings($basis, $warnings);

        return new WithholdingTaxStatement(
            $basis->year,
            $variant,
            WithholdingTaxStatement::DRUH_PRIJMU_FO,
            $months,
            array_values(array_unique($warnings)),
        );
    }

    /** @param list<string> $warnings */
    private function appendCoverageWarnings(TaxStatementBasis $basis, array &$warnings): void
    {
        if (!$basis->hasApprovedRun()) {
            throw new \DomainException(
                'Za zvolený rok není žádný schválený mzdový běh, '
                . 'ze kterého by šlo vyúčtování sestavit.',
            );
        }
        $missing = [];
        foreach ($basis->months as $month) {
            if (!$month->hasApprovedRun) {
                $missing[] = $month->month;
            }
        }
        if ($missing !== []) {
            $warnings[] = sprintf(
                'Měsíce %s nemají schválený mzdový běh a ve vyúčtování zůstaly '
                . 'prázdné. Pokud v nich mzdy byly, nejdřív je schvalte.',
                implode(', ', $missing),
            );
        }
    }

    /**
     * Haléře → celé koruny. DPZVD6 má u všech `kc_*` `fractionDigits="0"`,
     * takže jiná možnost není. Zálohová daň i bonus se v české mzdě počítají
     * na celé koruny, takže zbytek by znamenal ztracené zaokrouhlovací pravidlo —
     * proto se na něj upozorní, místo aby se tiše zahodil.
     *
     * @param list<string> $warnings
     */
    private function toCzk(int $minor, string $label, array &$warnings): int
    {
        if ($minor % 100 !== 0) {
            $warnings[] = sprintf(
                '%s není v celých korunách (%d h) — do vyúčtování se zaokrouhlil.',
                ucfirst($label),
                $minor,
            );
        }

        return intdiv($minor + 50, 100);
    }
}
