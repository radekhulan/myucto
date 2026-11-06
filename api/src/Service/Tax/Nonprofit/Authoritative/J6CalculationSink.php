<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

interface J6CalculationSink
{
    public function begin(J6CalculationHeader $header): void;

    public function appendDecisionPage(array $rows): void;

    public function appendResultPage(array $rows): void;

    public function appendCoveragePage(array $rows): void;

    public function appendFundingUsagePage(array $rows): void;

    public function appendFundingReferencePage(array $rows): void;

    public function appendFundingHistoryPage(array $rows): void;

    public function complete(array $summary): J6CalculationReceipt;

    public function abort(): void;
}
