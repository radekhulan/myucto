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
 *
 * Má-li takový zaměstnanec na kartě vedle rodného čísla i EČP, jde do přihlášky
 * EČP ({@see self::bno()}): identifikuje cizince jednoznačně a ČSSZ takovou
 * přihlášku přijímá. Varování pak odpadá.
 */
final class PayrollRegistrationSlovakBirthNumberRule
{
    /**
     * Identifikátor pro `client/@bno` přihlášky REGZEC A1: rodné číslo, a není-li,
     * EČP; u občana SR narozeného po roce 1992 přednostně EČP.
     */
    public static function bno(
        ?string $citizenship,
        ?string $birthNumber,
        ?string $ecp,
        ?string $birthDate,
    ): ?string {
        $ecp = $ecp === null || trim($ecp) === '' ? null : $ecp;
        if ($ecp !== null && self::applies($citizenship, $birthNumber, $birthDate)) {
            return $ecp;
        }

        return $birthNumber ?? $ecp;
    }

    /** @return array{code:string,field:string,message:string}|null */
    public static function warning(
        ?string $citizenship,
        ?string $birthNumber,
        ?string $birthDate,
        ?string $ecp = null,
    ): ?array {
        if (!self::applies($citizenship, $birthNumber, $birthDate)
            || ($ecp !== null && trim($ecp) !== '')
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

    private static function applies(?string $citizenship, ?string $birthNumber, ?string $birthDate): bool
    {
        $birthDate ??= PayrollRegistrationMinimumAge::birthDateFromBirthNumber($birthNumber);

        return $citizenship === 'SK'
            && $birthNumber !== null
            && trim($birthNumber) !== ''
            && is_string($birthDate)
            && $birthDate > '1992-12-31';
    }
}
