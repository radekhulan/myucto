<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action;

use MyInvoice\Action\Admin\UpdateAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\Update\VersionService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class InstanceAdminUpdatesTest extends TestCase
{
    private function updateStatusFixture(array $patch = []): array
    {
        return array_replace([
            'current' => '6.0.0', 'latest' => '6.0.1', 'has_update' => true,
            'last_check_at' => '2026-10-27T10:00:00Z', 'last_check_error' => null,
            'cache_stale' => false, 'upgrade_in_progress' => false,
            'upgrade_progress' => ['step_index' => 1, 'step_count' => 5, 'step_message' => '/synthetic/private'],
            'last_upgrade_result' => ['status' => 'applied', 'log_path' => '/synthetic/private'],
        ], $patch);
    }

    private function request(string $method = 'session', string $role = 'instance_admin')
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 42, 'email' => 'operator@example.test'])
            ->withAttribute('auth.effective_role', new EffectiveRole(8, 'Synthetic operator', $role, true, [], $role));
    }

    private function action(VersionService $version, ?ActivityLogger $audit = null, bool $managed = false, int $recent = 0): UpdateAction
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn($recent);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createStub(\MyInvoice\Infrastructure\Database\Connection::class);
        $db->method('pdo')->willReturn($pdo);
        return new UpdateAction($version, $audit ?? $this->createStub(ActivityLogger::class), new ManagedModeGuard(new Config(['app' => ['managed' => $managed]])), new \MyInvoice\Repository\UserSessionRepository($db));
    }

    public function testTechnicalCheckUsesExistingServiceAndReturnsOnlySafeProjection(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->expects(self::once())->method('refreshLatestVersion')->willReturn($this->updateStatusFixture());
        $version->expects(self::never())->method('triggerUpgrade');
        $response = $this->action($version)->refresh($this->request(), (new ResponseFactory())->createResponse());
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('/synthetic/private', $body);
        self::assertSame('6.0.1', json_decode($body, true)['latest']);
    }

    public function testTechnicalTriggerUsesLatestVersionAndExistingAuditedUpdater(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->expects(self::once())->method('getStatus')->willReturn($this->updateStatusFixture());
        $version->expects(self::once())->method('triggerUpgrade')->with('6.0.1', 'operator@example.test')
            ->willReturn(['status' => 'queued', 'target_version' => '6.0.1', 'environment' => 'native', 'message' => '/synthetic/private']);
        $audit = $this->createMock(ActivityLogger::class);
        $audit->expects(self::once())->method('log')->with('system.upgrade.trigger', 42, null, null, self::anything());
        $response = $this->action($version, $audit)->trigger($this->request()->withParsedBody(['target_version' => '6.0.1']), (new ResponseFactory())->createResponse());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('/synthetic/private', (string) $response->getBody());
        self::assertSame('queued', json_decode((string) $response->getBody(), true)['status']);
    }

    public function testTechnicalTriggerRejectsStaleUnavailableRunningAndArbitraryTargets(): void
    {
        foreach ([['cache_stale' => true], ['has_update' => false], ['last_check_error' => 'synthetic failure'], ['upgrade_in_progress' => true], ['latest' => null], ['latest' => 'invalid']] as $patch) {
            $version = $this->createMock(VersionService::class);
            $version->method('getStatus')->willReturn($this->updateStatusFixture($patch));
            $version->expects(self::never())->method('triggerUpgrade');
            self::assertSame(409, $this->action($version)->trigger($this->request(), (new ResponseFactory())->createResponse())->getStatusCode());
        }
        foreach (['5.0.0', ['6.0.1']] as $invalid) {
            $version = $this->createMock(VersionService::class);
            $version->method('getStatus')->willReturn($this->updateStatusFixture());
            $version->expects(self::never())->method('triggerUpgrade');
            self::assertSame(400, $this->action($version)->trigger($this->request()->withParsedBody(['target_version' => $invalid]), (new ResponseFactory())->createResponse())->getStatusCode());
        }
    }

    public function testManagedAndBearerAndOrdinaryRoleCannotStartOrCheckUpdates(): void
    {
        foreach (['refresh', 'trigger'] as $operation) {
            $version = $this->createMock(VersionService::class);
            $version->expects(self::never())->method('getStatus');
            $version->expects(self::never())->method('refreshLatestVersion');
            $version->expects(self::never())->method('triggerUpgrade');
            self::assertSame(409, $this->action($version, managed: true)->{$operation}($this->request(), (new ResponseFactory())->createResponse())->getStatusCode());
            self::assertSame(403, $this->action($version)->{$operation}($this->request('bearer'), (new ResponseFactory())->createResponse())->getStatusCode());
            self::assertSame(403, $this->action($version)->{$operation}($this->request(role: 'staff'), (new ResponseFactory())->createResponse())->getStatusCode());
        }
    }

    public function testOtherRecentSessionsRequireExplicitConfirmationBeforeAnyUpdate(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->method('getStatus')->willReturn($this->updateStatusFixture());
        $version->expects(self::once())->method('triggerUpgrade')->willReturn(['status' => 'queued']);
        $action = $this->action($version, recent: 3);
        $blocked = $action->trigger($this->request(), (new ResponseFactory())->createResponse());
        self::assertSame(409, $blocked->getStatusCode());
        $error = json_decode((string) $blocked->getBody(), true)['error'];
        self::assertSame('active_sessions', $error['code']);
        self::assertSame(3, $error['other_recent_sessions']);
        $confirmed = $action->trigger($this->request()->withParsedBody(['confirm_active_sessions' => true]), (new ResponseFactory())->createResponse());
        self::assertSame(200, $confirmed->getStatusCode());
    }

    public function testSuperadminAlsoMustConfirmOtherRecentSessions(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->expects(self::once())->method('triggerUpgrade')->willReturn(['status' => 'queued']);
        $action = $this->action($version, recent: 2);
        $request = $this->request(role: 'superadmin')->withParsedBody(['target_version' => '6.0.1']);
        self::assertSame(409, $action->trigger($request, (new ResponseFactory())->createResponse())->getStatusCode());
        self::assertSame(200, $action->trigger($request->withParsedBody(['target_version' => '6.0.1', 'confirm_active_sessions' => true]), (new ResponseFactory())->createResponse())->getStatusCode());
    }

    public function testTechnicalRoleCannotReadFullStatusPreflightOrCancel(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->expects(self::never())->method('getStatus');
        $version->expects(self::never())->method('nativePreflight');
        $version->expects(self::never())->method('cancelUpgrade');
        foreach (['status', 'preflight', 'cancel'] as $operation) {
            self::assertSame(403, $this->action($version)->{$operation}($this->request(), (new ResponseFactory())->createResponse())->getStatusCode());
        }
    }
}
