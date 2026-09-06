<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Action\Admin\ListSentEmailsAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Přehled „Odeslané e-maily" (/admin/emails?tab=sent) není vrstva maileru, ale
 * pohled na `activity_log` filtrovaný whitelistem ListSentEmailsAction::SENT_TO_FAILED.
 * Odesílací cesta je tedy v přehledu vidět jen tehdy, když (a) sama zaloguje
 * dohodnutou akci a (b) ta akce je ve whitelistu. Chyběl-li jeden z kroků,
 * e-mail se tiše odeslal mimo log — přesně to se stalo odkazu na nastavení
 * hesla při založení uživatele.
 *
 * Tenhle test hlídá obě půlky pro e-maily s odkazem na heslo a navíc to, že
 * každý typ z whitelistu umí frontend pojmenovat (mapa EMAIL_TYPES + obě locale).
 */
final class SentEmailLogCoverageTest extends TestCase
{
    /** @return array<string,string> */
    private function whitelist(): array
    {
        /** @var array<string,string> $map */
        $map = (new ReflectionClass(ListSentEmailsAction::class))->getConstant('SENT_TO_FAILED');
        return $map;
    }

    private function source(string $relativeToRepoRoot): string
    {
        $path = __DIR__ . '/../../../' . $relativeToRepoRoot;
        self::assertFileExists($path, "Chybí soubor $relativeToRepoRoot");
        return (string) file_get_contents($path);
    }

    public function testPasswordLinkMailsAreWhitelisted(): void
    {
        $map = $this->whitelist();
        self::assertSame('user.invite_mail_failed', $map['user.password_link_sent'] ?? null);
        self::assertSame('auth.forgot_mail_failed', $map['auth.forgot_sent'] ?? null);
    }

    public function testUserInviteLogsSentActionNextToTheSend(): void
    {
        // Log musí být u samotného odeslání (sendPasswordLink), ne až u jednoho
        // z volajících — jinak druhá cesta (pozvánka při create) v přehledu chybí.
        $src = $this->source('api/src/Action/Admin/UserAdminAction.php');
        $method = strstr($src, 'private function sendPasswordLink');
        self::assertIsString($method, 'sendPasswordLink v UserAdminAction zmizel.');
        self::assertStringContainsString("'user.password_link_sent'", $method);
        self::assertStringContainsString("'user.invite_mail_failed'", $method);
    }

    public function testForgotPasswordLogsRecipient(): void
    {
        // Bez `to` v payloadu zůstane sloupec Příjemce prázdný.
        $src = $this->source('api/src/Action/Auth/ForgotPasswordAction.php');
        self::assertMatchesRegularExpression(
            "/'auth\\.forgot_sent'.*?'to'\\s*=>/s",
            $src,
            'auth.forgot_sent musí logovat příjemce pod klíčem `to`.',
        );
    }

    public function testFrontendKnowsEveryWhitelistedType(): void
    {
        $vue = $this->source('web/src/pages/admin/SentEmails.vue');
        $cs  = json_decode($this->source('web/src/i18n/cs.json'), true);
        $en  = json_decode($this->source('web/src/i18n/en.json'), true);
        self::assertIsArray($cs);
        self::assertIsArray($en);

        foreach (array_keys($this->whitelist()) as $action) {
            self::assertMatchesRegularExpression(
                "/'" . preg_quote($action, '/') . "':\\s*\\{\\s*key:\\s*'([a-z_]+)'/",
                $vue,
                "EMAIL_TYPES v SentEmails.vue nezná akci $action — v přehledu se ukáže syrový název.",
            );
            preg_match("/'" . preg_quote($action, '/') . "':\\s*\\{\\s*key:\\s*'([a-z_]+)'/", $vue, $m);
            $key = $m[1];
            self::assertArrayHasKey($key, $cs['sent_emails']['types'], "Chybí český popisek sent_emails.types.$key");
            self::assertArrayHasKey($key, $en['sent_emails']['types'], "Chybí anglický popisek sent_emails.types.$key");
        }
    }
}
