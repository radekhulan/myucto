<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit\Membership;

use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use PHPUnit\Framework\TestCase;

final class MembershipVariableSymbolTest extends TestCase
{
    public function testNumericMemberNumberIsPreservedIncludingLeadingZeros(): void
    {
        self::assertSame('0012345678', MembershipChargeService::defaultVariableSymbol(1, ['id' => 2, 'member_number' => '0012345678']));
    }

    public function testFallbackIsStableNumericAndDoesNotTruncateLongMemberNumber(): void
    {
        // Členské číslo ze samých nul by dalo VS bez klíče shody, který platbu nikdy nenajde.
        foreach (['ABC-123', '123456789012345', '', '0', '0000000000'] as $number) {
            $member = ['id' => 2, 'member_number' => $number];
            $symbol = MembershipChargeService::defaultVariableSymbol(1, $member);
            self::assertMatchesRegularExpression('/^[1-9][0-9]{9}$/D', $symbol);
            self::assertSame($symbol, MembershipChargeService::defaultVariableSymbol(1, $member));
            self::assertNotSame('1234567890', $symbol);
        }
    }
}
