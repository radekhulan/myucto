<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Mail\Mailer;
use Psr\Log\LoggerInterface;

/**
 * E-mailové OTP jako druhý faktor pro uživatele BEZ TOTP.
 *
 * Tok (řídí LoginAction):
 *   1. heslo OK, user nemá totp_enabled, email_otp.enabled=true, není trusted device
 *   2. issue() vygeneruje 6místný kód, uloží sha256 hash do `login_otps` a pošle e-mail
 *   3. uživatel zadá kód → verify()
 *
 * Bezpečnost:
 *   - v DB nikdy plaintext, jen sha256 hash (kód má nízkou entropii, ale krátkou
 *     platnost + per-kód attempt cap + per-user lockout v BruteForceGuard)
 *   - vždy max jeden aktivní kód na uživatele (issue invaliduje předchozí)
 *   - resend má cooldown → ochrana proti spamování e-mailem
 */
final class EmailOtpService
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function ttlMinutes(): int
    {
        return max(1, (int) $this->config->get('auth.email_otp.code_ttl_minutes', 10));
    }

    public function resendCooldownSeconds(): int
    {
        return max(0, (int) $this->config->get('auth.email_otp.resend_cooldown_seconds', 60));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) $this->config->get('auth.email_otp.max_attempts', 5));
    }

    /**
     * Zajistí, že má uživatel platný kód, a podle potřeby pošle e-mail.
     *
     * - Když existuje aktivní (nepoužitý, neexpirovaný) kód a $force=false →
     *   nic neposílá (uživatel ho má v schránce), vrátí sent=false.
     * - Když $force=true (uživatel klikl „poslat znovu") → respektuje cooldown;
     *   po jeho uplynutí vygeneruje nový kód a pošle.
     * - Když žádný aktivní kód není → vygeneruje a pošle.
     *
     * @param array{id:int|string,email:string,name?:string,locale?:string} $user
     * @return array{sent:bool, cooldown_remaining:int}
     */
    public function issue(array $user, string $ip, bool $force = false): array
    {
        $userId = (int) $user['id'];
        $active = $this->activeCode($userId);

        if ($active !== null) {
            $age = time() - (int) $active['created_ts'];
            $cooldownRemaining = max(0, $this->resendCooldownSeconds() - $age);
            // Aktivní kód existuje. Bez force ho znovu neposíláme; s force jen
            // pokud uplynul cooldown (jinak by šlo e-mailem spamovat).
            if (!$force || $cooldownRemaining > 0) {
                return ['sent' => false, 'cooldown_remaining' => $cooldownRemaining];
            }
        }

        // Invaliduj předchozí aktivní kódy — vždy max jeden platný.
        $this->db->pdo()->prepare(
            'UPDATE login_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
        )->execute([$userId]);

        $code = $this->generateCode();
        $codeHash = hash('sha256', $code);
        $expiresAt = (new \DateTimeImmutable('+' . $this->ttlMinutes() . ' minutes'))->format('Y-m-d H:i:s');

        $this->db->pdo()->prepare(
            'INSERT INTO login_otps (user_id, code_hash, expires_at, ip) VALUES (?, ?, ?, ?)'
        )->execute([$userId, $codeHash, $expiresAt, @inet_pton($ip) ?: '']);

        try {
            $this->mailer->sendTemplate(
                'login_otp',
                (string) ($user['locale'] ?? 'cs'),
                [(string) $user['email']],
                [
                    'name'      => $user['name'] ?? '',
                    'code'      => $code,
                    'expiresIn' => $this->ttlMinutes() . ' min',
                ],
            );
        } catch (\Throwable $e) {
            // E-mail selhal — zalogujeme. Uživatel se bez kódu nepřihlásí; to je
            // známé riziko (kill-switch cfg.auth.email_otp.enabled, viz cfg.sample).
            $this->logger->error('login_otp.mail_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }

        return ['sent' => true, 'cooldown_remaining' => $this->resendCooldownSeconds()];
    }

    /**
     * Ověří kód proti poslednímu aktivnímu záznamu uživatele.
     * Při neúspěchu inkrementuje attempts; po překročení max_attempts kód
     * zneplatní (uživatel musí požádat o nový).
     */
    public function verify(int $userId, string $code): bool
    {
        $code = trim($code);
        if ($code === '' || !ctype_digit($code)) {
            return false;
        }

        $active = $this->activeCode($userId);
        if ($active === null) {
            return false;
        }

        if ((int) $active['attempts'] >= $this->maxAttempts()) {
            // Vyčerpané pokusy → invaliduj, ať si vyžádá nový.
            $this->invalidate((int) $active['id']);
            return false;
        }

        if (hash_equals((string) $active['code_hash'], hash('sha256', $code))) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE login_otps SET used_at = NOW()
                  WHERE id = ? AND used_at IS NULL AND expires_at > NOW() AND attempts < ?'
            );
            $stmt->execute([(int) $active['id'], $this->maxAttempts()]);
            return $stmt->rowCount() === 1;
        }

        $this->db->pdo()->prepare(
            'UPDATE login_otps SET attempts = attempts + 1
              WHERE id = ? AND used_at IS NULL AND expires_at > NOW() AND attempts < ?'
        )->execute([(int) $active['id'], $this->maxAttempts()]);
        return false;
    }

    /** Smaže expirované / použité kódy starší než 24 h (volá cron-cleanup). */
    public function pruneExpired(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM login_otps WHERE expires_at < NOW() - INTERVAL 1 DAY OR used_at < NOW() - INTERVAL 1 DAY'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** @return array{id:int,code_hash:string,attempts:int,created_ts:int}|null */
    private function activeCode(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code_hash, attempts, UNIX_TIMESTAMP(created_at) AS created_ts
               FROM login_otps
              WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW() AND attempts < ?
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, $this->maxAttempts()]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function invalidate(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE login_otps SET used_at = NOW() WHERE id = ?')->execute([$id]);
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
