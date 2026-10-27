<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\UserSessionRepository;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Group('integration')]
final class UserSessionAdminTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private SessionManager $manager;
    private array $users = [];
    private array $admin;
    private array $technical;
    private array $staff;

    protected function setUp(): void
    {
        $this->config = Config::load(Bootstrap::rootDir());
        $this->db = new Connection($this->config);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->manager = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
        $this->admin = $this->account('superadmin');
        $this->technical = $this->account('instance_admin');
        $this->staff = $this->account('readonly');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($this->users as $id) {
            $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM activity_log WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testListSeparatesTechnicalReadFromSuperadminAndNeverExposesSecrets(): void
    {
        $response = $this->request('GET', '/api/instance-admin/sessions', $this->admin);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $page = $this->json($response);
        self::assertTrue($page['can_terminate']);
        $own = $this->row($page, $this->admin['family']);
        self::assertTrue($own['is_current']);
        self::assertSame('127.0.0.1', $own['ip']);
        self::assertNotNull($own['email']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $own['last_seen']);
        foreach ([$this->admin, $this->technical, $this->staff] as $session) {
            self::assertStringNotContainsString($session['token'], (string) $response->getBody());
            self::assertStringNotContainsString($session['csrf_token'], (string) $response->getBody());
        }
        $technical = $this->request('GET', '/api/instance-admin/sessions', $this->technical);
        self::assertSame(200, $technical->getStatusCode(), (string) $technical->getBody());
        $readOnly = $this->json($technical);
        self::assertFalse($readOnly['can_terminate']);
        foreach ([$readOnly['current_session'], ...$readOnly['data']] as $row) {
            self::assertNull($row['email']);
            self::assertNull($row['ip']);
            self::assertNull($row['user_agent']);
        }
        self::assertSame(403, $this->request('GET', '/api/instance-admin/sessions', $this->staff)->getStatusCode());
        self::assertSame(401, $this->request('GET', '/api/instance-admin/sessions', null)->getStatusCode());
        foreach ([$this->technical, $this->staff] as $session) {
            self::assertSame(403, $this->request('DELETE', '/api/admin/user-sessions/' . $this->admin['family'], $session)->getStatusCode());
        }
        self::assertNotNull($this->manager->load($this->admin['token']));
    }

    public function testTerminationRevokesOnlySelectedFamilyAndIsAudited(): void
    {
        $other = $this->session($this->staff['user_id']);
        $response = $this->request('DELETE', '/api/admin/user-sessions/' . $this->staff['family'], $this->admin);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertFalse($this->json($response)['current_session_revoked']);
        self::assertNull($this->manager->load($this->staff['token']));
        self::assertNotNull($this->manager->load($other['token']));
        self::assertNotNull($this->manager->load($this->admin['token']));
        self::assertSame(401, $this->request('GET', '/api/auth/me', $this->staff)->getStatusCode());
        self::assertSame(404, $this->request('DELETE', '/api/admin/user-sessions/' . $this->staff['family'], $this->admin)->getStatusCode());
        $audit = $this->db->pdo()->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action = 'auth.session_revoked' AND entity_id = ?");
        $audit->execute([$this->admin['user_id'], $this->staff['user_id']]);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    public function testOwnSessionCanBeTerminatedAndCsrfIsRequired(): void
    {
        self::assertSame(403, $this->request('DELETE', '/api/admin/user-sessions/' . $this->admin['family'], $this->admin, false)->getStatusCode());
        self::assertNotNull($this->manager->load($this->admin['token']));
        $response = $this->request('DELETE', '/api/admin/user-sessions/' . $this->admin['family'], $this->admin);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($this->json($response)['current_session_revoked']);
        self::assertNull($this->manager->load($this->admin['token']));
    }

    public function testPageUsesDescendingLastSeenWithStableTieBreakAndExcludesInvalidSessions(): void
    {
        $tokens = [];
        for ($i = 0; $i < 55; $i++) $tokens[] = $this->session($this->staff['user_id']);
        $set = $this->db->pdo()->prepare('UPDATE sessions SET last_seen = FROM_UNIXTIME(?) WHERE id = ?');
        foreach ($tokens as $i => $session) $set->execute([time() - 60 - intdiv($i, 2), $session['token']]);
        $expired = $tokens[0];
        $this->db->pdo()->prepare('UPDATE sessions SET expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY) WHERE id = ?')->execute([$expired['token']]);
        $this->manager->destroy($tokens[1]['token']);
        $this->db->pdo()->prepare('UPDATE sessions SET replaced_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$tokens[2]['token']]);
        $all = [];
        $cursor = null;
        do {
            $response = $this->request('GET', '/api/instance-admin/sessions' . ($cursor ? '?cursor=' . urlencode($cursor) : ''), $this->admin);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $page = $this->json($response);
            self::assertSame($this->admin['family'], $page['current_session']['id']);
            self::assertLessThanOrEqual(50, count($page['data']) + 1);
            $all = [...$all, ...$page['data']];
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        $ids = array_column($all, 'id');
        self::assertCount(count(array_unique($ids)), $ids);
        foreach (array_slice($tokens, 0, 3) as $invalid) self::assertNotContains($invalid['family'], $ids);
        foreach (array_slice($tokens, 3) as $valid) self::assertContains($valid['family'], $ids);
        $sorted = $all;
        usort($sorted, static fn (array $a, array $b): int => strcmp($b['last_seen'], $a['last_seen']) ?: strcmp($b['id'], $a['id']));
        self::assertSame(array_column($sorted, 'id'), $ids);
    }

    public function testValidationAndExpiredSessionCannotBeRevoked(): void
    {
        self::assertSame(400, $this->request('GET', '/api/instance-admin/sessions?cursor=invalid', $this->admin)->getStatusCode());
        self::assertSame(400, $this->request('GET', '/api/instance-admin/sessions?cursor[]=invalid', $this->admin)->getStatusCode());
        self::assertSame(400, $this->request('DELETE', '/api/admin/user-sessions/invalid', $this->admin)->getStatusCode());
        self::assertSame(404, $this->request('DELETE', '/api/admin/user-sessions/' . str_repeat('a', 64), $this->admin)->getStatusCode());
    }

    public function testRevocationCoversReplacedGenerationAndRollsBackWhenAuditFails(): void
    {
        // Syntetická rotace: jedna lineage, nový token a předchozí nahrazená generace.
        $newToken = bin2hex(random_bytes(32));
        $this->db->pdo()->prepare('INSERT INTO sessions
            (id, user_id, csrf_token, ip, user_agent, created_at, last_seen, expires_at,
             auth_method, assurance_level, last_user_activity_at, session_family_id, generation)
            SELECT ?, user_id, csrf_token, ip, user_agent, created_at, last_seen, expires_at,
                   auth_method, assurance_level, last_user_activity_at, session_family_id, generation + 1
              FROM sessions WHERE id = ?')->execute([$newToken, $this->staff['token']]);
        $this->db->pdo()->prepare('UPDATE sessions SET replaced_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$this->staff['token']]);
        $target = (new UserSessionRepository($this->db))->revocable($this->staff['family']);
        self::assertSame($newToken, $target['token']);
        try {
            $this->manager->destroy($this->staff['token'], static function (): void { throw new \RuntimeException('Syntetické selhání auditu'); });
            self::fail('Selhání auditu se nesmí spolknout.');
        } catch (\RuntimeException $e) {
            self::assertSame('Syntetické selhání auditu', $e->getMessage());
        }
        self::assertNotNull($this->manager->load($newToken));
        self::assertSame(200, $this->request('DELETE', '/api/admin/user-sessions/' . $this->staff['family'], $this->admin)->getStatusCode());
        self::assertNull($this->manager->load($newToken));
        self::assertNotNull($this->manager->loadTombstoneForLogout($this->staff['token'])['revoked_at']);
    }

    public function testBulkTerminationValidatesWholeSelectionAndKeepsUnselectedSessions(): void
    {
        $other = $this->session($this->staff['user_id']);
        $keep = $this->session($this->staff['user_id']);
        $path = '/api/admin/user-sessions/terminate';
        foreach ([[], ['invalid'], array_fill(0, 51, $other['family'])] as $invalid) {
            self::assertSame(400, $this->request('POST', $path, $this->admin, true, ['ids' => $invalid])->getStatusCode());
        }
        self::assertSame(403, $this->request('POST', $path, $this->technical, true, ['ids' => [$other['family']]])->getStatusCode());
        self::assertSame(403, $this->request('POST', $path, $this->staff, true, ['ids' => [$other['family']]])->getStatusCode());
        self::assertSame(403, $this->request('POST', $path, $this->admin, false, ['ids' => [$other['family']]])->getStatusCode());
        self::assertSame(409, $this->request('POST', $path, $this->admin, true, ['ids' => [$other['family'], str_repeat('a', 64)]])->getStatusCode());
        self::assertNotNull($this->manager->load($other['token']));
        $response = $this->request('POST', $path, $this->admin, true, ['ids' => [$other['family'], $this->staff['family'], $other['family']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['terminated' => 2, 'current_session_revoked' => false], $this->json($response));
        self::assertNull($this->manager->load($other['token']));
        self::assertNull($this->manager->load($this->staff['token']));
        self::assertNotNull($this->manager->load($keep['token']));
        $audit = $this->db->pdo()->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action = 'auth.sessions_revoked'");
        $audit->execute([$this->admin['user_id']]);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    public function testBulkAuditFailureRollsBackAllFamiliesAndBulkCanEndOwnSession(): void
    {
        try {
            $this->manager->destroyMany([$this->staff['token'], $this->technical['token']], static function (): void { throw new \RuntimeException('Syntetické selhání hromadného auditu'); });
            self::fail('Selhání auditu se nesmí spolknout.');
        } catch (\RuntimeException $e) {
            self::assertSame('Syntetické selhání hromadného auditu', $e->getMessage());
        }
        self::assertNotNull($this->manager->load($this->staff['token']));
        self::assertNotNull($this->manager->load($this->technical['token']));
        $response = $this->request('POST', '/api/admin/user-sessions/terminate', $this->admin, true, ['ids' => [$this->admin['family'], $this->staff['family']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($this->json($response)['current_session_revoked']);
        self::assertNull($this->manager->load($this->admin['token']));
        self::assertNull($this->manager->load($this->staff['token']));
        self::assertNotNull($this->manager->load($this->technical['token']));
    }

    public function testActionsRejectBearerAuthenticationEvenForSuperadmin(): void
    {
        $action = new \MyInvoice\Action\Admin\UserSessionAction(
            new UserSessionRepository($this->db), $this->manager, new \MyInvoice\Service\ActivityLogger($this->db),
        );
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->admin['user_id'], 'is_superadmin' => true]);
        $factory = new \Slim\Psr7\Factory\ResponseFactory();
        self::assertSame(403, $action->list($request, $factory->createResponse())->getStatusCode());
        self::assertSame(403, $action->terminate($request, $factory->createResponse(), ['id' => $this->staff['family']])->getStatusCode());
        self::assertSame(403, $action->terminateSelected($request->withParsedBody(['ids' => [$this->staff['family']]]), $factory->createResponse())->getStatusCode());
        self::assertNotNull($this->manager->load($this->staff['token']));
    }

    public function testTechnicalAccountCanReadOnlySafeUpdateInformation(): void
    {
        $response = $this->request('GET', '/api/instance-admin/updates', $this->technical);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $status = $this->json($response);
        self::assertEqualsCanonicalizing(['current', 'latest', 'has_update', 'last_check_at', 'cache_stale', 'upgrade_in_progress', 'check_failed', 'managed', 'upgrade_progress', 'last_upgrade_result', 'other_recent_sessions'], array_keys($status));
        self::assertSame(trim((string) file_get_contents(Bootstrap::rootDir() . '/VERSION')), $status['current']);
        self::assertIsBool($status['check_failed']);
        self::assertSame(403, $this->request('GET', '/api/admin/update/status', $this->technical)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/admin/update/trigger', $this->technical)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/admin/update/refresh', $this->technical)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/instance-admin/updates', $this->staff)->getStatusCode());
    }

    public function testUpdateWarningCountsOtherRecentSessionsAndExcludesOwnExpiredAndRevoked(): void
    {
        $repo = new UserSessionRepository($this->db);
        $before = $repo->otherRecentCount($this->admin['family']);
        $fresh = $this->session($this->staff['user_id']);
        self::assertSame($before + 1, $repo->otherRecentCount($this->admin['family']));
        $this->db->pdo()->prepare('UPDATE sessions SET last_seen = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE) WHERE id = ?')->execute([$fresh['token']]);
        self::assertSame($before, $repo->otherRecentCount($this->admin['family']));
        $this->db->pdo()->prepare('UPDATE sessions SET last_seen = CURRENT_TIMESTAMP, expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY) WHERE id = ?')->execute([$fresh['token']]);
        self::assertSame($before, $repo->otherRecentCount($this->admin['family']));
        $this->manager->destroy($this->technical['token']);
        self::assertSame($before - 1, $repo->otherRecentCount($this->admin['family']));
    }

    public function testCurrentSessionNeverContributesToUpdateConfirmationGate(): void
    {
        $repo = new UserSessionRepository($this->db);
        $includingOwn = $repo->otherRecentCount('');
        $excludingOwn = $repo->otherRecentCount($this->admin['family']);
        self::assertSame($includingOwn - 1, $excludingOwn);
        $technicalView = $this->request('GET', '/api/instance-admin/updates', $this->admin);
        self::assertSame(200, $technicalView->getStatusCode(), (string) $technicalView->getBody());
        self::assertSame($excludingOwn, $this->json($technicalView)['other_recent_sessions']);
        $adminView = $this->request('GET', '/api/admin/update/status', $this->admin);
        self::assertSame(200, $adminView->getStatusCode(), (string) $adminView->getBody());
        self::assertSame($excludingOwn, $this->json($adminView)['other_recent_sessions']);
    }

    private function account(string $role): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ?');
        $stmt->execute([$role]);
        $roleId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $roleId);
        $this->db->pdo()->prepare("INSERT INTO users (email, name, password_hash, role, role_id, locale, is_active)
            VALUES (?, 'Syntetický uživatel relací', ?, ?, ?, 'cs', 1)")
            ->execute(['session-admin-' . bin2hex(random_bytes(6)) . '@example.test', password_hash('Synthetic session password', PASSWORD_DEFAULT), $role === 'superadmin' ? 'admin' : 'readonly', $roleId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->users[] = $id;
        return $this->session($id);
    }

    private function session(int $userId): array
    {
        $session = $this->manager->create($userId, '127.0.0.1', 'Synthetic Session Test', SessionAuthContext::strong('totp', new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        $session['user_id'] = $userId;
        $session['family'] = $this->manager->load($session['token'])['session_family_id'];
        return $session;
    }

    private function request(string $method, string $path, ?array $session, bool $csrf = true, ?array $body = null): ResponseInterface
    {
        $origin = rtrim((string) $this->config->get('app.url'), '/');
        $request = (new ServerRequestFactory())->createServerRequest($method, $origin . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Origin', $origin)->withHeader('Accept', 'application/json');
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($session !== null) {
            $request = $request->withCookieParams([(string) $this->config->get('session.cookie_name', '__Host-myinvoice_session') => $session['token']]);
            if ($csrf) $request = $request->withHeader('X-CSRF-Token', $session['csrf_token']);
        }
        if ($body !== null) $request = $request->withParsedBody($body);
        return Bootstrap::buildApp()->handle($request);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function row(array $page, string $family): array
    {
        if (($page['current_session']['id'] ?? null) === $family) return $page['current_session'];
        foreach ($page['data'] as $row) if ($row['id'] === $family) return $row;
        self::fail('Očekávaná relace v přehledu chybí.');
    }
}
