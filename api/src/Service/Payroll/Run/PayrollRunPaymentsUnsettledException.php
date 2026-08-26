<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final class PayrollRunPaymentsUnsettledException extends \DomainException
{
    /**
     * @param array{
     *   liability_count:int,
     *   batch_count:int,
     *   required_minor:int,
     *   allocated_minor:int,
     *   settled_minor:int,
     *   incoming_unsettled_count:int,
     *   uncovered:list<array{
     *     liability_id:int,
     *     liability_kind:string,
     *     direction:string,
     *     employee_name:?string,
     *     currency_code:string,
     *     uncovered_minor:int
     *   }>
     * } $coverage
     */
    public function __construct(
        public readonly array $coverage,
        string $message,
    ) {
        parent::__construct($message);
    }
}
