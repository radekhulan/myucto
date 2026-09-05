<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

final class LicensePayrollLimitExceeded extends \DomainException
{
    public function __construct(
        public readonly string $reason,
        public readonly LicenseState $state,
        public readonly int $before,
        public readonly int $after,
    ) {
        parent::__construct('Změna by překročila zaplacený rozsah modulu Mzdy.');
    }
}
