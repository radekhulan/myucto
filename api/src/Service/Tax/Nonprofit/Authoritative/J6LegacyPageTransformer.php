<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

interface J6LegacyPageTransformer extends J6LegacyInputTransformer
{
    /** @param list<array<string,mixed>> $records @return list<array<string,mixed>> */
    public function transformPage(string $kind, array $records): array;
}
