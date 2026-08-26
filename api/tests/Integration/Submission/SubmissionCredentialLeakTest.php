<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\SubmissionChannelCredentialRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přístup k datové schránce nesmí uniknout do odpovědi API, do logu ani do
 * výjimky.
 *
 * Test je zvlášť, a ne jako jeden `assert` někde v jiném testu, schválně:
 * bezpečnostní audit ISDS knihovny našel přesně tenhle typ chyby — redakce
 * existovala v jedné cestě (`__debugInfo()`), ale ne v ostatních
 * (`serialize()`, `var_export()`), takže budila důvěru, kterou neměla.
 */
#[Group('integration')]
final class SubmissionCredentialLeakTest extends TestCase
{
    use IsolatedSupplierTrait;
    use OpensslConfigTrait;

    private const PASSPHRASE = 'TAJNE-HESLO-K-CERTIFIKATU-42';

    private Connection $db;
    private SubmissionCredentialService $service;
    private SubmissionChannelCredentialRepository $repository;
    private int $supplierId;
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

        $crypto = $container->get(SecretEncryption::class);
        self::assertInstanceOf(SecretEncryption::class, $crypto);
        if ($crypto->validateKey() !== null) {
            $this->markTestSkipped('Šifrovací klíč není nastaven.');
        }
        $this->service = new SubmissionCredentialService($this->repository, $crypto);

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testStoredCertificateNeverAppearsInTheApiShape(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $saved = $this->service->save(
            $this->supplierId,
            'test',
            'Testovací schránka',
            'abcdefg',
            $pfx,
            self::PASSPHRASE,
            $this->userId,
        );

        // Tvar, který jde do odpovědi API.
        foreach ([$saved, ...$this->service->listPublic($this->supplierId)] as $row) {
            self::assertArrayNotHasKey('certificate_ciphertext', $row);
            self::assertArrayNotHasKey('certificate_passphrase_ciphertext', $row);
            $encoded = (string) json_encode($row);
            self::assertStringNotContainsString(self::PASSPHRASE, $encoded);
            self::assertStringNotContainsString($pfx, $encoded);
        }
    }

    public function testSecretsAreStoredEncryptedNotAsPlaintext(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $this->service->save($this->supplierId, 'test', 'Testovací schránka', 'abcdefg', $pfx, self::PASSPHRASE, $this->userId);

        $raw = $this->repository->findWithSecrets($this->supplierId, 'isds', 'test');
        self::assertNotNull($raw);
        self::assertStringStartsWith('enc:v2:', (string) $raw['certificate_ciphertext']);
        self::assertStringStartsWith('enc:v2:', (string) $raw['certificate_passphrase_ciphertext']);
        self::assertStringNotContainsString(self::PASSPHRASE, (string) $raw['certificate_passphrase_ciphertext']);
    }

    public function testCertificateWithoutPrivateKeyIsRejectedBeforeStorage(): void
    {
        [, $pem] = $this->syntheticCertificate();

        try {
            $this->service->save(
                $this->supplierId,
                'test',
                'Certifikát bez klíče',
                'abcdefg',
                $pem,
                null,
                $this->userId,
            );
            self::fail('Holý veřejný certifikát nesmí být uložen jako funkční systémový přístup.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('invalid_certificate', $e->errorCode);
        }

        self::assertNull($this->repository->findPublic($this->supplierId, 'isds', 'test'));
    }

    public function testUnlockedSecretsDoNotLeakThroughDumpingOrLogContext(): void
    {
        [$pfx] = $this->syntheticCertificate();
        $this->service->save($this->supplierId, 'test', 'Testovací schránka', 'abcdefg', $pfx, self::PASSPHRASE, $this->userId);

        $context = $this->service->unlock($this->supplierId, 'test');
        self::assertSame(self::PASSPHRASE, $context->credentials->certificatePassphrase?->reveal());

        ob_start();
        var_dump($context);
        $rendered = [(string) ob_get_clean(), print_r($context, true), var_export($context, true), (string) json_encode($context)];

        foreach ($rendered as $text) {
            self::assertStringNotContainsString(self::PASSPHRASE, $text);
            self::assertStringNotContainsString($pfx, $text);
        }

        // To, co se smí dostat do logu, je jen veřejný údaj.
        $logContext = (string) json_encode($context->toLogContext());
        self::assertStringNotContainsString(self::PASSPHRASE, $logContext);
        self::assertStringNotContainsString($pfx, $logContext);
        self::assertStringContainsString('abcdefg', $logContext);
    }

    /**
     * Když se dešifrování nepovede, nesmí se původní výjimka připojit jako
     * `previous` — nesla by v trace ciphertext i šifrovací kontext.
     */
    public function testDecryptionFailureExceptionCarriesNoCiphertext(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO submission_channel_credentials
                (supplier_id, environment, channel, label, box_id, certificate_ciphertext,
                 certificate_passphrase_ciphertext)
             VALUES (?, 'test', 'isds', 'Rozbitý', 'abcdefg', 'enc:v2:deadbeef:Ym9ndXM=', 'enc:v2:deadbeef:Ym9ndXM=')"
        )->execute([$this->supplierId]);

        try {
            $this->service->unlock($this->supplierId, 'test');
            self::fail('Rozšifrování mělo selhat.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('credential_decryption_failed', $e->errorCode);
            self::assertNull($e->getPrevious(), 'Původní výjimka by v trace nesla ciphertext.');
            foreach ([$e->getMessage(), $e->getTraceAsString(), (string) $e] as $text) {
                self::assertStringNotContainsString('enc:v2:', $text);
                self::assertStringNotContainsString('credential-passphrase', $text);
            }
        }
    }

    /**
     * Projekce pro API nesmí citlivé sloupce ani vyjmenovat. Maskování
     * filtrováním pole po načtení se dá zapomenout; co se nevybere, to neunikne.
     */
    public function testPublicProjectionDoesNotEvenNameTheSecretColumns(): void
    {
        $reflection = new \ReflectionClass(SubmissionChannelCredentialRepository::class);
        $publicColumns = (string) $reflection->getConstant('PUBLIC_COLUMNS');

        self::assertStringNotContainsString('certificate_ciphertext', $publicColumns);
        self::assertStringNotContainsString('certificate_passphrase_ciphertext', $publicColumns);
    }

    /**
     * Firemní trezor certifikátu nesmí omylem získat osobní login ani běžné
     * heslo. Volitelný Mobilní klíč má vlastní uživatelsky a tenantově vázanou
     * tabulku; běžné heslo ani SMS kód se do ní neukládají.
     */
    public function testTableHasNoColumnsForLoginAndPassword(): void
    {
        $columns = $this->db->pdo()->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'submission_channel_credentials'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        self::assertNotContains('login_ciphertext', $columns);
        self::assertNotContains('password_ciphertext', $columns);
        self::assertContains('certificate_ciphertext', $columns);
    }

    /** @return array{0:string,1:string} PKCS#12 bajty, veřejný PEM certifikát */
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
