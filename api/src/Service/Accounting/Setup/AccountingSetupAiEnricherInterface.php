<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Setup;

interface AccountingSetupAiEnricherInterface
{
    public function isAvailable(int $supplierId): bool;

    /**
     * @param list<array{sample_id:string,text:string,occurrences:int}> $samples
     * @return array<string,mixed>
     */
    /** @param list<array{code:string,is_synthetic:bool,analytic_count:int}> $chartShape */
    public function enrich(int $supplierId, array $samples, array $chartShape): array;
}
