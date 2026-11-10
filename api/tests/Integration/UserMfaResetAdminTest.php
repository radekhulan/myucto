<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\StepUpOperationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Superadmin resetuje MFA jinému uživateli a potvrzuje to vlastním MFA.
 */
#[Group('integration')]
final class UserMfaResetAdminTest extends TestCase
{
    private const REASON = 'Ztracený telefon, ověřeno telefonicky.';

    private Connection $db;
    private Config $config;
    private SessionManager $manager;
    private MfaStepUpService $stepUp;
    /** @var list<int> */
    private array $users = [];
    private array $admin;
    private array $otherAdmin;
    private array $technical;
    private array $staff;

    protected function setUp(): void
    {
        $this->config = Config::load(Bootstrap::rootDir());
        $this->db = new Connection($this->config);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->manager = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
        $container = Bootstrap::buildApp()->getContainer();
        $this->stepUp = $container->get(MfaStepUpService::class);
        $this->admin = $this->account('superadmin');
        $this->otherAdmin = $this->account('superadmin');
        $this->technical = $this->account('instance_admin');
        $this->staff = $this->account('readonly');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($this->users as $id) {
            foreach (['sessions', 'mfa_recovery_codes', 'api_tokens', 'trusted_devices', 'mfa_step_up_proofs', 'activity_log'] as $table) {
                $pdo->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$id]);
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testSuperadminResetsAnotherSuperadminAfterConfirmingWithOwnFactor(): void
    {
        $targetId = $this->otherAdmin['user_id'];
        $this->withFactors($targetId);
        $token = $this->apiToken($targetId);

        $response = $this->reset($this->admin, $targetId, self::REASON, $this->proof($this->admin, $targetId));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertTrue($body['totp_was_enabled']);
        self::assertArrayHasKey('mail_sent', $body);
        $user = $this->db->pdo()->query("SELECT totp_enabled, totp_secret FROM users WHERE id = {$targetId}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $user['totp_enabled']);
        self::assertNull($user['totp_secret']);
        self::assertSame(0, $this->rows('mfa_recovery_codes', $targetId));
        self::assertSame(0, $this->rows('trusted_devices', $targetId));
        self::assertNull($this->manager->load($this->otherAdmin['token']), 'Uživatel je odhlášený ze všech zařízení.');
        self::assertNotNull($this->manager->load($this->admin['token']), 'Superadmin zůstal přihlášený.');
        self::assertNull(
            $this->db->pdo()->query("SELECT revoked_at FROM api_tokens WHERE id = {$token}")->fetchColumn(),
            'API tokeny reset nechává.',
        );

        $audit = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log WHERE user_id = ? AND action = 'user.mfa_reset' AND entity_id = ?"
        );
        $audit->execute([$this->admin['user_id'], $targetId]);
        $payload = json_decode((string) $audit->fetchColumn(), true);
        self::assertSame(self::REASON, $payload['reason']);
        self::assertSame('totp', $payload['confirmed_by']);
        self::assertSame(1, $payload['recovery_codes_revoked']);
    }

    public function testResetNeedsProofBoundToTheTargetUser(): void
    {
        $targetId = $this->staff['user_id'];
        $this->withFactors($targetId);

        $missing = $this->reset($this->admin, $targetId, self::REASON, '');
        self::assertSame(403, $missing->getStatusCode(), (string) $missing->getBody());
        self::assertSame('step_up_required', $this->json($missing)['error']['code']);

        $other = $this->reset($this->admin, $targetId, self::REASON, $this->proof($this->admin, $this->otherAdmin['user_id']));
        self::assertSame(403, $other->getStatusCode(), 'Potvrzení resetu jiného účtu neplatí.');

        self::assertSame(1, (int) $this->db->pdo()->query("SELECT totp_enabled FROM users WHERE id = {$targetId}")->fetchColumn());
        self::assertNotNull($this->manager->load($this->staff['token']));
    }

    public function testRecoveryCodeCannotConfirmReset(): void
    {
        $this->expectException(StepUpOperationException::class);
        $this->stepUp->issue(
            $this->admin['user_id'],
            $this->admin['token'],
            MfaStepUpService::userMfaResetOperation($this->staff['user_id']),
            'recovery',
        );
    }

    public function testOnlySuperadminMayResetAndNeverOwnAccount(): void
    {
        $targetId = $this->staff['user_id'];
        foreach ([$this->technical, $this->staff] as $session) {
            $response = $this->reset($session, $this->otherAdmin['user_id'], self::REASON, '');
            self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        }
        self::assertNotNull($this->manager->load($this->otherAdmin['token']));

        $self = $this->reset($this->admin, $this->admin['user_id'], self::REASON, $this->proof($this->admin, $this->admin['user_id']));
        self::assertSame(409, $self->getStatusCode(), (string) $self->getBody());
        self::assertSame('mfa_reset_self', $this->json($self)['error']['code']);

        $short = $this->reset($this->admin, $targetId, 'telefon', $this->proof($this->admin, $targetId));
        self::assertSame(422, $short->getStatusCode(), (string) $short->getBody());
    }

    private function withFactors(int $userId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE users SET totp_enabled = 1, totp_secret = 'enc:v1:synthetic' WHERE id = ?")->execute([$userId]);
        $pdo->prepare('INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (?, ?)')
            ->execute([$userId, hash('sha256', 'SYNTHETIC' . $userId)]);
    }

    private function apiToken(int $userId): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO api_tokens (user_id, name, token_hash, prefix) VALUES (?, 'Syntetický token', ?, 'mi_test')"
        )->execute([$userId, hash('sha256', random_bytes(16))]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function proof(array $session, int $targetId): string
    {
        return $this->stepUp->issue(
            $session['user_id'],
            $session['token'],
            MfaStepUpService::userMfaResetOperation($targetId),
            'totp',
        );
    }

    private function reset(array $session, int $targetId, string $reason, string $proof): ResponseInterface
    {
        return $this->request('POST', "/api/admin/users/{$targetId}/mfa-reset", $session, [
            'reason' => $reason,
            'step_up_token' => $proof,
        ]);
    }

    private function rows(string $table, int $userId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = ?");
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    private function account(string $role): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ?');
        $stmt->execute([$role]);
        $roleId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $roleId);
        $this->db->pdo()->prepare("INSERT INTO users (email, name, password_hash, role, role_id, locale, is_active)
            VALUES (?, 'Syntetický uživatel MFA', ?, ?, ?, 'cs', 1)")
            ->execute(['mfa-reset-' . bin2hex(random_bytes(6)) . '@example.test', password_hash('Synthetic MFA password', PASSWORD_DEFAULT), $role === 'superadmin' ? 'admin' : 'readonly', $roleId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->users[] = $id;
        $session = $this->manager->create($id, '127.0.0.1', 'Synthetic MFA Reset Test', SessionAuthContext::strong('totp', new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        $session['user_id'] = $id;

        return $session;
    }

    private function request(string $method, string $path, array $session, array $body): ResponseInterface
    {
        $origin = rtrim((string) $this->config->get('app.url'), '/');
        $request = (new ServerRequestFactory())->createServerRequest($method, $origin . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Origin', $origin)->withHeader('Accept', 'application/json')
            ->withCookieParams([(string) $this->config->get('session.cookie_name', '__Host-myinvoice_session') => $session['token']])
            ->withHeader('X-CSRF-Token', $session['csrf_token'])
            ->withParsedBody($body);

        return Bootstrap::buildApp()->handle($request);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
