<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;
use PDOException;

final class SupplierCascadeCleanup
{
    public static function delete(PDO $pdo, int ...$supplierIds): void
    {
        foreach ($supplierIds as $supplierId) {
            self::deleteOne($pdo, $supplierId);
        }
    }

    private static function deleteOne(PDO $pdo, int $supplierId): void
    {
        $cleared = [];
        for ($attempt = 0; $attempt < 16; $attempt++) {
            try {
                $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplierId]);
                return;
            } catch (PDOException $e) {
                $parent = self::restrictingParent($e);
                if ($parent === null || $parent === 'supplier' || isset($cleared[$parent])) {
                    throw $e;
                }
                $cleared[$parent] = true;
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                try {
                    $pdo->prepare('DELETE FROM `' . $parent . '` WHERE supplier_id = ?')->execute([$supplierId]);
                } finally {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                }
            }
        }
        throw new \RuntimeException('Dodavatele ' . $supplierId . ' se nepodařilo smazat.');
    }

    private static function restrictingParent(PDOException $e): ?string
    {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1451) {
            return null;
        }
        $matched = preg_match('/FOREIGN KEY \([^)]*\) REFERENCES `([A-Za-z0-9_]+)`/', (string) $e->getMessage(), $m);

        return $matched === 1 ? $m[1] : null;
    }
}
