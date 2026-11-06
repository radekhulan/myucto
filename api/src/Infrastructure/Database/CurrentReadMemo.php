<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

/**
 * Paměť výsledku aktuálního čtení (`FOR UPDATE`) v probíhající transakci.
 *
 * Důkazy agendy SVJ ověřují tytéž zamčené záznamy u každého dílčího kroku operace.
 * Zamčené řádky drží transakce až do svého konce a zápisy SVJ jdou přes zámek období,
 * jiná transakce je proto mezitím nezmění. Výsledek se tak smí znovu použít, dokud
 * vlastní transakce nic nezapsala a neskončila: pozná to {@see LoggingPdo::writeCount()},
 * který zvedá každý zápis, hranice transakce, návrat k savepointu i nepovedený příkaz.
 * Mimo transakci nebo bez sledování příkazů se čte vždy znovu. Výjimka čtení se
 * nepamatuje.
 */
final class CurrentReadMemo
{
    /** @var \WeakMap<\PDO,array{writes:int,values:array<string,mixed>}>|null */
    private static ?\WeakMap $memo = null;

    /**
     * @template T
     * @param callable():T $read
     * @return T
     */
    public static function remember(Connection $db, string $key, callable $read): mixed
    {
        $pdo = $db->pdo();
        if (!$pdo instanceof LoggingPdo || !$pdo->statementsTracked() || !$pdo->inTransaction()) {
            return $read();
        }
        $memo = (self::$memo ??= new \WeakMap())[$pdo] ?? null;
        if ($memo !== null && $memo['writes'] === $pdo->writeCount() && array_key_exists($key, $memo['values'])) {
            return $memo['values'][$key];
        }
        $value = $read();
        $writes = $pdo->writeCount();
        $memo = self::$memo[$pdo] ?? null;
        if ($memo === null || $memo['writes'] !== $writes) {
            $memo = ['writes' => $writes, 'values' => []];
        }
        $memo['values'][$key] = $value;
        self::$memo[$pdo] = $memo;
        return $value;
    }
}
