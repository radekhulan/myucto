<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Dimension;

use MyInvoice\Service\Accounting\Dimension\DimensionAccountMask;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleEngine;
use PHPUnit\Framework\TestCase;

final class DimensionProvenanceRuleTest extends TestCase
{
    public function testWinningRuleAndActualCardBranchHaveTruthfulOrigins(): void
    {
        $rules = [$this->rule(1, '5', 10), $this->rule(2, '518', 20)];
        $result = DimensionRuleEngine::apply($rules, [['account_code' => '518']], '2098-01-01', null);
        self::assertSame([7 => 20], $result['lines'][0]['dimensions']);
        self::assertSame([7 => ['origin' => 'rule_default', 'ref' => 'rule:2']], $result['lines'][0]['dimension_origins']);
        $rule = $this->rule(3, '5', 10) + ['default_from_card' => true];
        $rule['default_from_card'] = true;
        $card = DimensionRuleEngine::apply([$rule], [['account_code' => '518']], '2098-01-01', static fn (int $type): int => 30);
        self::assertSame(['origin' => 'card_default', 'ref' => 'rule:3'], $card['lines'][0]['dimension_origins'][7]);
        $fallback = DimensionRuleEngine::apply([$rule], [['account_code' => '518']], '2098-01-01', static fn (int $type): ?int => null);
        self::assertSame(['origin' => 'rule_default', 'ref' => 'rule:3'], $fallback['lines'][0]['dimension_origins'][7]);
    }

    public function testExistingUnknownIsNotRelabelledByAnEqualRuleDefault(): void
    {
        $line = ['account_code' => '518', 'dimensions' => [7 => 10], 'dimension_origins' => [7 => ['origin' => 'unknown', 'ref' => 'legacy-reference']]];
        $result = DimensionRuleEngine::apply([$this->rule(1, '5', 10)], [$line], '2098-01-01', null);
        self::assertSame([$line], $result['lines']);
    }

    private function rule(int $id, string $mask, int $valueId): array
    {
        return ['id' => $id, 'dimension_type_id' => 7, 'mask' => DimensionAccountMask::parse($mask), 'default_value_id' => $valueId, 'default_from_card' => false, 'valid_from' => null, 'valid_to' => null, 'is_active' => true, 'enforcement' => 'error'];
    }
}
