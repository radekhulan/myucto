<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\RateLimitMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Captcha\TurnstileVerifier;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Vždy vrací 204 (i pro neexistující email) → ochrana proti enumeration.
 * Token se generuje jen pokud user existuje, hash se uloží do password_resets.
 */
final class ForgotPasswordAction
{
    /** Kolik nejnovějších reset tokenů necháváme současně platných (viz úklid níže). */
    private const KEEP_VALID_RESETS = 3;

    /**
     * Platnost tokenu na OBNOVU hesla. Onboardingový odkaz na *nastavení* hesla má
     * vlastní, delší lhůtu — viz {@see \MyInvoice\Service\Setup\PasswordSetupLinkIssuer::SETUP_TTL_HOURS}.
     * Odlišnost je záměr: obnovu si uživatel vyžádal teď, uvítací e-mail může
     * otevřít až ráno.
     */
    public const RESET_TTL_MINUTES = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly Mailer $mailer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Config $config,
        private readonly BruteForceGuard $bf,
        private readonly TurnstileVerifier $turnstile,
        private readonly RateLimitMiddleware $rateLimit,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($body['email'] ?? ''));
        $turnstileToken = (string) ($body['cf_turnstile_response'] ?? '');

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $userAgent = $request->getHeaderLine('User-Agent');

        // Rate limit přes BruteForceGuard (sdílený se /login)
        $state = $this->bf->check($email, $ip);
        if (in_array($state, [BruteForceGuard::STATE_LOCKED_15M, BruteForceGuard::STATE_LOCKED_24H], true)) {
            return Json::error($response, 'rate_limited', 'Příliš mnoho pokusů. Zkus to později.', 429);
        }

        // Turnstile vždy aktivní — Cloudflare sám rozhoduje (auto-pass nebo interactive challenge).
        // No-op pokud captcha.provider != 'turnstile' nebo chybí secret_key (TurnstileVerifier).
        if (!$this->turnstile->verify($turnstileToken, $ip, 'forgot')) {
            $this->logger->log('auth.captcha_failed', null, null, null, [
                'email' => $email, 'ip' => $ip, 'flow' => 'forgot',
            ], $ip, $userAgent);
            $this->bf->recordFailure($email, $ip);
            return Json::error($response, 'captcha_failed', 'CAPTCHA selhala.', 400);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Pořád vrátíme 204
            return Json::ok($response, ['ok' => true], 204);
        }

        $stmt = $this->db->pdo()->prepare('SELECT id, email, name, locale FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            // Tichý exit. Per-email bucket tu VĚDOMĚ nekonzumujeme — jinak by
            // enumerace neexistujících adres zaplňovala čítače. Spam na neznámé
            // adresy brzdí per-IP limit v RateLimitMiddleware.
            $this->logger->log('auth.forgot_unknown', null, null, null, ['email' => $email], $ip, $request->getHeaderLine('User-Agent'));
            return Json::ok($response, ['ok' => true], 204);
        }

        // Per-email limit. RateLimitMiddleware ho vybrat neumí — běží před
        // BodyParsingMiddleware, takže na `email` z JSON body nedosáhne (per-IP
        // bucket řeší tam). Tady je tělo zparsované, takže limit konzumujeme sami,
        // atomicky přes stejný čítač (Redis, jinak DB fallback).
        //
        // Konzumujeme AŽ TEĎ, po ověření existence účtu — dřív to bylo před ním,
        // takže spam na neexistující adresy zbytečně žral sloty.
        //
        // Dva buckety, aby cizí spam nezablokoval legitimní obnovu:
        //   1. per (e-mail, /24 odesílatele) — jeden útočný zdroj vyčerpá jen
        //      SVŮJ bucket; oběť ze své vlastní IP má pořád volné sloty,
        //   2. globální per e-mail se štědřejším stropem — pojistka proti
        //      distribuovanému mailbombingu do cizí schránky.
        //
        // Při překročení vracíme STEJNÝCH 204 jako u neznámého účtu, ne 429:
        // 429 by po přesunu za ověření existence bylo orákulum na enumeraci
        // (nastalo by výhradně u existujícího účtu).
        $perEmail = max(1, (int) $this->config->get('rate_limits.forgot_per_hour_per_email', 3));
        $perEmailGlobal = max($perEmail, (int) $this->config->get(
            'rate_limits.forgot_per_hour_per_email_global',
            $perEmail * 5,
        ));
        $emailHash = sha1(strtolower($email));
        $sourceBucket = sha1($emailHash . '|' . $this->rateLimit->ipBucket($ip));

        $throttled = $this->rateLimit->consume('rl:forgot:email-ip:' . $sourceBucket, $perEmail, 3600) !== null
            // Globální strop konzumujeme jen když zdrojový bucket prošel — jinak
            // by jeden zablokovaný útočník vyčerpal i celoemailový limit.
            || $this->rateLimit->consume('rl:forgot:email:' . $emailHash, $perEmailGlobal, 3600) !== null;

        if ($throttled) {
            $this->logger->log('auth.forgot_throttled', (int) $user['id'], 'user', (int) $user['id'], [
                'email' => $email,
            ], $ip, $userAgent);
            return Json::ok($response, ['ok' => true], 204);
        }

        // Vygeneruj token
        $tokenRaw = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $tokenRaw);
        $expiresAt = (new \DateTimeImmutable(sprintf('+%d minutes', self::RESET_TTL_MINUTES)))->format('Y-m-d H:i:s');

        $this->db->pdo()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES (?, ?, ?, ?)'
        )->execute([(int) $user['id'], $tokenHash, $expiresAt, @inet_pton($ip) ?: '']);
        $newResetId = (int) $this->db->pdo()->lastInsertId();

        // Pošli email
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $resetLink = $appUrl . '/reset?token=' . $tokenRaw;

        $sent = true;
        try {
            $this->mailer->sendTemplate(
                'password_reset',
                (string) ($user['locale'] ?? 'cs'),
                [(string) $user['email']],
                [
                    'name'      => $user['name'],
                    'resetLink' => $resetLink,
                    'expiresIn' => self::RESET_TTL_MINUTES . ' minut',
                ],
            );
        } catch (\Throwable $e) {
            // Email se nepovedl, ale uživateli dál tváříme úspěch
            $sent = false;
            $this->logger->log('auth.forgot_mail_failed', (int) $user['id'], 'user', (int) $user['id'], [
                'to' => [(string) $user['email']],
                'error' => $e->getMessage(),
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        // Úklid starých tokenů AŽ TEĎ, ne před odesláním.
        //
        // Dřív se všechny předchozí tokeny rušily hned na začátku requestu, takže
        // kdokoli znalý e-mailu mohl opakovaným voláním /forgot držet cizí účet
        // trvale bez použitelného reset linku (každý request zabil ten předchozí).
        //
        // Teď: (a) když se mail neodeslal, zneplatníme jen ten nový a starý platný
        // link zůstává funkční; (b) když se odeslal, necháme platných N nejnovějších,
        // takže uživateli funguje i link z předchozího e-mailu. Počet je shora
        // omezený per-email limitem (forgot_per_hour_per_email).
        if (!$sent) {
            $this->db->pdo()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
                ->execute([$newResetId]);
        } else {
            // Derived table je kvůli MySQL/MariaDB omezení "can't specify target
            // table for update in FROM clause".
            $this->db->pdo()->prepare(
                'UPDATE password_resets SET used_at = NOW()
                  WHERE user_id = ? AND used_at IS NULL
                    AND id NOT IN (
                        SELECT id FROM (
                            SELECT id FROM password_resets
                             WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()
                             ORDER BY id DESC LIMIT ' . self::KEEP_VALID_RESETS . '
                        ) AS keep
                    )'
            )->execute([(int) $user['id'], (int) $user['id']]);
        }

        // Per-IP limit řeší RateLimitMiddleware, per-email buckety výše.
        // Dříve jsme zde volali recordFailure i při success — matoucí semantika, RateLimit teď pokrývá lépe.
        $this->logger->log('auth.forgot_sent', (int) $user['id'], 'user', (int) $user['id'], [
            'to' => [(string) $user['email']],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ok' => true], 204);
    }
}
