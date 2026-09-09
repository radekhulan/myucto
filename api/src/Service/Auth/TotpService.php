<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * RFC 6238 TOTP (Time-based One-Time Password) implementace.
 * Kompatibilní s Google Authenticator, Authy, 1Password, Bitwarden, Microsoft Authenticator.
 *
 * Algoritmus: HMAC-SHA1, 6 číslic, 30s perioda (defaults — to co aplikace používají).
 *
 * Použití:
 *   $secret = TotpService::generateSecret();              // base32 string, ulož do DB
 *   $uri = $svc->provisioningUri($secret, 'me@x', 'MyInvoice'); // pro QR
 *   $svc->verify($secret, '123456');                      // true/false (s ±1 window pro skew)
 */
final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALGO = 'sha1';

    /** Base32 abeceda (RFC 4648). */
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Vygeneruje 160bitový (20B) náhodný secret zakódovaný do base32 (32 znaků).
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Ověří 6místný kód proti aktuálnímu času ± window slotů (default 1 = ±30s pro skew).
     */
    public function verify(string $base32Secret, string $code, int $window = 1): bool
    {
        return $this->matchingStep($base32Secret, $code, $window) !== null;
    }

    public function verifyAndConsume(Connection $db, string $base32Secret, string $code): bool
    {
        $step = $this->matchingStep($base32Secret, $code, 1);
        if ($step === null) return false;

        $pdo = $db->pdo();
        $pdo->prepare('DELETE FROM totp_used_steps WHERE time_step < ?')
            ->execute([(int) floor(time() / self::PERIOD) - 2880]);
        try {
            $pdo->prepare('INSERT INTO totp_used_steps (secret_fingerprint, time_step) VALUES (?, ?)')
                ->execute([hash('sha256', strtoupper(rtrim($base32Secret, '='))), $step]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') return false;
            throw $e;
        }
        return true;
    }

    private function matchingStep(string $base32Secret, string $code, int $window): ?int
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) return null;

        $now = (int) floor(time() / self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->generateAt($base32Secret, $now + $offset), $code)) {
                return $now + $offset;
            }
        }
        return null;
    }

    /**
     * Vygeneruje aktuální 6místný TOTP kód (užitečné pro testování).
     */
    public function currentCode(string $base32Secret): string
    {
        return $this->generateAt($base32Secret, (int) floor(time() / self::PERIOD));
    }

    /**
     * Vrátí otpauth:// URI pro QR kód.
     *
     * Kompatibilní formát:
     *   otpauth://totp/Issuer:account?secret=BASE32&issuer=Issuer&algorithm=SHA1&digits=6&period=30
     */
    public function provisioningUri(string $base32Secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $params = http_build_query([
            'secret'    => $base32Secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * RFC 6238 / RFC 4226: HMAC-SHA1(secret, counter) → 31bit truncate → mod 10^digits.
     */
    private function generateAt(string $base32Secret, int $counter): string
    {
        $secret = self::base32Decode($base32Secret);
        $binCounter = pack('N*', 0, $counter); // 64bit big-endian
        $hash = hash_hmac(self::ALGO, $binCounter, $secret, true);

        // Dynamic truncation
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $code = ((ord($hash[$offset])     & 0x7F) << 24)
              | ((ord($hash[$offset + 1]) & 0xFF) << 16)
              | ((ord($hash[$offset + 2]) & 0xFF) << 8)
              |  (ord($hash[$offset + 3]) & 0xFF);

        $modulo = 10 ** self::DIGITS;
        return str_pad((string) ($code % $modulo), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::B32[bindec($chunk)];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $bits = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $idx = strpos(self::B32, $b32[$i]);
            if ($idx === false) {
                throw new \InvalidArgumentException("Neplatný base32 znak: {$b32[$i]}");
            }
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }
        return $out;
    }
}
