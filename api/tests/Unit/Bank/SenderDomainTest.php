<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\Parser\SenderDomain;
use PHPUnit\Framework\TestCase;

final class SenderDomainTest extends TestCase
{
    public function testMatchesExactDomainAndSubdomain(): void
    {
        self::assertTrue(SenderDomain::matches('noreply@csob.cz', 'csob.cz'));
        self::assertTrue(SenderDomain::matches('ČSOB <noreply@csob.cz>', 'csob.cz'));
        self::assertTrue(SenderDomain::matches('noreply@mail.csob.cz', 'csob.cz'));
        self::assertTrue(SenderDomain::matches('UniCredit <noe@unicredit.eu>', 'unicreditgroup.cz', 'unicredit.eu'));
        self::assertTrue(SenderDomain::matches('Info@RB.CZ', 'rb.cz'));
    }

    public function testRejectsSpoofedAndForeignSenders(): void
    {
        // str_contains by tohle pustil — doména musí být na konci adresy
        self::assertFalse(SenderDomain::matches('attacker@csob.cz.evil.com', 'csob.cz'));
        self::assertFalse(SenderDomain::matches('Banka <attacker@notcsob.cz>', 'csob.cz'));
        self::assertFalse(SenderDomain::matches('noreply@csob.cz@evil.com', 'csob.cz'));
        self::assertFalse(SenderDomain::matches('"CSOB <noreply@csob.cz>" <attacker@evil.com>', 'csob.cz'));
        self::assertFalse(SenderDomain::matches('CSOB <noreply@csob.cz> trailing', 'csob.cz'));
        self::assertFalse(SenderDomain::matches('csob.cz', 'csob.cz')); // bez @
        self::assertFalse(SenderDomain::matches('', 'csob.cz'));
    }
}
