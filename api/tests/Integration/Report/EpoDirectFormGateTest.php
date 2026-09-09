<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EpoDirectSubmissionRepository;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Epo\EpoDirectClient;
use MyInvoice\Service\Epo\EpoDirectResponseParser;
use MyInvoice\Service\Epo\EpoAssistedConfirmationService;
use MyInvoice\Service\Epo\EpoConfirmationPartsArchiver;
use MyInvoice\Service\Epo\EpoDirectSubmissionService;
use MyInvoice\Service\Epo\EpoPkcs7Signer;
use MyInvoice\Service\Epo\EpoSigningCredentialService;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Service\Epo\EpoSubmissionPayloadBuilder;
use MyInvoice\Service\Epo\TaxSubmissionDocumentService;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Brána na typ formuláře před PŘÍMÝM podáním do EPO (protějšek
 * {@see EpoHandoffFormGateTest}, který hlídá asistované předání).
 *
 * Oba kanály míří na `/dpr/epo_podani` a portál na OSS písemnost odpovídá, že
 * uživatel musí být přihlášený v aplikaci MOSS/OSS. Přímý kanál se o tu podmínku
 * láme stejně, jen po zbytečném odemčení klíče a podepsání — brána ho proto
 * odmítne dřív, než se cokoli odešle nebo dešifruje.
 *
 * Guzzle běží s prázdnou MockHandler frontou: kdyby se kód dostal až k volání
 * portálu, spadne to nahlas a bez jediného skutečného požadavku na adisspr.mfcr.cz.
 */
