<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final class JmhzOrdinaryEvidenceCompatibility
{
    private const PREVIOUS_SPECIFICATION = [
        'package_key' => 'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1',
        'spec_manifest_sha256' => '429e3de56e37442f35fdf8a79aab4bdff49a99beb8b3ac06afa8306312c1d205',
        'scenario_catalog_key' => 'jmhz-scenario-requirements-1.4.0.2-source-v1',
        'scenario_manifest_sha256' => 'bb43e8621c713729d534c026379c87e761711c53c42ce7e97377b68b0868b4e0',
        'control_catalog_key' => 'jmhz-controls-1.4.2.8-source-v4',
        'control_manifest_sha256' => '83ec6a985cf1c6d6e2429657d4ba6d12b09bfb849a32b504fff882383fb03800',
    ];

    private const COMPATIBLE_TARGET = [
        'package_key' => 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.9_manifest-v1',
        'spec_manifest_sha256' => '3d8b45317198db8d21d1eda6aed304ad70bdf8448bc4a118d7092c7bd5a05fe3',
        'scenario_catalog_key' => 'jmhz-scenario-requirements-1.4.0.2-source-v1',
        'scenario_manifest_sha256' => '31d8b0f859ab0ac197e08d08b0b7d9c4814b8bba62a5ef3c287e7122978aa0e1',
        'control_catalog_key' => 'jmhz-controls-1.4.2.9-source-v4',
        'control_manifest_sha256' => '65ccaa12d3ac0485f5b901f91b8a7a4398486aadafc3b33fe6a79bd30a76c2e7',
    ];

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
        return self::matches($spec, $current)
            || (self::matches($current, self::COMPATIBLE_TARGET)
                && self::matches($spec, self::PREVIOUS_SPECIFICATION));
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
