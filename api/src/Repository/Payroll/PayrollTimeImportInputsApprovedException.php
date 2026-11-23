<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Přepnutí měsíce z importu na docházku narazilo na schválené nebo zamčené
 * mzdové vstupy z importu. Ty se automaticky rušit nesmí: schválený vstup je
 * rozhodnutí účetní, zamčený už leží ve mzdovém běhu.
 */
final class PayrollTimeImportInputsApprovedException extends \DomainException
{
    /** @param list<int> $inputIds */
    public function __construct(public readonly array $inputIds)
    {
        parent::__construct(sprintf(
            'Měsíc má %d schválených nebo zamčených mzdových vstupů z importu docházky. '
            . 'Ty se automaticky nezruší. Vraťte je v Mzdových vstupech do konceptu '
            . '(u zamčených nejdřív znovu otevřete mzdový běh) a akci „Počítat ze směn" opakujte.',
            count($inputIds),
        ));
    }
}
