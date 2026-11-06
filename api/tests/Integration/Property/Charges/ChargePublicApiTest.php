<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property\Charges;

use MyInvoice\Action\Property\Charges\ChargeAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Property\Charges\ChargeReadRepository;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Service\Property\Charges\ChargeRegistrySnapshot;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class ChargePublicApiTest extends TestCase
{
    use PropertyChargeFixture;

    public function testCuratedActualResponsesPreserveFrozenCalculationAndEnforceTenant(): void
    {
        $db=Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test',(string)$db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $db->pdo()->beginTransaction();
        try {
            $f=$this->chargeFixture($db);
            $request=self::chargeRequest($f);$request['decision_ref']='Private charge-person@example.test';
            $preview=(new ChargePreviewService($db))->preview($f['supplier'],$request);
            $repo=new ChargeRepository($db);
            $saved=$repo->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'synthetic-api-version'],$f['actor']);
            $action=new ChargeAction($repo,new ChargeReadRepository($db),new ChargePreviewService($db),new ChargeRegistrySnapshot($db));
            $http=(new ServerRequestFactory())->createServerRequest('GET','/api/property/catalog/charges/versions')
                ->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer')
                ->withAttribute('auth.effective_role',new EffectiveRole(1,'Synthetic reader','staff',true,['property'=>1]))
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$f['supplier'])
                ->withQueryParams(['house_id'=>(string)$f['house']['id'],'as_of'=>$request['effective_from']]);
            $response=$action->catalogVersions($http,new Response());
            self::assertSame(200,$response->getStatusCode());
            $body=json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR);
            self::assertCount(1,$body['items']);
            self::assertSame($body['items'][0],$body['selected']);
            self::assertSame('26400000',$body['selected']['total_cents']);
            self::assertSame($preview['preview']['calculation']['unit_totals'],$body['selected']['unit_totals']);
            self::assertStringNotContainsString('charge-person@example.test',(string)$response->getBody());
            $detail=$action->catalogDetail($http,new Response(),['id'=>(string)$saved['id']]);
            self::assertSame($body['selected'],json_decode((string)$detail->getBody(),true,flags:JSON_THROW_ON_ERROR));
            $components=json_decode((string)$action->catalogComponents($http,new Response())->getBody(),true,flags:JSON_THROW_ON_ERROR);
            self::assertCount(3,$components['items']);
            foreach($components['items'] as $component) {
                self::assertArrayNotHasKey('created_by',$component);
                self::assertArrayNotHasKey('supplier_id',$component);
            }
            $other=$this->createIsolatedSupplier($db->pdo(),$f['supplier']);
            $foreign=$http->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$other);
            self::assertSame(404,$action->catalogDetail($foreign,new Response(),['id'=>(string)$saved['id']])->getStatusCode());
            $empty=json_decode((string)$action->catalogVersions($foreign,new Response())->getBody(),true,flags:JSON_THROW_ON_ERROR);
            self::assertSame([],$empty['items']);
            self::assertNull($empty['selected']);
        } finally {
            if($db->pdo()->inTransaction()) $db->pdo()->rollBack();
        }
    }
}
