<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

/**
 * Připnutá schémata pro evidenční list důchodového pojištění.
 *
 * Dvě věci, které je nutné držet od sebe:
 *
 * 1. **Datová věta samostatného ELDP podání ČSSZ (e-Podání ELDP) v lokálním
 *    oficiálním balíčku NENÍ.** `api/xsd/jmhz/regzeldopl-1.2` je i přes
 *    zavádějící zkratku *registrace zaměstnavatele – doplňující údaje*
 *    (`REGZELDOPL25`), nikoli ELDP: obsahuje jen hlavičku pracoviště ČSSZ/FÚ
 *    a evidenční příznaky zaměstnavatele. Požadavek na schéma odesílaného
 *    ELDP proto končí fail-closed, stejně jako u chybějících REGZEL interakcí.
 * 2. **Strukturu ELDP naproti tomu oficiálně a připnutě popisuje JMHZ**
 *    (`jmhz-1.4.3.6/formCommonTypes.xsd`, typy `eldpType`, `vylouceneDnyType`
 *    a `odecitaneDnyType`). Sestavený evidenční list se proti nim validuje.
 *
 * `formCommonTypes.xsd` nemá žádný globální element, takže se nedá validovat
 * přímo. Adaptér níže proto vygeneruje minimální schéma, které připnutý soubor
 * `xs:include`ne a doplní jediný globální element `eldpSeznam` typu `eldpType`.
 * Adaptér neobsahuje ŽÁDNOU vlastní definici typu — všechny přicházejí
 * bajt po bajtu z ověřeného oficiálního souboru.
 */
final class EldpSchemaCatalog
{
    public const PACKAGE_KEY = 'jmhz-1.4.3.6';
    public const DATA_VERSION = '1.4.3';
    public const NAMESPACE_URI = 'http://schemas.cssz.cz/JMHZ/form/1.0';
    public const ROOT_ELEMENT = 'eldpSeznam';

    private const BUNDLE = [
        'formCommonTypes.xsd' =>
            '072d2e3002be26c602ccb23326647c1489fd734d1fb976bc8a50165148b5d881',
        'baseTypes2.xsd' =>
            '839973458fa82559dfb56114ef2e555523db64d5af9b4f203ece6e941f946660',
    ];

    /**
     * Schéma, proti kterému se validuje sestavený evidenční list.
     *
     * @return array{
     *   package_key:string,data_version:string,namespace:string,
     *   root_element:string,bundle_sha256:string,schema_source:string
     * }
     */
    public function evidenceSchema(): array
    {
        $directory = dirname(__DIR__, 5) . '/xsd/jmhz/' . self::PACKAGE_KEY;
        $hashes = [];
        foreach (self::BUNDLE as $file => $expected) {
            $path = $directory . '/' . $file;
            $actual = is_file($path) ? hash_file('sha256', $path) : false;
            if ($actual === false || !hash_equals($expected, $actual)) {
                throw new EldpValidationException(
                    'eldp_schema_integrity_failed',
                    'Připnutý oficiální balíček JMHZ pro ELDP chybí nebo má jiný otisk.',
                );
            }
            $hashes[] = $expected . '  ' . self::PACKAGE_KEY . '/' . $file;
        }
        sort($hashes, SORT_STRING);
        $entryPoint = $directory . '/formCommonTypes.xsd';

        return [
            'package_key' => self::PACKAGE_KEY,
            'data_version' => self::DATA_VERSION,
            'namespace' => self::NAMESPACE_URI,
            'root_element' => self::ROOT_ELEMENT,
            'bundle_sha256' => hash('sha256', implode("\n", $hashes) . "\n"),
            'schema_source' => self::adapterSource($entryPoint),
        ];
    }

    /**
     * Schéma odesílaného samostatného ELDP podání — fail-closed.
     */
    public function submissionSchema(): never
    {
        throw new EldpValidationException(
            'eldp_submission_schema_unavailable',
            'Lokální oficiální sada neobsahuje XSD samostatného ELDP podání ČSSZ; '
                . 'evidenční list lze sestavit a zmrazit, odeslat ho ale nelze.',
        );
    }

    private static function adapterSource(string $entryPoint): string
    {
        $real = realpath($entryPoint);
        if ($real === false) {
            throw new EldpValidationException(
                'eldp_schema_integrity_failed',
                'Připnuté oficiální XSD JMHZ nelze otevřít.',
            );
        }
        $location = 'file:///' . str_replace('\\', '/', $real);
        $namespace = self::NAMESPACE_URI;
        $root = self::ROOT_ELEMENT;

        return <<<XSD
            <?xml version="1.0" encoding="utf-8"?>
            <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
              xmlns="{$namespace}"
              targetNamespace="{$namespace}"
              elementFormDefault="qualified">
              <xs:include schemaLocation="{$location}"/>
              <xs:element name="{$root}" type="eldpType"/>
            </xs:schema>
            XSD;
    }
}
