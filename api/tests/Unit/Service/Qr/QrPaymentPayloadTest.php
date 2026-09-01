<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Qr;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Qr\PaymentQrDueDate;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class QrPaymentPayloadTest extends TestCase
{
    private QrPaymentGenerator $generator;

    /** @var array{account_number:string,bank_code:string} */
    private array $czBank = ['account_number' => '1000000005', 'bank_code' => '0100'];

    protected function setUp(): void
    {
        $this->generator = new QrPaymentGenerator(new Config([]), new NullLogger());
    }

    public function testCzkPayloadIncludesActualDueDateWhenEnabled(): void
    {
        $payload = $this->generator->buildPayload(
            'CZK',
            1234.5,
            '2026-00001',
            $this->czBank,
            dueDate: new \DateTimeImmutable('2026-06-30'),
            includeDueDate: true,
        );

        self::assertNotNull($payload);
        self::assertStringContainsString('*DT:20260630', $payload);
    }

    public function testCzkPayloadOmitsDueDateWhenDisabled(): void
    {
        $payload = $this->generator->buildPayload(
            'CZK',
            1234.5,
            '202600001',
            $this->czBank,
            dueDate: new \DateTimeImmutable('2026-06-30'),
            includeDueDate: false,
        );

        self::assertNotNull($payload);
        self::assertStringNotContainsString('*DT:', $payload);
    }

    public function testEnabledCzkPayloadDoesNotSubstituteTodayForMissingDate(): void
    {
        $payload = $this->generator->buildPayload(
            'CZK',
            1234.5,
            '202600001',
            $this->czBank,
            dueDate: null,
            includeDueDate: true,
        );

        self::assertNotNull($payload);
        self::assertStringNotContainsString('*DT:', $payload);
    }

    public function testMissingEnabledDueDateIsLoggedAndOmitted(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'QR due date omitted because it is missing or invalid.',
            ['currency' => 'CZK', 'varsymbol' => '202600001'],
        );
        $generator = new QrPaymentGenerator(new Config([]), $logger);

        self::assertNull($generator->generate(
            'CZK',
            1234.5,
            '202600001',
            [],
            dueDate: null,
            includeDueDate: true,
        ));
    }

    public function testEpcPayloadIsUnaffectedByDueDateSetting(): void
    {
        $bank = ['iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX'];
        $dueDate = new \DateTimeImmutable('2026-06-30');

        $withDateEnabled = $this->generator->buildPayload(
            'EUR', 1234.5, '202600001', $bank, 'Synthetic Supplier', $dueDate, includeDueDate: true,
        );
        $withDateDisabled = $this->generator->buildPayload(
            'EUR', 1234.5, '202600001', $bank, 'Synthetic Supplier', $dueDate, includeDueDate: false,
        );

        self::assertSame($withDateDisabled, $withDateEnabled);
        self::assertStringNotContainsString('DT:', (string) $withDateEnabled);
    }

    #[DataProvider('invalidDueDates')]
    public function testStrictDueDateParserRejectsInvalidValues(mixed $value): void
    {
        self::assertNull(PaymentQrDueDate::parse($value));
    }

    public function testStrictDueDateParserAcceptsDatabaseDate(): void
    {
        self::assertSame('2026-06-30', PaymentQrDueDate::parse('2026-06-30')?->format('Y-m-d'));
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidDueDates(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'impossible date' => ['2026-02-30'];
        yield 'timestamp is not a DATE' => ['2026-06-30 00:00:00'];
        yield 'non-string' => [20260630];
    }
}
