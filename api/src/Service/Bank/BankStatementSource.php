<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class BankStatementSource
{
    private const STATEMENTS = ['gpc', 'pdf', 'bank_api'];

    public static function isStatement(string $source): bool
    {
        return in_array($source, self::STATEMENTS, true);
    }

    public static function sqlList(): string
    {
        return "('" . implode("','", self::STATEMENTS) . "')";
    }
}
