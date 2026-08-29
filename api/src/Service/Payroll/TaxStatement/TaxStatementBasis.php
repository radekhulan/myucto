<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxStatement;

/**
 * Roční podklad obou vyúčtování — dvanáct měsíců plus to, co patří do příloh.
 *
 * Vzniká výhradně z {@see \MyInvoice\Repository\Payroll\PayrollTaxStatementRepository},
 * tedy ze zmrazených zákonných výsledků schválených revizí a ze spárovaných
 * plateb. Druhý výpočet daně tu nevzniká — kdyby vznikl, rozešel by se
 * s odvodem, který si systém sám předepsal.
 */
final readonly class TaxStatementBasis
{
    /**
     * @param int $year Vykazované zdaňovací období.
     * @param list<TaxStatementMonth> $months Právě dvanáct řádků, leden až prosinec.
     * @param list<WorkplaceHeadcount> $workplaces Příloha č. 1 — počty osob podle
     *        místa výkonu práce k 1. prosinci.
     * @param int $nonResidentCount Kolik osob bylo v roce vedeno jako daňový
     *        nerezident. Nenulová hodnota znamená povinnou přílohu č. 2, kterou
     *        aplikace neumí naplnit — viz {@see TaxStatementCalculator}.
     * @param list<string> $warnings
     */
    public function __construct(
        public int $year,
        public array $months,
        public array $workplaces,
        public int $nonResidentCount,
        public array $warnings = [],
    ) {
        if (count($months) !== 12) {
            throw new \LogicException('Podklad vyúčtování musí mít dvanáct měsíců.');
        }
        foreach ($months as $index => $month) {
            if ($month->month !== $index + 1) {
                throw new \LogicException(
                    'Měsíce podkladu vyúčtování nejsou v pořadí leden až prosinec.',
                );
            }
        }
    }

    public function hasApprovedRun(): bool
    {
        foreach ($this->months as $month) {
            if ($month->hasApprovedRun) {
                return true;
            }
        }

        return false;
    }
}
