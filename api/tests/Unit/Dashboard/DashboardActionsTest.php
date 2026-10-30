<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Dashboard;

use MyInvoice\Action\Dashboard\DashboardCatalogAction;
use MyInvoice\Action\Dashboard\DashboardLayoutAction;
use MyInvoice\Action\UserSettings\UserPreferenceAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Service\License\LicenseConsistency;
use Psr\Log\NullLogger;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Dashboard\DashboardLayoutService;
use MyInvoice\Service\Dashboard\DashboardWidgetAccess;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class DashboardActionsTest extends TestCase
{
    public function testEveryActionRejectsBearerAndMissingAuthBeforeReadingStorage(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $registry = new DashboardWidgetRegistry();
        $layout = new DashboardLayoutAction(new DashboardLayoutService($db, $registry));
        $catalog = new DashboardCatalogAction(new DashboardWidgetAccess($db, $registry, $this->consistency()));
        foreach (['bearer', null] as $method) {
            $request = $this->request()->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
            foreach (['get', 'put', 'delete'] as $operation) {
                $response = $layout->$operation($request, new Response(), ['board' => 'home']);
                self::assertSame(403, $response->getStatusCode());
                self::assertSame('session_required', json_decode((string) $response->getBody(), true)['error']['code']);
            }
            self::assertSame(403, $catalog($request, new Response())->getStatusCode());
        }
    }

    public function testBoardAndBodyValidationRejectUnknownOwnershipAndParameters(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $registry = new DashboardWidgetRegistry();
        $layout = new DashboardLayoutAction(new DashboardLayoutService($db, $registry));
        $catalog = new DashboardCatalogAction(new DashboardWidgetAccess($db, $registry, $this->consistency()));
        $request = $this->request();
        self::assertSame(422, $layout->get($request, new Response(), ['board' => 'invalid'])->getStatusCode());
        self::assertSame(422, $catalog($request->withQueryParams(['board' => []]), new Response())->getStatusCode());
        foreach ([null, [], ['revision' => 0], ['revision' => '0', 'layout' => [], 'user_id' => 2]] as $body) {
            self::assertSame(422, $layout->put($request->withParsedBody($body), new Response(), ['board' => 'home'])->getStatusCode());
        }
        self::assertSame(422, $layout->delete($request->withParsedBody(['revision' => '0', 'user_id' => 2]), new Response(), ['board' => 'home'])->getStatusCode());
    }

    public function testGenericPreferenceWritesCannotBypassDashboardValidation(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $generic = new UserPreferenceAction($db);
        foreach (['dashboard.home', 'dashboard.portal'] as $key) {
            self::assertSame(422, $generic->put($this->request(), new Response(), ['key' => $key])->getStatusCode());
            self::assertSame(422, $generic->delete($this->request(), new Response(), ['key' => $key])->getStatusCode());
        }
    }

    private function consistency(): LicenseConsistency
    {
        return new LicenseConsistency($this->createStub(Connection::class), new Config([]), EntityCache::disabled(), new NullLogger());
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('PUT', '/api/user/dashboard-layouts/home')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 654])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 123)
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Syntetický čtenář', 'staff', true, ['dashboard' => 1, 'profile' => 1]));
    }
}
