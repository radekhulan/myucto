<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;
use MyInvoice\Action\Codebook\TaxConstantsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
final class NonprofitAnnualConstantsLegacySaveTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testLegacyPayloadCanSaveKnownYearWithoutLosingExplicitOrStoredValues(bool $existing):void
    {
        $container=Bootstrap::buildContainer();$db=$container->get(Connection::class);$pdo=$db->pdo();self::assertStringEndsWith('_test',(string)$pdo->query('SELECT DATABASE()')->fetchColumn());$pdo->beginTransaction();
        try{
            $repo=new TaxConstantsRepository($db);$repo->reset(2025);if($existing)$repo->upsert(2025,['nonprofit_reduction_max'=>500000,'nonprofit_reduction_rate'=>0.2]);
            $payload=TaxConstants::forYear(2025);foreach(array_keys(\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoReduction::configuration($payload))as$key)unset($payload[$key]);unset($payload['tax_loss_carryback_years'],$payload['tax_loss_carryback_limit']);$payload['credit_taxpayer']=99999;
            $request=(new ServerRequestFactory())->createServerRequest('PUT','/api/codebooks/tax-constants/2025')->withAttribute(AuthMiddleware::ATTR_USER,['id'=>(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn(),'role'=>'admin'])->withAttribute(AuthMiddleware::ATTR_METHOD,'session')->withParsedBody(['data'=>$payload]);
            $response=$container->get(TaxConstantsAction::class)->update($request,new Response(),['year'=>'2025']);self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());$saved=$repo->forExactYear(2025);self::assertSame(99999,$saved['credit_taxpayer']);self::assertSame($existing?500000:1000000,$saved['nonprofit_reduction_max']);self::assertSame($existing?0.2:0.3,$saved['nonprofit_reduction_rate']);self::assertSame(2,$saved['tax_loss_carryback_years']);
            $request=$request->withParsedBody(['data'=>['nonprofit_reduction_rate'=>0]]);self::assertSame(200,$container->get(TaxConstantsAction::class)->update($request,new Response(),['year'=>'2025'])->getStatusCode());self::assertSame(0,$repo->forExactYear(2025)['nonprofit_reduction_rate']);
            $repo->reset(2099);$request=$request->withParsedBody(['data'=>['credit_taxpayer'=>99999]]);self::assertSame(422,$container->get(TaxConstantsAction::class)->update($request,new Response(),['year'=>'2099'])->getStatusCode());self::assertNull($repo->override(2099));
        }finally{if($pdo->inTransaction())$pdo->rollBack();}
    }
}
