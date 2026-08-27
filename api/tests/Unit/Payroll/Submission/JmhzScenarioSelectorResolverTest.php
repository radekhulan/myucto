<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioSelectorResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JmhzScenarioSelectorResolverTest extends TestCase
{
    /** @return iterable<string,array{string,?string,?string,string,string}> */
    public static function pinnedSelectors(): iterable
    {
        yield 'foster-carer' => ['M', '1', null, 'scenario_2', 'formPestoun.xsd'];
        yield 'specific-activity' => ['K', '1', null, 'scenario_3', 'formCinnostKS.xsd'];
        yield 'specific-relationship' => ['1', '3', null, 'scenario_3', 'formCinnostKS.xsd'];
        yield 'prison-service' => ['1', '2', null, 'scenario_4', 'formVezen.xsd'];
        yield 'other-income-11' => ['11', '1', null, 'scenario_5', 'formJinyPrijem.xsd'];
        yield 'other-income-13' => ['13', '1', null, 'scenario_5', 'formJinyPrijem.xsd'];
        yield 'other-income-14' => ['14', '1', null, 'scenario_5', 'formJinyPrijem.xsd'];
        yield 'international-hire' => ['12', '1', null, 'scenario_6', 'formMezinarodniPronajemSily.xsd'];
        yield 'disability-training' => ['10', null, null, 'scenario_7', 'formOzpTpp.xsd'];
        yield 'explicit-deferred-income' => ['A', null, 'scenario_8', 'scenario_8', 'formOdlozenyPrijem.xsd'];
    }

    #[DataProvider('pinnedSelectors')]
    public function testClassifiesPinnedScenariosAndCarriesImmutableSourceEvidence(
        string $activityCode,
        ?string $detailCode,
        ?string $manualScenarioKey,
        string $scenarioKey,
        string $entrypoint,
    ): void {
        $resolution = JmhzScenarioSelectorResolver::load()->resolve(
            $activityCode,
            $detailCode,
            $manualScenarioKey,
        );

        self::assertTrue($resolution['supported']);
        self::assertSame($scenarioKey, $resolution['evidence']['scenario_key'] ?? null);
        self::assertSame($entrypoint, $resolution['evidence']['xsd_entrypoint'] ?? null);
        self::assertFalse($resolution['preparation_supported']);
        self::assertSame(
            $scenarioKey === 'scenario_8'
                ? 'deferred_income_evidence_missing'
                : "jmhz_{$scenarioKey}_preparation_unsupported",
            $resolution['readiness_issue_code'],
        );
        self::assertNotEmpty($resolution['readiness_attribute_ids']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            (string) ($resolution['evidence']['scenario_row_sha256'] ?? null),
        );
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            (string) ($resolution['evidence']['matrix_sha256'] ?? null),
        );
    }

    public function testDoesNotInferManualDeferredIncomeScenario(): void
    {
        $resolution = JmhzScenarioSelectorResolver::load()->resolve('A', null);

        self::assertSame('scenario_1', $resolution['evidence']['scenario_key'] ?? null);
    }

    public function testEnablesOnlyPinnedStatutoryBodySelectorForScenarioThreePreparation(): void
    {
        $supported = JmhzScenarioSelectorResolver::load()->resolve('S', '1');
        $otherScenarioThree = JmhzScenarioSelectorResolver::load()->resolve('K', '1');

        self::assertSame('scenario_3', $supported['evidence']['scenario_key'] ?? null);
        self::assertSame('formCinnostKS.xsd', $supported['evidence']['xsd_entrypoint'] ?? null);
        self::assertTrue($supported['preparation_supported']);
        self::assertNull($supported['readiness_issue_code']);
        self::assertSame([], $supported['readiness_attribute_ids']);
        self::assertFalse($otherScenarioThree['preparation_supported']);
        self::assertSame(
            'jmhz_scenario_3_preparation_unsupported',
            $otherScenarioThree['readiness_issue_code'],
        );
    }

    public function testDeferredIncomeIsForbiddenForActivityKindTen(): void
    {
        $resolution = JmhzScenarioSelectorResolver::load()->resolve('10', null, 'scenario_8');

        self::assertFalse($resolution['supported']);
        self::assertSame('jmhz_scenario_8_activity_10_forbidden', $resolution['issue_code']);
    }

    public function testRejectsUnknownManualScenario(): void
    {
        $resolution = JmhzScenarioSelectorResolver::load()->resolve('A', '1', 'scenario_7');

        self::assertFalse($resolution['supported']);
        self::assertSame('jmhz_scenario_manual_selection_invalid', $resolution['issue_code']);
    }
}
