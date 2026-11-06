<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Action\Settings\CompanyProfileAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Pravidlo „právnická osoba musí vést účetnictví" se smí ozvat jen u změny.
 *
 * Formulář nastavení posílá celý objekt včetně `accounting_mode`, takže když se
 * pravidlo vyhodnocovalo z odeslané hodnoty bez ohledu na tu uloženou, firma
 * převzatá z MyInvoice (s.r.o. v daňové evidenci) neuložila ani e-mail —
 * každý pokus skončil na 422 (issue myinvoice#265).
 */
#[Group('integration')]
final class SupplierAccountingModeGuardTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SettingsAction $action;
    private CompanyProfileAction $profileAction;

    private int $userId = 0;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container    = Bootstrap::buildContainer();
            $this->db     = $container->get(Connection::class);
            $this->action = $container->get(SettingsAction::class);
            $this->profileAction = $container->get(CompanyProfileAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId   = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplier === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $pdo->prepare('UPDATE supplier SET dimensions_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /** Přesně stav po převodu z MyInvoice: s.r.o., kterou nikdo nepřepnul na účetnictví. */
    private function migratedLegalEntity(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET taxpayer_type = 'po', accounting_mode = 'tax_evidence' WHERE id = ?"
        )->execute([$this->supplierId]);
    }

    public function testLegalEntityInTaxEvidenceCanStillSaveUnrelatedFields(): void
    {
        $this->migratedLegalEntity();

        $r = $this->save([
            'email'           => 'ucetni@example.test',
            'accounting_mode' => 'tax_evidence',
            'taxpayer_type'   => 'po',
        ]);

        self::assertSame(200, $r['status'], 'Nezměněný režim nesmí zamknout nastavení: ' . json_encode($r['body']));
        self::assertSame('ucetni@example.test', $this->column('email'));
    }

    public function testUnchangedModeDoesNotWriteHistory(): void
    {
        $this->migratedLegalEntity();
        $before = $this->modeHistoryCount();

        self::assertSame(200, $this->save(['email' => 'x@example.test', 'accounting_mode' => 'tax_evidence'])['status']);

        self::assertSame($before, $this->modeHistoryCount(), 'Uložení beze změny režimu nemá zapisovat historii.');
    }

    public function testSwitchingLegalEntityToTaxEvidenceIsStillRejected(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET taxpayer_type = 'po', accounting_mode = 'double_entry' WHERE id = ?"
        )->execute([$this->supplierId]);

        $r = $this->save(['accounting_mode' => 'tax_evidence']);

        self::assertSame(422, $r['status']);
        self::assertSame('legal_form_requires_accounting', $r['body']['error']['code'] ?? null);
    }

    public function testTurningIntoLegalEntityWhileInTaxEvidenceIsRejected(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET taxpayer_type = 'fo', accounting_mode = 'tax_evidence' WHERE id = ?"
        )->execute([$this->supplierId]);

        $r = $this->save(['taxpayer_type' => 'po', 'accounting_mode' => 'tax_evidence']);

        self::assertSame(422, $r['status']);
        self::assertSame('legal_form_requires_accounting', $r['body']['error']['code'] ?? null);
        self::assertSame('fo', $this->column('taxpayer_type'), 'Odmítnuté uložení nesmí nic změnit.');
    }

    public function testDimensionsCannotBeActivatedWithoutAccountingLicence(): void
    {
        $free = $this->license(false);
        $blocked = $this->save(['dimensions_enabled' => true], $free);
        self::assertSame(403, $blocked['status']);
        self::assertSame('license_commercial_feature_unavailable', $blocked['body']['error']['code'] ?? null);
        self::assertSame('0', $this->column('dimensions_enabled'));

        self::assertSame(200, $this->save(['dimensions_enabled' => true], $this->license(true))['status']);
        self::assertSame('1', $this->column('dimensions_enabled'));
        self::assertSame(200, $this->save(['dimensions_enabled' => true, 'email' => 'test@example.invalid'], $free)['status']);
        self::assertSame(200, $this->save(['dimensions_enabled' => false], $free)['status']);
        self::assertSame('0', $this->column('dimensions_enabled'));
    }

    public function testCompanyProfileCannotActivateDimensionsWithoutAccountingLicence(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/settings/company-profile/import')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin', 'is_superadmin' => true])
            ->withAttribute(LicenseMiddleware::ATTR_STATE, $this->license(false))
            ->withParsedBody(['profile' => [
                'format' => 'myucto.company-profile', 'version' => 1,
                'sections' => ['company' => ['dimensions_enabled' => true]],
            ]]);
        $response = $this->profileAction->import($request, new Psr7Response());
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('0', $this->column('dimensions_enabled'));
    }

    public function testUnlicensedProfileImportAllowsEmptyDimensionSections(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/settings/company-profile/import')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin', 'is_superadmin' => true])
            ->withAttribute(LicenseMiddleware::ATTR_STATE, $this->license(false))
            ->withParsedBody(['profile' => [
                'format' => 'myucto.company-profile', 'version' => 1,
                'sections' => [
                    'company' => ['dimensions_enabled' => false],
                    'dimensions' => ['types' => []],
                    'dimension_defaults' => [],
                    'dimension_rules' => [],
                ],
            ]]);

        self::assertSame(200, $this->profileAction->import($request, new Psr7Response())->getStatusCode());
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $body @return array{status:int,body:array<string,mixed>} */
    private function save(array $body, ?LicenseState $license = null): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin', 'is_superadmin' => true])
            ->withParsedBody($body);
        if ($license !== null) $req = $req->withAttribute(LicenseMiddleware::ATTR_STATE, $license);

        $resp = $this->action->updateSupplier($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);

        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function license(bool $commercial): LicenseState
    {
        return new LicenseState(LicenseState::ACTIVE, 'test-instance', 'test', null, 0, 0, 0,
            time() + 3600, null, null, null, null, true, commercial: $commercial);
    }

    private function column(string $name): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT `{$name}` FROM supplier WHERE id = ?");
        $stmt->execute([$this->supplierId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function modeHistoryCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM supplier_accounting_modes WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
