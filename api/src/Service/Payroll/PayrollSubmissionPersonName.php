<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzDerivedRegistrations;

/**
 * Znaky, které smí nést jméno a příjmení osoby v podáních ČSSZ.
 *
 * Měsíční hlášení JMHZ (`jmenoType`, `prijmeniType`), registrace REGZEC/PREZEC,
 * NEMPRI i OZUSPOJ mají jméno typu `simpleA_ZX_SP_Type` z `baseTypes2.xsd`:
 * latinka s diakritikou, pomlčka, čárka, tečka, apostrof a mezera mezi slovy.
 * Číslice, závorka nebo cyrilice projdou zápisem na kartě, ale podání pak
 * spadne až na XSD, hláškou knihovny a ve chvíli, kdy už běží lhůta. Proto se
 * jména, která do podání jdou, kontrolují tímhle vzorem už při zápisu.
 *
 * Jméno se samo nepřepisuje: změna zapsaného jména by byla změnou identity
 * osoby v podání.
 */
final class PayrollSubmissionPersonName
{
    /**
     * Povolené znaky bez mezery, doslova ze vzoru `simpleA_ZX_SP_Type`
     * (shodu s XSD hlídá PayrollSubmissionPersonNameTest).
     */
    public const ALLOWED_CHARACTERS = "A-Za-zŠŚŤŽŹšśťžźŁĄŞŻłąşĽľżŔÁÂĂÄĹĆÇČÉĘËĚÍÎĎĐŃŇÓÔŐÖŘŮÚŰÜÝŢßŕáâăäĺćçčéęëěíîďđńňóôőöřůúűüýţ\\-,\\.'";

    /**
     * Znaky jména, které podání nepřipouští, každý jednou, v pořadí výskytu.
     *
     * @return list<string>
     */
    public static function disallowedCharacters(string $value): array
    {
        if (preg_match_all('/[^' . self::ALLOWED_CHARACTERS . ' ]/u', $value, $matches) < 1) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    /**
     * Odmítne jméno se znakem, který podání nepřipouští, s hláškou, která
     * jmenuje pole i vadné znaky.
     *
     * @throws InvalidArgumentException
     */
    public static function assertAllowed(string $value, string $label): void
    {
        $characters = self::disallowedCharacters($value);
        if ($characters === []) {
            return;
        }
        throw new InvalidArgumentException(sprintf(
            '„%s“ obsahuje znaky, které podání ČSSZ (měsíční hlášení, registrace) nepřipouští: %s.'
            . ' Povolená je latinka s diakritikou, pomlčka, čárka, tečka, apostrof a mezera.',
            $label,
            self::shown($characters),
        ));
    }

    /**
     * Jméno, příjmení a rodné příjmení zaměstnance (karta osoby, založení osoby).
     *
     * Výjimkou je zástupné jméno osoby založené z hlášení, které nese jen OIČ
     * ({@see JmhzDerivedRegistrations::isPlaceholderName()}): číslo v něm odlišuje
     * zástupce mezi sebou a účetní ho přepíše skutečným jménem.
     *
     * @throws InvalidArgumentException
     */
    public static function assertPersonNames(?string $firstName, ?string $lastName, ?string $birthSurname): void
    {
        $placeholder = JmhzDerivedRegistrations::isPlaceholderName($firstName, $lastName);
        foreach ([
            'Křestní jméno' => $firstName,
            'Příjmení' => $placeholder ? null : $lastName,
            'Rodné příjmení' => $birthSurname,
        ] as $label => $value) {
            if ($value !== null) {
                self::assertAllowed($value, $label);
            }
        }
    }

    /** @param list<string> $characters */
    public static function shown(array $characters): string
    {
        return implode(' ', array_map(
            static fn (string $character): string => $character === "\t"
                ? '[tabulátor]'
                : '„' . $character . '“',
            $characters,
        ));
    }
}
