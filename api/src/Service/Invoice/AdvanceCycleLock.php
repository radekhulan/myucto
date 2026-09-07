<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;

final class AdvanceCycleLock
{
    public function __construct(private readonly Connection $db) {}

    public function synchronized(int $proformaId, callable $callback): mixed
    {
        if ($proformaId <= 0) {
            return $callback();
        }

        // Serverový named lock — scoping podle databáze, viz NamedLockName.
        $name = NamedLockName::for($this->db, 'myinvoice:advance-cycle', $proformaId);
        $lock = $this->db->pdo()->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$name]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new \RuntimeException('Zálohový cyklus právě mění jiný požadavek. Zkuste akci znovu.');
        }

        $enteredInsideTransaction = $this->db->pdo()->inTransaction();
        try {
            return $callback();
        } finally {
            // Když callback běžel uvnitř transakce calleru, její COMMIT nastane až po
            // návratu. Zámek proto záměrně držíme do uzavření DB spojení (konec requestu),
            // jinak by mezi návratem creatoru a COMMIT mohl proběhnout issue/storno DDKP.
            if (!$enteredInsideTransaction) {
                $release = $this->db->pdo()->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$name]);
            }
        }
    }
}
