<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1SelectorResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JmhzScenario1SelectorResolverTest extends TestCase
{
    /** @return iterable<string,array{string,?string,?string}> */
    public static function supportedSelectors(): iterable
    {
        foreach (range(1, 9) as $code) {
            yield "activity-{$code}-none" => [(string) $code, '1', '1'];
        }
        foreach ([
            '15', '16',
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J',
            'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC',
        ] as $code) {
            yield "direct-{$code}" => [$code, '1', null];
        }
    }

    #[DataProvider('supportedSelectors')]
    public function testResolvesOnlyPinnedScenarioOneBranches(
        string $activityCode,
        ?string $detailCode,
        ?string $expectedDetailCode,
    ): void {
        $resolution = JmhzScenario1SelectorResolver::load()->resolve(
            $activityCode,
            $detailCode,
        );

        self::assertTrue($resolution['supported']);
        self::assertNull($resolution['issue_code']);
        self::assertSame('scenario_1', $resolution['evidence']['scenario_key'] ?? null);
        self::assertSame($activityCode, $resolution['evidence']['activity_code'] ?? null);
        self::assertSame($expectedDetailCode, $resolution['evidence']['relationship_detail_code'] ?? null);
        self::assertSame(
            JmhzScenario1SelectorResolver::MATRIX_SHA256,
            $resolution['evidence']['matrix_sha256'] ?? null,
        );
    }

    /** @return iterable<string,array{?string,?string,string}> */
    public static function blockedSelectors(): iterable
    {
        yield 'missing activity' => [null, null, 'jmhz_scenario_activity_code_missing'];
        yield 'unknown activity' => ['XX', null, 'jmhz_scenario_activity_code_invalid'];
        yield 'missing detail' => ['1', null, 'jmhz_scenario_relationship_detail_missing'];
        yield 'other scenario prison' => ['1', '2', 'jmhz_scenario_not_supported'];
        yield 'other scenario group' => ['9', '3', 'jmhz_scenario_not_supported'];
        yield 'missing direct detail' => ['A', null, 'jmhz_scenario_relationship_detail_missing'];
        yield 'other direct scenario' => ['M', '1', 'jmhz_scenario_not_supported'];
    }

    #[DataProvider('blockedSelectors')]
    public function testBlocksMissingInvalidAndOtherScenarioSelectors(
        ?string $activityCode,
        ?string $detailCode,
        string $issueCode,
    ): void {
        $resolution = JmhzScenario1SelectorResolver::load()->resolve(
            $activityCode,
            $detailCode,
        );

        self::assertFalse($resolution['supported']);
        self::assertSame($issueCode, $resolution['issue_code']);
        self::assertNull($resolution['evidence']);
    }
}
