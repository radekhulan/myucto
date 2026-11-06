<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Action\Settings\SupplierCarryOverAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\LlmProviderCapabilities;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Převzetí AI klíče a certifikátu do nově zakládané firmy.
 *
 * Nabízí se jen to, co je ve všech firmách uživatele stejné; cizí firma se do
 * porovnání nepočítá, firma bez AI nevadí. Certifikát chce stejný step-up jako
 * jeho sdílení v trezoru a bez něj firma nevznikne. Nepovedené převzetí založení
 * nezruší. Bez `carry_over` se firma zakládá beze změny.
 *
 * Vše běží v jedné transakci, která se na konci vrací. Klíče a certifikáty jsou
 * syntetické, poskytovatel AI se nevolá.
 */
#[Group('integration')]
final class SupplierCarryOverTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PASSWORD = 'Synteticke-Heslo-Prevzeti-7';
    private const MODEL = 'claude-haiku-4-5';

    private ContainerInterface $container;
    private Connection $db;
    private AnthropicClient $anthropic;
    private EpoSigningCredentialRepository $vault;
    private int $userId;
    private int $roleId;
    private int $a;
    private int $b;
    private int $noAi;
    private int $foreign;
    private string $key;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        if (!$this->db->hasTable('epo_signing_credential_suppliers')) {
            $this->markTestSkipped('Migrace trezoru certifikátů neproběhla.');
        }
        if ($this->container->get(SecretEncryption::class)->validateKey() !== null) {
            $this->markTestSkipped('Šifrovací klíč není nastaven.');
        }
        $this->anthropic = $this->container->get(AnthropicClient::class);
        $this->vault = $this->container->get(EpoSigningCredentialRepository::class);

        $pdo = $this->db->pdo();
        $this->roleId = (int) $pdo->query("SELECT id FROM roles WHERE system_key = 'admin_plus' LIMIT 1")->fetchColumn();
        if ($this->roleId === 0) {
            $this->markTestSkipped('Systémová role admin_plus v DB chybí.');
        }
        $pdo->beginTransaction();
        $grant = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE access_level = VALUES(access_level)'
        );
        foreach (['settings.ai_provider', 'settings.signing'] as $permission) {
            $grant->execute([$this->roleId, $permission, AccessLevel::WRITE->value]);
        }
        $pdo->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, ?, 'Synthetic carry-over user', ?, 'cs', 1)"
        )->execute([
            'carry-over-' . bin2hex(random_bytes(6)) . '@example.invalid',
            $this->container->get(PasswordHasher::class)->hash(self::PASSWORD),
            $this->roleId,
        ]);
        $this->userId = (int) $pdo->lastInsertId();

        $template = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->a = $this->supplier($template);
        $this->b = $this->supplier($template);
        $this->noAi = $this->supplier($template);
        $this->foreign = $this->supplier($template);
        $assign = $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)');
        foreach ([$this->a, $this->b, $this->noAi] as $sid) {
            $assign->execute([$this->userId, $sid]);
        }

        $this->key = 'sk-ant-synthetic-' . bin2hex(random_bytes(12));
        $this->anthropic->setCredentials($this->a, $this->key, self::MODEL);
        $this->anthropic->setCredentials($this->b, $this->key, self::MODEL);
        $this->anthropic->setCredentials($this->foreign, 'sk-ant-synthetic-foreign', self::MODEL);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testPreviewOffersAiSharedByAllConfiguredCompaniesAndIgnoresForeignOne(): void
    {
        $ai = $this->preview()['ai'];

        self::assertTrue($ai['available']);
        self::assertSame('anthropic', $ai['provider']);
        self::assertSame(2, $ai['companies_count'], 'firma bez AI se nepočítá a nevadí');
        self::assertCount(2, $ai['companies']);
        self::assertStringNotContainsString($this->key, json_encode($this->preview(), JSON_THROW_ON_ERROR));
    }

    public function testPreviewDoesNotOfferAiWhenKeysDiffer(): void
    {
        $this->anthropic->setCredentials($this->b, 'sk-ant-synthetic-other', self::MODEL);

        $ai = $this->preview()['ai'];

        self::assertFalse($ai['available']);
        self::assertSame('different', $ai['reason']);
        self::assertNull($ai['provider']);
    }

    public function testPreviewDoesNotOfferAiWhenModelDiffers(): void
    {
        $this->anthropic->setCredentials($this->b, $this->key, 'claude-sonnet-4-5');

        self::assertFalse($this->preview()['ai']['available']);
    }

    public function testPreviewOffersOnlyValidCertificateEnabledInEveryCompany(): void
    {
        $everywhere = $this->certificate([$this->a, $this->b, $this->noAi]);
        $this->certificate([$this->a]);
        $this->certificate([$this->a, $this->b, $this->noAi], '2000-01-01 00:00:00', '2001-01-01 00:00:00');

        $certificates = $this->preview()['certificates'];

        self::assertSame([$everywhere], array_column($certificates, 'id'));
        self::assertSame(3, $certificates[0]['companies_count']);
        self::assertSame('Synthetic Signer', $certificates[0]['subject']);
        self::assertArrayNotHasKey('pfx_ciphertext', $certificates[0]);
    }

    public function testPreviewEndpointRequiresSessionAndSupplierCreation(): void
    {
        $action = $this->container->get(SupplierCarryOverAction::class);

        $bearer = $action($this->request('GET', '/api/suppliers/carry-over')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'), new Psr7Response());
        self::assertSame(403, $bearer->getStatusCode());

        $staff = $action($this->request('GET', '/api/suppliers/carry-over')
            ->withAttribute('auth.effective_role', new EffectiveRole($this->roleId, 'Staff', 'staff', true, [])), new Psr7Response());
        self::assertSame(403, $staff->getStatusCode());
    }

    public function testCreatingWithoutCarryOverStaysUnchanged(): void
    {
        [$status, $body] = $this->create([]);

        self::assertSame(201, $status);
        self::assertSame(['id'], array_keys($body), 'odpověď bez carry_over beze změny');
        self::assertNull($this->rawKey((int) $body['id']));
    }

    public function testAiIsCarriedIntoNewCompany(): void
    {
        [$status, $body] = $this->create(['carry_over' => ['ai' => true]]);

        self::assertSame(201, $status);
        $id = (int) $body['id'];
        self::assertSame(['ok' => true, 'provider' => 'anthropic'], $body['carry_over_result']['ai']);
        $creds = $this->anthropic->getCredentials($id);
        self::assertSame($this->key, $creds['api_key'] ?? null);
        self::assertSame(self::MODEL, $creds['default_model'] ?? null);
        self::assertSame('anthropic', $this->column($id, 'ai_provider'));
        self::assertNotSame($this->rawKey($this->a), $this->rawKey($id), 'nová firma má vlastní šifrovanou kopii');
        self::assertSame(1, $this->auditCount('import.ai_credentials_set', $id));
        self::assertSame(1, $this->auditCount('supplier.carry_over', $id));
    }

    /** Nepovedené převzetí (klíče se mezitím rozešly, cizí certifikát) firmu nezruší. */
    public function testFailedCarryOverKeepsTheNewCompany(): void
    {
        $this->anthropic->setCredentials($this->b, 'sk-ant-synthetic-other', self::MODEL);
        $stranger = $this->stranger();
        $foreignCertificate = $this->certificate([], owner: $stranger);

        [$status, $body] = $this->create(['carry_over' => [
            'ai' => true,
            'certificate_ids' => [$foreignCertificate],
            'step_up' => ['password' => self::PASSWORD],
        ]]);

        self::assertSame(201, $status);
        $id = (int) $body['id'];
        self::assertSame(['ok' => false, 'error' => 'different'], $body['carry_over_result']['ai']);
        self::assertSame(
            [['id' => $foreignCertificate, 'ok' => false, 'error' => 'not_owned']],
            $body['carry_over_result']['certificates'],
        );
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM supplier WHERE id = ?', [$id]));
        self::assertNull($this->rawKey($id));
        self::assertFalse($this->vault->isEnabledForSupplier($foreignCertificate, $id));
    }

    public function testCertificateIsEnabledInNewCompanyAfterStepUp(): void
    {
        $certificate = $this->certificate([$this->a, $this->b, $this->noAi]);

        [$status, $body] = $this->create(['carry_over' => [
            'certificate_ids' => [$certificate],
            'step_up' => ['password' => self::PASSWORD],
        ]]);

        self::assertSame(201, $status, json_encode($body));
        $id = (int) $body['id'];
        self::assertSame([['id' => $certificate, 'ok' => true]], $body['carry_over_result']['certificates']);
        self::assertArrayNotHasKey('ai', $body['carry_over_result']);
        self::assertTrue($this->vault->isEnabledForSupplier($certificate, $id));
        self::assertSame(1, $this->auditCount('certificate_vault_supplier_enabled', $id));
        self::assertStringNotContainsString(self::PASSWORD, (string) $this->scalar(
            "SELECT GROUP_CONCAT(payload) FROM activity_log WHERE supplier_id = ? OR entity_id = ?",
            [$id, $id],
        ));
    }

    public function testCertificateWithoutStepUpIsRefusedAndNoCompanyIsCreated(): void
    {
        $certificate = $this->certificate([$this->a, $this->b, $this->noAi]);
        $before = (int) $this->scalar('SELECT COUNT(*) FROM supplier', []);

        [$status, $body] = $this->create(['carry_over' => ['ai' => true, 'certificate_ids' => [$certificate]]]);

        self::assertSame(401, $status);
        self::assertSame('invalid_password', $body['error']['code']);
        self::assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM supplier', []));
    }

    public function testCertificateNotSharedEverywhereIsRefused(): void
    {
        $partial = $this->certificate([$this->a]);

        [$status, $body] = $this->create(['carry_over' => [
            'certificate_ids' => [$partial],
            'step_up' => ['password' => self::PASSWORD],
        ]]);

        self::assertSame(201, $status);
        self::assertSame('not_shared_everywhere', $body['carry_over_result']['certificates'][0]['error']);
        self::assertFalse($this->vault->isEnabledForSupplier($partial, (int) $body['id']));
    }

    public function testCarryOverRefusesTokenAuthentication(): void
    {
        $before = (int) $this->scalar('SELECT COUNT(*) FROM supplier', []);

        [$status] = $this->create(['carry_over' => ['ai' => true]], 'bearer');

        self::assertSame(403, $status);
        self::assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM supplier', []));
    }

    public function testInvalidCarryOverShapeIsValidationError(): void
    {
        [$status, $body] = $this->create(['carry_over' => ['certificate_ids' => ['x']]]);

        self::assertSame(400, $status);
        self::assertSame('validation_failed', $body['error']['code']);
    }

    /**
     * Superadmin s jiným členstvím k nové firmě řádek `user_suppliers` nedostane;
     * převzetí přesto míří do ní. Firmy superadmina jsou všechny, proto se tu
     * sjednotí AI i certifikát v celé testovací databázi (v transakci).
     */
    public function testSuperadminWithoutMembershipInNewCompanyReceivesCarryOver(): void
    {
        $pdo = $this->db->pdo();
        $all = array_map('intval', $pdo->query('SELECT id FROM supplier')->fetchAll(\PDO::FETCH_COLUMN));
        $effort = $this->effortSql();
        $pdo->exec("UPDATE supplier SET ai_provider = 'anthropic', ai_data_region = 'us', ai_eu_residency_required = 0{$effort}");
        foreach ($all as $sid) {
            $this->anthropic->setCredentials($sid, $this->key, self::MODEL);
        }
        $certificate = $this->certificate($all);

        $preview = $this->preview(true);
        self::assertTrue($preview['ai']['available']);
        self::assertSame(count($all), $preview['ai']['companies_count']);
        self::assertContains($certificate, array_column($preview['certificates'], 'id'));

        [$status, $body] = $this->create(['carry_over' => [
            'ai' => true,
            'certificate_ids' => [$certificate],
            'step_up' => ['password' => self::PASSWORD],
        ]], 'session', true);

        self::assertSame(201, $status, json_encode($body));
        $id = (int) $body['id'];
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM user_suppliers WHERE supplier_id = ?', [$id]));
        self::assertTrue($body['carry_over_result']['ai']['ok']);
        self::assertSame([['id' => $certificate, 'ok' => true]], $body['carry_over_result']['certificates']);
        self::assertSame($this->key, $this->anthropic->getCredentials($id)['api_key'] ?? null);
        self::assertTrue($this->vault->isEnabledForSupplier($certificate, $id));
    }

    /** @return array<string,mixed> */
    private function preview(bool $superadmin = false): array
    {
        $response = $this->container->get(SupplierCarryOverAction::class)(
            $this->request('GET', '/api/suppliers/carry-over', $superadmin),
            new Psr7Response(),
        );
        $response->getBody()->rewind();
        $raw = $response->getBody()->getContents();
        self::assertSame(200, $response->getStatusCode(), $raw);

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function create(array $body, string $method = 'session', bool $superadmin = false): array
    {
        $request = $this->request('POST', '/api/suppliers', $superadmin)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0)
            ->withParsedBody($body + [
                'company_name' => '__TEST Převzetí ' . bin2hex(random_bytes(3)),
                'street' => 'Testovací 1',
                'city' => 'Zkušební Lhota',
                'zip' => '11111',
                'email' => 'prevzeti@example.test',
                'taxpayer_type' => 'fo',
                'is_vat_payer' => false,
            ]);
        $response = $this->container->get(SettingsAction::class)->createSupplier($request, new Psr7Response());
        $response->getBody()->rewind();

        return [$response->getStatusCode(), json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR)];
    }

    private function request(string $method, string $path, bool $superadmin = false): Request
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '192.0.2.10'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->a)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'role_id' => $this->roleId,
                'is_superadmin' => $superadmin,
                'role_summary' => ['id' => $this->roleId, 'type' => $superadmin ? 'superadmin' : 'staff'],
            ])
            ->withAttribute('auth.effective_role', $superadmin
                ? new EffectiveRole($this->roleId, 'Superadmin', 'superadmin', true, [], 'superadmin')
                : new EffectiveRole($this->roleId, 'Admin Plus', 'staff', true, [
                    'settings.ai_provider' => AccessLevel::WRITE->value,
                    'settings.signing' => AccessLevel::WRITE->value,
                ], 'admin_plus'));
    }

    private function supplier(int $template): int
    {
        $id = $this->createIsolatedSupplier($this->db->pdo(), $template);
        $effort = $this->effortSql();
        $this->db->pdo()->prepare(
            "UPDATE supplier SET ai_provider = 'anthropic', ai_data_region = 'us', ai_eu_residency_required = 0{$effort},
                anthropic_api_key_enc = NULL, azure_openai_api_key_enc = NULL, openai_api_key_enc = NULL,
                gemini_api_key_enc = NULL WHERE id = ?"
        )->execute([$id]);

        return $id;
    }

    private function effortSql(): string
    {
        return $this->db->hasColumn('supplier', 'ai_effort')
            ? ", ai_effort = '" . LlmProviderCapabilities::EFFORT_DEFAULT . "'"
            : '';
    }

    /** @param list<int> $suppliers */
    private function certificate(
        array $suppliers,
        string $validFrom = '2020-01-01 00:00:00',
        string $validTo = '2099-01-01 00:00:00',
        ?int $owner = null,
    ): int {
        $owner ??= $this->userId;
        $id = $this->vault->create($owner, [
            'label' => 'Syntetický certifikát',
            'pfx_ciphertext' => 'enc:v1:synthetic',
            'passphrase_ciphertext' => 'enc:v1:synthetic',
            'fingerprint_sha256' => hash('sha256', 'synthetic-carry-over-' . random_bytes(8)),
            'subject_dn' => 'CN=Synthetic Signer, O=Synthetic',
            'issuer_dn' => 'CN=Synthetic Test CA',
            'serial_hex' => '0B',
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'ik_mpsv_present' => false,
        ]);
        foreach ($suppliers as $sid) {
            $this->vault->setSupplierEnabled($id, $owner, $sid, true, $owner);
        }

        return $id;
    }

    private function stranger(): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, 'disabled-test-password', 'Synthetic stranger', ?, 'cs', 1)"
        )->execute(['carry-stranger-' . bin2hex(random_bytes(6)) . '@example.invalid', $this->roleId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function rawKey(int $supplierId): ?string
    {
        $value = $this->column($supplierId, 'anthropic_api_key_enc');

        return $value === null || $value === '' ? null : $value;
    }

    private function column(int $supplierId, string $column): ?string
    {
        $value = $this->scalar("SELECT {$column} FROM supplier WHERE id = ?", [$supplierId]);

        return $value === false || $value === null ? null : (string) $value;
    }

    private function auditCount(string $action, int $supplierId): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM activity_log WHERE action = ? AND supplier_id = ?',
            [$action, $supplierId],
        );
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }
}
