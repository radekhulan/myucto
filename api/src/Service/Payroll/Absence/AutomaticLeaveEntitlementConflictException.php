<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

final class AutomaticLeaveEntitlementConflictException extends \RuntimeException
{
    public function __construct(public readonly int $employmentId)
    {
        parent::__construct('Podklady nároku se od načtení změnily. Obnovte přehled a akci zopakujte.');
    }
}
