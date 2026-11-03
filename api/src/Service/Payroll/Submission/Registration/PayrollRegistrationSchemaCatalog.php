<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;

final class PayrollRegistrationSchemaCatalog
{
    public const NS_PREZEC_2026 = 'http://schemas.cssz.cz/PREZEC/2026';
    public const NS_REGZEC_2026 = 'http://schemas.cssz.cz/REGZEC/2025';
    public const NS_REGZEC_2027 = 'http://schemas.cssz.cz/REGZEC/2027';

    /** Kód blokace podání, jehož verze (podle data události) v aplikaci není. */
    public const UNAVAILABLE_CODE = 'registration_spec_version_unavailable';

    /**
     * Připnuté XSD REGZEC27 (balík 1.5 z 30. 9. 2026, `REGZEC27_podaní_1.5.zip`):
     * `[vstupní soubor, SHA-256, baseTypes, SHA-256 baseTypes]` relativně
     * k `api/xsd/jmhz`. Otisky drží i `api/xsd/jmhz/SHA256SUMS`.
     *
     * @var array{0:string,1:string,2:string,3:string}
     */
    private const REGZEC27_SCHEMA = [
        'regzec-1.5/REGZEC27_1_5.xsd',
        'e82159b3fa7a1ce33dde905034d003f182d1cc0e2acd07755d867e9abf35315d',
        'regzec-1.5/baseTypes2.xsd',
        '0ed12320dc9f9230fb60182ac0389dd10b2b76ea5e2aaacf3f71715cbfa82e58',
    ];

    /**
     * Rozhodné datum, podle kterého ČSSZ přijme REGZEC 2025, nebo 2027.
     *
     * EDV 1.5.0.3 a 1.4.0.7, list Slovník, záhlaví (DM-296): REGZEC 2027 jen
     * když je
     * - A1: nástup 10223 (je-li vyplněno i skončení 10224, pak 10224) >= 1. 1. 2027,
     * - A2: skončení 10224 >= 1. 1. 2027,
     * - A3, A4: skončení 10224 prázdné, nebo >= 1. 1. 2027 (ČSSZ, odpovědi
     *   4 a 13: rozhoduje jen 10224),
     * - A5 až A7: platnost 10009 >= 1. 1. 2027,
     * - A8: obě verze bez omezení.
     * REGZEC 2025 zrcadlově jen s daty do 31. 12. 2026.
     *
     * U A8 rozhoduje den platnosti (storno podané stejným ročníkem jako
     * stornovaná událost).
     *
     * Vrací datum pro hlášky a pro volbu ročníku u akcí s datem. U A3/A4 bez
     * skončení datum o ročníku nerozhoduje, viz {@see self::versionFor()}.
     *
     * @param array<string,mixed>|null $eventData `data` zmrazené události
     */
    public static function decisiveDate(
        int $actionCode,
        string $effectiveOn,
        ?array $eventData,
    ): string {
        return self::changeEndOn($actionCode, $eventData) ?? $effectiveOn;
    }

    /**
     * Ročník REGZEC podání. ČSSZ, Otázky a odpovědi k NV2027
     * (developers.mpsv.cz, stránka dokumentace NV2027), odpovědi 4 a 13:
     * u A3/A4 rozhoduje VÝHRADNĚ datum skončení 10224 v podání. Vyplněné
     * před 1. 1. 2027 znamená REGZEC25, vyplněné později nebo prázdné
     * REGZEC27; platnost 10009 ani datum opravovaného oznámení se
     * nezohledňují, takže i změna údajů z roku 2026 u trvajícího vztahu jde
     * jako REGZEC27. Prázdné 10224 ale ostrá ČSSZ v REGZEC27 přijme až od
     * 23. 12. 2026 (odpověď 5), proto u něj rozhoduje den vyhotovení
     * ({@see JmhzSpecVersion::forRegistrationChangeWithoutEnd()}).
     * Ostatní akce jdou podle {@see self::decisiveDate()}.
     *
     * @param array<string,mixed>|null $eventData `data` zmrazené události
     */
    public static function versionFor(
        int $actionCode,
        string $effectiveOn,
        ?array $eventData,
        string $preparedOn,
    ): JmhzSpecVersion {
        if (in_array($actionCode, [3, 4], true)
            && self::changeEndOn($actionCode, $eventData) === null
        ) {
            return JmhzSpecVersion::forRegistrationChangeWithoutEnd($preparedOn);
        }

        return JmhzSpecVersion::forRegistrationEventDate(
            self::decisiveDate($actionCode, $effectiveOn, $eventData),
        );
    }

    /** @param array<string,mixed>|null $eventData */
    private static function changeEndOn(int $actionCode, ?array $eventData): ?string
    {
        if (!in_array($actionCode, [3, 4], true) || !is_array($eventData)) {
            return null;
        }
        $employment = $eventData['delta']['employment'] ?? null;
        $endOn = is_array($employment) ? ($employment['end_on'] ?? null) : null;

        return is_string($endOn) && $endOn !== '' ? $endOn : null;
    }

