<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFieldRequirementKind;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioSelectorResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecialScenarioDocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use PHPUnit\Framework\TestCase;

final class JmhzSpecialScenarioDocumentResolverTest extends TestCase
{
    public function testFrozenSpecialScenariosProduceDeterministicCandidatesAndExactCatalogBlockers(): void
    {
        $resolver = new JmhzSpecialScenarioDocumentResolver();

        $first = $resolver->resolve($this->preparation());
        $second = $resolver->resolve($this->preparation());

        self::assertNotNull($first);
        self::assertSame('blocked', $first->status());
        self::assertNotNull($first->candidate);
        self::assertSame($first->candidate->canonicalJson(), $second?->candidate?->canonicalJson());
        self::assertSame($first->candidate->sha256(), $second?->candidate?->sha256());
        self::assertSame(
            ['scenario_3', 'scenario_4', 'scenario_5', 'scenario_6', 'scenario_7'],
            $first->candidate->payload['scope']['scenario_keys'],
        );
        self::assertSame(
            ['employee_id', 'employment_id', 'scenario_evidence'],
            array_keys($first->candidate->payload['forms'][0]),
        );
        self::assertSame('scenario_7', $first->candidate->payload['forms'][0]['scenario_evidence']['scenario_key']);

        $blockers = array_map(static fn ($blocker): array => $blocker->toArray(), $first->blockers);
        self::assertSame(
            array_fill(0, 5, 'jmhz_special_scenarios_evidence_gap'),
            array_column($blockers, 'code'),
        );
        foreach ($blockers as $blocker) {
            self::assertSame(
                $this->requiredAttributeIds(
                    $first->candidate->payload['forms'][$blocker['entity_id'] - 101]['scenario_evidence']['scenario_key'],
                ),
                $blocker['attribute_ids'],
            );
        }
    }

    public function testAlteredFrozenEvidenceDoesNotProduceCandidate(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        $payload['people'][0]['employments'][0]['scenario_resolution']['matrix_sha256'] = str_repeat('f', 64);

        $resolution = (new JmhzSpecialScenarioDocumentResolver())->resolve(
            $this->withPayload($preparation, $payload),
        );

        self::assertNotNull($resolution);
        self::assertNotNull($resolution->candidate);
        self::assertCount(4, $resolution->candidate->payload['forms']);
        self::assertSame(
            [
                'jmhz_special_scenarios_evidence_gap',
                'jmhz_special_scenarios_evidence_gap',
                'jmhz_special_scenarios_evidence_gap',
                'jmhz_special_scenarios_evidence_gap',
                'jmhz_special_scenarios_frozen_resolution_invalid',
            ],
            array_map(static fn ($blocker): string => $blocker->code, $resolution->blockers),
        );
    }

    public function testOrdinaryScopeIsNotASecondSpecialDocument(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        $payload['scope']['scenario_set'] = ['scenario_1'];

        self::assertNull((new JmhzSpecialScenarioDocumentResolver())->resolve(
            $this->withPayload($preparation, $payload),
        ));
    }

    /** @return list<string> */
    private function requiredAttributeIds(string $scenarioKey): array
    {
        $attributeIds = [];
        foreach (JmhzScenarioRequirementSourceCatalog::load()->requirementsForMatrix($scenarioKey) as $requirement) {
            if ($requirement->requirement === JmhzFieldRequirementKind::Required) {
                $attributeIds[] = $requirement->attributeId;
            }
        }
        sort($attributeIds, SORT_STRING);

        return $attributeIds;
    }

    private function preparation(): JmhzVerifiedPreparationSnapshot
    {
        $selector = JmhzScenarioSelectorResolver::load();
        $sources = [
            ['scenario_3', 'K', '1'],
            ['scenario_4', '1', '2'],
            ['scenario_5', '11', '1'],
            ['scenario_6', '12', '1'],
            ['scenario_7', '10', null],
        ];
        $employments = [];
        foreach ($sources as $index => [$scenarioKey, $activityCode, $relationshipDetailCode]) {
            $selection = $selector->resolve($activityCode, $relationshipDetailCode);
            self::assertTrue($selection['supported']);
            self::assertSame($scenarioKey, $selection['evidence']['scenario_key']);
            $employments[] = [
                'employment_id' => 105 - $index,
                'scenario_resolution' => $selection['evidence'],
            ];
        }

        return new JmhzVerifiedPreparationSnapshot(
            501,
            7,
            'test',
            401,
            301,
            1,
            '2026-07-01',
            '2026-07-31',
            'scenario_3',
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
            [],
            ['status' => 'blocked'],
            [
                'schema_reference' => JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE,
                'scope' => [
                    'period_start' => '2026-07-01',
                    'period_end' => '2026-07-31',
                    'scenario_set' => ['scenario_7', 'scenario_5', 'scenario_3', 'scenario_6', 'scenario_4'],
                ],
                'specification' => [
                    'package_key' => 'jmhz-1.4.3.4',
                    'spec_manifest_sha256' => str_repeat('a', 64),
                ],
                'people' => [[
                    'employee_id' => 11,
                    'employments' => $employments,
                ]],
            ],
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
