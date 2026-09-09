<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceCompatibility;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzOrdinaryEvidenceCompatibilityTest extends TestCase
{
    private const PREVIOUS = [
        'package_key' => 'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1',
        'spec_manifest_sha256' => '429e3de56e37442f35fdf8a79aab4bdff49a99beb8b3ac06afa8306312c1d205',
        'scenario_catalog_key' => 'jmhz-scenario-requirements-1.4.0.2-source-v1',
        'scenario_manifest_sha256' => 'bb43e8621c713729d534c026379c87e761711c53c42ce7e97377b68b0868b4e0',
        'control_catalog_key' => 'jmhz-controls-1.4.2.8-source-v4',
        'control_manifest_sha256' => '83ec6a985cf1c6d6e2429657d4ba6d12b09bfb849a32b504fff882383fb03800',
    ];

    public function testCurrentSpecificationRemainsAccepted(): void
    {
        self::assertTrue(JmhzOrdinaryEvidenceCompatibility::acceptsSpecification(self::current()));
    }

    public function testAuditedPreviousSpecificationRemainsApplicableWithoutMutation(): void
    {
        $spec = self::PREVIOUS + ['attribute_requirement_row_sha256' => ['10546' => str_repeat('a', 64)]];
        $before = $spec;
        self::assertTrue(JmhzOrdinaryEvidenceCompatibility::acceptsSpecification($spec));
        self::assertSame($before, $spec);
    }

    public function testEveryIdentifierMustMatchAnEntireKnownSpecification(): void
    {
        foreach ([self::PREVIOUS, self::current()] as $valid) {
            foreach (array_keys($valid) as $field) {
                $modified = $valid;
                $modified[$field] .= '-changed';
                self::assertFalse(JmhzOrdinaryEvidenceCompatibility::acceptsSpecification($modified), $field);
                unset($modified[$field]);
                self::assertFalse(JmhzOrdinaryEvidenceCompatibility::acceptsSpecification($modified), $field);
                $modified[$field] = null;
                self::assertFalse(JmhzOrdinaryEvidenceCompatibility::acceptsSpecification($modified), $field);
            }
        }
    }

    public function testMixedManifestGenerationsAreRejected(): void
    {
        foreach (self::current() as $field => $value) {
            if ($value === self::PREVIOUS[$field]) {
                continue;
            }
            $mixed = self::PREVIOUS;
            $mixed[$field] = $value;
            self::assertFalse(JmhzOrdinaryEvidenceCompatibility::acceptsSpecification($mixed), $field);
        }
    }

    private static function current(): array
    {
        return [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
            'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
            'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
        ];
    }
}
