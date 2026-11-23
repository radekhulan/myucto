<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/** Tentýž mzdový nárok současně vlastní více zdrojů. */
final class PayrollHourlyWageConflictException extends \InvalidArgumentException
{
    public const ERROR_CODE = 'hourly_external_amount_conflict';

    /**
     * @param array<string,scalar|null> $details vztah, složka a odkaz na místo
     *        nápravy; odpověď je nese v `error.details`
     */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function responseExtra(): array
    {
        return $this->details === [] ? [] : ['details' => $this->details];
    }
}
