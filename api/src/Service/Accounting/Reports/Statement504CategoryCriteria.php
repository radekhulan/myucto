<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Repository\Statement504DataRepository;

final class Statement504CategoryCriteria
{
    public function __construct(private readonly Statement504DataRepository $data, private readonly LedgerReportRepository $ledger) {}

    public function calculate(int $supplierId, array $period, string $asOf, ?Statement504Builder $builder = null): array
    {
        // § 5 vyhlášky 504 ve znění 2017–2023 už vyžaduje aktiva netto.
        // Historická kritéria proto používají stejnou bilanční mapu i bez oficiální
        // šablony; k obratu všech výnosů podle tehdejšího § 1d ZoÚ není nutná činnost.
        $builder ??= new Statement504Builder();
        $revenue = $this->ledger->netTurnoverForCodes($supplierId, $period['starts_on'], $asOf, ['6']);
        $months = ((int) substr($period['ends_on'], 0, 4) - (int) substr($period['starts_on'], 0, 4)) * 12
            + (int) substr($period['ends_on'], 5, 2) - (int) substr($period['starts_on'], 5, 2) + 1;
        return ['assets_net' => $builder->assetsNet($this->data->balances($supplierId, $period['starts_on'], $asOf)),
            'net_turnover' => round($revenue * 12 / $months, 2)];
    }
}
