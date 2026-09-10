<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\DocumentLinker;
use PHPUnit\Framework\TestCase;

/**
 * Úhrada faktury z Money: číslo dokladu úhrady (`UDoklad`) se v Money opakuje každý rok,
 * takže samotný rok nestačí. Rozhoduje částka, pak datum úhrady, až nakonec rok.
 */
final class PaymentChoiceTest extends TestCase
{
    public function testSameNumberInTwoYearsIsDecidedByAmountAndDateNotByYear(): void
    {
        $candidates = [
            ['id' => 11, 'year' => 2024, 'amount' => -500.0, 'date' => '2024-06-01'],
            ['id' => 12, 'year' => 2025, 'amount' => -1210.0, 'date' => '2025-01-10'],
        ];

        // Faktura z prosince 2024 uhrazená v lednu 2025 pohybem se stejným číslem jako
        // nesouvisející pohyb z června 2024.
        self::assertSame(12, DocumentLinker::choosePayment($candidates, 2024, 1210.0, '2025-01-10'));
    }

    public function testSameYearWinsWhenAmountsDoNotDecide(): void
    {
        $candidates = [
            ['id' => 21, 'year' => 2024, 'amount' => 300.0, 'date' => '2024-03-01'],
            ['id' => 22, 'year' => 2025, 'amount' => 300.0, 'date' => '2025-03-01'],
        ];

        self::assertSame(21, DocumentLinker::choosePayment($candidates, 2024, 300.0, null));
    }

    public function testPartialPaymentIsStillLinkedWhenItIsTheOnlyCandidate(): void
    {
        $candidates = [['id' => 31, 'year' => 2024, 'amount' => 100.0, 'date' => '2024-05-05']];

        self::assertSame(31, DocumentLinker::choosePayment($candidates, 2024, 250.0, '2024-05-05'));
    }

    public function testTwoEquallyGoodCandidatesAreAmbiguous(): void
    {
        $candidates = [
            ['id' => 41, 'year' => 2024, 'amount' => 800.0, 'date' => '2024-02-02'],
            ['id' => 42, 'year' => 2024, 'amount' => 800.0, 'date' => '2024-02-02'],
        ];

        self::assertNull(DocumentLinker::choosePayment($candidates, 2024, 800.0, '2024-02-02'));
    }

    public function testCandidateFromDistantYearIsIgnoredWhenOthersExist(): void
    {
        $candidates = [
            ['id' => 51, 'year' => 2021, 'amount' => 900.0, 'date' => '2021-01-01'],
            ['id' => 52, 'year' => 2024, 'amount' => 100.0, 'date' => '2024-01-01'],
        ];

        self::assertSame(52, DocumentLinker::choosePayment($candidates, 2024, 900.0, null));
    }
}
