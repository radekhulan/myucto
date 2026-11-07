<?php

declare(strict_types=1);

use MyInvoice\Bootstrap;
use MyInvoice\Service\Auth\UserSessionCli;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require __DIR__ . '/../vendor/autoload.php';

$arguments = array_slice($argv, 1);
if ($arguments === [] || in_array('--help', $arguments, true) || $arguments === ['help']) {
    echo <<<'HELP'
Správa relací MyÚčto ze serveru (včetně superadminů a správců instance).

php api/bin/user-sessions.php list [--email=EMAIL] [--cursor=KURZOR] [--json]
php api/bin/user-sessions.php terminate --id=ID [--yes] [--json]
php api/bin/user-sessions.php terminate --email=EMAIL [--yes] [--json]
php api/bin/user-sessions.php terminate --all [--yes] [--json]

Bez --yes se ukončení pouze připraví jako náhled počtu relací.
--all odhlásí všechny účty. Hromadné ukončení probíhá v auditovaných dávkách po 50.
Poslední kontakt není důkazem aktuální práce uživatele.
HELP;
    echo PHP_EOL;
    exit(0);
}

$json = in_array('--json', $arguments, true);
$exit = 0;
try {
    $options = UserSessionCli::parse($arguments);
    $result = Bootstrap::buildApp()->getContainer()->get(UserSessionCli::class)->run($options);
    if (isset($result['error'])) $exit = 1;
} catch (InvalidArgumentException $e) {
    $result = ['error' => $e->getMessage()];
    $exit = 2;
} catch (DomainException $e) {
    $result = ['error' => $e->getMessage()];
    $exit = 3;
} catch (Throwable $e) {
    // Připojovací údaje ani výjimky ovladače DB nesmějí uniknout do terminálu.
    $result = ['error' => 'Příkaz selhal. Ověřte konfiguraci a dostupnost databáze. Již dokončené dávky ukončení zůstávají auditované.'];
    $exit = 1;
}

if ($json) {
    fwrite($exit === 0 ? STDOUT : STDERR, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
} else {
    // Hodnoty z user-agentu nebo uživatelských polí nesmí ovládat SSH terminál.
    $safe = static fn (mixed $value): string => preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', (string) ($value ?? '')) ?? '';
    if (isset($result['data'])) {
        foreach ($result['data'] as $row) {
            echo 'Relace: ' . $safe($row['id']) . PHP_EOL;
            echo '  Uživatel: #' . $row['user_id'] . ' ' . $safe($row['email']) . ' (' . $safe($row['name']) . ')' . PHP_EOL;
            echo '  IP: ' . $safe($row['ip']) . ' | Zamčeno: ' . $safe($row['locked_at'] ?? 'ne') . PHP_EOL;
            echo '  Zařízení: ' . $safe($row['user_agent']) . PHP_EOL;
            echo '  Přihlášení: ' . $row['created_at'] . PHP_EOL;
            echo '  Poslední kontakt: ' . $row['last_seen'] . PHP_EOL;
            echo '  Platnost do: ' . $row['expires_at'] . PHP_EOL . PHP_EOL;
        }
        echo 'Zobrazeno relací: ' . count($result['data']) . PHP_EOL;
        if ($result['next_cursor'] !== null) {
            echo 'Další stránka: použijte --cursor=' . $result['next_cursor'] . ' se stejným filtrem.' . PHP_EOL;
        }
    } elseif (!isset($result['error'])) {
        echo ($result['executed'] ? 'Ukončeno relací: ' . $result['terminated'] : 'Náhled, nalezeno relací: ' . $result['matched']) . PHP_EOL;
        if (!$result['executed']) echo 'Pro skutečné ukončení přidejte --yes.' . PHP_EOL;
    }
    if (isset($result['error'])) {
        fwrite(STDERR, $result['error'] . PHP_EOL);
        if (isset($result['terminated'])) fwrite(STDERR, 'Již ukončeno relací: ' . $result['terminated'] . PHP_EOL);
    }
}
exit($exit);
