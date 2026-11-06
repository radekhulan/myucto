<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Zadaný identifikátor (rodné číslo, EČP, VČP, zahraniční DIČ) už ve firmě
 * nese jiná osoba.
 *
 * Dědí z `InvalidArgumentException`, takže stávající obsluha ji dál hlásí
 * jako chybu zadání (422). Vlastní typ existuje kvůli tomu, že shoda je
 * odpověď na otázku „patří tahle hodnota někomu?", tedy porovnání proti
 * údaji, který volající bez `payroll.person.read_sensitive` vidí jen
 * maskovaný. Akce ji proto zapisují do auditní stopy a pokusy omezují.
 * Hláška nikdy nejmenuje osobu, které hodnota patří.
 */
final class PayrollPersonIdentifierCollisionException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $identifierType,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $identifierType === 'birth_number'
                ? 'Zaměstnance se stejným rodným číslem už evidujete.'
                : 'Zaměstnance se stejným identifikátorem už evidujete.',
            0,
            $previous,
        );
    }
}
