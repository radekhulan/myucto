<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

interface BankConnector
{
    public function provider(): string;

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string;

    /**
     * @return array{accepted:true,reference:string,status?:'import_started'}
     */
    public function submitPaymentOrder(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $abo,
    ): array;
}
