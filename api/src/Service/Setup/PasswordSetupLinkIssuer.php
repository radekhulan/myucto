<?php

declare(strict_types=1);

namespace MyInvoice\Service\Setup;

/**
 * H-33 — jednorázový odkaz na **nastavení** hesla (ne obnovu) pro nově zřízenou
 * instanci. Mechanika je táž jako u `ForgotPasswordAction` (`password_resets`,
 * `ResetPasswordAction`), liší se dvě věci:
 *
 *  - token se **vrací volajícímu**, e-mail se neposílá (onboarding tak nezávisí
 *    na tom, jestli instanci chodí odchozí pošta),
 *  - ⚠️ platnost je **24 hodin**, ne standardních
 *    {@see \MyInvoice\Action\Auth\ForgotPasswordAction::RESET_TTL_MINUTES} minut.
 *    Kdo objedná večer a otevře poštu ráno, nesmí u prvního dojmu najít mrtvý odkaz.
 *
 * Zákazník žádné heslo neměl, takže se tomu všude říká „nastavení hesla".
 */
final class PasswordSetupLinkIssuer
{
    /** Onboardingová lhůta — vědomě delší než lhůta na obnovu hesla. */
    public const SETUP_TTL_HOURS = 24;

    /**
     * Náhodné heslo pro admina v režimu odkazu. Nikdo ho nikdy nepoužije, jen
     * zaplní `users.password_hash`, než si zákazník nastaví své.
     */
    public function randomPassword(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Založí záznam v `password_resets` a vrátí token v otevřené podobě —
     * v databázi zůstává jen jeho SHA-256.
     *
     * @return array{token:string,expires_at:\DateTimeImmutable}
     */
    public function issue(\PDO $pdo, int $userId, ?string $ip = null, ?\DateTimeImmutable $now = null): array
    {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = ($now ?? new \DateTimeImmutable())
            ->modify(sprintf('+%d hours', self::SETUP_TTL_HOURS));

        // ⚠️ `purpose = 'setup'` není jen popisek. Podle něj `ResetPasswordAction`
        // pozná, že smí rovnou vydat sezení (zákazník právě zakládá účet a nemá
        // se přihlašovat heslem, které si před vteřinou vymyslel). Běžná obnova
        // hesla zůstává `reset` a sezení nedostane — viz migrace 1525.
        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, purpose, expires_at, ip)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            hash('sha256', $token),
            'setup',
            $expiresAt->format('Y-m-d H:i:s'),
            @inet_pton((string) $ip) ?: '',
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Odkaz s onboardingovou lhůtou, ale BEZ setupového sezení.
     *
     * ⚠️ Pro účet, který už existuje a mohl si mezitím zapnout vlastní druhý
     * faktor. `purpose = 'setup'` by mu po nastavení hesla vydal sezení rovnou
     * z odkazu v e-mailu, takže by jeho TOTP na instalaci bez povinného MFA
     * neplatilo. `reset` ho pošle na přihlašovací formulář, kde druhý faktor
     * projde standardní cestou. Delší lhůta zůstává: pozvánku od admina
     * otevře člověk klidně až druhý den.
     *
     * @return array{token:string,expires_at:\DateTimeImmutable}
     */
    public function issueReset(\PDO $pdo, int $userId, ?string $ip = null, ?\DateTimeImmutable $now = null): array
    {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = ($now ?? new \DateTimeImmutable())
            ->modify(sprintf('+%d hours', self::SETUP_TTL_HOURS));

        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, purpose, expires_at, ip)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            hash('sha256', $token),
            'reset',
            $expiresAt->format('Y-m-d H:i:s'),
            @inet_pton((string) $ip) ?: '',
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }
}
