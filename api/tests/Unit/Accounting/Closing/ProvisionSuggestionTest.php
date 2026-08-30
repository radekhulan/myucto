<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\Closing\ClosingService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit testy návrhu zákonné opravné položky (EP-16) — čistá statická metoda bez DB.
 *
 * Ověřuje dvě opravy proti auditu:
 *   (1) hranice měsíců jsou striktní „více než" (>), ne >= — hraniční měsíc (12/18/30)
 *       ještě NESPADNE do daného pásma;
 *   (2) limit §8c 30 000 Kč se posuzuje za AGREGÁT pohledávek za týmž dlužníkem
 *       (`$debtorTotal`), ne per doklad — drobná pohledávka u dlužníka, jehož součet
 *       přesahuje 30 000, už §8c nedostane.
 */
final class ProvisionSuggestionTest extends TestCase
{
    /** @return array{0: ?string, 1: float} */
    private function suggest(float $remaining, float $debtorTotal, int $months): array
    {
        $m = new ReflectionMethod(ClosingService::class, 'suggestLegalProvision');
        /** @var array{0: ?string, 1: float} $out */
        $out = $m->invoke(null, $remaining, $debtorTotal, $months, 18, 30, 12, 30000.0);
        return $out;
    }

    public function testMonthThresholdsAreStrictlyGreaterThan(): void
    {
        // §8a 50 %: „více než 18 měsíců" → přesně 18 ještě NE, 19 už ano.
        self::assertSame([null, 0.0], $this->suggest(50000.0, 50000.0, 18));
        self::assertSame(['8a', 0.5], $this->suggest(50000.0, 50000.0, 19));

        // §8a 100 %: „více než 30 měsíců" → přesně 30 je stále jen 50 %, 31 už 100 %.
        self::assertSame(['8a', 0.5], $this->suggest(50000.0, 50000.0, 30));
        self::assertSame(['8a', 1.0], $this->suggest(50000.0, 50000.0, 31));
    }

    public function testSection8cRequiresMoreThanTwelveMonths(): void
    {
        // Drobná pohledávka do 30 000: přesně 12 měsíců ještě NE, 13 už §8c 100 %.
        self::assertSame([null, 0.0], $this->suggest(20000.0, 20000.0, 12));
        self::assertSame(['8c', 1.0], $this->suggest(20000.0, 20000.0, 13));
    }

    public function testSection8cLimitIsAggregatedPerDebtor(): void
    {
        // Jedna drobná pohledávka 20 000, ale dlužník dluží celkem 40 000 → §8c NEplatí
        // (agregát nad limit), a 13 měsíců na §8a pásmo nestačí → žádný návrh.
        self::assertSame([null, 0.0], $this->suggest(20000.0, 40000.0, 13));

        // Týž dlužník samostatně pod limitem → §8c 100 % z hodnoty pohledávky.
        self::assertSame(['8c', 1.0], $this->suggest(20000.0, 20000.0, 13));
    }

    public function testSection8cLimitBoundaryIsInclusive(): void
    {
        // „nepřevyšuje 30 000 Kč" → přesně 30 000 se do §8c ještě vejde, 30 000,01 už ne.
        self::assertSame(['8c', 1.0], $this->suggest(30000.0, 30000.0, 13));
        self::assertSame([null, 0.0], $this->suggest(30000.01, 30000.01, 13));
    }
}
