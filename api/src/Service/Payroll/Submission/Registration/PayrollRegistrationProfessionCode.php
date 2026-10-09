<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Kód profese CZ-ISCO v registraci zaměstnance (REGZEC, employee/job/prof/@clas).
 *
 * EDV 1.4.0.6, list Slovník, řádek R91: „Z číselníku lze použít pouze kódy
 * s délkou 5 znaků.“ XSD připouští 3 až 5 znaků, takže čtyřmístná podskupina
 * by odešla a ČSSZ by ji odmítla. Měsíční hlášení JMHZ podskupinu přijme
 * (CzIscoCodebook::SELECTABLE_LEVELS), registrace ne. Převod na pětimístnou
 * kategorii se nehádá: podskupina má obvykle víc kategorií a vybrat ji musí
 * účetní.
 */
final class PayrollRegistrationProfessionCode
{
    public const REGISTRATION_LENGTH = 5;

    public static function isRegistrable(string $code): bool
    {
        return preg_match('/^\d{5}$/D', $code) === 1;
    }
}
