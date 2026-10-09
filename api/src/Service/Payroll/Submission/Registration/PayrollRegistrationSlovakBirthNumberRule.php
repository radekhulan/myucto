<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Rodné číslo občana SR narozeného po 31. 12. 1992 v přihlášce REGZEC A1.
 *
 * Pokyny REGZEC, atribut 10057: občané SR, jimž bylo rodné číslo přiděleno
 * po 31. 12. 1992, jsou cizinci a toto „slovenské" rodné číslo pro formuláře
 * použít nelze. Tentýž atribut ale říká, že rodné číslo je nezastupitelný
 * identifikátor i u cizinců, a cizinec s pobytem v ČR může mít české rodné
 * číslo přidělené ministerstvem vnitra. Které z nich na kartě je, evidence
 * nepozná (formát je shodný), a ČSSZ přihlášky občanů SR narozených po roce
 * 1992 s rodným číslem přijímá. Sestavení proto neodmítá, jen upozorní.
 */
final class PayrollRegistrationSlovakBirthNumberRule
{
    /** @return array{code:string,field:string,message:string}|null */
    public static function warning(
        ?string $citizenship,
        ?string $birthNumber,
        ?string $birthDate,
    ): ?array {
        $birthDate ??= PayrollRegistrationMinimumAge::birthDateFromBirthNumber($birthNumber);
        if ($citizenship !== 'SK'
            || $birthNumber === null
            || trim($birthNumber) === ''
            || !is_string($birthDate)
            || $birthDate <= '1992-12-31'
        ) {
            return null;
        }

        return [
            'code' => 'registration_identity_slovak_birth_number_after_1992',
            'field' => 'identifiers.birth_number',
            'message' => 'Zaměstnanec se slovenským státním občanstvím narozený po '
                . '31. 12. 1992 je pro ČSSZ cizinec a slovenské rodné číslo se do '
                . 'přihlášky REGZEC A1 neuvádí. Je-li rodné číslo na kartě '
                . 'slovenské, odeberte ho; má-li zaměstnanec přidělené EČP, '
                . 'vyplňte to, jinak přihláška odejde jen s datem narození '
                . 'a ČSSZ EČP přidělí. České rodné číslo přidělené v ČR ponechte.',
        ];
    }
}
