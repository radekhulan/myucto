<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class StatementTransactionScope
{
    public static function sql(int|string $statementId, string $alias = 'bt'): string
    {
        self::validate($statementId, $alias);
        return "($alias.statement_id = $statementId OR EXISTS (
            SELECT 1 FROM bank_transaction_imports bti
            " . self::ownershipJoins($alias) . "
            WHERE bti.statement_id = $statementId AND bti.bank_transaction_id = $alias.id
              AND evidence.supplier_id = original.supplier_id
        ))";
    }

    public static function countSql(int|string $statementId, string $alias = 'bt', string $condition = '1=1'): string
    {
        self::validate($statementId, $alias);
        return "((SELECT COUNT(*) FROM bank_transactions $alias
                  WHERE $alias.statement_id = $statementId AND ($condition))
                + (SELECT COUNT(*) FROM bank_transaction_imports bti
                   JOIN bank_transactions $alias ON $alias.id = bti.bank_transaction_id
                   " . self::ownershipJoins($alias) . "
                   WHERE bti.statement_id = $statementId
                     AND $alias.statement_id <> $statementId
                     AND evidence.supplier_id = original.supplier_id
                     AND ($condition)))";
    }

    private static function ownershipJoins(string $alias): string
    {
        return "JOIN bank_statements evidence ON evidence.id = bti.statement_id
                JOIN bank_statements original ON original.id = $alias.statement_id";
    }

    private static function validate(int|string $statementId, string $alias): void
    {
        if ((is_int($statementId) ? $statementId <= 0 : preg_match('/^[a-z][a-z0-9_]*\.id$/D', $statementId) !== 1)
            || preg_match('/^[a-z][a-z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid statement scope.');
        }
    }
}
