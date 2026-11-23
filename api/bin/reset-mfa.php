<?php

declare(strict_types=1);

/**
 * CLI: nouzově resetuje všechny MFA faktory uživatele podle e-mailu.
 *
 * Vypne TOTP, odvolá passkeys, zruší trusted devices, login OTP, rozpracované
 * WebAuthn ceremonies a step-up proofy. Zruší API tokeny a MCP připojení
 * uživatele. Invaliduje všechny session uživatele.
 *
 * POZOR NA E-MAILOVÉ OTP: to není faktor uložený u uživatele, ale konfigurační
 * fallback (`auth.email_otp.enabled`) pro každého, kdo NEMÁ TOTP. Reset tedy
 * uživatele do e-mailového OTP naopak zavede — vypne mu TOTP a k tomu smaže
 * důvěryhodné zařízení, které ten druhý faktor přeskakovalo. Na instalaci
 * s nefunkčním SMTP je to slepá ulička: záložní kódy jsou po resetu taky pryč,
 * takže se nemá čím přihlásit. Proto skript na konci varuje a nabízí
 * `--no-email-otp`.
 *
 * Použití:
 *   php api/bin/reset-mfa.php admin@example.com
 *   php api/bin/reset-mfa.php admin@example.com --no-email-otp
 *
 * `--no-email-otp` zapíše `auth.email_otp.enabled = false` do `cfg.local.php`,
 * tedy pro CELOU instalaci, ne jen pro resetovaného uživatele — jinou
 * granularitu ten přepínač nemá. Přihlašuje se pak jen heslem (plus TOTP nebo
 * passkey u toho, kdo si je zaregistruje), takže je to nouzové opatření na
 * dobu, než bude odesílání pošty fungovat.
 */

require __DIR__ . '/../vendor/autoload.php';

/**
 * Kdo skript spustil — do auditu. Na Windows je to USERNAME, na unixu USER/LOGNAME;
 * `posix_geteuid()` je spolehlivější (ENV lze podvrhnout), ale rozšíření nemusí být.
 */
function cliOperator(): ?string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && ($pw['name'] ?? '') !== '') {
            return (string) $pw['name'];
        }
    }
    foreach (['USER', 'LOGNAME', 'USERNAME'] as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }
    return null;
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tento skript musí běžet z CLI.\n");
    exit(1);
}

$email          = null;
$disableEmailOtp = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-email-otp') {
        $disableEmailOtp = true;
    } elseif ($email === null) {
        $email = $arg;
    }
}
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Použití: php api/bin/reset-mfa.php <email> [--no-email-otp]\n");
    exit(2);
}

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

$stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "User '$email' neexistuje.\n");
    exit(3);
}

// API tokeny a MCP připojení: kdo účet ovládl, mohl si přes unesenou session
// vyrazit dlouhodobý token, který by jinak reset faktorů přežil. Nouzový reset
// je reakce na ztrátu kontroly nad účtem, proto se ruší všechny; integrace si
// majitel po obnově vystaví znovu. Reset z administrace je nechává.
$result = $container->get(\MyInvoice\Service\Auth\MfaResetService::class)
    ->reset((int) $user['id'], true);
$wasEnabled = $result['totp_was_enabled'] ? 'ano' : 'ne';

// Přes ActivityLogger, NE syrovým INSERTem: jen tudy se článek zapečetí do
// hash-chainu (§ 33a). Syrový zápis nechával `hash = NULL`, takže nejcitlivější
// administrativní operace v systému ležela mimo tamper-evident řetěz a šla
// beze stopy smazat.
//
// `user_id` je tu OBĚŤ resetu (entita, které se to týká), takže kdo reset spustil
// musí být v payloadu — CLI nemá session ani přihlášeného uživatele.
$container->get(\MyInvoice\Service\ActivityLogger::class)->log(
    'auth.mfa_reset',
    (int) $user['id'],
    'user',
    (int) $user['id'],
    $result + [
        'via' => 'cli:reset-mfa',
        'operator_os_user' => cliOperator(),
        'operator_host' => gethostname() ?: null,
    ],
);

echo "✓ MFA reset pro {$user['email']} (id={$user['id']}, TOTP původně aktivní: {$wasEnabled}).\n";
echo "  Odvoláno {$result['passkeys_revoked']} passkeys, {$result['trusted_devices_removed']} důvěryhodných zařízení, "
    . "{$result['login_otps_removed']} e-mailových kódů, {$result['ceremonies_removed']} flow, "
    . "{$result['step_up_proofs_removed']} proofů, {$result['recovery_codes_revoked']} záložních kódů "
    . "a {$result['sessions_invalidated']} session(í).\n";
echo "  Zrušeno {$result['api_tokens_revoked']} API tokenů a {$result['mcp_grants_revoked']} MCP připojení; "
    . "integrace je potřeba po obnově účtu napojit novým tokenem.\n";

// E-mailové OTP zůstává po resetu jediným druhým faktorem — a to je přesně ten
// stav, ve kterém se dá zamknout ven. Buď ho na přání vypni, nebo aspoň řekni,
// že se teď bude chtít kód z pošty; mlčet by znamenalo poslat člověka na
// přihlašovací obrazovku, které nemá čím vyhovět.
$config       = $container->get(\MyInvoice\Infrastructure\Config\Config::class);
$emailOtpOn   = (bool) $config->get('auth.email_otp.enabled', false);

if ($disableEmailOtp) {
    if (!$emailOtpOn) {
        echo "  E-mailové OTP už bylo vypnuté — cfg.local.php nechávám beze změny.\n";
    } else {
        try {
            \MyInvoice\Service\Config\CfgLocalWriter::setKeys(
                \MyInvoice\Service\Config\CfgLocalWriter::resolveTargetDir(\MyInvoice\Bootstrap::rootDir()),
                ['auth.email_otp.enabled' => false],
            );
            echo "  E-mailové OTP VYPNUTO pro celou instalaci (cfg.local.php: auth.email_otp.enabled = false).\n";
            echo "  Až bude odesílání pošty fungovat, vrať ho zpátky na true.\n";
        } catch (\Throwable $e) {
            fwrite(STDERR, '  VAROVÁNÍ: cfg.local.php se nepodařilo zapsat (' . $e->getMessage()
                . "). Vypni auth.email_otp.enabled ručně.\n");
        }
    }
} elseif ($emailOtpOn) {
    echo "\n  POZOR: e-mailové OTP je zapnuté (auth.email_otp.enabled), a protože tenhle účet\n"
        . "  teď nemá TOTP ani passkey, bude po heslu chtít 6místný kód na {$user['email']}.\n"
        . "  Ověř, že instalace umí odeslat poštu — jinak se účet nepřihlásí a záložní kódy\n"
        . "  jsou po resetu taky pryč. Nouzové řešení: spusť reset znovu s --no-email-otp.\n";
}
