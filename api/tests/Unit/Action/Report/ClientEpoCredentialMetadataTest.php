<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Report;

use MyInvoice\Action\Report\EpoDirectSubmissionAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Service\Epo\EpoSigningCredentialService;
use PHPUnit\Framework\TestCase;

/** Vlastnictví certifikátu neopravňuje klienta číst jeho použití v jiných firmách. */
final class ClientEpoCredentialMetadataTest extends TestCase
{
    public function testSupplierScopedMetadataIgnoresOtherCompaniesAndDoesNotQueryGlobalUsage(): void
    {
        $repository = $this->repository(true);
        $items = $repository->listOwnedForSupplier(4, 9, true);
        self::assertCount(1, $items);
        self::assertSame(1, $items[0]['linked_profiles_count']);
        self::assertSame(1, $items[0]['linked_supplier_profiles_count']);
        self::assertSame('2025-01-10 10:00:00', $items[0]['epo_verified_at']);
        self::assertTrue($items[0]['epo_verified']);
        foreach (['linked_data_box_count', 'linked_isds_gateway_count', 'linked_payroll_selections_count'] as $key) {
            self::assertArrayNotHasKey($key, $items[0]);
        }
    }

    public function testVerificationInAnotherCompanyDoesNotVerifyCurrentCompany(): void
    {
        $items = $this->repository(true, false)->listOwnedForSupplier(4, 9, true);
        self::assertNull($items[0]['epo_verified_at']);
        self::assertFalse($items[0]['epo_verified']);
    }

    public function testClientActionRequestsScopedMetadata(): void
    {
        $service = (new \ReflectionClass(EpoSigningCredentialService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(EpoSigningCredentialService::class, 'credentials'))->setValue($service, $this->repository(true));
        $action = (new \ReflectionClass(EpoDirectSubmissionAction::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(EpoDirectSubmissionAction::class, 'credentialService'))->setValue($action, $service);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/reports/submissions/epo-credentials')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 4])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 9)
            ->withAttribute('auth.effective_role', new EffectiveRole(8, 'Klient podání', 'client', true, [
                'reports.submissions' => 1, 'reports.submit' => 2,
            ]));
        $response = $action->credentials($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('2025-01-10 10:00:00', json_decode((string) $response->getBody(), true)[0]['epo_verified_at']);
    }

    public function testInternalDefaultKeepsGlobalMetadata(): void
    {
        $items = $this->repository(false)->listOwnedForSupplier(4, 9);
        self::assertSame(2, $items[0]['linked_profiles_count']);
        self::assertSame('2025-03-10 10:00:00', $items[0]['epo_verified_at']);
        self::assertArrayHasKey('linked_payroll_selections_count', $items[0]);
    }

    public function testClientListDoesNotReadSecretsOrUpdateLegacyCertificateMetadata(): void
    {
        $service = (new \ReflectionClass(EpoSigningCredentialService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(EpoSigningCredentialService::class, 'credentials'))->setValue($service, $this->repository(true));
        // Crypto a inspektor schválně nejsou inicializované: GET je nesmí potřebovat.
        $items = $service->listOwnedForSupplier(4, 9, true);
        self::assertSame(1, $items[0]['linked_profiles_count']);
        self::assertFalse($items[0]['ik_mpsv_present']);
        self::assertSame('2025-01-10 10:00:00', $items[0]['epo_verified_at']);
    }

    private function repository(bool $scoped, bool $currentVerification = true): EpoSigningCredentialRepository
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE epo_signing_credentials (id INTEGER, owner_user_id INTEGER, label TEXT, fingerprint_sha256 TEXT, subject_dn TEXT, issuer_dn TEXT, serial_hex TEXT, valid_from TEXT, valid_to TEXT, ik_mpsv_present INTEGER, created_at TEXT, deleted_at TEXT)');
        $pdo->exec("INSERT INTO epo_signing_credentials VALUES (17, 4, 'Syntetický certifikát', 'synthetic-fingerprint', 'CN=Synthetic', 'CN=Synthetic', '123', '2020-01-01', '2099-01-01', 0, '2025-01-01', NULL)");
        $pdo->exec('CREATE TABLE epo_signing_credential_suppliers (credential_id INTEGER, supplier_id INTEGER)');
        $pdo->exec('INSERT INTO epo_signing_credential_suppliers VALUES (17, 9), (17, 10)');
        $pdo->exec('CREATE TABLE signing_profiles (id INTEGER, supplier_id INTEGER, deleted_at TEXT)');
        $pdo->exec('INSERT INTO signing_profiles VALUES (1, 9, NULL), (2, 10, NULL)');
        $pdo->exec('CREATE TABLE signing_credentials (profile_id INTEGER, vault_credential_id INTEGER, deleted_at TEXT)');
        $pdo->exec('INSERT INTO signing_credentials VALUES (1, 17, NULL), (2, 17, NULL)');
        $pdo->exec('CREATE TABLE tax_submission_attempts (signing_credential_id INTEGER, supplier_id INTEGER, channel TEXT, test_passed INTEGER, signing_fingerprint TEXT, tested_at TEXT)');
        $pdo->exec("INSERT INTO tax_submission_attempts VALUES (17, 9, 'epo_direct', 1, 'synthetic-fingerprint', '2025-01-10 10:00:00'), (17, 10, 'epo_direct', 1, 'synthetic-fingerprint', '2025-03-10 10:00:00')");
        if (!$currentVerification) {
            $pdo->exec('DELETE FROM tax_submission_attempts WHERE supplier_id = 9');
        }
        $db = $this->createMock(Connection::class);
        if ($scoped) {
            $queryPdo = $this->createMock(\PDO::class);
            $queryPdo->expects(self::once())->method('prepare')->willReturnCallback(static function (string $sql) use ($pdo): \PDOStatement {
                self::assertSame(1, substr_count($sql, 'SELECT COUNT(*)'), 'Klientský SELECT nesmí obsahovat globální součet profilů.');
                self::assertStringContainsString('a.supplier_id = ?', $sql);
                return $pdo->prepare($sql);
            });
            $db->expects(self::once())->method('pdo')->willReturn($queryPdo);
            $db->expects(self::never())->method('hasTable');
        } else {
            $db->expects(self::atLeastOnce())->method('pdo')->willReturn($pdo);
            $db->method('hasTable')->willReturn(false);
        }
        return new EpoSigningCredentialRepository($db);
    }
}
