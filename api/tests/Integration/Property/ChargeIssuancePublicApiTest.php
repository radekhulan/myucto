<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Action\Property\Charges\Issuance\IssuanceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class ChargeIssuancePublicApiTest extends TestCase
{
    use ChargeIssuanceFixture;

    public function testActualReadResponsesHaveExactFinancialRowsWithoutPrivateHistoryAndStayTenantScoped(): void
    {
        $container=Bootstrap::buildContainer();$db=$container->get(Connection::class);
        self::assertStringEndsWith('_test',(string)$db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $db->pdo()->beginTransaction();
        try {
            $f=$this->issuanceFixture($db);$request=$f['issue_request'];
            $request['reason']='Private synthetic-person@example.test';
            $preview=(new IssuancePreviewService($db))->preview($f['supplier'],$request);
            $repo=$container->get(IssuanceRepository::class);
            $batch=$repo->issue($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],
                'idempotency_key'=>'synthetic-public-issue'],$f['actor']);
            $action=$container->get(IssuanceAction::class);
            $http=(new ServerRequestFactory())->createServerRequest('GET','/api/property/catalog/charges/issues')
                ->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer')
                ->withAttribute('auth.effective_role',new EffectiveRole(1,'Synthetic reader','staff',true,['property'=>1]))
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$f['supplier']);
            $response=$action->catalogList($http,new Response());
            self::assertSame(200,$response->getStatusCode());
            $body=json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR);
            self::assertCount(12,$body['items']);
            $sum='0';foreach($body['items'] as $charge) $sum=bcadd($sum,$charge['amount_cents'],0);
            self::assertSame('26400000',$sum);
            self::assertStringNotContainsString('synthetic-person@example.test',(string)$response->getBody());
            $first=$batch['charges'][0];$id=(string)$first['id'];
            $detail=$action->catalogDetail($http,new Response(),['id'=>$id]);
            self::assertSame(200,$detail->getStatusCode());
            $actual=json_decode((string)$detail->getBody(),true,flags:JSON_THROW_ON_ERROR);
            self::assertSame($first['amount_cents'],$actual['amount_cents']);
            self::assertSame($first['snapshot']['lines'][0]['dimensions'],$actual['lines'][0]['dimensions']);
            foreach(['snapshot','source_key','created_by','snapshot_hash','evidence','capabilities'] as $key) self::assertArrayNotHasKey($key,$actual);
            $foreign=$http->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->createIsolatedSupplier($db->pdo(),$f['supplier']));
            self::assertSame(404,$action->catalogDetail($foreign,new Response(),['id'=>$id])->getStatusCode());
            self::assertSame(['items'=>[]],json_decode((string)$action->catalogList($foreign,new Response())->getBody(),true,flags:JSON_THROW_ON_ERROR));
            $denied=$http->withAttribute('auth.effective_role',new EffectiveRole(1,'Synthetic denied','staff',true,[]));
            self::assertSame(403,$action->catalogList($denied,new Response())->getStatusCode());
        } finally {
            if($db->pdo()->inTransaction()) $db->pdo()->rollBack();
        }
    }
}
