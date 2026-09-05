<?php

declare(strict_types=1);

/**
 * CI / test fixture — minimální deterministická data pro `--testsuite Integration`.
 *
 *   php api/bin/ci-seed.php
 *
 * Integrační testy si vlastní faktury a klienty zakládají samy, ale potřebují
 * zastat číselníky a tenanta: bez currency, dodavatele a uživatele se jich
 * přeskočí drtivá většina. Tenhle skript proto vytvoří jen tohle minimum:
 *
 *   - dva dodavatele (druhý kvůli cross-tenant testům izolace)
 *   - pro každého CZK + EUR, CZK s testovacím bankovním účtem
 *   - jednoho admin uživatele
 *
 * Není to náhrada `setup.php` — nevolá ARES, nic se neptá a data jsou
 * syntetická. Určeno pro čerstvě zmigrovanou prázdnou databázi.
 *
 * Bezpečnostní pojistka: pokud v DB už existuje dodavatel nebo uživatel,
 * skript se ukončí a nic nezmění. Proti ostré databázi tedy nic neprovede.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Accounting\Bank\BankRuleTemplateSeeder;

$container = Bootstrap::buildApp()->getContainer();
$pdo = $container->get(Connection::class)->pdo();

$existing = (int) $pdo->query('SELECT COUNT(*) FROM supplier')->fetchColumn()
    + (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($existing > 0) {
    fwrite(STDERR, "Databáze už obsahuje dodavatele nebo uživatele — fixture se nespouští.\n");
    exit(1);
}

$countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
$vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
if ($countryId < 1 || $vatRateId < 1) {
    fwrite(STDERR, "Chybí číselníky (countries/vat_rates) — spusť nejdřív migrace.\n");
    exit(2);
}

// Cyklická závislost supplier.default_currency_id <-> currencies.supplier_id;
// stejný postup jako v HTTP setup wizardu (SetupAction).
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->beginTransaction();
try {
    $insertSupplier = $pdo->prepare(
        'INSERT INTO supplier
            (company_name, street, city, zip, country_id, email, is_vat_payer,
             default_currency_id, default_vat_rate_id, default_payment_due_days,
             default_payment_due_unit, default_hourly_rate)
         VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, 14, ?, ?)'
    );
    $insertCurrency = $pdo->prepare(
        'INSERT INTO currencies
            (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
             account_number, bank_code)
         VALUES (?, ?, ?, ?, ?, ?, 2, 1, ?, ?, ?)'
    );

    // Testovací účet musí projít mod-11 kontrolou CZ účtů.
    $tenants = [
        ['Fixture Alfa s.r.o.', 'alfa@example.invalid', '1000000005', '0100'],
        ['Fixture Beta s.r.o.', 'beta@example.invalid', '1000000005', '0300'],
    ];
    $supplierIds = [];
    foreach ($tenants as [$name, $email, $accountNumber, $bankCode]) {
        $insertSupplier->execute([
            $name, 'Testovací 1', 'Praha', '11000', $countryId, $email,
            $vatRateId, 'days', '1500.00',
        ]);
        $supplierId = (int) $pdo->lastInsertId();
        $supplierIds[] = $supplierId;
        \MyInvoice\Service\Vat\VatStatusService::seedInitialStatus($pdo, $supplierId, true);

        $defaultCurrencyId = 0;
        foreach ([
            ['CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 1, $accountNumber, $bankCode],
            ['EUR', 'EUR', '€', 'Euro', 'Euro', 0, null, null],
        ] as [$code, $label, $symbol, $nameCs, $nameEn, $isDefault, $account, $bank]) {
            $insertCurrency->execute([
                $supplierId, $code, $label, $symbol, $nameCs, $nameEn, $isDefault, $account, $bank,
            ]);
            if ($isDefault === 1) {
                $defaultCurrencyId = (int) $pdo->lastInsertId();
            }
        }
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
            ->execute([$defaultCurrencyId, $supplierId]);
        BankRuleTemplateSeeder::seed($pdo, $supplierId);
    }

    // `role_id` MUSÍ být vyplněné, ne jen legacy enum `role`: autorizace i
    // ApiTokenService::validate() joinují `roles` přes role_id, takže uživatel
    // bez něj se tváří jako neexistující (validate vrací null, akce 403).
    $superadminRoleId = $pdo->query(
        "SELECT id FROM roles WHERE system_key = 'superadmin' LIMIT 1"
    )->fetchColumn();
    if ($superadminRoleId === false) {
        throw new RuntimeException('Role superadmin chybí — neproběhly migrace rolí?');
    }

    $hasher = $container->get(PasswordHasher::class);
    $pdo->prepare(
        'INSERT INTO users (email, password_hash, name, role, role_id, locale) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        'fixture@example.invalid',
        $hasher->hash('Fixture-password-42'),
        'Fixture Admin',
        'admin',
        (int) $superadminRoleId,
        'cs',
    ]);

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    throw $e;
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

printf(
    "✓ Fixture: %d dodavatelé (id %s), %d měny, 1 admin.\n",
    count($supplierIds),
    implode(', ', $supplierIds),
    (int) $pdo->query('SELECT COUNT(*) FROM currencies')->fetchColumn(),
);
