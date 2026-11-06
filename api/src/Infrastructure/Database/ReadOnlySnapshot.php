<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

/**
 * Konzistentní snímek pro sestavy, které čtou stejná data víc dotazy (souhrn a pak
 * řádky po dávkách). REPEATABLE READ platí i na serveru s výchozí izolací READ
 * COMMITTED a READ ONLY vyloučí, že by čtecí cesta omylem zapsala.
 *
 * Snímek drží metadatové zámky čtených tabulek po celou dobu volání (u rozpisu hlavní
 * knihy včetně sazby PDF); ALTER TABLE nad nimi počká, až čtení doběhne. Sazbu omezuje
 * limit rozpisu (≈ 30 s) a migrace běží při startu kontejneru, kdy se neexportuje.
 *
 * Snímek jen čte, takže nehrozí deadlock ani lock wait: výjimku čtení vrací volajícímu
 * beze změny až po rollbacku (AbortedTransactionGuardTest hlídá, že ji nespolkne).
 */
final class ReadOnlySnapshot
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Spustí čtení ve snímku jen pro čtení nad spojením aplikace (viz run()).
     * Jako služba jde v testu akce nahradit, a ověřit tak, že akce snímek otevírá.
     *
     * @template T
     * @param callable():T $read
     * @return T
     */
    public function read(callable $read): mixed
    {
        return self::run($this->db->pdo(), $read);
    }

    /**
     * Spustí čtení ve snímku jen pro čtení a snímek po něm potvrdí, při výjimce vrátí.
     * Uvnitř cizí transakce nic nemění a jen čtení spustí: transakci vlastní volající.
     *
     * @template T
     * @param callable():T $read
     * @return T
     */
    public static function run(\PDO $pdo, callable $read): mixed
    {
        if ($pdo->inTransaction()) return $read();
        // Bez SESSION platí izolace jen pro následující transakci.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION READ ONLY');
        try {
            $result = $read();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable) {
                    // Nepovedený rollback (spadlé spojení) nesmí přemaskovat původní chybu.
                }
            }
            throw $e;
        }
    }
}
