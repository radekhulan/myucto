<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

interface MultiFileBankConnector extends StructuredBankConnector
{
    /** @return array{account_number:string,bank_code:string,currency:string} */
    public function verifyAccount(#[\SensitiveParameter] string $credentials): array;

    /** @return list<array{content:string,filename:string}> */
    public function statementFiles(#[\SensitiveParameter] string $content): array;
}
