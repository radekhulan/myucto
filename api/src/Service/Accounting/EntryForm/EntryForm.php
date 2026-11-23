<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\EntryForm;

/**
 * Forma účetního zápisu firmy k datu.
 *
 * Composite = složený zápis (řádky MD a Dal bez vazby, dosavadní chování).
 * Single = jednoduchý (párový) zápis: každý řádek patří do dvojice `pair_no`.
 * Formu k datu určuje výhradně {@see EntryFormService::formAt()}.
 */
enum EntryForm: string
{
    case Composite = 'composite';
    case Single = 'single';

    public function isSingle(): bool
    {
        return $this === self::Single;
    }
}
