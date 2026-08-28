<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollMealEntitlementBasisLockedException extends \DomainException
{
    public function __construct()
    {
        parent::__construct(
            'Režim nároku na stravování nelze změnit, protože zaměstnanec má '
            . 'schválený příspěvek s aktivním čerpáním. Nejdřív příspěvek stornujte.',
        );
    }
}
