<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Repository\Submission\IsdsGatewayRegistrationRepository;
use MyInvoice\Repository\Submission\SubmissionChannelCredentialRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SharedCertificateResolver;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Jeden certifikát, tři místa — a nahrává se jen jednou.
 *
 * Trezor `epo_signing_credentials` plní Systém → Elektronické podpisy a vybírají
 * z něj mzdová podání. ISDS si dosud držel vlastní kopie ve dvou tabulkách, takže
 * tentýž klíč uživatel nahrával podruhé a při obnově ho měnil na víc místech,
 * aniž by mu kterékoli z nich řeklo, že ta ostatní jsou prošlá (migrace 1711).
 *
 * Tenhle test hlídá obojí: že navázaný řádek vydá TOTÉŽ co vlastní kopie,
 * a že odkaz do prázdna skončí pojmenovanou chybou, ne tichým pádem na nic.
 */
#[Group('integration')]
final class SharedCertificateReuseTest extends TestCase
{
    use IsolatedSupplierTrait;
    use OpensslConfigTrait;

    private const PASSPHRASE = 'TAJNE-HESLO-Z-TREZORU-42';

    private Connection $db;
    private SecretEncryption $crypto;
    private EpoSigningCredentialRepository $vault;
    private SubmissionChannelCredentialRepository $repository;
    private SharedCertificateResolver $resolver;
    private SubmissionCredentialService $service;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;

        $this->repository = new SubmissionChannelCredentialRepository($db);
        if (!$this->repository->isAvailable()) {
            $this->markTestSkipped('Migrace 1381 neproběhla.');
        }
        if (!$db->hasTable('epo_signing_credentials')) {
            $this->markTestSkipped('Migrace 1142 neproběhla.');
        }
        if (!$this->hasCredentialColumn()) {
            $this->markTestSkipped('Migrace 1711 neproběhla.');
        }

