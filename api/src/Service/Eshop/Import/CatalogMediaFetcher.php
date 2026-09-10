<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

interface CatalogMediaFetcher
{
    public function assertSyntax(string $url): void;

    /** @return array{body:string,original_name:string} */
    public function fetch(string $url): array;
}