    /**
     * Ročníkový formulář pro podání s událostí k danému dni (nástup, skončení,
     * platnost změny). Volba verze je v {@see JmhzSpecVersion}; tady se jen
     * překládá na formulář a fail-closed odmítne verze, které aplikace neumí.
     *
     * PREZEC ročník nemění: balík „PREZEC27_podani_1.5.zip" (30. 9. 2026)
     * nese soubor `PREZEC27_1_5.xsd`, ale s `targetNamespace`
     * `http://schemas.cssz.cz/PREZEC/2026` a bajtově shodný s
     * `PREZEC26_1_5.xsd`; jmenný prostor `/PREZEC/2027` z návrhu 1.2.0
     * (15. 9. 2026) ČSSZ ve verzi 1.5.0.0 vrátila. Příručka „JMHZ testování
     * změn 2027" (kap. 3.1, 13.2) mění `eType` jen u JMHZ a REGZEC. Přihláška
     * před nástupem v roce 2027 proto odchází jako PREZEC26.
     *
     * @param string $documentType formulář tak, jak ho určila interakce (`REGZEC25`, `PREZEC26`)
     * @param JmhzSpecVersion|null $version ročník z {@see self::versionFor()}; bez něj podle data události
     */
    public function documentTypeFor(
        string $documentType,
        string $eventDate,
        ?JmhzSpecVersion $version = null,
    ): string {
        $version ??= JmhzSpecVersion::forRegistrationEventDate($eventDate);
        if ($version === JmhzSpecVersion::V2026 || $documentType === 'PREZEC26') {
            return $documentType;
        }
        $versioned = match ($documentType) {
            'REGZEC25' => 'REGZEC27',
            default => null,
        };
        if ($versioned === null) {
            throw new PayrollRegistrationXmlException(
                self::UNAVAILABLE_CODE,
                "Formulář {$documentType} s datem události od 1. 1. 2027 se podává"
                    . ' podle specifikace JMHZ 2027, kterou ČSSZ pro tenhle formulář'
                    . ' zatím nezveřejnila. Podání se proto nepřipraví; podejte ho'
                    . ' přes portál ČSSZ.',
            );
        }
        if (!self::isPinned($versioned)) {
            throw new PayrollRegistrationXmlException(
                self::UNAVAILABLE_CODE,
                "Událost k {$eventDate} se hlásí formulářem {$versioned}"
                    . ' (specifikace JMHZ 2027), který tahle verze aplikace zatím'
                    . ' neumí sestavit. Podání se proto nepřipraví. Události do'
                    . ' 31. 12. 2026 připravíte jako dosud.',
            );
        }

        return $versioned;
    }

    /**
     * Formulář datové věty pro agendu (`REGZEC25`, `PREZEC26`) a verzi
     * specifikace. Kód agendy se s ročníkem nemění ({@see JmhzSpecVersion}),
     * ročník nese až formulář: jmenný prostor, XSD a `eType` obálky.
     */
    public static function formFor(string $documentType, JmhzSpecVersion $version): string
    {
        return $documentType === 'REGZEC25' && $version === JmhzSpecVersion::V2027
            ? 'REGZEC27'
            : $documentType;
    }

    public static function isPinned(string $documentType): bool
    {
        return match ($documentType) {
            'PREZEC26', 'REGZEC25', 'REGZEC27' => true,
            default => false,
        };
    }

    /** @return array{path:string,namespace:string} */
    public function schemaFor(string $documentType): array
    {
        $root = dirname(__DIR__, 5) . '/xsd/jmhz';
        $baseTypesHash = '0ed12320dc9f9230fb60182ac0389dd10b2b76ea5e2aaacf3f71715cbfa82e58';
        $definition = match ($documentType) {
            'PREZEC26' => [
                $root . '/prezec-1.2/PREZEC26 1.2.xsd',
                '117d56be4a79ebec3c1684a4b32b94df6dbeb99a7533489359bee48cb9a11b0a',
                $root . '/prezec-1.2/baseTypes2.xsd',
                self::NS_PREZEC_2026,
            ],
            'REGZEC25' => [
                $root . '/regzec-1.4.0.4/REGZEC25.xsd',
                'bbf96586cccd36457283f8474a982d3bee8ae98bbdba120f240065aa6d40a83b',
                $root . '/regzec-1.4.0.4/baseTypes2.xsd',
                self::NS_REGZEC_2026,
            ],
            'REGZEC27' => [
                $root . '/' . self::REGZEC27_SCHEMA[0],
                self::REGZEC27_SCHEMA[1],
                $root . '/' . self::REGZEC27_SCHEMA[2],
                self::NS_REGZEC_2027,
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
        if ($documentType === 'REGZEC27') {
            $baseTypesHash = self::REGZEC27_SCHEMA[3];
        }
        [$entry, $entryHash, $dependency, $namespace] = $definition;
        foreach ([
            $entry => $entryHash,
            $dependency => $baseTypesHash,
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
