<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eet\Transport;

use MyInvoice\Service\Eet\Transport\EetEnvironment;
use MyInvoice\Service\Eet\Transport\EetMessageHeader;
use MyInvoice\Service\Eet\Transport\EetOutcome;
use MyInvoice\Service\Eet\Transport\EetSaleData;
use MyInvoice\Service\Eet\Transport\EetTimeouts;
use MyInvoice\Service\Eet\Transport\FakeEetTransport;
use PHPUnit\Framework\TestCase;

final class FakeEetTransportTest extends TestCase
{
    public function testDefaultsAndQueueAreDeterministic(): void
    {
        $fake = new FakeEetTransport();
        $data = new EetSaleData('CZ00000019', 11, 'P1', 'A1', new \DateTimeImmutable(), 100);
        $credential = EetTestPki::credential();

        $confirmed = $fake->send(EetEnvironment::Playground, $data, EetMessageHeader::create(true), $credential, new EetTimeouts());
        self::assertSame(EetOutcome::Confirmed, $confirmed->outcome);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}-ff$/', (string) $confirmed->pok);

        $verification = $fake->send(EetEnvironment::Playground, $data, EetMessageHeader::create(true, true), $credential, new EetTimeouts());
        self::assertSame(EetOutcome::VerificationOk, $verification->outcome);
        self::assertSame(0, $verification->errorCode);

        $fake->queueOutcome(EetOutcome::Timeout)->queueOutcome(EetOutcome::Rejected, 4);
        self::assertSame(EetOutcome::Timeout, $fake->send(EetEnvironment::Playground, $data, EetMessageHeader::create(true), $credential, new EetTimeouts())->outcome);
        $rejected = $fake->send(EetEnvironment::Playground, $data, EetMessageHeader::create(false), $credential, new EetTimeouts());
        self::assertSame(4, $rejected->errorCode);

        self::assertSame(4, $fake->callCount());
        self::assertFalse($fake->lastCall()['header']->firstAttempt);
        self::assertSame(0, $fake->pendingResults());
    }
}
