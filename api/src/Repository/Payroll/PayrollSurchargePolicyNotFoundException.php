<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollSurchargePolicyNotFoundException extends \RuntimeException
{
    public function __construct(
        string $message = 'Zásada příplatků nebyla u tohoto pracovního vztahu nalezena.',
    ) {
        parent::__construct($message);
    }
}
