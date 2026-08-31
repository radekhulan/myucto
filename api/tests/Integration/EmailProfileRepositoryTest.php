<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailProfileRepository;
use MyInvoice\Repository\SigningProfileRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class EmailProfileRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EmailProfileRepository $profiles;
    private SigningProfileRepository $signingProfiles;
    private int $supplierId;
    private ?int $userId = null;
    /** @var list<int> */
    private array $createdEmailProfiles = [];
    /** @var list<int> */
    private array $createdSigningProfiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }

        try {
            $app = Bootstrap::buildApp();
            $container = $app->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->pdo = $container->get(Connection::class)->pdo();
            $this->profiles = $container->get(EmailProfileRepository::class);
            $this->signingProfiles = $container->get(SigningProfileRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $references = $this->pdo->query(
            'SELECT
                (SELECT id FROM countries ORDER BY id LIMIT 1) AS country_id,
                (SELECT id FROM currencies ORDER BY id LIMIT 1) AS currency_id,
                (SELECT id FROM vat_rates ORDER BY id LIMIT 1) AS vat_rate_id'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($references)
            || (int) ($references['country_id'] ?? 0) <= 0
            || (int) ($references['currency_id'] ?? 0) <= 0
            || (int) ($references['vat_rate_id'] ?? 0) <= 0
        ) {
            $this->markTestSkipped('Missing supplier codebook prerequisites');
        }
        $supplier = $this->pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email,
                 default_currency_id, default_vat_rate_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $supplier->execute([
            'Synthetic Email Profile Test ' . bin2hex(random_bytes(4)),
            'Testovací 1',
            'Praha',
            '11000',
            (int) $references['country_id'],
            'email-profile-' . bin2hex(random_bytes(8)) . '@example.invalid',
            (int) $references['currency_id'],
            (int) $references['vat_rate_id'],
        ]);
        $this->supplierId = (int) $this->pdo->lastInsertId();

        $uid = $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $this->userId = $uid !== false && $uid !== null ? (int) $uid : null;
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        foreach ($this->createdEmailProfiles as $profileId) {
            $this->pdo->prepare('DELETE FROM email_profiles WHERE id = ?')->execute([$profileId]);
        }
        foreach ($this->createdSigningProfiles as $profileId) {
            $this->pdo->prepare('DELETE FROM signing_profiles WHERE id = ?')->execute([$profileId]);
        }
        if (isset($this->supplierId) && $this->supplierId > 0) {
            $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
    }

    public function testCreateUpdateAndDefaultProfile(): void
    {
        $firstId = $this->profiles->createProfile($this->supplierId, [
            'name' => 'Integration invoices',
            'code' => 'itest_email_' . bin2hex(random_bytes(4)),
            'from_email' => 'Invoices@Example.test',
            'from_name' => 'Invoices',
        ], $this->userId);
        $this->createdEmailProfiles[] = $firstId;

        $first = $this->profiles->findProfile($this->supplierId, $firstId);
        self::assertNotNull($first);
        self::assertTrue($first['is_default']);
        self::assertSame('invoices@example.test', $first['from_email']);
        self::assertFalse($first['reply_to_enabled']);
        self::assertSame($firstId, $this->profiles->defaultProfile($this->supplierId)['id'] ?? null);

        $secondId = $this->profiles->createProfile($this->supplierId, [
            'name' => 'Integration accounting',
            'code' => 'itest_accounting_' . bin2hex(random_bytes(4)),
            'from_email' => 'accounting@example.test',
            'from_name' => 'Accounting',
            'reply_to_email' => 'reply@example.test',
            'is_default' => true,
        ], $this->userId);
        $this->createdEmailProfiles[] = $secondId;

        self::assertSame($secondId, $this->profiles->defaultProfile($this->supplierId)['id'] ?? null);
        self::assertTrue($this->profiles->findProfile($this->supplierId, $secondId)['reply_to_enabled'] ?? false);
        self::assertFalse($this->profiles->findProfile($this->supplierId, $firstId)['is_default'] ?? true);

        $this->profiles->updateProfile($this->supplierId, $firstId, [
            'is_default' => true,
            'dkim_domain' => 'example.test',
            'dkim_selector' => 'myinvoice',
        ]);

        $updatedFirst = $this->profiles->findProfile($this->supplierId, $firstId);
        self::assertTrue($updatedFirst['is_default'] ?? false);
        self::assertSame('example.test', $updatedFirst['dkim_domain'] ?? null);
        self::assertSame('myinvoice', $updatedFirst['dkim_selector'] ?? null);
        self::assertTrue($updatedFirst['dkim_enabled'] ?? false);
        self::assertFalse($this->profiles->findProfile($this->supplierId, $secondId)['is_default'] ?? true);

        $this->profiles->updateProfile($this->supplierId, $firstId, [
            'dkim_enabled' => false,
            'dkim_domain' => 'ignored.example.test',
            'dkim_selector' => 'ignored',
        ]);
        $disabledDkim = $this->profiles->findProfile($this->supplierId, $firstId);
        self::assertFalse($disabledDkim['dkim_enabled'] ?? true);
        self::assertArrayHasKey('dkim_domain', $disabledDkim);
        self::assertArrayHasKey('dkim_selector', $disabledDkim);
        self::assertNull($disabledDkim['dkim_domain']);
        self::assertNull($disabledDkim['dkim_selector']);

        $this->profiles->updateProfile($this->supplierId, $secondId, [
            'reply_to_enabled' => false,
            'reply_to_email' => 'ignored@example.test',
            'reply_to_name' => 'Ignored',
        ]);
        $disabledReplyTo = $this->profiles->findProfile($this->supplierId, $secondId);
        self::assertFalse($disabledReplyTo['reply_to_enabled'] ?? true);
        self::assertArrayHasKey('reply_to_email', $disabledReplyTo);
        self::assertArrayHasKey('reply_to_name', $disabledReplyTo);
        self::assertNull($disabledReplyTo['reply_to_email']);
        self::assertNull($disabledReplyTo['reply_to_name']);
    }

    public function testCanAttachAdminEmailSmimeSigningProfile(): void
    {
        $signingProfileId = $this->signingProfiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: null,
            name: 'Integration S/MIME',
            code: 'itest_smime_' . bin2hex(random_bytes(4)),
            allowedUsages: ['email_smime'],
            defaultBackend: 'native',
            createdBy: $this->userId,
        );
        $this->createdSigningProfiles[] = $signingProfileId;

        $profileId = $this->profiles->createProfile($this->supplierId, [
            'name' => 'Integration signed',
            'code' => 'itest_signed_' . bin2hex(random_bytes(4)),
            'from_email' => 'signed@example.test',
            'signing_profile_id' => $signingProfileId,
        ], $this->userId);
        $this->createdEmailProfiles[] = $profileId;

        $profile = $this->profiles->findProfile($this->supplierId, $profileId);
        self::assertNotNull($profile);
        self::assertSame($signingProfileId, $profile['signing_profile_id']);
        self::assertSame('Integration S/MIME', $profile['signing_profile_name']);
    }

    public function testSoftDeleteFreesLongCodeForReuse(): void
    {
        $prefix = 'itestdel' . bin2hex(random_bytes(4));
        $code = $prefix . str_repeat('x', 80 - strlen($prefix));

        $profileId = $this->profiles->createProfile($this->supplierId, [
            'name' => 'Delete long code',
            'code' => $code,
            'from_email' => 'delete-long@example.test',
        ], $this->userId);
        $this->createdEmailProfiles[] = $profileId;

        self::assertTrue($this->profiles->softDeleteProfile($this->supplierId, $profileId));
        $deleted = $this->profiles->findProfile($this->supplierId, $profileId, true);
        self::assertNotNull($deleted);
        self::assertNotSame($code, $deleted['code']);
        self::assertLessThanOrEqual(80, strlen((string) $deleted['code']));

        $replacementId = $this->profiles->createProfile($this->supplierId, [
            'name' => 'Replacement long code',
            'code' => $code,
            'from_email' => 'replacement-long@example.test',
        ], $this->userId);
        $this->createdEmailProfiles[] = $replacementId;

        self::assertSame($code, $this->profiles->findProfile($this->supplierId, $replacementId)['code'] ?? null);
    }

    public function testSmtpTransportStoresSecretOnlyForInternalUse(): void
    {
        $profileId = $this->profiles->createProfile($this->supplierId, [
            'name' => 'SMTP transport',
            'code' => 'itest_smtp_' . bin2hex(random_bytes(4)),
            'from_email' => 'smtp-profile@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_auth_enabled' => true,
            'smtp_auth_type' => 'LOGIN',
            'smtp_username' => 'smtp-user',
            'smtp_password' => 'smtp-secret',
            'smtp_verify_peer' => false,
            'smtp_verify_peer_name' => false,
            'smtp_allow_self_signed' => true,
            'smtp_timeout' => 45,
            'smtp_keepalive' => true,
        ], $this->userId);
        $this->createdEmailProfiles[] = $profileId;

        $public = $this->profiles->findProfile($this->supplierId, $profileId);
        self::assertNotNull($public);
        self::assertSame('smtp', $public['transport_type']);
        self::assertSame('smtp.example.test', $public['smtp_host']);
        self::assertSame('LOGIN', $public['smtp_auth_type']);
        self::assertFalse($public['smtp_verify_peer']);
        self::assertFalse($public['smtp_verify_peer_name']);
        self::assertTrue($public['smtp_allow_self_signed']);
        self::assertSame(45, $public['smtp_timeout']);
        self::assertTrue($public['smtp_keepalive']);
        self::assertTrue($public['has_smtp_password']);
        self::assertArrayNotHasKey('smtp_password', $public);

        $internal = $this->profiles->findProfile($this->supplierId, $profileId, false, true);
        self::assertNotNull($internal);
        self::assertSame('smtp-secret', $internal['smtp_password'] ?? null);
    }

    public function testImapSentSettingsStoreSecretOnlyForInternalUse(): void
    {
        $profileId = $this->profiles->createProfile($this->supplierId, [
            'name' => 'IMAP Sent profile',
            'code' => 'itest_imap_sent_' . bin2hex(random_bytes(4)),
            'from_email' => 'imap-sent@example.test',
            'imap_sent_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_validate_cert' => false,
            'imap_username' => 'imap-user',
            'imap_password' => 'imap-secret',
            'imap_folder' => 'Sent Items',
            'imap_create_folder' => true,
            'imap_mark_seen' => false,
            'imap_timeout' => 45,
            'imap_on_failure' => 'fail_send',
        ], $this->userId);
        $this->createdEmailProfiles[] = $profileId;

        $public = $this->profiles->findProfile($this->supplierId, $profileId);
        self::assertNotNull($public);
        self::assertTrue($public['imap_sent_enabled']);
        self::assertSame('imap.example.test', $public['imap_host']);
        self::assertSame(993, $public['imap_port']);
        self::assertSame('ssl', $public['imap_encryption']);
        self::assertFalse($public['imap_validate_cert']);
        self::assertSame('imap-user', $public['imap_username']);
        self::assertSame('Sent Items', $public['imap_folder']);
        self::assertTrue($public['imap_create_folder']);
        self::assertFalse($public['imap_mark_seen']);
        self::assertSame(45, $public['imap_timeout']);
        self::assertSame('fail_send', $public['imap_on_failure']);
        self::assertTrue($public['has_imap_password']);
        self::assertArrayNotHasKey('imap_password', $public);

        $internal = $this->profiles->findProfile($this->supplierId, $profileId, false, true);
        self::assertNotNull($internal);
        self::assertSame('imap-secret', $internal['imap_password'] ?? null);
    }

    public function testDraftTestProfileDoesNotPersistAndCanReuseStoredSecret(): void
    {
        $profileId = $this->profiles->createProfile($this->supplierId, [
            'name' => 'Draft SMTP transport',
            'code' => 'itest_draft_base_' . bin2hex(random_bytes(4)),
            'from_email' => 'draft-base@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'smtp-base.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'base-user',
            'smtp_password' => 'stored-secret',
            'imap_sent_enabled' => true,
            'imap_host' => 'imap-base.example.test',
            'imap_username' => 'base-imap-user',
            'imap_password' => 'stored-imap-secret',
            'imap_folder' => 'Sent',
        ], $this->userId);
        $this->createdEmailProfiles[] = $profileId;

        $draftCode = 'itest_draft_test_' . bin2hex(random_bytes(4));
        $draft = $this->profiles->profileForDraftTest($this->supplierId, [
            'name' => 'Draft changed',
            'code' => $draftCode,
            'from_email' => 'draft-changed@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'smtp-draft.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'draft-user',
            'smtp_password' => '',
            'imap_sent_enabled' => true,
            'imap_host' => 'imap-draft.example.test',
            'imap_username' => 'draft-imap-user',
            'imap_password' => '',
            'imap_folder' => 'Sent Items',
        ], $profileId);

        self::assertSame($profileId, $draft['id']);
        self::assertSame('draft-changed@example.test', $draft['from_email']);
        self::assertSame('smtp-draft.example.test', $draft['smtp_host']);
        self::assertSame('draft-user', $draft['smtp_username']);
        self::assertSame('stored-secret', $draft['smtp_password']);
        self::assertTrue($draft['imap_sent_enabled']);
        self::assertSame('imap-draft.example.test', $draft['imap_host']);
        self::assertSame('draft-imap-user', $draft['imap_username']);
        self::assertSame('stored-imap-secret', $draft['imap_password']);
        self::assertSame('Sent Items', $draft['imap_folder']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM email_profiles WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$this->supplierId, $draftCode]);
        self::assertSame(0, (int) $stmt->fetchColumn());

        $stored = $this->profiles->findProfile($this->supplierId, $profileId);
        self::assertSame('draft-base@example.test', $stored['from_email'] ?? null);
        self::assertSame('smtp-base.example.test', $stored['smtp_host'] ?? null);
        self::assertSame('imap-base.example.test', $stored['imap_host'] ?? null);
    }

    /**
     * Klientská role si nesmí uložené heslo přesměrovat na vlastní server —
     * ani zápisem, ani testovacím odesláním, ani IMAP sondou.
     */
    public function testStrictSecretReuseRefusesToForwardStoredPasswordToAnotherServer(): void
    {
        $profileId = $this->profiles->createProfile($this->supplierId, [
            'name' => 'Strict reuse base',
            'code' => 'itest_strict_reuse_' . bin2hex(random_bytes(4)),
            'from_email' => 'strict-base@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'smtp-base.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'base-user',
            'smtp_password' => 'stored-secret',
            'imap_sent_enabled' => true,
            'imap_host' => 'imap-base.example.test',
            'imap_username' => 'base-imap-user',
            'imap_password' => 'stored-imap-secret',
            'imap_folder' => 'Sent',
        ], $this->userId);
        $this->createdEmailProfiles[] = $profileId;

        $draftPayload = [
            'name' => 'Strict reuse draft',
            'code' => 'itest_strict_draft_' . bin2hex(random_bytes(4)),
            'from_email' => 'strict-draft@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'attacker.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'base-user',
            'smtp_password' => '',
        ];

        try {
            $this->profiles->profileForDraftTest($this->supplierId, $draftPayload, $profileId, true);
            self::fail('Přesměrovaný testovací profil měl být odmítnut.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('smtp_password', $e->getMessage());
        }

        try {
            $this->profiles->imapProbeSettingsForDraft($this->supplierId, [
                'imap_host' => 'attacker.example.test',
                'imap_username' => 'base-imap-user',
                'imap_password' => '',
                'imap_folder' => 'Sent',
            ], $profileId, true);
            self::fail('Přesměrovaná IMAP sonda měla být odmítnuta.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('imap_password', $e->getMessage());
        }

        try {
            $this->profiles->updateProfile($this->supplierId, $profileId, [
                'smtp_host' => 'attacker.example.test',
                'smtp_password' => '',
            ], true);
            self::fail('Přesměrovaný zápis profilu měl být odmítnut.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('smtp_password', $e->getMessage());
        }

        $stored = $this->profiles->findProfile($this->supplierId, $profileId);
        self::assertSame('smtp-base.example.test', $stored['smtp_host'] ?? null);
    }

    public function testStrictSecretReuseKeepsWorkingWhenTheTargetIsUnchanged(): void
    {
        $profileId = $this->profiles->createProfile($this->supplierId, [
            'name' => 'Strict reuse unchanged',
            'code' => 'itest_strict_same_' . bin2hex(random_bytes(4)),
            'from_email' => 'strict-same@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'smtp-base.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'base-user',
            'smtp_password' => 'stored-secret',
        ], $this->userId);
        $this->createdEmailProfiles[] = $profileId;

        $draft = $this->profiles->profileForDraftTest($this->supplierId, [
            'name' => 'Strict reuse unchanged draft',
            'code' => 'itest_strict_same_draft_' . bin2hex(random_bytes(4)),
            'from_email' => 'strict-same-draft@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'smtp-base.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'base-user',
            'smtp_password' => '',
        ], $profileId, true);

        self::assertSame('stored-secret', $draft['smtp_password']);
        self::assertSame('strict-same-draft@example.test', $draft['from_email']);
    }

    public function testImapProbeSettingsCanReuseStoredSecretWithoutWholeProfile(): void
    {
        $profileId = $this->profiles->createProfile($this->supplierId, [
            'name' => 'IMAP browse base',
            'code' => 'itest_imap_browse_' . bin2hex(random_bytes(4)),
            'from_email' => 'imap-browse@example.test',
            'imap_sent_enabled' => true,
            'imap_host' => 'imap-base.example.test',
            'imap_username' => 'base-user',
            'imap_password' => 'stored-imap-secret',
            'imap_folder' => 'Sent',
        ], $this->userId);
        $this->createdEmailProfiles[] = $profileId;

        $settings = $this->profiles->imapProbeSettingsForDraft($this->supplierId, [
            'imap_host' => 'imap-draft.example.test',
            'imap_port' => 143,
            'imap_encryption' => 'tls',
            'imap_validate_cert' => false,
            'imap_username' => 'draft-user',
            'imap_password' => '',
            'imap_folder' => 'Sent Items',
            'imap_mark_seen' => false,
            'imap_timeout' => 60,
            'imap_on_failure' => 'fail_send',
        ], $profileId);

        self::assertTrue($settings['imap_sent_enabled']);
        self::assertSame('imap-draft.example.test', $settings['imap_host']);
        self::assertSame(143, $settings['imap_port']);
        self::assertSame('tls', $settings['imap_encryption']);
        self::assertFalse($settings['imap_validate_cert']);
        self::assertSame('draft-user', $settings['imap_username']);
        self::assertSame('stored-imap-secret', $settings['imap_password']);
        self::assertSame('Sent Items', $settings['imap_folder']);
        self::assertFalse($settings['imap_mark_seen']);
        self::assertSame(60, $settings['imap_timeout']);
        self::assertSame('fail_send', $settings['imap_on_failure']);
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->createProfile($this->supplierId, [
            'name' => 'Invalid',
            'code' => 'itest_invalid_' . bin2hex(random_bytes(4)),
            'from_email' => 'not-an-email',
        ], $this->userId);
    }

    public function testRejectsInactiveDefault(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->createProfile($this->supplierId, [
            'name' => 'Inactive default',
            'code' => 'itest_inactive_' . bin2hex(random_bytes(4)),
            'from_email' => 'inactive@example.test',
            'is_default' => true,
            'is_active' => false,
        ], $this->userId);
    }

    public function testRejectsEnabledReplyToWithoutEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->createProfile($this->supplierId, [
            'name' => 'Invalid reply to',
            'code' => 'itest_reply_' . bin2hex(random_bytes(4)),
            'from_email' => 'reply-to-required@example.test',
            'reply_to_enabled' => true,
            'reply_to_name' => 'Reply',
        ], $this->userId);
    }

    public function testRejectsEnabledDkimWithoutCompleteIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->createProfile($this->supplierId, [
            'name' => 'Invalid DKIM',
            'code' => 'itest_dkim_' . bin2hex(random_bytes(4)),
            'from_email' => 'dkim-required@example.test',
            'dkim_enabled' => true,
            'dkim_domain' => 'example.test',
        ], $this->userId);
    }

    public function testRejectsEnabledSmtpAuthWithoutPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->createProfile($this->supplierId, [
            'name' => 'Invalid SMTP auth',
            'code' => 'itest_smtp_invalid_' . bin2hex(random_bytes(4)),
            'from_email' => 'smtp-invalid@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'smtp.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'smtp-user',
        ], $this->userId);
    }

    public function testRejectsEnabledImapSentWithoutPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->createProfile($this->supplierId, [
            'name' => 'Invalid IMAP Sent',
            'code' => 'itest_imap_invalid_' . bin2hex(random_bytes(4)),
            'from_email' => 'imap-invalid@example.test',
            'imap_sent_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_username' => 'imap-user',
            'imap_folder' => 'Sent',
        ], $this->userId);
    }
}
