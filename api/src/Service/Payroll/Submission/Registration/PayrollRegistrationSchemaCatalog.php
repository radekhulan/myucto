<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationSchemaCatalog
{
    /** @return array{path:string,namespace:string} */
    public function schemaFor(string $documentType): array
    {
        $root = dirname(__DIR__, 5) . '/xsd/jmhz';
        $definition = match ($documentType) {
            'PREZEC26' => [
                $root . '/prezec-1.2/PREZEC26 1.2.xsd',
                '117d56be4a79ebec3c1684a4b32b94df6dbeb99a7533489359bee48cb9a11b0a',
                $root . '/prezec-1.2/baseTypes2.xsd',
                'http://schemas.cssz.cz/PREZEC/2026',
            ],
            'REGZEC25' => [
                $root . '/regzec-1.4.0.4/REGZEC25.xsd',
                'bbf96586cccd36457283f8474a982d3bee8ae98bbdba120f240065aa6d40a83b',
                $root . '/regzec-1.4.0.4/baseTypes2.xsd',
                'http://schemas.cssz.cz/REGZEC/2025',
            ],
            // Výjimka tu zůstává schválně: bez předlohy formuláře se podání
            // nedá ani zkontrolovat, natož odeslat. Není to neúplný vstup
            // účetní, ale požadavek na formulář, který appka neumí.
            default => throw new PayrollRegistrationXmlException(
                'registration_schema_unavailable',
                "Registrační formulář ČSSZ „{$documentType}“ tahle verze "
                    . 'neumí připravit. Podporované jsou přihlášení a oznámení '
                    . 'REGZEC25 a částečné přihlášení PREZEC26; ostatní podejte '
                    . 'přes portál ČSSZ.',
            ),
        };
        [$entry, $entryHash, $dependency, $namespace] = $definition;
        foreach ([
            $entry => $entryHash,
            $dependency =>
                '0ed12320dc9f9230fb60182ac0389dd10b2b76ea5e2aaacf3f71715cbfa82e58',
        ] as $path => $expectedHash) {
            $actualHash = is_file($path) ? hash_file('sha256', $path) : false;
            if ($actualHash === false
                || !hash_equals($expectedHash, $actualHash)
            ) {
                // Taky vědomě výjimka: kdyby se předloha jen „přeskočila",
                // odešlo by se na ČSSZ nezkontrolované podání. Účetní s tím
                // nic neudělá, ale musí vědět, že to není chyba jejích dat.
                throw new PayrollRegistrationXmlException(
                    'registration_schema_integrity_failed',
                    'Předlohy formulářů ČSSZ v této instalaci chybí nebo se '
                        . 'liší od ověřených. Podání proto nejde zkontrolovat — '
                        . 'není to chybou vašich údajů, obraťte se na podporu.',
                );
            }
        }

        return ['path' => $entry, 'namespace' => $namespace];
    }
}
