<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Supplier\SupplierResponseFields;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Detail firmy vrací allowlist, ne `SELECT s.*` (MYU-2026-H39). Citlivé skupiny
 * jen s právem je upravovat, API token se scope `read` je nedostane nikdy
 * a `GET /api/suppliers/{id}` bez `settings.company` vrací jen bezpečný výřez.
 */
#[Group('integration')]
final class SupplierResponseExposureTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const NEVER = [
        'azure_openai_endpoint', 'azure_openai_deployment', 'openai_base_url', 'ollama_base_url',
        'anthropic_workspace_id', 'anthropic_default_model', 'openai_extractions_count', 'ai_pseudo_salt',
        'ai_dpa_confirmations', 'ai_extraction_notes', 'idoklad_client_id', 'fakturoid_slug', 'fakturoid_email',
        'integration_sample_seeded_at', 'abo_client_number', 'health_insurance_code',
    ];

    private Connection $db;
    private SettingsAction $action;
    private int $supplierId;
    private int $otherSupplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(SettingsAction::class);
        $pdo = $this->db->pdo();
        $sourceId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $sourceId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceId);
        $pdo->prepare(
            "UPDATE supplier SET health_insurance_number = '9001010001', cssz_vsdp = '1234567890',
                    cssz_ossz_code = '123', logo_path = 'storage/supplier-logos/test.png',
                    azure_openai_endpoint = 'https://syntetic.example', openai_base_url = 'https://syntetic.example/v1',
                    anthropic_workspace_id = 'ws-syntetic', ai_provider = 'anthropic'
              WHERE id IN (?, ?)"
        )->execute([$this->supplierId, $this->otherSupplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testAdminSessionGetsGatedGroupsButNeverSecretsOrEndpoints(): void
    {
        $data = $this->settings($this->request(['id' => 1, 'role' => 'admin']));

        self::assertSame('9001010001', $data['health_insurance_number']);
        self::assertSame('1234567890', $data['cssz_vsdp']);
        self::assertSame('storage/supplier-logos/test.png', $data['logo_path']);
        self::assertSame('anthropic', $data['ai_provider']);
        $this->assertNoForbiddenKeys($data);
        self::assertSame([], array_values(array_diff(array_keys($data), SupplierResponseFields::allFields())),
            'Odpověď obsahuje pole mimo allowlist.');
    }

    public function testSettingsReaderWithoutWriteGetsBaseOnly(): void
    {
        $data = $this->settings($this->request(['id' => 1], $this->role(['settings.company' => 1, 'profile' => 1])));

        foreach (['company_name', 'default_hourly_rate', 'vat_status_history', 'is_vat_payer', 'has_email_logo'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
        foreach (['health_insurance_number', 'cssz_vsdp', 'cssz_ossz_code', 'ai_provider', 'client_ai_extraction_enabled', 'logo_path', 'signature_path'] as $key) {
            self::assertArrayNotHasKey($key, $data);
        }
        $this->assertNoForbiddenKeys($data);
    }

    public function testEachGatedGroupFollowsItsPermission(): void
    {
        $data = $this->settings($this->request(['id' => 1], $this->role([
            'settings.company' => 1, 'settings.company.write' => 2, 'settings.ai_provider' => 1,
        ])));
        self::assertArrayHasKey('health_insurance_number', $data);
        self::assertArrayHasKey('ai_provider', $data);
        self::assertArrayNotHasKey('logo_path', $data, 'Cesty k souborům jen se zápisem brandingu.');

        $branding = $this->settings($this->request(['id' => 1], $this->role(['settings.company' => 1, 'settings.branding' => 2])));
        self::assertArrayHasKey('logo_path', $branding);
        self::assertArrayNotHasKey('health_insurance_number', $branding);
    }

    public function testReadScopeTokenNeverGetsGatedGroups(): void
    {
        $request = $this->request(['id' => 1, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => $this->supplierId, 'scope' => 'read']);
        $data = $this->settings($request);
        self::assertArrayNotHasKey('health_insurance_number', $data);
        self::assertArrayNotHasKey('logo_path', $data);
        self::assertArrayNotHasKey('ai_provider', $data);
        self::assertArrayHasKey('company_name', $data);

        $write = $this->request(['id' => 1, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => $this->supplierId, 'scope' => 'read_write']);
        self::assertArrayHasKey('health_insurance_number', $this->settings($write));
    }

    public function testSupplierByIdWithProfileOnlyReturnsSafeMinimum(): void
    {
        $request = $this->request(['id' => 999_999_999], $this->role(['profile' => 1]));
        $response = $this->action->getSupplierById($request, new Response(), ['id' => (string) $this->supplierId]);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertEqualsCanonicalizing(SupplierResponseFields::MINIMAL, array_keys($data));
        self::assertSame($this->supplierId, $data['id']);
        self::assertIsBool($data['is_vat_payer']);
    }

    public function testSupplierByIdFullOnlyForCurrentSupplierWithSettingsRead(): void
    {
        $request = $this->request(['id' => 999_999_999], $this->role(['profile' => 1, 'settings.company' => 1]));

        $current = json_decode((string) $this->action->getSupplierById($request, new Response(), ['id' => (string) $this->supplierId])->getBody(), true);
        self::assertArrayHasKey('vat_status_history', $current);
        self::assertArrayHasKey('invoice_number_format', $current);

        $other = json_decode((string) $this->action->getSupplierById($request, new Response(), ['id' => (string) $this->otherSupplierId])->getBody(), true);
        self::assertEqualsCanonicalizing(SupplierResponseFields::MINIMAL, array_keys($other),
            'Oprávnění aktuální firmy neopravňuje k plnému detailu jiné firmy.');
    }

    /** @param array<string,int> $permissions */
    private function role(array $permissions): EffectiveRole
    {
        return new EffectiveRole(1, 'Syntetická role', 'staff', true, $permissions);
    }

    /** @param array<string,mixed> $user */
    private function request(array $user, ?EffectiveRole $role = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, $user);

        return $role !== null ? $request->withAttribute('auth.effective_role', $role) : $request;
    }

    /** @return array<string,mixed> */
    private function settings(ServerRequestInterface $request): array
    {
        $response = $this->action->getSupplier($request, new Response());
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $data */
    private function assertNoForbiddenKeys(array $data): void
    {
        foreach (self::NEVER as $key) {
            self::assertArrayNotHasKey($key, $data);
        }
        foreach (array_keys($data) as $key) {
            self::assertDoesNotMatchRegularExpression('/_enc$|salt|secret|password|access_token/', (string) $key);
        }
    }
}