#[Group('integration')]
final class EpoDirectFormGateTest extends TestCase
{
    private Connection $db;
    private TaxSubmissionRepository $submissions;
    private EpoDirectSubmissionRepository $direct;
    private EpoSigningCredentialRepository $credentials;
    private TaxSubmissionEpoRepository $epo;
    private EpoDirectSubmissionService $service;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->submissions = $container->get(TaxSubmissionRepository::class);
            $this->direct = $container->get(EpoDirectSubmissionRepository::class);
            $this->credentials = $container->get(EpoSigningCredentialRepository::class);
            $this->epo = $container->get(TaxSubmissionEpoRepository::class);
            $this->service = new EpoDirectSubmissionService(
                $this->db,
                $this->submissions,
                $container->get(TaxSubmissionArchiver::class),
                $this->epo,
                $this->direct,
                $container->get(EpoSigningCredentialService::class),
                $container->get(EpoPkcs7Signer::class),
                $container->get(EpoSubmissionPayloadBuilder::class),
                new EpoDirectClient(
                    new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
                    'production',
                ),
                $container->get(EpoDirectResponseParser::class),
                $container->get(TaxSubmissionDocumentService::class),
                $container->get(SecretEncryption::class),
                $container->get(EpoConfirmationPartsArchiver::class),
                $container->get(EpoAssistedConfirmationService::class),
            );
        } catch (\ArgumentCountError|\TypeError $e) {
            // Rozejití s konstruktorem služby NENÍ důvod ke skipu. Přesně tím se tahle
            // brána na půl roku vypnula: přibyly dva argumenty, test spadl do catch
            // a pět testů „prošlo" jako přeskočených, takže regrese v pravidle
            // „OSS přiznání jde podat jen přes MOSS/OSS" by nikoho neupozornila.
            self::fail('EpoDirectSubmissionService má jiný konstruktor, než test skládá: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->db->close();
    }

    private function archiveSnapshot(string $formCode, string $xml): int
    {
        return $this->submissions->archive(
            $this->supplierId,
            $formCode,
            2026,
            null,
            1,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
    }

    private function syntheticCredentialId(): int
    {
        $fingerprint = hash('sha256', 'synthetic-direct-gate-' . random_bytes(8));
        return $this->credentials->create($this->userId, [
            'label' => 'Syntetický certifikát pro bránu formulářů',
            'pfx_ciphertext' => 'enc:v1:synthetic',
            'passphrase_ciphertext' => 'enc:v1:synthetic',
            'fingerprint_sha256' => $fingerprint,
            'subject_dn' => 'CN=Synthetic Direct EPO Signer',
            'issuer_dn' => 'CN=Synthetic Test CA',
            'serial_hex' => '02',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'ik_mpsv_present' => false,
        ]);
    }

    /**
     * Odmítnutí musí přijít dřív, než se sáhne na certifikát: jinak by uživatel
     * u OSS dostal hlášku o klíči místo pravdivé informace o aplikaci MOSS/OSS.
     */
    public function testDirectTestIsRejectedForOssBeforeAnyKeyIsUnlocked(): void
    {
        $submissionId = $this->archiveSnapshot(
            'ossei1',
            '<?xml version="1.0"?><Pisemnost><OSSEI1/></Pisemnost>',
        );

        try {
            $this->service->test($submissionId, $this->supplierId, $this->userId, 0);
            self::fail('OSS přiznání se přímým EPO API odeslat nedá, test nesmí vzniknout.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('moss_oss_only', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
            self::assertStringContainsString('MOSS/OSS', $e->getMessage());
        }

        self::assertSame([], $this->epo->attempts($submissionId, $this->supplierId));
    }

    public function testDirectSubmitIsRejectedForOssEvenAfterAPassedTest(): void
    {
        $xml = '<?xml version="1.0"?><Pisemnost><OSSEI1/></Pisemnost>';
        $submissionId = $this->archiveSnapshot('ossei1', $xml);
        $credentialId = $this->syntheticCredentialId();
        $attemptId = $this->direct->createAttempt(
            $this->supplierId,
            $submissionId,
            $credentialId,
            hash('sha256', 'synthetic-fingerprint'),
            hash('sha256', $xml),
            $this->userId,
            'production',
        );
        $this->direct->setStatus($attemptId, 'test_passed');

        try {
            $this->service->submit($submissionId, $this->supplierId, $this->userId, $attemptId);
            self::fail('OSS přiznání se přímým EPO API odeslat nedá ani po úspěšném testu.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('moss_oss_only', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }

        $attempt = $this->direct->findAttempt($attemptId, $submissionId, $this->supplierId);
        self::assertSame('test_passed', (string) ($attempt['status'] ?? ''));
        self::assertNull($attempt['submitted_at'] ?? null);
    }

    /**
     * Brána blokuje jen nové odeslání. Pokus z doby, kdy kanál OSS ještě nabízel,
     * musí jít pořád dohledat a uzavřít — jinak by uvízl navždy v „nejistém" stavu.
     * `status_unavailable` znamená, že kód bránou prošel a spadl až na chybějících
     * údajích pro dotaz na stav.
     */
    public function testStatusRefreshOfALegacyOssAttemptIsNotBlocked(): void
    {
        $xml = '<?xml version="1.0"?><Pisemnost><OSSEI1/></Pisemnost>';
        $submissionId = $this->archiveSnapshot('ossei1', $xml);
        $attemptId = $this->direct->createAttempt(
            $this->supplierId,
            $submissionId,
            $this->syntheticCredentialId(),
            hash('sha256', 'synthetic-fingerprint'),
            hash('sha256', $xml),
            $this->userId,
            'production',
        );
        $this->direct->setStatus($attemptId, 'uncertain');

        try {
            $this->service->refreshStatus($submissionId, $this->supplierId, $this->userId, $attemptId);
            self::fail('Bez reference a hesla se stav dotázat nedá.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('status_unavailable', $e->errorCode);
        }
    }

    public function testUnknownFormKeepsItsOwnGenericRejection(): void
    {
        $submissionId = $this->archiveSnapshot('dphsd1', '<?xml version="1.0"?><Pisemnost/>');

        try {
            $this->service->test($submissionId, $this->supplierId, $this->userId, 0);
            self::fail('Neznámý formulář nesmí projít.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('unsupported_form', $e->errorCode);
        }
    }

    /**
     * Kontrola, že brána nezavřela i podporované formuláře: DPHDP3 musí projít až
     * k certifikátu (ten je synteticky rozbitý, takže selže na něm — ne na formuláři).
     */
    public function testSupportedFormPassesTheFormGate(): void
    {
        $submissionId = $this->archiveSnapshot(
            'dphdp3',
            '<?xml version="1.0"?><Pisemnost><DPHDP3/></Pisemnost>',
        );

        try {
            $this->service->test($submissionId, $this->supplierId, $this->userId, 0);
            self::fail('Se synteticky rozbitým certifikátem se test odeslat nedá.');
        } catch (\Throwable $e) {
            $code = $e instanceof EpoSubmissionException ? $e->errorCode : '';
            self::assertNotSame('moss_oss_only', $code);
            self::assertNotSame('unsupported_form', $code);
        }
    }
}
