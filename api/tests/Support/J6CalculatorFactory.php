<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\J6AuthoritativeCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxCalculator;

/**
 * Výpočet J6 používá pracovní dočasné tabulky MariaDB, proto i čistě výpočtové
 * testy potřebují spojení na testovací databázi.
 */
final class J6CalculatorFactory
{
    private static ?Connection $db = null;

    public static function connection(): Connection
    {
        return self::$db ??= Bootstrap::buildContainer()->get(Connection::class);
    }

    public static function authoritative(): J6AuthoritativeCalculator
    {
        return new J6AuthoritativeCalculator(self::connection());
    }

    public static function legacy(): NonprofitTaxCalculator
    {
        return new NonprofitTaxCalculator(self::authoritative());
    }
}
