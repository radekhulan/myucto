<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Eet;

use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetDispatchService;
use MyInvoice\Service\Eet\EetValues;
use MyInvoice\Service\Eet\PaymentEventService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EetValuesTest extends TestCase
{
    /** @return iterable<array{0:mixed,1:string}> */
    public static function amounts(): iterable
    {
        yield ['1210', '1210.00'];
        yield ['1210.5', '1210.50'];
        yield ['1 210,05', '1210.05'];
        yield [1210, '1210.00'];
        yield [12.1, '12.10'];
        yield ['-210.00', '-210.00'];
        yield ['0.01', '0.01'];
    }

    #[DataProvider('amounts')]
    public function testAmountNormalization(mixed $input, string $expected): void
    {
        self::assertSame($expected, EetValues::amount($input));
        self::assertSame($expected, EetValues::fromCents(EetValues::toCents($expected)));
    }

    public function testAmountRejectsThreeDecimals(): void
    {
        $this->expectException(EetCommandException::class);
        EetValues::amount('1.005');
    }

    public function testClientTimeTolerance(): void
    {
        $now = new \DateTimeImmutable('2026-11-02 10:00:00', EetValues::zone());
        self::assertSame('2026-11-02T09:00:00+01:00', EetValues::clientTime('2026-11-02T08:00:00Z', $now, 't')->format(DATE_ATOM));
        self::assertSame($now->format(DATE_ATOM), EetValues::clientTime(null, $now, 't')->format(DATE_ATOM));
        foreach (['2026-10-31T09:00:00+01:00', '2026-11-02T10:06:00+01:00', '2026-11-02 10:00'] as $bad) {
            try {
                EetValues::clientTime($bad, $now, 't');
                self::fail('Čas mimo toleranci prošel: ' . $bad);
            } catch (EetCommandException $e) {
                self::assertSame('eet_validation', $e->errorCode);
            }
        }
    }

    public function testUuidIsVersion4(): void
    {
        $uuid = EetValues::uuid4();
        self::assertTrue(EetValues::isUuid($uuid));
        self::assertSame('4', $uuid[14]);
        self::assertNotSame($uuid, EetValues::uuid4());
    }

    /** Stejné tělo v jiném pořadí klíčů = stejný otisk; jiná hodnota nebo operace = jiný. */
    public function testRequestHashIsCanonical(): void
    {
        $a = PaymentEventService::requestHash('payments.accept', ['amount' => '10.00', 'channel' => 'cash', 'note' => null]);
        $b = PaymentEventService::requestHash('payments.accept', ['channel' => 'cash', 'amount' => '10.00']);
        self::assertSame($a, $b);
        self::assertNotSame($a, PaymentEventService::requestHash('payments.accept', ['channel' => 'cash', 'amount' => '10.01']));
        self::assertNotSame($a, PaymentEventService::requestHash('records.corrections:1', ['channel' => 'cash', 'amount' => '10.00']));
    }

    public function testIdempotencyKeyValidation(): void
    {
        self::assertSame('abc-12345', PaymentEventService::validateKey(' abc-12345 '));
        foreach (['', 'short', str_repeat('a', 81), 'mezera uvnitř klíče'] as $bad) {
            try {
                PaymentEventService::validateKey($bad);
                self::fail('Neplatný klíč prošel: ' . $bad);
            } catch (EetCommandException $e) {
                self::assertSame(400, $e->httpStatus);
            }
        }
    }

    public function testBackoffScheduleWithJitterAndCap(): void
    {
        foreach ([1 => 30, 2 => 60, 3 => 120, 4 => 300, 5 => 600, 6 => 900, 9 => 900] as $attempt => $base) {
            $value = EetDispatchService::backoffSeconds($attempt);
            self::assertGreaterThanOrEqual($base, $value);
            self::assertLessThanOrEqual($base + intdiv($base, 10), $value);
        }
    }
}
