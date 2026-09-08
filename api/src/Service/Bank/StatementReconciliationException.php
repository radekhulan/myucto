<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class StatementReconciliationException extends \InvalidArgumentException
{
    public const ERROR_CODE = 'statement_reconciliation_required';

    /**
     * @param list<array{confirmation_key:string,posted_at:string,amount:string,currency:string,
     *     existing_transaction_id:int,existing_statement_id:int,description:string,
     *     existing_description:string,counterparty_account:string,
     *     existing_counterparty_account:string,variable_symbol:string,
     *     existing_variable_symbol:string}> $candidates
     */
    public function __construct(public readonly array $candidates = [])
    {
        parent::__construct(
            'Bankovní výpis obsahuje nejednoznačnou duplicitu; správné shody je nutné potvrdit.',
        );
    }
}
