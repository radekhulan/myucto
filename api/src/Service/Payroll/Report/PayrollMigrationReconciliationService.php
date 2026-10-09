<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Report;

use MyInvoice\Repository\Payroll\PayrollMigrationReconciliationRepository;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverCoverage;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverDiscountIntentCheck;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverInvariants;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverJmhzFormCheck;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverLayerCheck;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;

/**
 * Kontrolní sestava „naše přepočtená mzda vs. mzda převzatá z původního systému".
 *
 * Skládá jen data: převzatou stranu z `payroll_migration_reference_totals`, naši
 * z výsledků mzdových běhů. Porovnání dělá {@see PayrollMigrationReconciliationBuilder},
 * který je čistou funkcí a jde testovat bez databáze.
 */
final class PayrollMigrationReconciliationService
{
    private readonly PayrollMigrationReconciliationBuilder $builder;

    public function __construct(
        private readonly PayrollMigrationReconciliationRepository $repository,
        private readonly PayrollTakeoverCoverage $coverage,
        private readonly PayrollTakeoverLayerCheck $layers,
        private readonly PayrollHistoricalPeriodService $historical,
        private readonly PayrollTakeoverDiscountIntentCheck $discountIntents,
        private readonly PayrollTakeoverJmhzFormCheck $jmhzForms,
        private readonly PayrollTakeoverInvariants $invariants,
        ?PayrollMigrationReconciliationBuilder $builder = null,
    ) {
        $this->builder = $builder ?? new PayrollMigrationReconciliationBuilder();
    }

    /** @return array<string,mixed> */
    public function report(int $supplierId, int $year, ?string $source = null): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být zvolená.');
        }
        if ($year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Mzdový rok musí být v rozsahu 2000 až 2200.');
        }
        if ($source !== null && !in_array($source, PayrollMigrationReferenceTotalsWriter::SOURCES, true)) {
            throw new \InvalidArgumentException('Neznámý zdroj převzatých mezd.');
        }

        $calculated = $this->repository->calculatedTotals($supplierId, $year);
        $report = $this->builder->build(
            $year,
            $this->repository->referenceTotals($supplierId, $year, $source),
            $calculated,
            $this->repository->calculatedEmployerSocial($supplierId, $year),
            $this->historical->startPeriod($supplierId),
        );

        $statuses = $this->revisionStatuses($calculated);
        $report['months'] = array_map(
            static fn (array $month): array => [
                ...$month,
                'calculated_revision_status' => $statuses[$month['period']] ?? null,
            ],
            $report['months'],
        );
        $report['sources'] = $this->repository->referenceSources($supplierId, $year);
        $report['source'] = $source;
        // Převzatá část roku stojí na dvou vrstvách: počátečních stavech (čte
        // roční zúčtování a vyúčtování daně) a převzatých mzdách (čte ELDP).
        // Sestava převzetí je místo, kde se má ukázat, že si odporují nebo že
        // některý převzatý měsíc chybí úplně.
        $report['takeover_check'] = [
            'takeover_months' => $this->coverage->takeoverMonths($supplierId, $year),
            'missing_openings' => $this->coverage->gaps($supplierId, $year),
            'estimated_starts' => $this->coverage->estimatedStartGaps($supplierId, $year),
            // Sleva na pojistném bez přijatého záměru OZUSPOJ se neuplatní; po
            // převodu záměr předchozího programu chybí, dokud se nepřevezme.
            'missing_discount_intents' => $this->discountIntents->missingIntents($supplierId, $year),
            // Hlášení předchozího programu podané před opravou mzdy: podnět
            // k opravnému hlášení, převzetí nedrží.
            'jmhz_form_differences' => $this->jmhzForms->check($supplierId, $year),
            ...$this->layers->check($supplierId, $year),
            // Brána G2: kontroly vlastních dat znovu, kontroly proti zdroji z posledního převodu.
            'invariants' => [
                'live' => $this->invariants->live($supplierId, $year),
                'stored' => $this->invariants->stored($supplierId),
            ],
        ];

        return $report;
    }

    /**
     * Stav revize, ze které se naše strana čte. Sestava schválně nepracuje jen se
     * schválenými revizemi — celý její smysl je podívat se na přepočet dřív, než ho
     * účetní schválí —, takže na obrazovce musí být vidět, že jde o rozpracovaný stav.
     * Dva různé stavy v jednom měsíci (víc provozoven) hlásí `mixed`.
     *
     * @param list<array<string,mixed>> $calculated
     * @return array<string,string>
     */
    private function revisionStatuses(array $calculated): array
    {
        $statuses = [];
        foreach ($calculated as $row) {
            $period = substr((string) ($row['period'] ?? ''), 0, 7);
            $status = (string) ($row['revision_status'] ?? '');
            if ($period === '' || $status === '') {
                continue;
            }
            $statuses[$period] = ($statuses[$period] ?? $status) === $status ? $status : 'mixed';
        }

        return $statuses;
    }
}
