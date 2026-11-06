<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

interface J6LegacyMappedIdentityTransformer extends J6LegacyInputTransformer
{
    public function permitsIdentityChange(string $kind, array $path, mixed $source, mixed $target): bool;
}
