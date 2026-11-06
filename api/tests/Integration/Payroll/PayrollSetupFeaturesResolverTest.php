<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Settings\PayrollSetupFeaturesResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Připravenost JMHZ se dřív nepočítala vůbec — `jmhz`, `jmhzRegistryReady`
 * i `jmhzCertificateReady` byly natvrdo `false` a panel místo důvodu ukazoval
 * vývojářskou poznámku „chybí tenantový zdroj aktivace". Přežilo to proto, že
 * na tuhle třídu neexistoval jediný test.
 */
#[Group('integration')]
final class PayrollSetupFeaturesResolverTest extends TestCase
{
    private const ON = '2026-08-15';

    private Connection $db;
    private PayrollSetupFeaturesResolver $resolver;
    private int $supplierId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->resolver = $container->get(PayrollSetupFeaturesResolver::class);
        } catch (\Throwable $exception) {
            self::markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        $this->db->pdo()->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($this->db->pdo(), 1);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    use IsolatedSupplierTrait;

    /**
     * Měsíční hlášení se týká každého zaměstnavatele ze zákona. Dokud matice
     * funkcí říká, že je JMHZ dostupné, platí to i pro nově založenou firmu —
     * žádný per-firemní přepínač aktivace neexistuje.
     */
    public function testJmhzAppliesWithoutAnyTenantSwitch(): void
    {
        $features = $this->resolver->resolve($this->supplierId, self::ON);

        self::assertTrue($features->jmhz);
        self::assertArrayNotHasKey('jmhz_feature_source', $features->sourceBlockers);
    }

    public function testRegistrationNumberDrivesRegistryReadiness(): void
    {
        self::assertFalse(
            $this->resolver->resolve($this->supplierId, self::ON)->jmhzRegistryReady,
        );

        $this->givenEmployerRegistrationNumber('4440000000');

        self::assertTrue(
            $this->resolver->resolve($this->supplierId, self::ON)->jmhzRegistryReady,
        );
    }

    public function testCertificateReadinessNeedsAProductionChoice(): void
    {
        self::assertFalse(
            $this->resolver->resolve($this->supplierId, self::ON)->jmhzCertificateReady,
        );

        $credentialId = $this->givenVaultCredential('2029-01-01');
        $this->givenSigningProfile($credentialId, 'test');

        // Testovací volba nestačí: hlášení se podává do ostrého prostředí.
        self::assertFalse(
            $this->resolver->resolve($this->supplierId, self::ON)->jmhzCertificateReady,
        );

        $this->givenSigningProfile($credentialId, 'production');

        self::assertTrue(
            $this->resolver->resolve($this->supplierId, self::ON)->jmhzCertificateReady,
        );
    }

    /**
     * Prošlý certifikát přestane fungovat v den vypršení, ne až si toho někdo
     * všimne — hlásit u něj „připraveno" by znamenalo slíbit podání, které
     * ČSSZ odmítne.
     */
    public function testExpiredCertificateIsNotReady(): void
    {
        $credentialId = $this->givenVaultCredential('2026-01-31');
        $this->givenSigningProfile($credentialId, 'production');

        self::assertFalse(
            $this->resolver->resolve($this->supplierId, self::ON)->jmhzCertificateReady,
        );
    }

    private function givenEmployerRegistrationNumber(string $number): void
    {
        $officeId = $this->db->pdo()->query(
            'SELECT id FROM payroll_offices WHERE supplier_id = ' . $this->supplierId . ' LIMIT 1',
        )?->fetchColumn();
        if ($officeId === false || $officeId === null) {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_offices (supplier_id, code, name) VALUES (?, ?, ?)',
            )->execute([$this->supplierId, 'HQ', 'Sídlo']);
            $officeId = (int) $this->db->pdo()->lastInsertId();
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, employer_registration_number)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE employer_registration_number = VALUES(employer_registration_number)',
        )->execute([$this->supplierId, (int) $officeId, $number]);
    }

    private function givenVaultCredential(string $validTo): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO epo_signing_credentials
                (owner_user_id, label, pfx_ciphertext, passphrase_ciphertext,
                 fingerprint_sha256, subject_dn, issuer_dn, serial_hex,
                 valid_from, valid_to)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            'test-' . $this->supplierId,
            'x',
            'x',
            hash('sha256', 'test-' . $this->supplierId . $validTo),
            'CN=Test',
            'CN=Test CA',
            '0A',
            '2026-01-01',
            $validTo,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function givenSigningProfile(int $credentialId, string $environment): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_signing_profiles
                (supplier_id, environment, credential_id, owner_user_id)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE credential_id = VALUES(credential_id)',
        )->execute([$this->supplierId, $environment, $credentialId]);
    }
}
