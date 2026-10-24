<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\InstanceAdmin\InstanceAdminAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Repository\TechnicalSupplierAccessRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\InstanceAdmin\InstanceSmtpRepository;
use MyInvoice\Service\InstanceAdmin\NeutralSmtpTestSender;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\System\ManagedModeGuard;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class InstanceAdminTechnicalTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private InstanceSmtpRepository $smtp;
    private TechnicalSupplierAccessRepository $access;
    private InstanceAdminAction $action;
    private NeutralSmtpTestSender $sender;
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        $base = Config::load(Bootstrap::rootDir())->all();
        $base['app']['managed'] = false;
        $base['app']['pepper'] = 'synthetic-instance-admin-test-pepper';
        $base['app']['secret_encryption_key'] = base64_encode(str_repeat('k', 32));
        $base['smtp'] = ['host' => 'legacy.example.test', 'user' => 'legacy-user', 'pass' => 'synthetic-legacy-password',
            'port' => 587, 'encryption' => 'tls', 'auth_enabled' => true, 'from_email' => 'legacy@example.test'];
        $this->config = new Config($base);
        $this->db = new Connection($this->config);
        $pdo = $this->db->pdo();
        self::assertTrue($this->db->hasTable('instance_smtp_settings'), 'Test vyžaduje aplikovanou migraci 2135.');
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM instance_smtp_settings');
        $this->smtp = $this->repository($this->config);
        $this->access = new TechnicalSupplierAccessRepository($this->db);
        $this->userId = $this->createUser();
        $this->otherUserId = $this->createUser();
        $this->sender = new class extends NeutralSmtpTestSender {
            public array $sent = [];
            public bool $fail = false;
            public function send(array $settings): void
            {
                if ($this->fail) throw new \RuntimeException('Synthetic confidential SMTP reply password=do-not-expose');
                $this->sent[] = $settings;
            }
        };
        $this->action = new InstanceAdminAction($this->db, $this->config, $this->access, $this->smtp,
            $this->sender, new ActivityLogger($this->db), new ManagedModeGuard($this->config), new NullLogger());
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testNewTargetCannotReuseLegacyOrPreviousSecret(): void
    {
        $first = $this->target('first');
        $rejected = $this->action->saveSmtp($this->request(['target_id' => $first['id']]), new Response());
        self::assertSame(400, $rejected->getStatusCode());
        self::assertSame('smtp_target_requires_password', $this->json($rejected)['error']['code']);
        self::assertNull($this->smtp->runtime());

        $saved = $this->action->saveSmtp($this->request(['target_id' => $first['id'], 'password' => 'synthetic-first-secret']), new Response());
        self::assertSame(200, $saved->getStatusCode());
        $second = $this->target('second');
        $rejected = $this->action->saveSmtp($this->request(['target_id' => $second['id']]), new Response());
        self::assertSame(400, $rejected->getStatusCode());
        self::assertSame('smtp_target_requires_password', $this->json($rejected)['error']['code']);
        self::assertSame($first['id'], $this->smtp->runtime()['id']);
        self::assertSame('synthetic-first-secret', $this->smtp->runtime()['password']);

        $saved = $this->action->saveSmtp($this->request(['target_id' => $second['id'], 'password' => 'synthetic-second-secret']), new Response());
        self::assertSame(200, $saved->getStatusCode());
        self::assertSame($second['id'], $this->smtp->runtime()['id']);
        self::assertSame('synthetic-second-secret', $this->smtp->runtime()['password']);
    }

    public function testSecretIsEncryptedAndAbsentFromViewsAndAudits(): void
    {
        $target = $this->target('secret');
        $this->smtp->save($target['id'], 'synthetic-hidden-password');
        self::assertStringStartsWith('enc:v2:', $this->smtp->stored()['password_ciphertext']);
        $response = $this->action->saveSmtp($this->request(['target_id' => $target['id'], 'password' => 'synthetic-replacement']), new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('synthetic-hidden-password', (string) $response->getBody());
        self::assertStringNotContainsString('synthetic-replacement', (string) $response->getBody());
        self::assertStringNotContainsString('ciphertext', (string) $response->getBody());
        self::assertTrue($this->json($response)['password_set']);
        $payload = (string) $this->db->pdo()->query("SELECT payload FROM activity_log WHERE action = 'instance_admin.smtp_updated' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertStringNotContainsString('synthetic-replacement', $payload);
        self::assertTrue(json_decode($payload, true)['password_changed']);
    }

    public function testExistingPasswordCannotBeForwardedToAnotherApprovedTarget(): void
    {
        $first = $this->target('original');
        $second = $this->target('different');
        $this->smtp->save($first['id'], 'synthetic-original-secret');
        $response = $this->action->saveSmtp($this->request(['target_id' => $second['id']]), new Response());
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('smtp_target_requires_password', $this->json($response)['error']['code']);
        self::assertSame($first['id'], $this->smtp->runtime()['id']);
        self::assertSame('synthetic-original-secret', $this->smtp->runtime()['password']);
    }

    public function testOnlyApprovedTestRecipientIsUsedAndBodyCannotOverrideIt(): void
    {
        $target = $this->target('test');
        $this->smtp->save($target['id'], 'synthetic-secret');
        $response = $this->action->testSmtp($this->request(['to' => 'unapproved@example.test']), new Response());
        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->sender->sent);
        $response = $this->action->testSmtp($this->request([]), new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('receiver@example.test', $this->json($response)['test_recipient']);
        self::assertSame('receiver@example.test', $this->sender->sent[0]['test_recipient']);
        self::assertNotSame($this->request([])->getAttribute(AuthMiddleware::ATTR_USER)['email'], $this->sender->sent[0]['test_recipient']);
        self::assertSame(429, $this->action->testSmtp($this->request([]), new Response())->getStatusCode());
    }

    public function testGlobalCooldownCannotBeBypassedByAnotherUserOrSave(): void
    {
        $target = $this->target('cooldown');
        $this->smtp->save($target['id'], 'synthetic-secret');
        self::assertTrue($this->smtp->reserveTest($this->userId, $target['id']));
        $this->smtp->save($target['id'], null);
        self::assertFalse($this->smtp->reserveTest($this->otherUserId, $target['id']));
        $this->db->pdo()->exec('UPDATE instance_smtp_settings SET test_attempted_at = UTC_TIMESTAMP() - INTERVAL 61 SECOND');
        $this->db->pdo()->prepare('UPDATE instance_smtp_test_attempts SET attempted_at = UTC_TIMESTAMP() - INTERVAL 61 SECOND WHERE user_id = ?')->execute([$this->otherUserId]);
        self::assertTrue($this->smtp->reserveTest($this->otherUserId, $target['id']));
    }

    public function testFailureReturnsSafeCodeAndRetainsAudit(): void
    {
        $target = $this->target('failure');
        $this->smtp->save($target['id'], 'synthetic-secret');
        $this->sender->fail = true;
        $response = $this->action->testSmtp($this->request([]), new Response());
        self::assertSame(502, $response->getStatusCode());
        self::assertSame('smtp_test_failed', $this->json($response)['error']['code']);
        self::assertStringNotContainsString('do-not-expose', (string) $response->getBody());
        $audit = $this->json($this->action->audit($this->request([]), new Response()));
        self::assertSame(['started', 'failed'], array_column($audit['data'], 'result'));
        self::assertStringNotContainsString('confidential', json_encode($audit));
    }

    public function testSupplierScopeAndCustomProfileAreEnforcedWithoutReadingCompanyContent(): void
    {
        $first = $this->createSupplier('Synthetic A');
        $second = $this->createSupplier('Synthetic B');
        $this->access->replaceForUser($this->userId, false, [$first]);
        $request = $this->request([])->withHeader('X-Supplier-Id', (string) $first)->withQueryParams(['supplier_id' => $first]);
        self::assertSame(403, $this->action->supplierStatus($request, new Response(), ['id' => $second])->getStatusCode());
        $response = $this->action->supplierStatus($request, new Response(), ['id' => $first]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['supplier_id', 'name', 'mail_transport', 'smtp_test_supported'], array_keys($this->json($response)));
        self::assertStringNotContainsString('private@example.test', (string) $response->getBody());
        $this->db->pdo()->prepare("INSERT INTO email_profiles (supplier_id, name, code, from_email, transport_type, is_default) VALUES (?, 'Synthetic private profile', 'synthetic-private', 'private@example.test', 'smtp', 1)")->execute([$first]);
        $view = $this->json($this->action->supplierSmtp($request, new Response(), ['id' => $first]));
        self::assertSame('supplier_profile', $view['transport_scope']);
        self::assertFalse($view['test_supported']);
        $response = $this->action->testSmtp($request, new Response(), ['id' => $first]);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('smtp_supplier_profile_unsupported', $this->json($response)['error']['code']);
        self::assertSame([], $this->sender->sent);
    }

    public function testAuditUsesCurrentScopeAndOwnEventsOnly(): void
    {
        $first = $this->createSupplier('Synthetic A');
        $second = $this->createSupplier('Synthetic B');
        $this->access->replaceForUser($this->userId, false, [$first]);
        $insert = $this->db->pdo()->prepare("INSERT INTO instance_admin_events (user_id, supplier_id, action, result) VALUES (?, ?, 'instance_admin.smtp_test', 'sent')");
        $insert->execute([$this->userId, null]);
        $insert->execute([$this->userId, $first]);
        $insert->execute([$this->userId, $second]);
        $insert->execute([$this->otherUserId, $first]);
        $rows = $this->json($this->action->audit($this->request([]), new Response()))['data'];
        self::assertSame([null, $first], array_column($rows, 'supplier_id'));
        $this->access->replaceForUser($this->userId, false, []);
        $rows = $this->json($this->action->audit($this->request([]), new Response()))['data'];
        self::assertSame([null], array_column($rows, 'supplier_id'));
    }

    public function testManagedAndEnvironmentConfigurationDisableRuntimeOverride(): void
    {
        $target = $this->target('locked');
        $this->smtp->save($target['id'], 'synthetic-secret');
        $data = $this->config->all();
        $data['app']['managed'] = true;
        $managed = $this->repository(new Config($data));
        self::assertNull($managed->runtime());
        self::assertSame('managed', $managed->view()['read_only_reason']);
        self::assertSame([], $managed->view()['targets']);
        $old = getenv('MYINVOICE_SMTP_HOST');
        putenv('MYINVOICE_SMTP_HOST=environment.example.test');
        try {
            self::assertNull($this->smtp->runtime());
            self::assertSame('environment', $this->smtp->view()['read_only_reason']);
            self::assertSame(409, $this->action->saveSmtp($this->request(['target_id' => $target['id']]), new Response())->getStatusCode());
        } finally {
            putenv($old === false ? 'MYINVOICE_SMTP_HOST' : 'MYINVOICE_SMTP_HOST=' . $old);
        }
    }

    public function testTechnicalAccountCannotApproveTargetsAndBearerCannotUseActions(): void
    {
        self::assertSame(403, $this->action->createTarget($this->request(self::targetInput('denied')), new Response())->getStatusCode());
        self::assertSame(403, $this->action->targets($this->request([]), new Response())->getStatusCode());
        self::assertSame(403, $this->action->smtp($this->request([])->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'), new Response())->getStatusCode());
        self::assertSame(403, $this->action->smtp($this->request([])->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic staff', 'staff', true)), new Response())->getStatusCode());
    }

    public function testMailerRuntimeUsesApprovedRouteAndIdentity(): void
    {
        $target = $this->target('runtime');
        $this->smtp->save($target['id'], 'synthetic-secret');
        $mailer = new Mailer($this->config, new NullLogger(), $this->db, $this->createStub(EmailTemplateRepository::class));
        $runtime = new \ReflectionMethod($mailer, 'instanceSmtp')->invoke($mailer);
        self::assertSame($target['id'], $runtime['id']);
        self::assertSame('synthetic-secret', $runtime['password']);
        $transport = new \ReflectionMethod($mailer, 'buildTransport')->invoke($mailer);
        self::assertSame('smtp-user', $transport->getUsername());
        self::assertSame('smtp-runtime.example.test', $transport->getStream()->getHost());
    }

    public function testOnlySuperadminSessionCanResetOverrideWithoutDeletingApprovalOrChangingConfiguration(): void
    {
        $target = $this->target('reset');
        $this->smtp->save($target['id'], 'synthetic-override-secret');
        self::assertTrue($this->smtp->reserveTest($this->userId, $target['id']));
        $configuration = $this->config->all();
        self::assertSame(403, $this->action->resetSmtpOverride($this->request([]), new Response())->getStatusCode());
        self::assertSame($target['id'], $this->smtp->runtime()['id']);
        $admin = $this->superadminRequest([]);
        self::assertSame(403, $this->action->resetSmtpOverride($admin->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'), new Response())->getStatusCode());
        self::assertSame(400, $this->action->resetSmtpOverride($admin->withParsedBody(['password' => 'synthetic-forbidden']), new Response())->getStatusCode());
        self::assertSame($target['id'], $this->smtp->runtime()['id']);

        $response = $this->action->resetSmtpOverride($admin, new Response());
        self::assertSame(200, $response->getStatusCode());
        $view = $this->json($response);
        self::assertSame('configuration', $view['source']);
        self::assertNull($view['target_id']);
        self::assertFalse($view['password_set']);
        self::assertNull($view['test_recipient']);
        self::assertNull($this->smtp->runtime());
        self::assertSame($configuration, $this->config->all());
        self::assertNotNull($this->smtp->target($target['id']));
        self::assertStringNotContainsString('synthetic-override-secret', (string) $response->getBody());
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM instance_smtp_test_attempts WHERE user_id = ?');
        $stmt->execute([$this->userId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
        $stmt = $this->db->pdo()->prepare("SELECT payload FROM activity_log WHERE action = 'instance_admin.smtp_override_reset' AND user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$admin->getAttribute(AuthMiddleware::ATTR_USER)['id']]);
        $payload = (string) $stmt->fetchColumn();
        self::assertSame($target['id'], json_decode($payload, true)['previous_target_id']);
        self::assertStringNotContainsString('synthetic-override-secret', $payload);

        $mailer = new Mailer($this->config, new NullLogger(), $this->db, $this->createStub(EmailTemplateRepository::class));
        self::assertNull(new \ReflectionMethod($mailer, 'instanceSmtp')->invoke($mailer));
        $transport = new \ReflectionMethod($mailer, 'buildTransport')->invoke($mailer);
        self::assertSame('legacy.example.test', $transport->getStream()->getHost());
        self::assertSame('legacy-user', $transport->getUsername());
        self::assertSame(200, $this->action->resetSmtpOverride($admin, new Response())->getStatusCode());
        self::assertTrue($this->smtp->deleteTarget($target['id']));
    }

    public function testManagedAndEnvironmentLocksAlsoPreventReset(): void
    {
        $target = $this->target('reset-locked');
        $this->smtp->save($target['id'], 'synthetic-locked-secret');
        $admin = $this->superadminRequest([]);
        $data = $this->config->all();
        $data['app']['managed'] = true;
        $managedConfig = new Config($data);
        $managed = new InstanceAdminAction($this->db, $managedConfig, $this->access, $this->repository($managedConfig),
            $this->sender, new ActivityLogger($this->db), new ManagedModeGuard($managedConfig), new NullLogger());
        self::assertSame(409, $managed->resetSmtpOverride($admin, new Response())->getStatusCode());
        self::assertSame($target['id'], $this->smtp->runtime()['id']);
        $old = getenv('MYINVOICE_SMTP_HOST');
        putenv('MYINVOICE_SMTP_HOST=environment.example.test');
        try {
            self::assertSame(409, $this->action->resetSmtpOverride($admin, new Response())->getStatusCode());
            self::assertSame($target['id'], (int) $this->smtp->stored()['target_id']);
        } finally {
            putenv($old === false ? 'MYINVOICE_SMTP_HOST' : 'MYINVOICE_SMTP_HOST=' . $old);
        }
    }

    private function request(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/instance-admin/smtp/test')
            ->withParsedBody($body)->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'email' => 'unverified@example.test'])
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Správce instance', 'instance_admin', true, [], 'instance_admin'));
    }

    private function superadminRequest(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $admin = $this->createUser('superadmin');
        return $this->request($body)->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $admin, 'email' => 'synthetic-admin@example.test'])
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin'));
    }

    private function repository(Config $config): InstanceSmtpRepository
    {
        return new InstanceSmtpRepository($this->db, $config, new SecretEncryption($config), new ManagedModeGuard($config));
    }

    private function target(string $name): array
    {
        return $this->smtp->createTarget(self::targetInput($name), $this->userId);
    }

    private static function targetInput(string $name): array
    {
        return ['name' => 'Synthetic ' . $name, 'host' => 'smtp-' . $name . '.example.test', 'port' => 587,
            'encryption' => 'tls', 'username' => 'smtp-user', 'from_email' => 'sender@example.test', 'test_recipient' => 'receiver@example.test'];
    }

    private function createUser(string $systemKey = 'instance_admin'): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ?');
        $stmt->execute([$systemKey]);
        $roleId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $roleId);
        $this->db->pdo()->prepare("INSERT INTO users (email, password_hash, name, role, role_id) VALUES (?, 'synthetic-hash', 'Synthetic Technical User', 'readonly', ?)")
            ->execute(['technical-' . bin2hex(random_bytes(5)) . '@example.test', $roleId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createSupplier(string $name): int
    {
        $pdo = $this->db->pdo();
        $country = (int) $pdo->query('SELECT MIN(id) FROM countries')->fetchColumn();
        $currency = (int) $pdo->query('SELECT MIN(id) FROM currencies')->fetchColumn();
        $vat = (int) $pdo->query('SELECT MIN(id) FROM vat_rates')->fetchColumn();
        self::assertGreaterThan(0, min($country, $vat));
        // První firma má cyklický FK na vlastní měnu; stejný bootstrap používá SupplierCreator.
        $foreignKeys = (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
        $bootstrap = $currency === 0;
        if ($bootstrap) $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare("INSERT INTO supplier (company_name,street,city,zip,country_id,email,default_currency_id,default_vat_rate_id)
                VALUES (?, 'Synthetic Street', 'Synthetic City', '10000', ?, 'private@example.test', ?, ?)")->execute([$name, $country, $currency, $vat]);
            $supplier = (int) $pdo->lastInsertId();
            if ($currency === 0) {
                $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en) VALUES (?, 'CZK', 'Synthetic CZK', 'Kč', 'Syntetická měna', 'Synthetic currency')")->execute([$supplier]);
                $currency = (int) $pdo->lastInsertId();
                $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $supplier]);
            }
            return $supplier;
        } finally {
            if ($bootstrap) $pdo->exec('SET FOREIGN_KEY_CHECKS = ' . $foreignKeys);
        }
    }

    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
