<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final class JmhzOrdinaryEvidenceCompatibility
{
    /**
     * Dřívější připnuté specifikace, jejichž potvrzení zůstává použitelné.
     * Katalog 1.4.2.9 změnil jen text hlášky kontroly 238 a 1.4.2.10 přidal
     * kontrolu 22 (duplicitní GUID podání, rozhoduje evidence ČSSZ) a upřesnil
     * kontrolu 290 nad pozdě podaným hlášením. Katalog 1.4.2.11 přidal kontroly
     * ELDP 368 a 369, informativní kontrolu cizinců 371 a opravil atributy
     * kontroly 333 na 10495 a 10016. Nic z toho se nedotýká atributů 10116
     * a 10546, na kterých potvrzení stojí.
     */
    private const PREVIOUS_SPECIFICATIONS = [
        [
            'package_key' => 'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1',
            'spec_manifest_sha256' => '429e3de56e37442f35fdf8a79aab4bdff49a99beb8b3ac06afa8306312c1d205',
            'scenario_catalog_key' => 'jmhz-scenario-requirements-1.4.0.2-source-v1',
            'scenario_manifest_sha256' => 'bb43e8621c713729d534c026379c87e761711c53c42ce7e97377b68b0868b4e0',
            'control_catalog_key' => 'jmhz-controls-1.4.2.8-source-v4',
            'control_manifest_sha256' => '83ec6a985cf1c6d6e2429657d4ba6d12b09bfb849a32b504fff882383fb03800',
        ],
        [
            'package_key' => 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.9_manifest-v1',
            'spec_manifest_sha256' => '3d8b45317198db8d21d1eda6aed304ad70bdf8448bc4a118d7092c7bd5a05fe3',
            'scenario_catalog_key' => 'jmhz-scenario-requirements-1.4.0.2-source-v1',
            'scenario_manifest_sha256' => '31d8b0f859ab0ac197e08d08b0b7d9c4814b8bba62a5ef3c287e7122978aa0e1',
            'control_catalog_key' => 'jmhz-controls-1.4.2.9-source-v4',
            'control_manifest_sha256' => '65ccaa12d3ac0485f5b901f91b8a7a4398486aadafc3b33fe6a79bd30a76c2e7',
        ],
        [
            'package_key' => 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.10_manifest-v1',
            'spec_manifest_sha256' => 'de478274906eac47d5d51c3a5837a8278ade1fa0dba3fc5dcde6c86d5d05113d',
            'scenario_catalog_key' => 'jmhz-scenario-requirements-1.4.0.2-source-v1',
            'scenario_manifest_sha256' => 'e73c6e96bafe3b2c36ebfc96f299d64e83df2b1deeb0910df42ca7299c452aa7',
            'control_catalog_key' => 'jmhz-controls-1.4.2.10-source-v4',
            'control_manifest_sha256' => '565bfb145efd1e7c0893e81c26b3d865c486929dc44cef72620340b663b4e493',
        ],
    ];

    private const COMPATIBLE_TARGET = [
        'package_key' => 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.11_manifest-v1',
        'spec_manifest_sha256' => '39bb516b4fff204c44f0b8a6b9e0ec40601ef73af79460bdf937777e046c00d8',
        'scenario_catalog_key' => 'jmhz-scenario-requirements-1.4.0.2-source-v1',
        'scenario_manifest_sha256' => 'fb631105f714f9d4ba757259d58a83e04096f07df5371511a8872afb3b75ed85',
        'control_catalog_key' => 'jmhz-controls-1.4.2.11-source-v4',
        'control_manifest_sha256' => '05e26c2722a72c989282ed72939faa3cedc02789239b0a798f4ecd33b4dca195',
    ];

    /** Atributy, na kterých potvrzení běžné evidence stojí. */
    public const ATTRIBUTE_IDS = ['10116', '10546'];

    /**
     * Řádky matice scénáře, které potvrzení běžné evidence pokrývá: jen ty
     * z {@see self::ATTRIBUTE_IDS}, které matice scénáře vůbec vede. Scénář 1
     * a vězeň (4) mají oba, statutár (3) jen 10546, jiný příjem a pronájem
     * síly (5, 6) žádný - formulář srážky ani slevu na pojistném nenese.
     *
     * @return array<string,string> ID atributu → otisk řádku matice, podle ID
     */
    public static function requirementRowHashes(
        JmhzScenarioRequirementSourceCatalog $catalog,
        string $scenarioKey,
    ): array {
        $requirements = [];
        foreach ($catalog->requirementsForMatrix($scenarioKey) as $requirement) {
            if (in_array($requirement->attributeId, self::ATTRIBUTE_IDS, true)) {
                $requirements[$requirement->attributeId] = $requirement->rowHash;
            }
        }
        ksort($requirements, SORT_STRING);

        return $requirements;
    }

    /**
     * Ročník specifikace zmrazeného potvrzení podle klíče jeho balíku;
     * `null` u neznámého klíče.
     */
    public static function specVersionOf(array $spec): ?JmhzSpecVersion
    {
        $packageKey = $spec['package_key'] ?? null;

        return is_string($packageKey) ? JmhzSpecPackageCatalog::versionOf($packageKey) : null;
    }

    /**
     * Potvrzení musí patřit téže verzi jako hlášení, ke kterému se váže:
     * potvrzení postavené nad slovníkem 2026 se pro období 2027 nepoužije
     * a naopak. Pro verzi 2027 zatím neplatí žádné potvrzení: katalog
     * scénářů a kontrol 1.5 ještě není připnutý (WP-1, WP-4), takže by
     * nebylo s čím porovnat řádky matice.
     */
    public static function acceptsSpecificationFor(array $spec, JmhzSpecVersion $version): bool
    {
        if (self::specVersionOf($spec) !== $version) {
            return false;
        }

        return match ($version) {
            JmhzSpecVersion::V2026 => self::acceptsSpecification($spec),
            JmhzSpecVersion::V2027 => false,
        };
    }

    public static function acceptsSpecification(array $spec): bool
    {
        $current = [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
            'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
            'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
        ];
        if (self::matches($spec, $current)) {
            return true;
        }
        if (!self::matches($current, self::COMPATIBLE_TARGET)) {
            return false;
        }
        foreach (self::PREVIOUS_SPECIFICATIONS as $previous) {
            if (self::matches($spec, $previous)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(array $actual, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            if (($actual[$field] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }
}
