<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Action\Property\Payments\PaymentOperationsAction;
use MyInvoice\Middleware\{AuthMiddleware,SupplierScopeMiddleware};
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PaymentOperationsActionTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture;
    private Connection $db;
    private array $f;
    protected function setUp():void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));
        self::assertSame(getenv('MYINVOICE_DB_NAME'),$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);
    }
    protected function tearDown():void {if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    public function testReserveRequiresSessionAndSupportsExactRetryAndCurrentVersion():void
    {
        $base=$this->settledPaymentFixture('600','400');$id=$base['operation']['credit_id'];$action=Bootstrap::buildContainer()->get(PaymentOperationsAction::class);
        $body=['expected_version'=>1,'idempotency_key'=>'synthetic-http-reserve','request'=>['amount_cents'=>'100','party_ids'=>[$this->f['clients'][1]],'disposition'=>'Synthetic entitled persons instruction','recipient_account'=>'1000000005/0100']];
        $request=(new ServerRequestFactory())->createServerRequest('POST','/property/payments/credits/'.$id.'/reserve')->withParsedBody($body)
            ->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->f['actor'],'role'=>'admin'])->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->f['supplier']);
        $denied=$action->reserve($request->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer'),new Response(),['id'=>(string)$id]);self::assertSame(403,$denied->getStatusCode());
        self::assertSame('0',$this->correctionPayments()->credit($this->f['supplier'],$id)['reserved_cents']);
        $session=$request->withAttribute(AuthMiddleware::ATTR_METHOD,'session');
        $result=$action->reserve($session,new Response(),['id'=>(string)$id]);self::assertSame(200,$result->getStatusCode());$saved=json_decode((string)$result->getBody(),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame($saved,json_decode((string)$action->reserve($session,new Response(),['id'=>(string)$id])->getBody(),true,flags:JSON_THROW_ON_ERROR));
        self::assertSame('100',$this->correctionPayments()->credit($this->f['supplier'],$id)['reserved_cents']);
        $body['idempotency_key']='different-request';$stale=$action->reserve($session->withParsedBody($body),new Response(),['id'=>(string)$id]);self::assertSame(409,$stale->getStatusCode());
        $foreign=$action->reserve($session->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->f['supplier']+100000),new Response(),['id'=>(string)$id]);self::assertSame(404,$foreign->getStatusCode());
        $body['expected_version']=true;$invalid=$action->reserve($session->withParsedBody($body),new Response(),['id'=>(string)$id]);self::assertSame(422,$invalid->getStatusCode());
        $readonly=$action->reserve($session->withAttribute('auth.effective_role',new \MyInvoice\Security\EffectiveRole(1,'Synthetic read only','staff',true,['property'=>1])),new Response(),['id'=>(string)$id]);self::assertSame(403,$readonly->getStatusCode());
    }
    public function testExportRequiresSessionAndCurrentReservationAndCancellationEvidence():void
    {
        $supplier=$this->f['supplier'];$base=$this->settledPaymentFixture('600','400');$credit=$base['operation']['credit_id'];
        $reservation=$this->correctionPayments()->reserve($supplier,$credit,1,['amount_cents'=>'300','party_ids'=>[$this->f['clients'][1]],'disposition'=>'Synthetic instruction','recipient_account'=>'1000000005/0100'],'reserve',$this->f['actor']);
        $this->db->pdo()->prepare("INSERT INTO supplier_bank_accounts (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,analytic_suffix) VALUES (?,'Synthetic account','1000000005','0100','0100','CZK','1000000005','100')")->execute([$supplier]);$bank=(int)$this->db->pdo()->lastInsertId();
        $body=['reservation_ids'=>[$reservation['id']],'expected_versions'=>[$reservation['id']=>1],'bank_account_id'=>$bank,'due_date'=>'2026-02-01','reason'=>'Synthetic export','payment_symbols'=>[$reservation['id']=>['variable_symbol'=>'20260001']]];
        $request=(new ServerRequestFactory())->createServerRequest('POST','/property/payments/exports/preview')->withParsedBody($body)
            ->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->f['actor'],'role'=>'admin'])->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$supplier)->withAttribute(AuthMiddleware::ATTR_METHOD,'session');
        $action=Bootstrap::buildContainer()->get(PaymentOperationsAction::class);
        self::assertSame(403,$action->exportPreview($request->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer'),new Response())->getStatusCode());
        $response=$action->exportPreview($request,new Response());self::assertSame(200,$response->getStatusCode());$preview=json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR);
        $confirm=$request->withParsedBody(['request'=>$body,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'opaque-http-export']);
        $response=$action->exportConfirm($confirm,new Response());self::assertSame(200,$response->getStatusCode());$export=json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR);self::assertSame('submitted',$export['reservation_statuses'][$reservation['id']]);
        self::assertSame($export,json_decode((string)$action->exportConfirm($confirm,new Response())->getBody(),true,flags:JSON_THROW_ON_ERROR));
        $cancel=['expected_version'=>2,'reason'=>'Cancel instruction','evidence'=>'','idempotency_key'=>'cancel-http-export'];
        self::assertSame(422,$action->cancelExport($request->withParsedBody($cancel),new Response(),['id'=>$reservation['id']])->getStatusCode());
        self::assertSame('submitted',$this->correctionPayments()->reservation($supplier,$reservation['id'])['status']);
        $cancel['evidence']='Synthetic confirmation of cancellation';$response=$action->cancelExport($request->withParsedBody($cancel),new Response(),['id'=>$reservation['id']]);self::assertSame(200,$response->getStatusCode());
        self::assertSame('submitted',$this->correctionPayments()->reservation($supplier,$reservation['id'])['status'],'Cancellation request keeps a sent order reserved.');
        self::assertTrue(json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR)['cancel_requested']);
        $this->db->pdo()->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic non-execution proof',?,'1000000005','0100','CZK','2026-02-05')")->execute([$supplier,hash('sha256',random_bytes(20))]);
        $release=['expected_version'=>3,'statement_id'=>(int)$this->db->pdo()->lastInsertId(),'evidence'=>'Synthetic statement without the refund','idempotency_key'=>'release-http-export'];
        $response=$action->releaseUnexecuted($request->withParsedBody($release),new Response(),['id'=>$reservation['id']]);self::assertSame(200,$response->getStatusCode(),(string)$response->getBody());
        self::assertSame('failed',$this->correctionPayments()->reservation($supplier,$reservation['id'])['status']);
        $accounts=json_decode((string)$action->bankAccounts($request,new Response())->getBody(),true,flags:JSON_THROW_ON_ERROR);self::assertContains($bank,array_column($accounts['items'],'id'));
        self::assertSame([] ,json_decode((string)$action->bankAccounts($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$supplier+100000),new Response())->getBody(),true,flags:JSON_THROW_ON_ERROR)['items']);
    }
    public function testAccessoryPickerBindsActualReceivableOnceAndKeepsTenantBoundary():void
    {
        $supplier=$this->f['supplier'];$base=$this->settledPaymentFixture('1','0');
        $entry=Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($supplier,'manual',null,[['account_code'=>'315','side'=>'debit','amount'=>'0.70'],['account_code'=>'379.900','side'=>'credit','amount'=>'0.70']],['entry_date'=>'2026-01-31','posted_by'=>$this->f['actor']]);
        $action=Bootstrap::buildContainer()->get(PaymentOperationsAction::class);$request=(new ServerRequestFactory())->createServerRequest('GET','/property/payments/accessory-candidates')->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->f['actor'],'role'=>'admin'])->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$supplier)->withAttribute(AuthMiddleware::ATTR_METHOD,'session');
        $response=$action->accessoryCandidates($request,new Response());self::assertSame(200,$response->getStatusCode());$items=json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR)['items'];self::assertCount(1,$items);self::assertSame($entry,$items[0]['journal_entry_id']);self::assertSame('70',$items[0]['amount_cents']);self::assertSame('315',$items[0]['account_code']);
        $body=['journal_entry_id'=>$entry,'journal_line_id'=>$items[0]['id'],'component'=>'interest','evidence'=>'Synthetic interest title','liability_evidence'=>'Synthetic same legal group'];
        $post=$request->withParsedBody($body);self::assertSame(403,$action->bindAccessory($post->withAttribute(AuthMiddleware::ATTR_METHOD,'bearer'),new Response(),['id'=>$base['debt']['id']])->getStatusCode());
        $saved=$action->bindAccessory($post,new Response(),['id'=>$base['debt']['id']]);self::assertSame(200,$saved->getStatusCode());self::assertSame((string)$saved->getBody(),(string)$action->bindAccessory($post,new Response(),['id'=>$base['debt']['id']])->getBody());
        self::assertSame([],json_decode((string)$action->accessoryCandidates($request,new Response())->getBody(),true,flags:JSON_THROW_ON_ERROR)['items']);
        self::assertSame([],json_decode((string)$action->accessoryCandidates($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$supplier+100000),new Response())->getBody(),true,flags:JSON_THROW_ON_ERROR)['items']);
    }
}
