<?php

declare(strict_types=1);

/**
 * Pozvánka k propojení účtu s poskytovatelem identity z příkazové řádky.
 *
 *   php api/bin/oidc-invite.php --email=spravce@example.com            # vypíše odkaz
 *   php api/bin/oidc-invite.php --email=spravce@example.com --send     # pošle ho e-mailem
 *   php api/bin/oidc-invite.php --email=spravce@example.com --provider=entra
 *
 * Při vynuceném přihlášení přes poskytovatele identity (`auth.oidc.required_provider`)
 * se heslem nepřihlásí nikdo, ani správce instance. Tímhle nástrojem se propojí první
 * účet po instalaci nebo účet, jehož identita u poskytovatele zanikla. Odkaz platí
 * 72 hodin a jednou. Kdo ho otevře a přihlásí se u poskytovatele, naváže svou
 * identitu na uvedený účet, proto ho předávejte jen jeho majiteli.
 *
 * Výchozí poskytovatel je ten vynucený, jinak `entra`.
 *
 * Exit kódy: 0 ok · 1 chyba běhu · 2 chybné argumenty
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\Oidc\OidcInviteMailer;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $body = substr($arg, 2);
    $eq = strpos($body, '=');
    $opts[$eq === false ? $body : substr($body, 0, $eq)] = $eq === false ? true : substr($body, $eq + 1);
}

$email = trim((string) ($opts['email'] ?? ''));
if ($email === '' || isset($opts['help'])) {
    fwrite(STDERR, "Použití: php api/bin/oidc-invite.php --email=<e-mail účtu> [--provider=entra] [--send]\n");
    exit(2);
}

try {
    $container = Bootstrap::buildContainer();
    $providers = $container->get(OidcProviderRegistry::class);
    $providerId = trim((string) ($opts['provider'] ?? ''));
    if ($providerId === '') {
        $providerId = $container->get(LoginPolicyService::class)->requiredProvider() ?? 'entra';
    }
    $provider = $providers->isEnabled() ? $providers->get($providerId) : null;
    if ($provider === null) {
        fwrite(STDERR, "Poskytovatel identity '{$providerId}' není v instalaci zapnutý.\n");
        exit(2);
    }

    $stmt = $container->get(Connection::class)->pdo()->prepare(
        'SELECT id, email, name, locale, is_active FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($user === false) {
        fwrite(STDERR, "Účet {$email} neexistuje.\n");
        exit(2);
    }
    if ((int) $user['is_active'] !== 1) {
        fwrite(STDERR, "Účet {$email} je deaktivovaný.\n");
        exit(2);
    }

    $invites = $container->get(OidcInviteMailer::class);
    $userId = (int) $user['id'];
    if (isset($opts['send'])) {
        $smtp = $invites->send($userId, (string) $user['email'], (string) ($user['name'] ?? ''), (string) ($user['locale'] ?? 'cs'), $provider, null);
        $container->get(ActivityLogger::class)->log('user.sso_invite_sent', null, 'user', $userId, [
            'to' => [(string) $user['email']], 'provider' => $provider->id, 'smtp_response' => $smtp, 'by' => 'cli',
        ], null, 'oidc-invite.php');
        fwrite(STDOUT, "Pozvánka odeslána na {$user['email']}.\n");
    } else {
        $link = $invites->issueLink($userId, $provider, null);
        $container->get(ActivityLogger::class)->log('user.sso_invite_issued', null, 'user', $userId, [
            'provider' => $provider->id, 'by' => 'cli',
        ], null, 'oidc-invite.php');
        fwrite(STDOUT, $link . "\n");
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Chyba: ' . $e->getMessage() . "\n");
    exit(1);
}
