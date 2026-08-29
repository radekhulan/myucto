<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxBonus;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollTaxBonusClaimRepository;
use MyInvoice\Service\Report\EpoSupplierBlockBuilder;

/**
 * Sestaví žádosti o poukázání chybějící částky na daňovém bonusu za jeden
 * kalendářní měsíc — § 35d odst. 5 (DPZMB1) i odst. 9 (DPZDB1).
 *
 * Obě žádosti jsou vázané na MĚSÍC, i když DPZDB1 v tiskopisu uvádí zdaňovací
 * období: `d_bonus` je datum SKUTEČNÉ výplaty a odvod záloh, proti kterému se
 * bonus započítává, je taky měsíční. Doplatek ze zúčtování vyplacený v březnu
 * a doplatek dovyplacený opravnou revizí v červnu jsou proto dvě samostatné
 * žádosti za totéž zdaňovací období, ne jedna sečtená.
 */
final class TaxBonusRequestService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollTaxBonusClaimRepository $repository,
        private readonly TaxBonusClaimCalculator $calculator,
        private readonly TaxBonusRequestXmlBuilder $xmlBuilder,
    ) {}

    /**
     * Podklad a obě možné žádosti za měsíc — bez generování XML.
     *
     * @return array{
     *   basis:array<string,mixed>,
     *   claims:array<string,array<string,mixed>>,
     *   warnings:list<string>
     * }
     */
    public function preview(int $supplierId, int $year, int $month): array
    {
        $basis = $this->basis($supplierId, $year, $month);
        $result = $this->calculator->calculate($basis);

        $claims = [];
        foreach (['monthly', 'annual'] as $slot) {
            $claim = $result[$slot];
            if ($claim instanceof TaxBonusClaim) {
                $claims[$claim->formCode] = $claim->toSummary();
            }
        }

        return [
            'basis' => [
                'period_start' => $basis->periodStart,
                'payment_date' => $basis->paymentDate,
                'advance_tax_minor' => $basis->advanceTaxMinor,
                'monthly_bonus_minor' => $basis->monthlyBonusMinor,
                'annual_settlement_minor' => $basis->annualSettlementMinor,
                'unapplied_offset_minor' => $basis->unappliedOffsetMinor(),
                'revision_ids' => $basis->revisionIds,
            ],
            'claims' => $claims,
            'warnings' => $result['warnings'],
        ];
    }

    /**
     * XML jedné žádosti.
     *
     * @param array{verze_sw?:string,verze_pis?:string,zad_typ?:string,kc_ponech?:int} $meta
     * @return array{xml:string,summary:array<string,mixed>,warnings:list<string>}
     */
    public function build(
        int $supplierId,
        int $year,
        int $month,
        string $formCode,
        array $meta = [],
    ): array {
        $basis = $this->basis($supplierId, $year, $month);
        $result = $this->calculator->calculate($basis);
        $claim = match ($formCode) {
            TaxBonusClaim::FORM_MONTHLY => $result['monthly'],
            TaxBonusClaim::FORM_ANNUAL => $result['annual'],
            default => throw new \InvalidArgumentException(
                'Neznámý tiskopis žádosti o daňový bonus: ' . $formCode,
            ),
        };
        if (!$claim instanceof TaxBonusClaim) {
            throw new \DomainException(
                'Za zvolené období není o co žádat — vyplacené bonusy se pokryly '
                . 'ze sražených záloh, nebo mzdový běh nemá datum výplaty.',
            );
        }

        $supplier = EpoSupplierBlockBuilder::loadSupplier($this->db->pdo(), $supplierId);
        $built = $this->xmlBuilder->build($supplier, $claim, $meta);

        return [
            'xml' => $built['xml'],
            'summary' => $claim->toSummary(),
            'warnings' => $built['warnings'],
        ];
    }

    private function basis(int $supplierId, int $year, int $month): TaxBonusClaimBasis
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Neplatné mzdové období.');
        }
        $rows = $this->repository->approvedMonthlyTotals($supplierId, $year, $month);
        if ($rows === []) {
            throw new \DomainException(
                'Za zvolený měsíc není schválený mzdový běh s vypočtenou daní.',
            );
        }

        $advance = 0;
        $monthly = 0;
        $annual = 0;
        $paymentDate = null;
        $revisionIds = [];
        foreach ($rows as $row) {
            $advance += $this->minor($row['advance_tax_minor'], 'úhrn záloh na daň');
            $monthly += $this->minor($row['monthly_bonus_minor'], 'úhrn měsíčních bonusů');
            $annual += $this->minor(
                $row['annual_settlement_minor'],
                'úhrn doplatků ze zúčtování',
            );
            $revisionIds[] = (int) $row['revision_id'];
            $date = $row['payment_date'];
            // Víc běhů v měsíci (mzdová střediska) může mít různé dny výplaty.
            // Bonus je vyplacený nejpozději tím posledním, a dřívější datum by
            // v žádosti tvrdilo výplatu, která tehdy ještě celá neproběhla.
            if (is_string($date) && $date !== ''
                && ($paymentDate === null || $date > $paymentDate)
            ) {
                $paymentDate = $date;
            }
        }

        return new TaxBonusClaimBasis(
            sprintf('%04d-%02d-01', $year, $month),
            $paymentDate,
            $advance,
            $monthly,
            $annual,
            $revisionIds,
        );
    }

    private function minor(mixed $value, string $label): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_numeric($value)) {
            throw new \DomainException(
                'Zmrazený mzdový výsledek nenese ' . $label . ' jako číslo.',
            );
        }
        $minor = (int) $value;
        if ($minor < 0) {
            throw new \DomainException(ucfirst($label) . ' vyšel záporně.');
        }

        return $minor;
    }
}
