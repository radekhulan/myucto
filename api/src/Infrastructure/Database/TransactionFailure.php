<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;
use PDOException;

/**
 * Rozpozná MariaDB chyby, které samy vracejí zpět celou transakci.
 * Lock timeout 1205 sem nepatří, protože standardně vrací jen aktuální statement.
 */
final class TransactionFailure
{
    public static function aborted(\Throwable $failure): bool
    {
        if (!$failure instanceof PDOException) {
            return false;
        }

        return in_array((int) ($failure->errorInfo[1] ?? 0), [1020, 1213], true);
    }

    /**
     * Souběh dvou požadavků nad týmiž řádky: vypršení zámku (1205), deadlock (1213)
     * nebo odvolaný snapshot (1020). Pro uživatele to není chyba aplikace, ale
     * „probíhá jiný požadavek, zkuste znovu".
     */
    public static function lockConflict(\Throwable $failure): bool
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException
                && in_array((int) ($cause->errorInfo[1] ?? 0), [1020, 1205, 1213], true)
            ) {
                return true;
            }
        }

        return false;
    }

    public static function rollbackIfAborted(PDO $pdo, \Throwable $failure): bool
    {
        if (!self::aborted($failure)) {
            return false;
        }
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (\Throwable) {
            }
        }

        return true;
    }

    /**
     * Spustí operaci, která si sama otevírá transakci, a když ji MariaDB odvolá (1020/1213),
     * zkusí ji znovu s novou transakcí, tedy i s novým snapshotem.
     *
     * Typicky jde o hlavu hash řetězu auditní stopy: je to jeden řádek pro celou instanci
     * a pod REPEATABLE READ se `innodb_snapshot_isolation` končí jeho zamykací čtení chybou
     * 1020, kdykoli ho mezi začátkem transakce a zapečetěním posune souběžný požadavek.
     * Uvnitř cizí transakce se neopakuje nic: odvolaná je i ta vnější, takže chybu musí
     * dostat její vlastník.
     *
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public static function retrying(PDO $pdo, callable $operation, int $attempts = 4): mixed
    {
        $outer = $pdo->inTransaction();
        for ($attempt = 1; ; $attempt++) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                if ($outer || $attempt >= $attempts || !self::rollbackIfAborted($pdo, $e)) {
                    throw $e;
                }
                usleep(random_int(10_000, 50_000) * $attempt);
            }
        }
    }
}
