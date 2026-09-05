<?php

declare(strict_types=1);

/**
 * SETUP — interaktivní úvodní zřízení (CLI):
 *   1. Zkontroluje cfg.php (instrukce pro kopii z cfg.sample.php pokud chybí)
 *   2. Otestuje DB připojení (instrukce pokud nelze)
 *   3. Spustí pending migrace (db/migrations/*.sql)
 *   4. Zeptá se na IČO → načte z ARES → vyplní dodavatele
 *   5. Zeptá se na admin email + jméno + heslo
 *   6. Uloží do DB
 *
 * Spustit:  php api/bin/setup.php
 *
 * Doporučené pořadí (fresh install):
 *   1. cp cfg.sample.php cfg.php  +  vyplň db/smtp/pepper
 *   2. php api/bin/setup.php       # tento skript
 *   3. php api/bin/sample.php      # (volitelné) testovací data
 *   ── později ──
 *      php api/bin/reset.php       # wipe všeho, pak znovu setup + sample
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$rootDir = dirname(__DIR__, 2);

// === Krok 1: cfg.php ===
$cfgPath    = $rootDir . '/cfg.php';
$samplePath = $rootDir . '/cfg.sample.php';
if (!is_file($cfgPath)) {
    echo "\n❌  cfg.php nenalezen.\n\n";
    echo "Postup:\n";
    echo "  1. cp cfg.sample.php cfg.php\n";
    echo "  2. Otevři cfg.php a vyplň hodnoty označené CHANGE-ME:\n";
    echo "     - app.url       (https://tvoje-domena.cz)\n";
    echo "     - app.pepper    (32B base64: openssl rand -base64 32)\n";
    echo "     - db.host / db.name / db.user / db.pass\n";
    echo "     - smtp.* pro odesílání e-mailů\n";
    echo "     - cloudflare.turnstile.* (volitelné)\n";
    echo "  3. Spusť znovu: php api/bin/setup.php\n\n";
    if (!is_file($samplePath)) {
        echo "  POZOR: ani cfg.sample.php neexistuje — repo je poškozené.\n";
    }
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankRuleTemplateSeeder;
use MyInvoice\Service\Ares\AresClient;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\WebAuthnConfig;
use MyInvoice\Service\Config\CfgLocalWriter;
use Monolog\Logger;

// === Krok 2: načti config ===
try {
    $config = Config::load($rootDir);
} catch (\Throwable $e) {
    echo "\n❌  Chyba při načítání cfg.php: " . $e->getMessage() . "\n";
    exit(1);
}

// === Krok 3: DB připojení ===
echo "\n🔌  Testuji připojení k DB…\n";
try {
    $pdo = (new Connection($config))->pdo();
    echo "    ✓ Připojeno k " . $config->get('db.name') . " @ " . $config->get('db.host') . "\n";
} catch (\Throwable $e) {
    echo "\n❌  Nelze se připojit k DB: " . $e->getMessage() . "\n\n";
    echo "Možné příčiny:\n";
    echo "  - cfg.php má špatné db.host / db.user / db.pass (uprav cfg.php)\n";
    echo "  - MariaDB neběží\n";
    echo "  - Databáze '" . $config->get('db.name') . "' neexistuje. Vytvoř ji:\n";
    echo "      mysql -u root -p -e \"CREATE DATABASE " . $config->get('db.name') . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"\n";
    echo "  - Uživatel nemá práva na DB\n";
    exit(1);
}

// === Krok 4: migrace ===
echo "\n📦  Spouštím migrace…\n";
$out = shell_exec('php ' . escapeshellarg(__DIR__ . '/migrate.php') . ' 2>&1');
echo "    " . trim((string) $out) . "\n";

// === Krok 5: ověř, jestli setup už neproběhl ===
$adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
$supplierCount = (int) $pdo->query("SELECT COUNT(*) FROM supplier")->fetchColumn();
if ($adminCount > 0 || $supplierCount > 0) {
    echo "\n⚠   Systém už má vyplněné údaje (admin: $adminCount, dodavatel: $supplierCount).\n";
    echo "    Pro nový setup nejdřív spusť: php api/bin/reset.php\n";
    exit(1);
}

echo "\n================================================\n";
echo "  ÚVODNÍ NASTAVENÍ DODAVATELE\n";
echo "================================================\n";

// === Krok 6: IČO + ARES ===
$ic = prompt('IČO dodavatele (8 číslic, prázdné = vyplnit ručně): ');
$supplier = [
    'company_name' => '',
    'street' => '', 'city' => '', 'zip' => '', 'country_iso2' => 'CZ',
    'ic' => '', 'dic' => '',
];

if ($ic !== '') {
    if (!preg_match('/^\d{8}$/', $ic)) {
        echo "    ⚠  IČO musí mít 8 číslic. Pokračujeme s ručním vyplněním.\n";
    } else {
        echo "    🔍 Hledám v ARES…\n";
        $logger = new Logger('setup');
        $conn   = new Connection($config);
        $ares   = new AresClient($config, $conn, $logger);
        $result = $ares->lookup($ic);
        if ($result && !empty($result['found']) && !empty($result['data'])) {
            $d = $result['data'];
            echo "    ✓ Nalezeno: " . ($d['company_name'] ?? '?') . "\n";
            $supplier['company_name'] = (string) ($d['company_name'] ?? '');
            $supplier['street']       = (string) ($d['street']       ?? '');
            $supplier['city']         = (string) ($d['city']         ?? '');
            $supplier['zip']          = (string) ($d['zip']          ?? '');
            $supplier['ic']           = (string) ($d['ic']           ?? $ic);
            $supplier['dic']          = (string) ($d['dic']          ?? '');
        } else {
            echo "    ⚠  Subjekt nenalezen v ARES. Pokračujeme s ručním vyplněním.\n";
            $supplier['ic'] = $ic;
        }
    }
}

// Doplnit chybějící povinná pole interaktivně
$supplier['company_name'] = prompt('Název firmy', $supplier['company_name']);
$supplier['street']       = prompt('Ulice + č.p.', $supplier['street']);
$supplier['zip']          = prompt('PSČ', $supplier['zip']);
$supplier['city']         = prompt('Město', $supplier['city']);
$supplier['ic']           = prompt('IČO', $supplier['ic']);
$supplier['dic']          = prompt('DIČ (prázdné = neplátce)', $supplier['dic']);
$supplier['email']        = prompt('Kontaktní email odesílatele', '');
$supplier['display_name'] = prompt('Jméno odesílatele e-mailů (display name)', $supplier['company_name']);
$supplier['phone']        = prompt('Telefon (volitelné)', '');
$supplier['web']          = prompt('Web (volitelné)', '');
$isVatPayer = $supplier['dic'] !== '';

echo "\n================================================\n";
echo "  ADMIN ÚČET\n";
echo "================================================\n";
$adminName  = prompt('Jméno admina', '');
$adminEmail = prompt('Email admina', '');
$adminPass  = promptPassword('Heslo (min. 12 znaků): ');
while (strlen($adminPass) < 12) {
    echo "    ⚠  Heslo musí mít alespoň 12 znaků.\n";
    $adminPass = promptPassword('Heslo: ');
}

echo "\n================================================\n";
echo "  BEZPEČNOST\n";
echo "================================================\n";
echo "  Vynucení vícefaktorového ověření (MFA) pro VŠECHNY uživatele?\n";
echo "  Doporučeno pro produkci. Po loginu uživatel dokončí passkey nebo TOTP.\n";
$requireMfaAns = strtolower(prompt('Vynutit MFA? (ano/NE)', 'ne'));
$requireMfa = in_array($requireMfaAns, ['ano', 'a', 'y', 'yes', 'true'], true);
$allowedMfaMethods = ['passkey', 'totp'];
if ($requireMfa) {
    while (true) {
        $methodsRaw = strtolower(prompt('Povolené metody (passkey,totp)', 'passkey,totp'));
        $allowedMfaMethods = array_values(array_unique(array_filter(array_map('trim', explode(',', $methodsRaw)))));
        if ($allowedMfaMethods !== []
            && array_diff($allowedMfaMethods, ['passkey', 'totp']) === []
        ) {
            if ($allowedMfaMethods === ['passkey']) {
                try {
                    new WebAuthnConfig($config);
                } catch (\InvalidArgumentException $e) {
                    echo "    ⚠  Passkey-only MFA nelze s aktuální app.url použít: {$e->getMessage()}\n";
                    echo "       Uprav app.url, ukonči setup, nebo zvol také TOTP jako dostupnou metodu.\n";
                    continue;
                }
            }
            break;
        }
        echo "    ⚠  Zadej neprázdnou kombinaci: passkey, totp nebo passkey,totp.\n";
    }
}

echo "\n================================================\n";
echo "  Shrnutí:\n";
echo "    Firma:  {$supplier['company_name']} ({$supplier['ic']})\n";
echo "    Adresa: {$supplier['street']}, {$supplier['zip']} {$supplier['city']}\n";
echo "    Email:  {$supplier['email']}\n";
echo "    Admin:  {$adminName} <{$adminEmail}>\n";
echo "    MFA:    " . ($requireMfa
    ? 'VYNUCENO (' . implode(', ', $allowedMfaMethods) . ')'
    : 'volitelné (per-user)') . "\n";
echo "================================================\n";
$confirm = prompt('Pokračovat? (ANO/ne)', 'ANO');
if ($confirm !== 'ANO') {
    echo "Zrušeno.\n";
    exit(0);
}

// === Zápis do DB ===
$pdo->beginTransaction();
try {
    // Country
    $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
    if ($countryId === 0) {
        throw new \RuntimeException("Tabulka countries je prázdná — spusť migrace (db/migrations/0001_init.sql obsahuje seed).");
    }
    $vatRateId = (int) $pdo->query("SELECT id FROM vat_rates WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($vatRateId === 0) {
        $vatRateId = (int) $pdo->query("SELECT id FROM vat_rates ORDER BY id LIMIT 1")->fetchColumn();
    }
    if ($vatRateId === 0) {
        throw new \RuntimeException("Tabulka vat_rates je prázdná.");
    }

    // Currencies pro nového supplier — seedneme po vytvoření supplier řádku níže.
    // Bootstrap: do supplier potřebujeme default_currency_id, ale ten ještě neexistuje.
    // Trik: vložíme placeholder (0), insertneme currencies, pak UPDATE supplier.

    $stmt = $pdo->prepare(
        'INSERT INTO supplier (company_name, display_name, street, city, zip, country_id, ic, dic,
                               is_vat_payer, email, phone, web, default_currency_id, default_vat_rate_id,
                               default_payment_due_days, default_hourly_rate)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 7, 1500.00)'
    );
    // FK check off jen pro tento INSERT (supplier.default_currency_id=0 dočasně)
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $stmt->execute([
        $supplier['company_name'],
        $supplier['display_name'] ?: null,
        $supplier['street'],
        $supplier['city'],
        $supplier['zip'],
        $countryId,
        $supplier['ic'] ?: null,
        $supplier['dic'] ?: null,
        $isVatPayer ? 1 : 0,
        $supplier['email'],
        $supplier['phone'] ?: null,
        $supplier['web'] ?: null,
        $vatRateId,
    ]);
    $supplierId = (int) $pdo->lastInsertId();
    \MyInvoice\Service\Vat\VatStatusService::seedInitialStatus($pdo, $supplierId, $isVatPayer);

    // Seed defaults currencies (CZK + EUR) pro tohoto supplier
    $insertCur = $pdo->prepare(
        'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
         VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1)'
    );
    $insertCur->execute([$supplierId, 'CZK', 'CZK — výchozí', 'Kč', 'Česká koruna', 'Czech Koruna']);
    $defaultCurrencyId = (int) $pdo->lastInsertId();
    $insertCur->execute([$supplierId, 'EUR', 'EUR — výchozí', '€', 'Euro', 'Euro']);

    // Doplníme supplier.default_currency_id na CZK a obnovíme FK
    $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
        ->execute([$defaultCurrencyId, $supplierId]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    BankRuleTemplateSeeder::seed($pdo, $supplierId);

    // POZOR: hash MUSÍ projít přes PasswordHasher (bcrypt cost 12 + pepper z cfg.app.pepper),
    // protože LoginAction ověřuje pomocí PasswordHasher::verify() s pepperem.
    // Pure password_hash() bez pepperu by produkoval hash, který nikdy nematchne při loginu.
    $hasher = new PasswordHasher($config);
    $superadminRoleId = (int) $pdo->query(
        "SELECT id FROM roles WHERE system_key = 'superadmin' AND role_type = 'superadmin' AND is_active = 1 LIMIT 1"
    )->fetchColumn();
    if ($superadminRoleId <= 0) {
        throw new RuntimeException('Systémová role superadmin není dostupná. Spusť nejprve migrace.');
    }
    $pdo->prepare(
        'INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active)
         VALUES (?, ?, ?, "admin", ?, "cs", 1)'
    )->execute([
        $adminEmail,
        $hasher->hash($adminPass),
        $adminName,
        $superadminRoleId,
    ]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "\n❌  Setup selhal: " . $e->getMessage() . "\n";
    exit(1);
}

// === Zapiš obecnou MFA politiku a vypni legacy TOTP-only přepínač ===
try {
    CfgLocalWriter::setKeys(CfgLocalWriter::resolveTargetDir($rootDir), [
        'auth.require_mfa' => $requireMfa,
        'auth.allowed_mfa_methods' => $allowedMfaMethods,
        'auth.require_totp' => false,
    ]);
    echo "\n🔒  Nastavení MFA zapsáno do cfg.local.php.\n";
} catch (\Throwable $e) {
    echo "\n⚠   Nepodařilo se zapsat cfg.local.php: " . $e->getMessage() . "\n";
    echo "    Nastav ručně auth.require_mfa a auth.allowed_mfa_methods.\n";
}

echo "\n✅  Hotovo.\n";
echo "    Přihlas se na " . $config->get('app.url', '/') . "/login\n";
echo "    Email: $adminEmail\n";
if ($requireMfa) {
    echo "    Po přihlášení budeš přesměrován na /setup-mfa pro aktivaci MFA.\n";
}
echo "    Bankovní účet pro CZK doplň v Systém → Nastavení.\n\n";

// === Helpers ===

function prompt(string $label, string $default = ''): string
{
    if ($default !== '') echo "  $label [$default]: ";
    else echo "  $label: ";
    $line = trim((string) fgets(STDIN));
    return $line !== '' ? $line : $default;
}

function promptPassword(string $label): string
{
    echo "  $label";
    if (DIRECTORY_SEPARATOR === '/') {
        // POSIX: vypni echo
        @system('stty -echo');
        $pass = trim((string) fgets(STDIN));
        @system('stty echo');
        echo "\n";
        return $pass;
    }
    // Windows: bez echo-disable, jen čteme
    return trim((string) fgets(STDIN));
}
