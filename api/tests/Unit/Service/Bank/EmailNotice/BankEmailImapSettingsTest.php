<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;

final class BankEmailImapSettingsTest extends TestCase
{
    private PDO $pdo;
    private BankEmailNoticeRepository $repo;

    protected function setUp(): void
    {
        $pdo = new Sqlite('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("CREATE TABLE bank_email_imap_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL, name TEXT NOT NULL,
            enabled INTEGER, host TEXT, port INTEGER, encryption TEXT, validate_cert INTEGER,
            require_email_auth INTEGER, allow_forwarded INTEGER, ingest_notices INTEGER,
            mark_seen_after_import INTEGER NOT NULL DEFAULT 0,
            ingest_pdf_invoices INTEGER, ingest_pdf_statements INTEGER, distribute_to_suppliers INTEGER,
            distribute_user_id INTEGER, forwarded_from TEXT, email_auth_serv_id TEXT,
            username TEXT, password_enc TEXT, auth_mode TEXT DEFAULT 'password',
            oauth_tenant_id TEXT, oauth_client_id TEXT, oauth_client_secret_enc TEXT,
            folder TEXT, max_messages_per_run INTEGER,
            process_from_date TEXT, success_action TEXT, success_flag TEXT, success_move_folder TEXT,
            failure_action TEXT, failure_flag TEXT, failure_move_folder TEXT, retry_failed INTEGER, max_attempts INTEGER
        )");
        $db = new Connection(new Config([]));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($db, $pdo);
        $this->pdo = $pdo;
        $this->repo = new BankEmailNoticeRepository($db, new SecretEncryption(new Config(['app' => ['pepper' => 'synthetic-test-pepper']])));
    }

    public function testMarkSeenSettingPersistsAndPartialUpdatePreservesIt(): void
    {
        $pdo = $this->pdo;
        $repo = $this->repo;

        self::assertFalse($repo->imapSettings(1)['mark_seen_after_import']);
        $default = $repo->saveImapAccount(1, ['name' => 'Syntetická schránka', 'encryption' => 'ssl']);
        self::assertFalse($default['mark_seen_after_import']);
        $enabled = $repo->saveImapAccount(1, ['mark_seen_after_import' => true, 'encryption' => 'ssl'], $default['id']);
        self::assertTrue($enabled['mark_seen_after_import']);
        self::assertSame(1, (int) $pdo->query('SELECT mark_seen_after_import FROM bank_email_imap_settings')->fetchColumn());
        $renamed = $repo->saveImapAccount(1, ['name' => 'Přejmenovaná schránka', 'encryption' => 'ssl'], $default['id']);
        self::assertTrue($renamed['mark_seen_after_import']);
        $disabled = $repo->saveImapAccount(1, ['mark_seen_after_import' => false, 'encryption' => 'ssl'], $default['id']);
        self::assertFalse($disabled['mark_seen_after_import']);
        self::assertFalse($repo->imapAccount(1, $default['id'])['mark_seen_after_import']);
        self::assertNull($repo->imapAccount(2, $default['id']));

        $newEnabled = $repo->saveImapAccount(1, ['name' => 'Nová zapnutá schránka', 'mark_seen_after_import' => true, 'encryption' => 'ssl']);
        self::assertTrue($newEnabled['mark_seen_after_import']);
    }

    public function testOAuthSecretIsWriteOnlyAndBoundToItsMailbox(): void
    {
        $data = [
            'name' => 'Syntetická schránka',
            'enabled' => true,
            'folder' => 'INBOX.Banka',
            'auth_mode' => 'microsoft365',
            'oauth_tenant_id' => '11111111-1111-4111-8111-111111111111',
            'oauth_client_id' => '22222222-2222-4222-8222-222222222222',
            'oauth_client_secret' => 'synthetic-secret-one',
            'username' => 'shared@example.test',
            'password' => 'obsolete-basic-password',
            'host' => 'external.example.test',
        ];
        $account = $this->repo->saveImapAccount(1, $data);
        $id = $account['id'];
        self::assertSame('outlook.office365.com', $account['host']);
        self::assertTrue($account['has_oauth_client_secret']);
        self::assertFalse($account['has_password']);
        self::assertArrayNotHasKey('oauth_client_secret_enc', $account);
        self::assertArrayNotHasKey('oauth_client_secret', $account);
        self::assertNull($this->repo->imapAccount(2, $id, true));

        $stored = $this->repo->imapAccount(1, $id, true)['oauth_client_secret_enc'];
        self::assertStringStartsWith('enc:v2:', $stored);
        $this->repo->saveImapAccount(1, ['name' => 'Jiné jméno'], $id);
        self::assertSame($stored, $this->repo->imapAccount(1, $id, true)['oauth_client_secret_enc']);

        try {
            $this->repo->saveImapAccount(1, ['username' => 'other@example.test'], $id);
            self::fail('Změna schránky vyžaduje znovu zadat Client Secret.');
        } catch (\InvalidArgumentException) {
            self::assertSame('shared@example.test', $this->repo->imapAccount(1, $id)['username']);
        }

        $rotated = $this->repo->saveImapAccount(1, ['oauth_client_secret' => 'synthetic-secret-two'], $id);
        self::assertTrue($rotated['enabled']);
        self::assertSame('INBOX.Banka', $rotated['folder']);
        self::assertNotSame($stored, $this->repo->imapAccount(1, $id, true)['oauth_client_secret_enc']);
        $basic = $this->repo->saveImapAccount(1, ['auth_mode' => 'password', 'password' => 'synthetic-basic'], $id);
        self::assertTrue($basic['has_password']);
        self::assertFalse($basic['has_oauth_client_secret']);
    }
}
