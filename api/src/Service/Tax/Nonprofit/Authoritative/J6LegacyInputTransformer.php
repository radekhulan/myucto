<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

interface J6LegacyInputTransformer
{
    public function transform(string $kind, array $record): array;
}
