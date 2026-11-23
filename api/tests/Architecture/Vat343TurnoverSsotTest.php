<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Úhradu daně finančnímu úřadu a vratku odpočtu na účtu 343 rozpoznává jediné pravidlo
 * {@see \MyInvoice\Service\Report\Vat343Turnover}. Kontrola obratu 343 proti přiznání
 * i kontrola 343 po dimenzích ho musí volat, ne mít vlastní kopii (dřív žila v
 * VatCrossCheckService jako vlastní SQL a druhá kopie se od ní rozešla).
 */
final class Vat343TurnoverSsotTest extends TestCase
{
    public function testBothChecksUseTheSharedSettlementRule(): void
    {
        $src = dirname(__DIR__, 2) . '/src/Service/Report/';
        foreach (['VatCrossCheckService.php', 'VatDimensionReportService.php'] as $file) {
            $code = (string) file_get_contents($src . $file);
            self::assertStringContainsString('Vat343Turnover::settlementLineSql(', $code, $file);
            self::assertStringContainsString('Vat343Turnover::bookTransferSql(', $code, $file);
            self::assertStringNotContainsString("LIKE '261%'", $code, $file . ' nesmí mít vlastní výčet peněžních účtů.');
        }
    }
}
