<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Admin\Import\AiProviderCredentialsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Import\AiCredentialsBulkApplier;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Tenant\TenantDomainContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * „Uložit nastavení do všech firem" v AI extrakci. Cílové firmy počítá server
 * z membershipu a per-firemní role; cizí firma ani firma bez práva zápisu se
 * nesmí dotknout, klíč se ukládá šifrovaně po firmách.
 *
 * Vše běží v transakci a na konci se vrací; klíče jsou syntetické a test nikdy
 * nevolá poskytovatele (akční test končí na EU-rezidenční bráně před sítí).
 */
#[Group('integration')]
final class AiCredentialsBulkApplierTest extends TestCase
{
    private Connection $db;
    private AiCredentialsBulkApplier $applier;
    private AiProviderCredentialsAction $action;
    private AnthropicClient $anthropic;
    private int $userId;
    private int $writeRole;
    private int $readRole;
    private int $source;
    private int $free;
    private int $configured;
    private int $readOnly;
    private int $foreign;
    private int $euOnly;
    private string $key;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) $this->markTestSkipped('Requires local test DB configuration.');
        $c = Bootstrap::buildContainer();
        $this->db        = $c->get(Connection::class);
        $this->applier   = $c->get(AiCredentialsBulkApplier::class);
        $this->action    = $c->get(AiProviderCredentialsAction::class);
        $this->anthropic = $c->get(AnthropicClient::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        $this->writeRole = $this->role(AccessLevel::WRITE);
        $this->readRole  = $this->role(AccessLevel::READ);
        $pdo->prepare("INSERT INTO users (email, password_hash, name, role_id, locale, is_active) VALUES (?, 'disabled-test-password', 'Synthetic AI bulk user', ?, 'cs', 1)")
            ->execute(['ai-bulk-' . bin2hex(random_bytes(6)) . '@example.invalid', $this->writeRole]);
        $this->userId = (int) $pdo->lastInsertId();

        $this->source     = $this->supplier('Synthetic AI source');
        $this->free       = $this->supplier('Synthetic AI free');
        $this->configured = $this->supplier('Synthetic AI configured');
        $this->readOnly   = $this->supplier('Synthetic AI read only');
        $this->foreign    = $this->supplier('Synthetic AI foreign');
        $this->euOnly     = $this->supplier('Synthetic AI EU only');

        $assign = $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)');
        foreach ([$this->source, $this->free, $this->configured, $this->euOnly] as $sid) $assign->execute([$this->userId, $sid, null]);
        $assign->execute([$this->userId, $this->readOnly, $this->readRole]);

        $this->key = 'sk-ant-synthetic-' . bin2hex(random_bytes(12));
        $this->anthropic->setCredentials($this->source, $this->key, 'claude-haiku-4-5');
        $this->anthropic->setCredentials($this->configured, 'sk-ant-synthetic-existing', 'claude-haiku-4-5');
        $pdo->prepare('UPDATE supplier SET ai_eu_residency_required = 1, ai_data_region = ? WHERE id = ?')->execute(['eu', $this->euOnly]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testOnlyPermittedMemberCompaniesAreWrittenAndForeignIsNeverTouched(): void
    {
        $result = $this->applier->apply($this->request(), $this->source, 'anthropic', false);

        self::assertEqualsCanonicalizing([$this->free, $this->configured], $this->ids($result['updated']));
        self::assertSame([$this->readOnly], $this->ids($result['skipped_forbidden']));
        self::assertSame([$this->euOnly], $this->ids($result['skipped_constraint']));
        self::assertSame('eu_residency', $result['skipped_constraint'][0]['reason']);
        $listed = array_merge(...array_map(fn (array $group): array => $this->ids($group), array_values($result)));
        self::assertNotContains($this->foreign, $listed, 'Firma mimo membership se nesmí objevit ani ve výsledku.');

        self::assertSame($this->key, $this->anthropic->getCredentials($this->free)['api_key'] ?? null);
        self::assertSame($this->key, $this->anthropic->getCredentials($this->configured)['api_key'] ?? null);
        self::assertNull($this->rawKey($this->readOnly), 'Firma bez práva zápisu zůstala beze změny.');
        self::assertNull($this->rawKey($this->foreign), 'Cizí firma zůstala beze změny.');
        self::assertNull($this->rawKey($this->euOnly), 'EU firma nesmí dostat US poskytovatele.');
        self::assertSame(1, (int) $this->column($this->euOnly, 'ai_eu_residency_required'));

        $audit = $this->db->pdo()->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'import.ai_credentials_set' AND entity_type = 'supplier' AND entity_id = ? AND supplier_id = ?");
        foreach ([$this->free, $this->configured] as $sid) {
            $audit->execute([$sid, $sid]);
            self::assertSame(1, (int) $audit->fetchColumn(), "audit pro firmu $sid");
        }
        $audit->execute([$this->readOnly, $this->readOnly]);
        self::assertSame(0, (int) $audit->fetchColumn());
    }

    public function testOnlyUnconfiguredSkipsCompaniesWithWorkingAi(): void
    {
        $result = $this->applier->apply($this->request(), $this->source, 'anthropic', true);

        self::assertSame([$this->free], $this->ids($result['updated']));
        self::assertSame([$this->configured], $this->ids($result['skipped_configured']));
        self::assertSame('sk-ant-synthetic-existing', $this->anthropic->getCredentials($this->configured)['api_key'] ?? null);
    }

    public function testStoredKeyIsEncryptedPerCompany(): void
    {
        $this->applier->apply($this->request(), $this->source, 'anthropic', true);

        $copy = (string) $this->rawKey($this->free);
        self::assertNotSame('', $copy);
        self::assertStringNotContainsString($this->key, $copy);
        self::assertStringNotContainsString(substr($this->key, 8), $copy);
        self::assertNotSame((string) $this->rawKey($this->source), $copy, 'Každá firma má vlastní šifrovanou kopii.');
        self::assertSame('anthropic', $this->column($this->free, 'ai_provider'));
    }

    /** ID workspace patří ke klíči: jde do cílových firem s ním a starší hodnota cíle nepřežije. */
    public function testWorkspaceIdTravelsWithTheKey(): void
    {
        $this->anthropic->updateWorkspaceId($this->source, 'wrkspc_01Synthetic');
        $this->anthropic->updateWorkspaceId($this->configured, 'wrkspc_01Stale');
        self::assertSame('different', $this->applier->uniformConfiguration([$this->source, $this->configured])['status']);

        $this->applier->apply($this->request(), $this->source, 'anthropic', false);

        self::assertSame('wrkspc_01Synthetic', $this->anthropic->getCredentials($this->free)['workspace_id'] ?? null);
        self::assertSame('wrkspc_01Synthetic', $this->anthropic->getCredentials($this->configured)['workspace_id'] ?? null);
        self::assertSame('same', $this->applier->uniformConfiguration([$this->source, $this->free, $this->configured])['status']);

        $this->anthropic->updateWorkspaceId($this->source, '');
        $this->applier->apply($this->request(), $this->source, 'anthropic', false);
        self::assertNull($this->column($this->configured, 'anthropic_workspace_id'));
    }

    public function testLockedCustomDomainCannotFanOutEvenForSuperadmin(): void
    {
        $domain = new TenantDomainContext(TenantDomainContext::CUSTOM, 'synthetic.example.invalid', 'https://synthetic.example.invalid', 1, $this->source, 'all', 'active');
        $result = $this->applier->apply($this->request(true)->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, $domain), $this->source, 'anthropic', false);

        self::assertSame(['updated' => [], 'skipped_configured' => [], 'skipped_forbidden' => [], 'skipped_constraint' => []], $result,
            'Mimo zamčenou firmu se nic nezapíše a nevypíše se ani jméno jiné firmy.');
        self::assertNull($this->rawKey($this->free));
        self::assertNull($this->rawKey($this->foreign));
    }

    public function testActionDoesNotFanOutWhenTheKeyTestFails(): void
    {
        // EU-required zdroj s US poskytovatelem → test skončí na rezidenční bráně (bez sítě).
        $this->db->pdo()->prepare('UPDATE supplier SET ai_eu_residency_required = 1 WHERE id = ?')->execute([$this->source]);
        $res = $this->update(['provider' => 'anthropic', 'api_key' => $this->key, 'apply_to_all_companies' => true], 'session');

        self::assertSame(200, $res['status']);
        self::assertFalse($res['body']['test_ok']);
        self::assertSame(['applied' => false, 'reason' => 'test_failed'], $res['body']['bulk']);
        self::assertNull($this->rawKey($this->free));
    }

    public function testActionRefusesFanOutOutsideBrowserSession(): void
    {
        $res = $this->update(['provider' => 'anthropic', 'api_key' => $this->key, 'apply_to_all_companies' => true], 'bearer');

        self::assertSame(403, $res['status']);
        self::assertNull($this->rawKey($this->free));
    }

    private function role(AccessLevel $level): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO roles (name, role_type, is_active) VALUES (?, ?, 1)')->execute(['Synthetic AI role ' . $level->name, 'staff']);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)')
            ->execute([$id, AiCredentialsBulkApplier::PERMISSION, $level->value]);
        return $id;
    }

    private function supplier(string $name): int
    {
        $pdo = $this->db->pdo();
        $base = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $base, 'Test database must have a synthetic supplier baseline.');
        $pdo->prepare("INSERT INTO supplier
            (company_name, display_name, street, city, zip, country_id, is_vat_payer, email, default_currency_id, default_vat_rate_id)
            SELECT ?, ?, 'Synthetic street', 'Synthetic city', '00000', country_id, 0, 'synthetic@example.invalid',
                   default_currency_id, default_vat_rate_id FROM supplier WHERE id = ?")->execute([$name, $name, $base]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE supplier SET ai_provider = 'anthropic', ai_data_region = 'us', ai_eu_residency_required = 0,
            anthropic_api_key_enc = NULL, azure_openai_api_key_enc = NULL, openai_api_key_enc = NULL, gemini_api_key_enc = NULL WHERE id = ?")
            ->execute([$id]);
        return $id;
    }

    private function request(bool $superadmin = false): Request
    {
        return (new ServerRequestFactory())->createServerRequest('PUT', '/api/admin/imports/ai/credentials')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->source)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role_id' => $this->writeRole,
                'is_superadmin' => $superadmin, 'role_summary' => ['type' => $superadmin ? 'superadmin' : 'staff']])
            ->withAttribute('auth.effective_role', new EffectiveRole($this->writeRole, 'Synthetic AI role',
                $superadmin ? 'superadmin' : 'staff', true, [AiCredentialsBulkApplier::PERMISSION => AccessLevel::WRITE->value],
                $superadmin ? 'superadmin' : null));
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function update(array $body, string $method): array
    {
        $req = $this->request()->withAttribute(AuthMiddleware::ATTR_METHOD, $method)->withParsedBody($body);
        $resp = $this->action->update($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function rawKey(int $supplierId): ?string
    {
        $value = $this->column($supplierId, 'anthropic_api_key_enc');
        return $value === null || $value === '' ? null : $value;
    }

    private function column(int $supplierId, string $column): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM supplier WHERE id = ?");
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /** @param list<array{id:int}> $rows @return list<int> */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