        $crypto = $container->get(SecretEncryption::class);
        self::assertInstanceOf(SecretEncryption::class, $crypto);
        if ($crypto->validateKey() !== null) {
            $this->markTestSkipped('Šifrovací klíč není nastaven.');
        }
        $this->crypto = $crypto;
        $this->vault = new EpoSigningCredentialRepository($db);
        $this->resolver = new SharedCertificateResolver($this->vault, $crypto);
        $this->service = new SubmissionCredentialService($this->repository, $crypto, $this->resolver);

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $template = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $template);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $template);
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /**
     * Jádro celého sjednocení: navázaný řádek musí vydat přesně tutéž dvojici
     * jako vlastní kopie. Kdyby se lišil byť tvarem (base64 vs. syrové bajty),
     * projevilo by se to až nesrozumitelným selháním TLS proti ISDS.
     */
    public function testLinkedRowUnlocksTheSameCertificateAsItsOwnCopy(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $credentialId = $this->storeInVault($pfx, 'Sdílený certifikát', $this->supplierId);

        // Referenční hodnota: týž certifikát uložený starou cestou.
        $this->service->save($this->supplierId, 'test', 'Vlastní kopie', 'abcdefg', $pfx, self::PASSPHRASE, $this->userId);
        $ownCopy = $this->service->unlock($this->supplierId, 'test');
        $expectedCertificate = $ownCopy->credentials->certificate?->reveal();
        self::assertSame(base64_encode($pfx), $expectedCertificate);

        // A totéž přes odkaz do trezoru.
        $saved = $this->service->saveFromVault($this->supplierId, 'test', 'Z trezoru', 'abcdefg', $credentialId, $this->userId);
        self::assertSame($credentialId, $saved['credential_id']);
        self::assertFalse($saved['credential_missing']);

        $linked = $this->service->unlock($this->supplierId, 'test');
        self::assertSame($expectedCertificate, $linked->credentials->certificate?->reveal());
        self::assertSame(self::PASSPHRASE, $linked->credentials->certificatePassphrase?->reveal());
        self::assertSame('abcdefg', $linked->credentials->boxId);
    }

    /**
     * Navázání nesmí nechat v řádku druhou kopii klíče. Kdyby ciphertext zůstal
     * ležet po předchozím nahrání, existoval by týž soukromý klíč na dvou
     * místech a nikdo by o tom nevěděl.
     */
    public function testLinkingDropsTheRowsOwnCiphertext(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $credentialId = $this->storeInVault($pfx, 'Sdílený certifikát', $this->supplierId);

        $this->service->save($this->supplierId, 'test', 'Vlastní kopie', 'abcdefg', $pfx, self::PASSPHRASE, $this->userId);
        $this->service->saveFromVault($this->supplierId, 'test', 'Z trezoru', 'abcdefg', $credentialId, $this->userId);

        $raw = $this->repository->findWithSecrets($this->supplierId, 'isds', 'test');
        self::assertNotNull($raw);
        self::assertNull($raw['certificate_ciphertext']);
        self::assertNull($raw['certificate_passphrase_ciphertext']);
        self::assertSame($credentialId, (int) $raw['credential_id']);
    }

    /** A opačně: nahrání souboru musí odkaz zrušit, ne ho nechat viset vedle. */
    public function testUploadingAFileClearsTheVaultLink(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $credentialId = $this->storeInVault($pfx, 'Sdílený certifikát', $this->supplierId);

        $this->service->saveFromVault($this->supplierId, 'test', 'Z trezoru', 'abcdefg', $credentialId, $this->userId);
        $saved = $this->service->save($this->supplierId, 'test', 'Vlastní kopie', 'abcdefg', $pfx, self::PASSPHRASE, $this->userId);

        self::assertNull($saved['credential_id']);
        $raw = $this->repository->findWithSecrets($this->supplierId, 'isds', 'test');
        self::assertNotNull($raw);
        self::assertNull($raw['credential_id']);
        self::assertStringStartsWith('enc:v', (string) $raw['certificate_ciphertext']);
    }

    /**
     * Platnost na JEDNOM místě. Navázaný řádek si ji neopisuje — čte ji
     * z trezoru, takže obnova certifikátu se propíše i sem.
     */
    public function testValidityIsReadFromTheVaultNotCopiedIntoTheRow(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $credentialId = $this->storeInVault($pfx, 'Sdílený certifikát', $this->supplierId);
        $vaultRow = $this->vault->findShared($credentialId);
        self::assertNotNull($vaultRow);

        $saved = $this->service->saveFromVault($this->supplierId, 'test', 'Z trezoru', 'abcdefg', $credentialId, $this->userId);
        self::assertSame((string) $vaultRow['valid_to'], (string) $saved['certificate_valid_to']);
        self::assertSame((string) $vaultRow['fingerprint_sha256'], (string) $saved['certificate_fingerprint']);
        self::assertSame('Sdílený certifikát', $saved['credential_label']);

        // Obnova v trezoru se propíše i do karty datové schránky.
        $this->db->pdo()->prepare('UPDATE epo_signing_credentials SET valid_to = ? WHERE id = ?')
            ->execute(['2039-01-01 00:00:00', $credentialId]);
        $reloaded = $this->repository->findPublic($this->supplierId, 'isds', 'test');
        self::assertNotNull($reloaded);
        self::assertSame('2039-01-01 00:00:00', (string) $reloaded['certificate_valid_to']);
    }

    /** Měkce smazaný certifikát = pojmenovaná chyba, ne tichý pád na prázdno. */
    public function testSoftDeletedCredentialFailsWithANamedError(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $credentialId = $this->storeInVault($pfx, 'Sdílený certifikát', $this->supplierId);
        $this->service->saveFromVault($this->supplierId, 'test', 'Z trezoru', 'abcdefg', $credentialId, $this->userId);

        $this->db->pdo()->prepare('UPDATE epo_signing_credentials SET deleted_at = NOW() WHERE id = ?')
            ->execute([$credentialId]);

        $card = $this->repository->findPublic($this->supplierId, 'isds', 'test');
        self::assertNotNull($card);
        self::assertTrue($card['credential_missing'], 'Karta musí osiřelý odkaz přiznat.');

        try {
            $this->service->unlock($this->supplierId, 'test');
            self::fail('Odkaz do prázdna musí skončit chybou.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('shared_certificate_missing', $e->errorCode);
        }
    }

    /** Certifikát nepovolený pro firmu se nesmí dát vybrat ani použít. */
    public function testCredentialNotEnabledForTheSupplierIsRefused(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $credentialId = $this->storeInVault($pfx, 'Cizí certifikát', $this->otherSupplierId);

        try {
            $this->service->saveFromVault($this->supplierId, 'test', 'Z trezoru', 'abcdefg', $credentialId, $this->userId);
            self::fail('Nepovolený certifikát nesmí projít.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('shared_certificate_not_enabled', $e->errorCode);
        }

        self::assertNull($this->repository->findPublic($this->supplierId, 'isds', 'test'));
    }

    /** Nabídka pro UI obsahuje jen povolené certifikáty a žádné tajemství. */
    public function testUsableListCarriesMetadataOnly(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $mine = $this->storeInVault($pfx, 'Můj certifikát', $this->supplierId);
        [$otherPfx] = $this->syntheticCertificate();
        $foreign = $this->storeInVault($otherPfx, 'Cizí certifikát', $this->otherSupplierId);

        $items = $this->resolver->listUsable($this->userId, $this->supplierId);
        $ids = array_column($items, 'id');
        self::assertContains($mine, $ids);
        self::assertNotContains($foreign, $ids);

        foreach ($items as $item) {
            self::assertArrayHasKey('valid_to', $item);
            self::assertArrayHasKey('expired', $item);
            $encoded = (string) json_encode($item);
            self::assertStringNotContainsString(self::PASSPHRASE, $encoded);
            self::assertStringNotContainsString('enc:v', $encoded);
            self::assertStringNotContainsString($pfx, $encoded);
        }
    }

    /** Prošlý certifikát se v nabídce označí, ale nezmizí — obnova je volba. */
    public function testExpiredCredentialIsFlaggedInTheList(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $credentialId = $this->storeInVault($pfx, 'Prošlý certifikát', $this->supplierId);
        $this->db->pdo()->prepare('UPDATE epo_signing_credentials SET valid_to = ? WHERE id = ?')
            ->execute(['2001-01-01 00:00:00', $credentialId]);

        $found = null;
        foreach ($this->resolver->listUsable($this->userId, $this->supplierId) as $item) {
            if ($item['id'] === $credentialId) {
                $found = $item;
            }
        }
        self::assertNotNull($found);
        self::assertTrue($found['expired']);
        self::assertFalse($found['valid_now']);
    }

    /**
     * Selhání dešifrování nesmí připojit původní výjimku jako `previous` —
     * nesla by v trace ciphertext i šifrovací kontext.
     */
    public function testDecryptionFailureCarriesNoCiphertext(): void
    {
        $credentialId = $this->insertVaultRow(
            'Rozbitý',
            'enc:v2:deadbeef:Ym9ndXM=',
            'enc:v2:deadbeef:Ym9ndXM=',
            $this->supplierId,
        );

        try {
            $this->resolver->resolve($credentialId, $this->supplierId);
            self::fail('Rozšifrování mělo selhat.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('shared_certificate_decryption_failed', $e->errorCode);
            self::assertNull($e->getPrevious(), 'Původní výjimka by v trace nesla ciphertext.');
            foreach ([$e->getMessage(), $e->getTraceAsString(), (string) $e] as $text) {
                self::assertStringNotContainsString('enc:v2:', $text);
                self::assertStringNotContainsString('credential-pfx', $text);
                self::assertStringNotContainsString('credential-passphrase', $text);
            }
        }
    }

    /** Odemčené tajemství se nesmí objevit ve výpisu ani v JSONu. */
    public function testResolvedSecretsDoNotLeakThroughDumping(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $credentialId = $this->storeInVault($pfx, 'Sdílený certifikát', $this->supplierId);

        $shared = $this->resolver->resolve($credentialId, $this->supplierId);
        self::assertSame(self::PASSPHRASE, $shared->passphrase?->reveal());

        ob_start();
        var_dump($shared);
        $rendered = [(string) ob_get_clean(), print_r($shared, true), var_export($shared, true)];
        foreach ($rendered as $text) {
            self::assertStringNotContainsString(self::PASSPHRASE, $text);
            self::assertStringNotContainsString(base64_encode($pfx), $text);
        }

        $this->expectException(\LogicException::class);
        serialize($shared);
    }

    /**
     * Druhá ISDS cesta: registrace odesílací brány. Je instalačně globální,
     * takže se rozsah na firmu neověřuje — ověřuje se ale, že se certifikát
     * bere z trezoru a že jeho platnost přichází odtamtud.
     */
    public function testGatewayRegistrationReadsTheCertificateFromTheVault(): void
    {
        $registrations = new IsdsGatewayRegistrationRepository($this->db);
        if (!$registrations->isAvailable()) {
            $this->markTestSkipped('Migrace 1411 neproběhla.');
        }
        [$pfx] = $this->syntheticCertificate();
        $credentialId = $this->storeInVault($pfx, 'Certifikát brány', $this->supplierId);

        $this->db->pdo()->prepare('DELETE FROM isds_gateway_registrations WHERE environment = ?')
            ->execute(['test']);
        $this->db->pdo()->prepare(
            "INSERT INTO isds_gateway_registrations
                (environment, credential_id, ats_id, label, return_url, concept_ttl_seconds,
                 portal_host, service_host, certificate_ciphertext, is_active)
             VALUES ('test', ?, 'ATS-TEST', 'Brána', 'https://example.test/callback', 900,
                     'datovka-test.gov.cz', 'cert.datovka-test.gov.cz', NULL, 1)"
        )->execute([$credentialId]);

        $service = new IsdsGatewayRegistrationService($registrations, $this->crypto, $this->resolver);
        $registration = $service->load('test');

        self::assertSame(base64_encode($pfx), $registration->certificate->reveal());
        self::assertSame(self::PASSPHRASE, $registration->certificatePassphrase?->reveal());

        $vaultRow = $this->vault->findShared($credentialId);
        self::assertNotNull($vaultRow);
        self::assertSame((string) $vaultRow['valid_to'], (string) $registration->certificateValidTo);
        self::assertSame((string) $vaultRow['fingerprint_sha256'], (string) $registration->certificateFingerprint);
    }

    /** I brána musí na osiřelý odkaz odpovědět pojmenovanou chybou. */
    public function testGatewayRegistrationWithOrphanLinkFails(): void
    {
        $registrations = new IsdsGatewayRegistrationRepository($this->db);
        if (!$registrations->isAvailable()) {
            $this->markTestSkipped('Migrace 1411 neproběhla.');
        }
        [$pfx] = $this->syntheticCertificate();
        $credentialId = $this->storeInVault($pfx, 'Certifikát brány', $this->supplierId);

        $this->db->pdo()->prepare('DELETE FROM isds_gateway_registrations WHERE environment = ?')
            ->execute(['test']);
        $this->db->pdo()->prepare(
            "INSERT INTO isds_gateway_registrations
                (environment, credential_id, ats_id, label, return_url, concept_ttl_seconds,
                 portal_host, service_host, certificate_ciphertext, is_active)
             VALUES ('test', ?, 'ATS-TEST', 'Brána', 'https://example.test/callback', 900,
                     'datovka-test.gov.cz', 'cert.datovka-test.gov.cz', NULL, 1)"
        )->execute([$credentialId]);
        $this->db->pdo()->prepare('UPDATE epo_signing_credentials SET deleted_at = NOW() WHERE id = ?')
            ->execute([$credentialId]);

        $service = new IsdsGatewayRegistrationService($registrations, $this->crypto, $this->resolver);
        try {
            $service->load('test');
            self::fail('Odkaz do prázdna musí skončit chybou.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('shared_certificate_missing', $e->errorCode);
        }
    }

    // ───────────────────────── pomocné ─────────────────────────

    private function hasCredentialColumn(): bool
    {
        $stmt = $this->db->pdo()->query(
            "SHOW COLUMNS FROM submission_channel_credentials LIKE 'credential_id'"
        );

        return $stmt !== false && $stmt->fetch() !== false;
    }

    private function storeInVault(string $pfx, string $label, int $supplierId): int
    {
        return $this->insertVaultRow(
            $label,
            $this->crypto->encryptFor(base64_encode($pfx), 'epo:credential-pfx'),
            $this->crypto->encryptFor(self::PASSPHRASE, 'epo:credential-passphrase'),
            $supplierId,
        );
    }

    private function insertVaultRow(
        string $label,
        string $pfxCiphertext,
        string $passphraseCiphertext,
        int $supplierId,
    ): int {
        return $this->vault->createForSupplier($this->userId, $supplierId, [
            'label' => $label,
            'pfx_ciphertext' => $pfxCiphertext,
            'passphrase_ciphertext' => $passphraseCiphertext,
            'fingerprint_sha256' => bin2hex(random_bytes(32)),
            'subject_dn' => 'CN=Testovaci schranka',
            'issuer_dn' => 'CN=Testovaci schranka',
            'serial_hex' => '01',
            'valid_from' => date('Y-m-d H:i:s', time() - 86400),
            'valid_to' => date('Y-m-d H:i:s', time() + 86400 * 30),
            'ik_mpsv_present' => false,
        ]);
    }

    /** @return array{0:string,1:string} pfx, pem */
    private function syntheticCertificate(): array
    {
        $config = self::opensslConfigArgs();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $config);
        self::assertNotFalse($key, self::opensslErrors());
        $csr = openssl_csr_new(['commonName' => 'Testovaci schranka'], $key, ['digest_alg' => 'sha256'] + $config);
        self::assertNotFalse($csr, self::opensslErrors());
        $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256'] + $config);
        self::assertNotFalse($cert, self::opensslErrors());

        $pfx = '';
        self::assertTrue(openssl_pkcs12_export($cert, $pfx, $key, self::PASSPHRASE));

        $pem = '';
        self::assertTrue(openssl_x509_export($cert, $pem));

        return [$pfx, $pem];
    }
}
