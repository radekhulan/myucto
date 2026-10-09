<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use DG\BypassFinals;
use MyInvoice\Action\Admin\Import\MyuctoMigrationAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Migration\Myucto\MyuctoImportWorkflow;
use MyInvoice\Service\Migration\Myucto\MyuctoImportJobService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class MyuctoMigrationActionTest extends TestCase
{
    public function testAnonymousAndBearerRequestsCannotUpload(): void
    {
        BypassFinals::enable();
        $workflow = $this->createMock(MyuctoImportWorkflow::class);
        $workflow->expects(self::never())->method('init');
        $action = new MyuctoMigrationAction($workflow, $this->createStub(MyuctoImportJobService::class));
        foreach ([null, 'bearer'] as $method) {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/myucto/uploads/chunked')
                ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);
            self::assertSame(403, $action->initChunked($request, (new ResponseFactory())->createResponse())->getStatusCode());
        }
    }

    public function testImportPermissionAloneDoesNotGrantGraphWrites(): void
    {
        BypassFinals::enable();
        $workflow = $this->createMock(MyuctoImportWorkflow::class);
        $workflow->expects(self::never())->method('init');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/myucto/uploads/chunked')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic role', 'staff', true, ['utilities.import' => 2]));
        self::assertSame(403, (new MyuctoMigrationAction($workflow, $this->createStub(MyuctoImportJobService::class)))->initChunked($request, (new ResponseFactory())->createResponse())->getStatusCode());
    }

    public function testSupplierAndActorComeFromTrustedRequestContext(): void
    {
        BypassFinals::enable();
        $workflow = $this->createStub(MyuctoImportWorkflow::class);
        $jobs = $this->createMock(MyuctoImportJobService::class);
        $jobs->expects(self::once())->method('launch')->with(12);
        $jobs->expects(self::once())->method('start')->with(4, 1, str_repeat('a', 16), 'synthetic', 'synthetic-password', false, false)->willReturn(['job_id' => 12, 'status' => 'queued']);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/myucto/uploads/token/run')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 4)
            ->withParsedBody(['supplier_id' => 99, 'actor_id' => 99, 'mode' => 'dry_run', 'source' => 'synthetic', 'password' => 'synthetic-password']);
        self::assertSame(202, (new MyuctoMigrationAction($workflow, $jobs))->run($request, (new ResponseFactory())->createResponse(), ['token' => str_repeat('a', 16)])->getStatusCode());
    }    public function testRunHistoryAlsoRequiresSessionAndGraphWritePermissions(): void
    {
        $workflow = $this->createStub(MyuctoImportWorkflow::class);
        $jobs = $this->createMock(MyuctoImportJobService::class);
        $jobs->expects(self::never())->method('history');
        $action = new MyuctoMigrationAction($workflow, $jobs);
        foreach (['bearer', 'session'] as $method) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/imports/myucto/runs')
                ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1])
                ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic role', 'staff', true, ['utilities.import' => 2]));
            self::assertSame(403, $action->runs($request, (new ResponseFactory())->createResponse())->getStatusCode());
        }
    }

    public function testGenericJobEndpointsCannotReadCancelOrDeleteNativeRuns(): void
    {
        $jobs = $this->createMock(\MyInvoice\Repository\ImportJobRepository::class);
        $jobs->expects(self::exactly(3))->method('find')->with(12, 4)->willReturn(['source' => MyuctoImportJobService::SOURCE]);
        $jobs->expects(self::never())->method('delete');
        $jobs->expects(self::never())->method('requestCancel');
        $logger = $this->createStub(\MyInvoice\Service\ActivityLogger::class);
        $ip = new \MyInvoice\Service\IpMatcher();
        $actions = [
            new \MyInvoice\Action\Admin\Import\ImportJobStatusAction($jobs),
            new \MyInvoice\Action\Admin\Import\CancelImportJobAction($jobs, $logger, $ip),
            new \MyInvoice\Action\Admin\Import\DeleteImportJobAction($jobs, $logger, $ip),
        ];
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/imports/12')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 4);
        foreach ($actions as $action) {
            self::assertContains($action($request, (new ResponseFactory())->createResponse(), ['id' => 12])->getStatusCode(), [403, 404]);
        }
    }

}
