<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;
use PDOException;

/**
 * Sdílené testovací spojení na vlastní databázi testu může držet otevřenou transakci
 * (metadata lock) a DROP by na ni čekal donekonečna. Před úklidem se proto ukončí
 * zbylá spojení na databáze, které test sám založil.
 */
final class OwnedTestDatabaseSessions
{
    /** @param list<string> $ownedSchemas */
    public static function close(PDO $server, array $ownedSchemas): void
    {
        foreach ($ownedSchemas as $schema) {
            if (preg_match('/\Amyucto_[a-z0-9_]+_test\z/D', $schema) !== 1) {
                throw new \LogicException('Ukončit lze jen spojení na vlastní testovací databázi.');
            }
            $sessions = $server->prepare('SELECT ID FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID()');
            $sessions->execute([$schema]);
            foreach ($sessions->fetchAll(PDO::FETCH_COLUMN) as $id) {
                try {
                    $server->exec('KILL ' . (int) $id);
                } catch (PDOException) {
                    // Spojení mezitím skončilo samo.
                }
            }
        }
    }
}
