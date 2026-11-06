<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Epo\EpoSigningCredentialService;
use MyInvoice\Service\Signing\CertificateVaultSupplierSharing;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Účetní se padesáti firmami nahraje certifikát jednou a povolí ho i ve
 * zbývajících firmách. Hlídá se hlavně to, KAM se povolení dostane: jen do firem,
 * kde je uživatel členem a má tam stejné oprávnění, jaké hlídá stránka
 * (`settings.signing` pro zápis). Cizí firma nesmí dostat nic, ani když jde
 * o superadmina.
 */
#[Group('integration')]
final class CertificateVaultSupplierSharingTest extends TestCase
{
    use IsolatedSupplierTrait;
    use OpensslConfigTrait;

    private const PASSPHRASE = 'SYNTETICKE-HESLO-K-PFX-7';

    private Connection $db;
    private EpoSigningCredentialRepository $vault;
    private EpoSigningCredentialService $credentialService;
    private CertificateVaultSupplierSharing $sharing;
    private int $userId;
    private int $signerRole;
    private int $source;
    private int $permitted;
    private int $readOnly;
    private int $noSigning;
    private int $alreadyCovered;
    private int $foreign;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('epo_signing_credential_suppliers')) {
            $this->markTestSkipped('Migrace 1142 neproběhla.');
        }
        if ($container->get(SecretEncryption::class)->validateKey() !== null) {
            $this->markTestSkipped('Šifrovací klíč není nastaven.');
        }
        $this->vault = $container->get(EpoSigningCredentialRepository::class);
        $this->credentialService = $container->get(EpoSigningCredentialService::class);
        $this->sharing = $container->get(CertificateVaultSupplierSharing::class);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->signerRole = $this->role(['settings.signing' => AccessLevel::WRITE]);
        $readRole = $this->role(['settings.signing' => AccessLevel::READ]);
        $noSigningRole = $this->role(['invoices' => AccessLevel::WRITE]);
        $pdo->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, 'disabled-test-password', 'Synthetic signer', ?, 'cs', 1)"
        )->execute(['cert-sharing-' . bin2hex(random_bytes(6)) . '@example.invalid', $this->signerRole]);
        $this->userId = (int) $pdo->lastInsertId();

        $template = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->source = $this->createIsolatedSupplier($pdo, $template);
        $this->permitted = $this->createIsolatedSupplier($pdo, $template);
        $this->readOnly = $this->createIsolatedSupplier($pdo, $template);
        $this->noSigning = $this->createIsolatedSupplier($pdo, $template);
        $this->alreadyCovered = $this->createIsolatedSupplier($pdo, $template);
        $this->foreign = $this->createIsolatedSupplier($pdo, $template);

        $this->assign($this->source, null);
        $this->assign($this->permitted, null);
        $this->assign($this->readOnly, $readRole);
        $this->assign($this->noSigning, $noSigningRole);
        $this->assign($this->alreadyCovered, null);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testOnlyMemberCompaniesWithSigningWritePermissionReceiveAccess(): void
    {
        $credentialId = $this->uploadIntoSource();

        $results = $this->sharing->shareWithOtherSuppliers(
            $this->request(),
            $credentialId,
            $this->userId,
            $this->source,
            false,
        );

        self::assertSame([
            $this->permitted => CertificateVaultSupplierSharing::STATUS_ENABLED,
            $this->readOnly => CertificateVaultSupplierSharing::STATUS_SKIPPED_NO_PERMISSION,
            $this->noSigning => CertificateVaultSupplierSharing::STATUS_SKIPPED_NO_PERMISSION,
            $this->alreadyCovered => CertificateVaultSupplierSharing::STATUS_ENABLED,
        ], $this->statuses($results));

        self::assertTrue($this->vault->isEnabledForSupplier($credentialId, $this->permitted));
        self::assertTrue($this->vault->isEnabledForSupplier($credentialId, $this->alreadyCovered));
        self::assertFalse($this->vault->isEnabledForSupplier($credentialId, $this->readOnly));
        self::assertFalse($this->vault->isEnabledForSupplier($credentialId, $this->noSigning));
        self::assertFalse($this->vault->isEnabledForSupplier($credentialId, $this->foreign));
        self::assertSame(0, $this->mappingCount($this->foreign));
    }

    /** Ani superadmin se hromadnou akcí nedostane do firmy, kde není členem. */
    public function testSuperadminIsNotWidenedBeyondMemberships(): void
    {
        $credentialId = $this->uploadIntoSource();

        $results = $this->sharing->shareWithOtherSuppliers(
            $this->request(true),
            $credentialId,
            $this->userId,
            $this->source,
            false,
        );

        self::assertNotContains($this->foreign, array_column($results, 'supplier_id'));
        self::assertFalse($this->vault->isEnabledForSupplier($credentialId, $this->foreign));
        self::assertSame(0, $this->mappingCount($this->foreign));
    }

    /**
     * Superadmin bez jediného členství vidí v přepínači všechny firmy. Kdyby se
     * cíle braly jen z `user_suppliers`, hromadné povolení by u něj nedělalo nic.
     */
    public function testSuperadminWithoutMembershipsReachesAllCompanies(): void
    {
        $credentialId = $this->uploadIntoSource();
        $this->db->pdo()->prepare('DELETE FROM user_suppliers WHERE user_id = ?')->execute([$this->userId]);

        $statuses = $this->statuses($this->sharing->shareWithOtherSuppliers(
            $this->request(true),
            $credentialId,
            $this->userId,
            $this->source,
            false,
        ));

        self::assertArrayNotHasKey($this->source, $statuses);
        self::assertSame(CertificateVaultSupplierSharing::STATUS_ENABLED, $statuses[$this->foreign]);
        self::assertSame(CertificateVaultSupplierSharing::STATUS_ENABLED, $statuses[$this->permitted]);
        self::assertTrue($this->vault->isEnabledForSupplier($credentialId, $this->foreign));
    }

    /** Běžný uživatel bez členství nedostane nic, ani přes hromadnou akci. */
    public function testNonSuperadminWithoutMembershipsReachesNothing(): void
    {
        $credentialId = $this->uploadIntoSource();
        $this->db->pdo()->prepare('DELETE FROM user_suppliers WHERE user_id = ?')->execute([$this->userId]);

        $results = $this->sharing->shareWithOtherSuppliers(
            $this->request(),
            $credentialId,
            $this->userId,
            $this->source,
            false,
        );

        self::assertSame([], $results);
        self::assertSame(0, $this->mappingCount($this->foreign));
    }

    /**
     * Nový certifikát nahradí starý všude, kde ho firma používala: v datové
     * schránce, podpisovém profilu i volbě pro mzdy. Zapíše se i do firmy, kde už
     * platný certifikát je, a i do firmy, ze které se akce spouští.
     */
    public function testReplaceRebindsEveryUsageAndEnablesEverywhere(): void
    {
        $old = $this->uploadIntoSource();
        $this->vault->setSupplierEnabled($old, $this->userId, $this->permitted, true, $this->userId);
        $this->vault->setSupplierEnabled($old, $this->userId, $this->alreadyCovered, true, $this->userId);
        $new = $this->uploadIntoSource();
        $pdo = $this->db->pdo();

        $pdo->prepare(
            "INSERT INTO submission_channel_credentials
                (supplier_id, environment, channel, label, box_id, credential_id, last_verified_at)
             VALUES (?, 'production', 'isds', 'Syntetická schránka', 'abc1234', ?, NOW())"
        )->execute([$this->permitted, $old]);
        $pdo->prepare(
            "INSERT INTO payroll_submission_signing_profiles
                (supplier_id, environment, credential_id, owner_user_id, cssz_registered_serial)
             VALUES (?, 'production', ?, ?, '0A')"
        )->execute([$this->source, $old, $this->userId]);
        $pdo->prepare(
            "INSERT INTO signing_profiles (supplier_id, owner_user_id, name, code, allowed_usages_json)
             VALUES (?, ?, 'Syntetický profil', ?, '[]')"
        )->execute([$this->permitted, $this->userId, 'synthetic-' . bin2hex(random_bytes(4))]);
        $profileId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO signing_credentials (profile_id, vault_credential_id, certificate_fingerprint)
             VALUES (?, ?, 'stary-otisk')"
        )->execute([$profileId, $old]);

        $statuses = $this->statuses($this->sharing->replaceInSuppliers(
            $this->request(),
            $new,
            $old,
            $this->userId,
            $this->source,
        ));

        self::assertSame(CertificateVaultSupplierSharing::STATUS_REPLACED, $statuses[$this->source]);
        self::assertSame(CertificateVaultSupplierSharing::STATUS_REPLACED, $statuses[$this->permitted]);
        self::assertSame(CertificateVaultSupplierSharing::STATUS_ENABLED, $statuses[$this->alreadyCovered]);
        self::assertSame(CertificateVaultSupplierSharing::STATUS_SKIPPED_NO_PERMISSION, $statuses[$this->readOnly]);
        self::assertTrue($this->vault->isEnabledForSupplier($new, $this->alreadyCovered));
        self::assertFalse($this->vault->isEnabledForSupplier($new, $this->foreign));

        $box = $pdo->prepare('SELECT credential_id, last_verified_at FROM submission_channel_credentials WHERE supplier_id = ?');
        $box->execute([$this->permitted]);
        $boxRow = $box->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($new, (int) $boxRow['credential_id']);
        self::assertNull($boxRow['last_verified_at']);

        $payroll = $pdo->prepare('SELECT credential_id, cssz_registered_serial FROM payroll_submission_signing_profiles WHERE supplier_id = ?');
        $payroll->execute([$this->source]);
        $payrollRow = $payroll->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($new, (int) $payrollRow['credential_id']);
        // Registrace u ČSSZ patří starému certifikátu, náhrada ji nepřepisuje.
        self::assertSame('0A', $payrollRow['cssz_registered_serial']);

        $profile = $pdo->prepare('SELECT vault_credential_id, certificate_fingerprint FROM signing_credentials WHERE profile_id = ?');
        $profile->execute([$profileId]);
        $profileRow = $profile->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($new, (int) $profileRow['vault_credential_id']);
        self::assertNotSame('stary-otisk', $profileRow['certificate_fingerprint']);

        // Starý certifikát už nic nepoužívá, smazat ho jde ručně.
        $usage = $this->vault->usage($old);
        self::assertSame(0, $usage['signing_profiles'] + $usage['data_box_channels'] + $usage['payroll_selections']);
    }

    public function testReplaceRefusesSameOrExpiredCertificate(): void
    {
        $current = $this->uploadIntoSource();
        $expired = $this->vaultRow('2000-01-01 00:00:00', '2001-01-01 00:00:00');

        try {
            $this->sharing->replaceInSuppliers($this->request(), $current, $current, $this->userId, $this->source);
            self::fail('Náhrada sebou samým měla selhat.');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->sharing->replaceInSuppliers($this->request(), $expired, $current, $this->userId, $this->source);
    }

    public function testOnlyWithoutValidCertificateSkipsCompaniesThatAlreadyHaveOne(): void
    {
        $valid = $this->vaultRow('2020-01-01 00:00:00', '2099-01-01 00:00:00');
        $this->vault->setSupplierEnabled($valid, $this->userId, $this->alreadyCovered, true, $this->userId);
        // Prošlý certifikát firmu nekryje, ta má nový dostat.
        $expired = $this->vaultRow('2000-01-01 00:00:00', '2001-01-01 00:00:00');
        $this->vault->setSupplierEnabled($expired, $this->userId, $this->permitted, true, $this->userId);

        $credentialId = $this->uploadIntoSource();
        $results = $this->sharing->shareWithOtherSuppliers(
            $this->request(),
            $credentialId,
            $this->userId,
            $this->source,
            true,
        );

        $statuses = $this->statuses($results);
        self::assertSame(CertificateVaultSupplierSharing::STATUS_SKIPPED_HAS_VALID, $statuses[$this->alreadyCovered]);
        self::assertSame(CertificateVaultSupplierSharing::STATUS_ENABLED, $statuses[$this->permitted]);
        self::assertFalse($this->vault->isEnabledForSupplier($credentialId, $this->alreadyCovered));
        self::assertTrue($this->vault->isEnabledForSupplier($credentialId, $this->permitted));
    }

    /**
     * Povolení NEKOPÍRUJE klíč: v trezoru zůstává jediný zašifrovaný řádek
     * a cílová firma ho odemkne přesně stejnou cestou jako zdrojová.
     */
    public function testKeyMaterialStaysEncryptedOnceAndUnlocksInTargetCompany(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $item = $this->credentialService->import($this->userId, $this->source, 'Syntetický podpis', $pfx, self::PASSPHRASE);
        $credentialId = (int) $item['id'];

        $this->sharing->shareWithOtherSuppliers($this->request(), $credentialId, $this->userId, $this->source, false);

        $stmt = $this->db->pdo()->prepare(
            'SELECT pfx_ciphertext, passphrase_ciphertext FROM epo_signing_credentials WHERE fingerprint_sha256 = ?'
        );
        $stmt->execute([$item['fingerprint_sha256']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertStringStartsWith('enc:v', (string) $rows[0]['pfx_ciphertext']);
        self::assertStringStartsWith('enc:v', (string) $rows[0]['passphrase_ciphertext']);
        self::assertStringNotContainsString(self::PASSPHRASE, (string) $rows[0]['passphrase_ciphertext']);
        self::assertStringNotContainsString(base64_encode($pfx), (string) $rows[0]['pfx_ciphertext']);

        $unlocked = $this->credentialService->unlockForSigning($credentialId, $this->userId, $this->permitted);
        self::assertSame($pfx, $unlocked['pfx']);
        self::assertSame(self::PASSPHRASE, $unlocked['password']);

        $log = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE action = 'certificate_vault_supplier_enabled' AND entity_id = ? AND supplier_id = ?"
        );
        $log->execute([$credentialId, $this->permitted]);
        $payloads = $log->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(1, $payloads);
        self::assertStringNotContainsString(self::PASSPHRASE, (string) $payloads[0]);
        self::assertStringNotContainsString('enc:v', (string) $payloads[0]);
    }

    public function testRepeatedShareReportsAlreadyEnabled(): void
    {
        $credentialId = $this->uploadIntoSource();
        $this->sharing->shareWithOtherSuppliers($this->request(), $credentialId, $this->userId, $this->source, false);

        $statuses = $this->statuses($this->sharing->shareWithOtherSuppliers(
            $this->request(),
            $credentialId,
            $this->userId,
            $this->source,
            false,
        ));

        self::assertSame(CertificateVaultSupplierSharing::STATUS_ALREADY_ENABLED, $statuses[$this->permitted]);
    }

    /** Certifikát jiného uživatele se povolit nedá, ani v jedné firmě. */
    public function testForeignOwnersCredentialIsRefused(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, 'disabled-test-password', 'Synthetic stranger', ?, 'cs', 1)"
        )->execute(['cert-stranger-' . bin2hex(random_bytes(6)) . '@example.invalid', $this->signerRole]);
        $stranger = (int) $pdo->lastInsertId();
        $credentialId = $this->vaultRow('2020-01-01 00:00:00', '2099-01-01 00:00:00', $stranger);

        self::assertNull($this->sharing->shareWithOtherSuppliers(
            $this->request(),
            $credentialId,
            $this->userId,
            $this->source,
            false,
        ));
        self::assertFalse($this->vault->isEnabledForSupplier($credentialId, $this->permitted));
    }

    private function uploadIntoSource(): int
    {
        [$pfx] = $this->syntheticCertificate();
        $item = $this->credentialService->import($this->userId, $this->source, 'Syntetický podpis', $pfx, self::PASSPHRASE);

        return (int) $item['id'];
    }

    private function vaultRow(string $validFrom, string $validTo, ?int $owner = null): int
    {
        return $this->vault->create($owner ?? $this->userId, [
            'label' => 'Syntetický starší certifikát',
            'pfx_ciphertext' => 'enc:v1:synthetic',
            'passphrase_ciphertext' => 'enc:v1:synthetic',
            'fingerprint_sha256' => hash('sha256', 'synthetic-sharing-' . random_bytes(8)),
            'subject_dn' => 'CN=Synthetic Signer',
            'issuer_dn' => 'CN=Synthetic Test CA',
            'serial_hex' => '0A',
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'ik_mpsv_present' => false,
        ]);
    }

    /** @param array<string,AccessLevel> $permissions */
    private function role(array $permissions): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO roles (name, role_type, is_active) VALUES (?, ?, 1)')
            ->execute(['Synthetic signing role ' . bin2hex(random_bytes(3)), 'staff']);
        $id = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach ($permissions as $key => $level) {
            $insert->execute([$id, $key, $level->value]);
        }

        return $id;
    }

    private function assign(int $supplierId, ?int $roleId): void
    {
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$this->userId, $supplierId, $roleId]);
    }

    private function mappingCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM epo_signing_credential_suppliers WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }

    private function request(bool $superadmin = false): Request
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/settings/certificates')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'role_id' => $this->signerRole,
                'is_superadmin' => $superadmin,
                'role_summary' => ['type' => $superadmin ? 'superadmin' : 'staff'],
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /**
     * @param list<array{supplier_id:int,name:string,status:string}>|null $results
     * @return array<int,string>
     */
    private function statuses(?array $results): array
    {
        self::assertNotNull($results);
        $map = [];
        foreach ($results as $row) {
            $map[$row['supplier_id']] = $row['status'];
        }
        ksort($map);

        return $map;
    }

    /** @return array{0:string} */
    private function syntheticCertificate(): array
    {
        $config = self::opensslConfigArgs();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $config);
        self::assertNotFalse($key, self::opensslErrors());
        $csr = openssl_csr_new(['commonName' => 'Synteticky podpis'], $key, ['digest_alg' => 'sha256'] + $config);
        self::assertNotFalse($csr, self::opensslErrors());
        $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256'] + $config);
        self::assertNotFalse($cert, self::opensslErrors());
        $pfx = '';
        self::assertTrue(openssl_pkcs12_export($cert, $pfx, $key, self::PASSPHRASE));

        return [$pfx];
    }
}
