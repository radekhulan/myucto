<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

enum J6ReadMode: string
{
    case CURRENT = 'current';
    case FROZEN_CURRENT = 'frozen_current';
    case HISTORIC = 'historic';
    case REBASED_HISTORIC = 'rebased_historic';
    case LEGACY_CONVERSION = 'legacy_conversion';
}
