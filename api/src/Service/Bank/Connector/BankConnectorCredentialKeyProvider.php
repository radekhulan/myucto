<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

interface BankConnectorCredentialKeyProvider
{
    public function callGuardCredential(#[\SensitiveParameter] string $credential): string;
}
