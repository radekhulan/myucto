<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

interface J6LegacyProjectionSink
{
    public function appendLegacyActivityPage(array $rows): void;

    public function appendLegacyExternalAdjustmentPage(array $rows): void;
}
