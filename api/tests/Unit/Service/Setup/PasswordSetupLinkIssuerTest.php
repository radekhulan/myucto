<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Setup;

use MyInvoice\Action\Auth\ForgotPasswordAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Setup\PasswordSetupLinkIssuer;
use PHPUnit\Framework\TestCase;

/**
 * H-33 — jednorázový odkaz na nastavení hesla.
 *
 * Běží nad SQLite v paměti: `password_resets` je obyčejná tabulka bez MariaDB
 * specifik, takže se tenhle kus dá ověřit bez sdílené testovací databáze.
 */
final class PasswordSetupLinkIssuerTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $this->pdo->exec(
            'CREATE TABLE password_resets (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                token_hash TEXT NOT NULL,
                purpose    TEXT NOT NULL DEFAULT \'reset\',
                expires_at TEXT NOT NULL,
                used_at    TEXT NULL,
                ip         BLOB NOT NULL
            )'
        );
    }

    public function testOnboardingTokenIsValidForTwentyFourHours(): void
    {
        $now = new \DateTimeImmutable('2026-08-21 19:40:00');

        $issued = (new PasswordSetupLinkIssuer())->issue($this->pdo, 42, '198.51.100.7', $now);

        self::assertSame(
            '2026-08-22 19:40:00',
            $issued['expires_at']->format('Y-m-d H:i:s'),
            'Kdo objedná večer a otevře poštu ráno, nesmí najít mrtvý odkaz.',
        );
        self::assertSame(
            '2026-08-22 19:40:00',
            (string) $this->pdo->query('SELECT expires_at FROM password_resets')->fetchColumn(),
        );
    }

    public function testOnboardingTtlIsDeliberatelyLongerThanPasswordReset(): void
    {
        self::assertSame(24, PasswordSetupLinkIssuer::SETUP_TTL_HOURS);
        self::assertGreaterThan(
            ForgotPasswordAction::RESET_TTL_MINUTES,
            PasswordSetupLinkIssuer::SETUP_TTL_HOURS * 60,
            'Onboardingový odkaz má mít vlastní, delší lhůtu než obnova hesla.',
        );
    }

    /**
     * Onboardingový odkaz se musí od obnovy hesla poznat i v databázi — podle
     * toho `ResetPasswordAction` rozhoduje, jestli smí vydat sezení.
     */
    public function testOnboardingTokenIsMarkedAsSetup(): void
    {
        (new PasswordSetupLinkIssuer())->issue($this->pdo, 42);

        $purpose = $this->pdo->query('SELECT purpose FROM password_resets')->fetchColumn();

        self::assertSame('setup', $purpose);
    }

    /**
     * ⚠️ Pozvánka pro UŽ EXISTUJÍCÍ účet nesmí být `setup`.
     *
     * Setupový token dává po nastavení hesla rovnou sezení. U účtu, který si
     * mezitím sám zapnul TOTP na instalaci bez povinného MFA, by odkaz
     * z e-mailu jeho druhý faktor obešel. Delší onboardingová lhůta zůstává —
     * pozvánku od admina otevře člověk klidně až druhý den.
     */
    public function testResendForAnExistingAccountKeepsTheLongTtlButNotTheSetupSession(): void
    {
        $now = new \DateTimeImmutable('2026-08-21 19:40:00');

        $issued = (new PasswordSetupLinkIssuer())->issueReset($this->pdo, 42, '198.51.100.7', $now);

        $row = $this->pdo->query('SELECT purpose, expires_at FROM password_resets')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('reset', $row['purpose'], 'Odkaz pro existující účet nesmí vydávat sezení.');
        self::assertSame('2026-08-22 19:40:00', $row['expires_at']);
        self::assertSame('2026-08-22 19:40:00', $issued['expires_at']->format('Y-m-d H:i:s'));
        self::assertSame(64, strlen($issued['token']));
        self::assertSame(hash('sha256', $issued['token']), $this->pdo->query('SELECT token_hash FROM password_resets')->fetchColumn());
    }

    public function testOnlyTheHashIsStored(): void
    {
        $issued = (new PasswordSetupLinkIssuer())->issue($this->pdo, 7, '198.51.100.7');

        $row = $this->pdo->query('SELECT user_id, token_hash FROM password_resets')->fetch(\PDO::FETCH_ASSOC);

        self::assertSame(7, (int) $row['user_id']);
        self::assertSame(hash('sha256', $issued['token']), $row['token_hash']);
        self::assertNotSame($issued['token'], $row['token_hash'], 'Token v otevřené podobě nesmí v DB zůstat.');
        self::assertSame(64, strlen($issued['token']));
    }

    public function testTokensDoNotRepeat(): void
    {
        $issuer = new PasswordSetupLinkIssuer();

        $first  = $issuer->issue($this->pdo, 1)['token'];
        $second = $issuer->issue($this->pdo, 1)['token'];

        self::assertNotSame($first, $second);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn());
    }

    public function testRandomPasswordIsAcceptedByTheHasher(): void
    {
        // Heslo nikdo nikdy nepoužije, ale musí projít politikou PasswordHasheru —
        // jinak by setup v režimu odkazu spadl na validaci.
        $hasher = new PasswordHasher(new Config(['db' => ['name' => 'myucto_test']]));
        $issuer = new PasswordSetupLinkIssuer();

        $password = $issuer->randomPassword();

        self::assertNotSame($password, $issuer->randomPassword());
        self::assertTrue($hasher->verify($password, $hasher->hash($password)));
    }
}
