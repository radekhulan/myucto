<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Setup\AccountingSetupAiEnricher;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AccountingSetupAiEnricherTest extends TestCase
{
    public function testEnergyNatureIsAcceptedForUtilities(): void
    {
        $method = new ReflectionMethod(AccountingSetupAiEnricher::class, 'validateRecommendations');
        $result = $method->invoke(null, [
            'recommendations' => [[
                'sample_ids' => ['s01'],
                'nature' => 'energy',
                'keyword' => 'elektrina',
                'analytic_name' => 'Elektrická energie',
                'confidence' => 0.9,
            ]],
        ], [[
            'sample_id' => 's01',
            'text' => 'spotreba elektrina',
            'occurrences' => 3,
        ]]);

        self::assertSame('energy', $result[0]['nature']);
        self::assertSame(0.36, $result[0]['confidence']);
    }

    public function testValidationKeepsOnlyKnownSamplesWithLiteralKeyword(): void
    {
        $method = new ReflectionMethod(AccountingSetupAiEnricher::class, 'validateRecommendations');
        $result = $method->invoke(null, [
            'recommendations' => [
                [
                    'sample_ids' => ['s01'],
                    'nature' => 'service',
                    'keyword' => 'úložiště',
                    'analytic_name' => 'Cloudové služby',
                    'confidence' => 0.9,
                ],
                [
                    'sample_ids' => ['s02'],
                    'nature' => 'material',
                    'keyword' => 'neexistuje',
                    'analytic_name' => 'Materiál',
                    'confidence' => 0.9,
                ],
                [
                    'sample_ids' => ['s99'],
                    'nature' => 'fuel',
                    'keyword' => 'nafta',
                    'analytic_name' => 'Pohonné hmoty',
                    'confidence' => 0.9,
                ],
            ],
        ], [
            ['sample_id' => 's01', 'text' => 'Cloudové úložiště a licence', 'occurrences' => 3],
            ['sample_id' => 's02', 'text' => 'Kancelářský spotřební materiál', 'occurrences' => 2],
        ]);

        self::assertSame([[
            'sample_ids' => ['s01'],
            'nature' => 'service',
            'keyword' => 'úložiště',
            'analytic_name' => 'Cloudové služby',
            'confidence' => 0.36,
        ]], $result);
    }
}
