<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

interface StructuredBankConnector extends BankConnector
{
    public function credentials(#[\SensitiveParameter] array $input, array $account, #[\SensitiveParameter] ?string $existing): string;

    public function parseStatement(#[\SensitiveParameter] string $content): array;

    public function statementFormat(): string;
}
