<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

/**
 * Jediné místo, kde se skládá jméno pro `GET_LOCK`.
 *
 * Why: named locky v MariaDB jsou **serverové, ne per-databázové**. Jméno
 * odvozené jen z identifikátoru uvnitř instance (typicky `supplier_id`) proto
 * kolinduje mezi instalacemi, které sdílejí jeden server:
 *
 *   - **SaaS hosting** — každý zákazník má vlastní databázi na společném
 *     MariaDB. Dvě firmy, obě se `supplier_id = 1`, si navzájem blokují operaci,
 *     se kterou nemají nic společného. Tam, kde se zámek nezískává s čekáním,
 *     to navíc není chyba, ale TICHÉ přeskočení — přepočet prostě neproběhne.
 *   - **Paralelní testy** — `bin/test-parallel.php` dává každému workeru vlastní
 *     databázi na TÉMŽE serveru, takže se `supplier_id` napříč workery opakují.
 *
 * Vzor převzatý z {@see \MyInvoice\Service\License\LicenseCapacityGate}, kde
 * scoping podle databáze existoval jako jediný; ostatní zámky ho postrádaly.
 *
 * Hashuje se, protože MariaDB má na jméno zámku limit 64 znaků a názvy databází
 * bývají dlouhé.
 */
final class NamedLockName
{
    /**
     * @param string $scope Logický jmenný prostor zámku (např. `automation_recommendations`).
     * @param string $key   Identifikátor uvnitř instance (např. `supplier_id`); volitelný.
     */
    public static function for(Connection $db, string $scope, string|int $key = ''): string
    {
        return self::compose($scope, self::database($db), $key);
    }

    /**
     * Čistá část skládání jména — bez ní by šlo otestovat jen to, že něco vrací,
     * ne to, na čem celá oprava stojí: že se jméno pro dvě různé databáze liší.
     *
     * @param string $database Název databáze instance (ne otisk).
     */
    public static function compose(string $scope, string $database, string|int $key = ''): string
    {
        if (trim($database) === '') {
            throw new \RuntimeException('Aktuální databázi pro named lock nelze určit.');
        }
        // Zkrácený otisk místo holého jména: drží se pod limitem 64 znaků
        // a nevytáhne název databáze do chybových hlášek.
        $name = $scope . ':' . substr(hash('sha256', $database), 0, 16) . ($key === '' ? '' : ':' . $key);
        if (strlen($name) <= 64) {
            return $name;
        }

        // Přes limit: zkracuje se i scope, ne jen zbytek. Otisk je z CELÉHO jména,
        // takže zůstává jednoznačný i po oříznutí čitelné předpony.
        return substr($scope, 0, 30) . ':' . substr(hash('sha256', $name), 0, 32);
    }

    private static function database(Connection $db): string
    {
        $statement = $db->pdo()->query('SELECT DATABASE()');
        if ($statement === false) {
            throw new \RuntimeException('Aktuální databázi pro named lock nelze načíst.');
        }

        return (string) $statement->fetchColumn();
    }
}
