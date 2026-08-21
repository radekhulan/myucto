<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

final class IncompleteTenantDataRegistryCoverage extends \LogicException
{
    /** @param list<string> $issues */
    public function __construct(public readonly array $issues)
    {
        parent::__construct(
            'Tenantový registr nepokrývá aktuální schéma: ' . implode(', ', $issues),
        );
    }
}
