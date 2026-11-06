<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceLocks;
final class PaymentLocks
{
    public function __construct(private readonly Connection $db) {}
    public function acquire(int $supplier): void
    {
        (new IssuanceLocks($this->db))->acquire($supplier);

    }
}
