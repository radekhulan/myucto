<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\Connection;

trait PropertyLegalRoleFixture
{
    use PropertyChargeFixture;

    private function legalRoleFixture(Connection $db): array
    {
        $pdo = $db->pdo();
        if ((int) $pdo->query('SELECT COUNT(*) FROM supplier')->fetchColumn() === 0) {
            $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
            $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
            $supplier = 0;
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                $pdo->prepare("INSERT INTO supplier (company_name, street, city, zip, email, country_id, default_currency_id, default_vat_rate_id, accounting_mode)
                    VALUES ('Synthetic property catalog base', 'Synthetic street', 'Synthetic city', '10000', 'property-catalog@example.test', ?, 1, ?, 'double_entry')")
                    ->execute([$country, $vat]);
                $supplier = (int) $pdo->lastInsertId();
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
            $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
                VALUES (?, 'CZK', 'Synthetic CZK', 'Kč', 'Koruna', 'Crown', 2, 1, 1, '1000000005', '0100')")->execute([$supplier]);
            $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $supplier]);
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO users (email, password_hash, name, role, locale, is_active)
                VALUES ('property-catalog-actor@example.test', ?, 'Synthetic property catalog actor', 'admin', 'cs', 1)")
                ->execute([password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        }
        return $this->chargeFixture($db);
    }
}
