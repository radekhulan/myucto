<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\PairingSymbolMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Čísla převedených dokladů v popisu řádku deníku Money: z nich převod přiřadí vyrovnání,
 * které podle párovacího symbolu přiřadit nejde.
 */
final class PairingSymbolDescriptionTest extends TestCase
{
    /** @var array<string,list<array{0:string,1:int,2:int}>> */
    private const NUMBERS = [
        '20250031' => [['invoice', 31, 2025]],
        '20250032' => [['invoice', 32, 2025]],
        'FP250040' => [['purchase_invoice', 40, 2025]],
        'PH250005' => [['invoice', 50, 2025]],
        '20240099A' => [['invoice', 99, 2024]],
        // Číslo, které Money v roce 2025 i 2026 použilo znovu.
        'FP250077' => [['purchase_invoice', 77, 2025], ['purchase_invoice', 177, 2026]],
        // Stejné číslo téhož roku u vydané i přijaté faktury.
        '20250050' => [['invoice', 150, 2025], ['purchase_invoice', 250, 2025]],
    ];

    /** @return iterable<string,array{string,int,list<string>}> */
    public static function descriptions(): iterable
    {
        yield 'zápočet dvou dokladů' => ['zápočet FP250040 x PH250005', 2025, ['purchase_invoice:40', 'invoice:50']];
        yield 'předpona FV a výčet čísel' => ['zápočet FV20250031,20250032 X FP250040', 2025, ['invoice:31', 'invoice:32', 'purchase_invoice:40']];
        yield 'dobropis s tečkou' => ['zápočet FV20250032 x dbp.20250031', 2025, ['invoice:32', 'invoice:31']];
        yield 'standardní text úhrady' => ['20250031 - Úhrada faktury vydané', 2026, ['invoice:31']];
        yield 'kurzový rozdíl s písmenem' => ['Kurz. ztráta při úhradě vyst. fakt. 20240099A', 2024, ['invoice:99']];
        yield 'malá písmena' => ['odpis dle OP, fv20250031-úhr.FV', 2025, ['invoice:31']];
        yield 'nejbližší předchozí rok' => ['úhrada FP250077', 2026, ['purchase_invoice:177']];
        yield 'doklad z pozdějšího roku ne' => ['úhrada FP250077', 2025, ['purchase_invoice:77']];
        yield 'nerozlišitelné číslo' => ['úhrada 20250050', 2025, []];
        yield 'zkrácený odkaz' => ['PFZ200029, -030 - Úhrada faktury přijaté', 2025, []];
        yield 'bez čísla dokladu' => ['Úhrada faktury přijaté', 2025, []];
        yield 'opakované číslo jednou' => ['20250031 a znovu 20250031', 2025, ['invoice:31']];
    }

    /** @param list<string> $expected */
    #[DataProvider('descriptions')]
    public function testDocumentsReferencedInDescription(string $popis, int $year, array $expected): void
    {
        self::assertSame($expected, PairingSymbolMatcher::popisRefs($popis, $year, self::NUMBERS));
    }
}
