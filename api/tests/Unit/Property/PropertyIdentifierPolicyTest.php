<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyIdentifierPolicy;
use PHPUnit\Framework\TestCase;

final class PropertyIdentifierPolicyTest extends TestCase
{
    public function testBankEquivalentVariableSymbolsConflictButOtherScopesDoNot(): void
    {
        $existing = $this->revision('000123');
        $candidate = $this->revision('123');
        $candidate['state']['identifiers'][0]['payment_scope'] = 'OTHER';
        PropertyIdentifierPolicy::assertUnique(2, $candidate, [1 => [$existing]]);
        $candidate['state']['identifiers'][0]['payment_scope'] = 'SUPPLIER';
        $this->expectException(PropertyException::class);
        PropertyIdentifierPolicy::assertUnique(2, $candidate, [1 => [$existing]]);
    }

    public function testHalfOpenIntervalsAndSupersededSymbolsDoNotCreateFalseConflicts(): void
    {
        $old = $this->revision('123');
        $old['effective_to'] = '2026-05-01T00:00:00+02:00';
        $next = $this->revision('123');
        $next['effective_from'] = $old['effective_to'];
        PropertyIdentifierPolicy::assertUnique(2, $next, [1 => [$old]]);
        $old['effective_to'] = null;
        $replacement = $this->revision('999');
        $replacement['id'] = $replacement['revision'] = 2;
        $replacement['effective_from'] = $next['effective_from'];
        PropertyIdentifierPolicy::assertUnique(2, $next, [1 => [$old, $replacement]]);
        self::assertTrue(true);
    }

    public function testSharedPayerAccountAndSpecificSymbolAreNotOwnershipOrUniqueDebtKeys(): void
    {
        foreach (['account', 'ss', 'sipo_link'] as $kind) {
            $a = $this->revision($kind === 'account' ? '1000000005/0100' : '123');
            $a['state']['identifiers'][0]['kind'] = $kind;
            PropertyIdentifierPolicy::assertUnique(2, $a, [1 => [$a]]);
        }
        self::assertTrue(true);
    }

    private function revision(string $value): array
    {
        return ['id' => 1, 'revision' => 1, 'status' => 'confirmed', 'effective_from' => '2026-01-01T00:00:00+01:00', 'effective_to' => null,
            'recorded_at' => '2026-01-01T09:00:00+01:00', 'state' => ['active' => true,
                'identifiers' => [['kind' => 'vs', 'value' => $value, 'payment_scope' => 'SUPPLIER']]]];
    }
}
