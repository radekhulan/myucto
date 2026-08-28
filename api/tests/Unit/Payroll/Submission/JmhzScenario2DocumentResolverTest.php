<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario2DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use PHPUnit\Framework\TestCase;

final class JmhzScenario2DocumentResolverTest extends TestCase
{
    public function testFrozenFosterCarerProducesDeterministicCandidateAndExactEvidenceGap(): void
    {
        $resolver = new JmhzScenario2DocumentResolver();

        $first = $resolver->resolve($this->preparation());
        $second = $resolver->resolve($this->preparation());

        self::assertSame('blocked', $first->status());
        self::assertNotNull($first->candidate);
        self::assertSame($first->candidate->canonicalJson(), $second->candidate?->canonicalJson());
        self::assertSame($first->candidate->sha256(), $second->candidate?->sha256());
        self::assertSame('scenario_2', $first->candidate->payload['scope']['scenario_key']);
        self::assertSame('formPestoun.xsd', $first->candidate->payload['specification']['xsd_entrypoint']);
        self::assertSame(11, $first->candidate->payload['forms'][0]['employee_id']);
        self::assertSame(101, $first->candidate->payload['forms'][0]['employment_id']);
        self::assertSame($this->scenarioEvidence(), $first->candidate->payload['forms'][0]['scenario_evidence']);
        self::assertSame([
            'builder_version' => JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            'source_manifest_sha256' => str_repeat('1', 64),
            'readiness_sha256' => str_repeat('2', 64),
            'snapshot_fingerprint' => str_repeat('3', 64),
        ], $first->candidate->payload['preparation_provenance']);

        $blockers = array_map(static fn ($blocker): array => $blocker->toArray(), $first->blockers);
        self::assertSame(['jmhz_scenario2_evidence_gap'], array_column($blockers, 'code'));
        self::assertSame(101, $blockers[0]['entity_id']);
        self::assertSame($this->requiredScenarioTwoAttributeIds(), $blockers[0]['attribute_ids']);
    }

    public function testScenarioTwoResolverRejectsScopeWithoutFrozenFosterCarer(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        $payload['scope']['scenario_set'] = ['scenario_1'];

        $resolution = (new JmhzScenario2DocumentResolver())->resolve(
            $this->withPayload($preparation, $payload),
        );

        self::assertSame('blocked', $resolution->status());
        self::assertNull($resolution->candidate);
        self::assertSame(
            ['jmhz_scenario2_scope_unsupported'],
            array_map(static fn ($blocker): string => $blocker->code, $resolution->blockers),
        );
    }

    public function testScenarioTwoResolverRejectsAlteredFrozenCatalogEvidence(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        $payload['people'][0]['employments'][0]['scenario_resolution']['matrix_sha256']
            = str_repeat('f', 64);

        $resolution = (new JmhzScenario2DocumentResolver())->resolve(
            $this->withPayload($preparation, $payload),
        );

        self::assertSame('blocked', $resolution->status());
        self::assertNull($resolution->candidate);
        self::assertSame(
            ['jmhz_scenario2_frozen_resolution_invalid', 'jmhz_scenario2_frozen_resolution_missing'],
            array_map(static fn ($blocker): string => $blocker->code, $resolution->blockers),
        );
    }

    /** @return list<string> */
    private function requiredScenarioTwoAttributeIds(): array
    {
        $catalog = JmhzScenarioRequirementSourceCatalog::load();
        $attributeIds = [];
        foreach ($catalog->requirementsForMatrix('scenario_2') as $requirement) {
            if ($requirement->requirement->value === 'required') {
                $attributeIds[] = $requirement->attributeId;
            }
        }
        sort($attributeIds, SORT_STRING);

        return $attributeIds;
    }

    /** @return array<string,string|null> */
    private function scenarioEvidence(): array
    {
        return [
            'scenario_key' => 'scenario_2',
            'activity_code' => 'M',
            'relationship_detail_code' => null,
            'manual_scenario_key' => null,
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
            'scenario_row_sha256' => '57962d57a52553687e1c361772512ffb95dfa4f0a676827fc439c144c4821367',
            'matrix_sha256' => '13b4a54fe6f9ca598c28c52070a8d3a420b552b5c54581734dc0c14a29f36a78',
            'matrix_row_sha256' => '6e4884d3a20b3c5c0a38b36787ffec338649354706e312ebc30eee5f54542629',
            'xsd_entrypoint' => 'formPestoun.xsd',
            'selection_kind' => 'activity_raw',
            'matrix_source_sheet' => 'M',
        ];
    }

    private function preparation(): JmhzVerifiedPreparationSnapshot
    {
        $payload = [
            'schema_reference' => JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE,
            'builder_version' => JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            'scope' => [
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'scenario_set' => ['scenario_1', 'scenario_2'],
            ],
            'specification' => [
                'package_key' => 'jmhz-1.4.3.4',
                'spec_manifest_sha256' => str_repeat('a', 64),
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
            ],
            'source_revision' => [
                'input_snapshot_hash' => str_repeat('b', 64),
                'result_snapshot_hash' => str_repeat('c', 64),
                'ruleset_manifest_hash' => str_repeat('d', 64),
            ],
            'people' => [[
                'employee_id' => 11,
                'employments' => [[
                    'employment_id' => 101,
                    'scenario_resolution' => [
                        'scenario_key' => 'scenario_2',
                        'activity_code' => 'M',
                        'relationship_detail_code' => null,
                        'manual_scenario_key' => null,
                        'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                        'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                        'scenario_row_sha256' => '57962d57a52553687e1c361772512ffb95dfa4f0a676827fc439c144c4821367',
                        'matrix_sha256' => '13b4a54fe6f9ca598c28c52070a8d3a420b552b5c54581734dc0c14a29f36a78',
                        'matrix_row_sha256' => '6e4884d3a20b3c5c0a38b36787ffec338649354706e312ebc30eee5f54542629',
                        'xsd_entrypoint' => 'formPestoun.xsd',
                        'selection_kind' => 'activity_raw',
                        'matrix_source_sheet' => 'M',
                    ],
                ]],
            ]],
        ];

        return new JmhzVerifiedPreparationSnapshot(
            501,
            7,
            'test',
            401,
            301,
            1,
            '2026-07-01',
            '2026-07-31',
            'scenario_2',
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
            [],
            ['status' => 'blocked'],
            $payload,
        );
    }

    /** @param array<string,mixed> $payload */
    private function withPayload(
        JmhzVerifiedPreparationSnapshot $preparation,
        array $payload,
    ): JmhzVerifiedPreparationSnapshot {
        return new JmhzVerifiedPreparationSnapshot(
            $preparation->id,
            $preparation->supplierId,
            $preparation->environment,
            $preparation->runId,
            $preparation->sourceRevisionId,
            $preparation->revisionNo,
            $preparation->periodStart,
            $preparation->periodEnd,
            $preparation->scenarioKey,
            $preparation->builderVersion,
            $preparation->sourceManifestSha256,
            $preparation->readinessSha256,
            $preparation->snapshotFingerprint,
            $preparation->manifest,
            $preparation->readiness,
            $payload,
        );
    }
}
