<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

interface FinancialCloneHashResolver
{
    public function resolve(string $oldHash): ?string;
}
