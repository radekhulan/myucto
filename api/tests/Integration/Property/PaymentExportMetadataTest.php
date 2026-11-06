<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Action\Property\Payments\PaymentOperationsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\{AuthMiddleware,SupplierScopeMiddleware};
use MyInvoice\Repository\Property\Payments\PaymentExportRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture,PaymentReadCorruptionSandbox};
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PaymentExportMetadataTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture,PaymentReadCorruptionSandbox;
    private Connection $db;
    private array $f;

    protected function setUp():void
    {
        $this->db=$this->paymentReadDatabase(['unit_payment_export_items_immutable_delete','unit_payment_audit_immutable_delete','unit_payment_exports_immutable_update']);
        $this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);
    }
    protected function tearDown():void {if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}

    public function testReadOnlyActionReloadsServerMetadataWithoutBytesOrWritesAndKeepsDownloadPermission():void
    {
        $reservations=$this->reservations();$id=$reservations[0]['id'];$supplier=$this->f['supplier'];
        $action=$this->paymentFixtureContainer()->get(PaymentOperationsAction::class);
        $request=(new ServerRequestFactory())->createServerRequest('GET','/api/property/payments/reservations/'.$id.'/abo-export')
            ->withAttribute(AuthMiddleware::ATTR_METHOD,'session')->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->f['actor'],'role'=>'accountant'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$supplier)
            ->withAttribute('auth.effective_role',new EffectiveRole(1,'Synthetic property reader','staff',true,['property'=>1]));
        $absent=$action->exportMetadata($request,new Response(),['id'=>(string)$id]);
        self::assertSame(200,$absent->getStatusCode());self::assertSame(['export'=>null],json_decode((string)$absent->getBody(),true));
        $export=$this->export($reservations);$before=$this->counts();
        $response=$action->exportMetadata($request,new Response(),['id'=>(string)$id]);self::assertSame(200,$response->getStatusCode());
        $body=json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR);
        $expected=$export;unset($expected['content_base64']);self::assertSame(['export'=>$expected],$body);
        self::assertStringNotContainsString('content_base64',(string)$response->getBody());
        self::assertStringNotContainsString('1000000005',(string)$response->getBody());
        self::assertSame($before,$this->counts());
        self::assertSame(403,$action->exportDownload($request,new Response(),['id'=>(string)$export['id']])->getStatusCode());
        $other=$this->createIsolatedSupplier($this->db->pdo(),$supplier);
        $foreign=$action->exportMetadata($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$other),new Response(),['id'=>(string)$id]);
        self::assertSame(409,$foreign->getStatusCode());
        self::assertSame('property_payment_reservation_not_found',json_decode((string)$foreign->getBody(),true)['error']['code']);
        $missing=$action->exportMetadata($request,new Response(),['id'=>'2147483647']);self::assertSame(409,$missing->getStatusCode());
        self::assertSame('property_payment_reservation_not_found',json_decode((string)$missing->getBody(),true)['error']['code']);
    }

    public function testMixedCancellationMetadataPreservesTheImmutableExportAndEveryCurrentStatus():void
    {
        $reservations=$this->reservations(2);$export=$this->export($reservations);$supplier=$this->f['supplier'];$repo=$this->repo();
        $this->cancelAndRelease($reservations[0]['id']);
        $before=$this->counts();$metadata=$repo->metadataForReservation($supplier,$reservations[0]['id']);
        self::assertSame($export['id'],$metadata['id']);self::assertSame($export['filename'],$metadata['filename']);
        self::assertSame([$reservations[0]['id']=>'failed',$reservations[1]['id']=>'submitted'],$metadata['reservation_statuses']);
        self::assertSame($metadata,$repo->metadataForReservation($supplier,$reservations[1]['id']));
        self::assertSame($export['content_base64'],$repo->find($supplier,$metadata['id'])['content_base64']);
        self::assertSame($before,$this->counts());
    }

    public function testMissingMaterializedLinkIsNotAbsenceEvenAfterCancellation():void
    {
        $reservations=$this->reservations();$this->export($reservations);$supplier=$this->f['supplier'];$id=$reservations[0]['id'];
        $this->cancelAndRelease($id);
        $this->db->pdo()->prepare('DELETE FROM unit_payment_export_items WHERE supplier_id=? AND reservation_id=?')->execute([$supplier,$id]);
        $this->db->pdo()->prepare("DELETE FROM unit_payment_audit WHERE supplier_id=? AND object_kind='reservation' AND object_id=? AND action='submitted'")->execute([$supplier,$id]);
        try{$this->repo()->metadataForReservation($supplier,$id);self::fail('Missing receipt materialization accepted.');}
        catch(PaymentException $e){self::assertSame('property_payment_export_receipt_mismatch',$e->errorCode);}
    }

    public function testMissingSnapshotDiscoveryStillFailsFromTheRecordedSubmission():void
    {
        $reservations=$this->reservations();$export=$this->export($reservations);$supplier=$this->f['supplier'];$id=$reservations[0]['id'];
        $this->cancelAndRelease($id);
        $this->db->pdo()->prepare('DELETE FROM unit_payment_export_items WHERE supplier_id=? AND reservation_id=?')->execute([$supplier,$id]);
        $this->db->pdo()->prepare("UPDATE unit_payment_exports SET snapshot_json=JSON_SET(snapshot_json,'$.reservations',JSON_ARRAY()) WHERE supplier_id=? AND id=?")->execute([$supplier,$export['id']]);
        try{$this->repo()->metadataForReservation($supplier,$id);self::fail('Recorded export hidden as absent.');}
        catch(PaymentException $e){self::assertSame('property_payment_export_receipt_missing',$e->errorCode);}
    }

    public function testCorruptExportContentIsNotMetadataOrAbsence():void
    {
        $reservations=$this->reservations();$export=$this->export($reservations);
        $this->db->pdo()->prepare('UPDATE unit_payment_exports SET content_hash=? WHERE supplier_id=? AND id=?')->execute([str_repeat('0',64),$this->f['supplier'],$export['id']]);
        try{$this->repo()->metadataForReservation($this->f['supplier'],$reservations[0]['id']);self::fail('Corrupt bytes accepted.');}
        catch(PaymentException $e){self::assertSame('property_payment_export_hash_mismatch',$e->errorCode);}
    }

    private function reservations(int $count=1):array
    {
        $base=$this->settledPaymentFixture('600','400');$credit=$base['operation']['credit_id'];$out=[];
        for($index=0;$index<$count;$index++){
            $version=$this->correctionPayments()->credit($this->f['supplier'],$credit)['row_version'];
            $out[]=$this->correctionPayments()->reserve($this->f['supplier'],$credit,$version,['amount_cents'=>'100','party_ids'=>[$this->f['clients'][1]],'disposition'=>'Synthetic refund','recipient_account'=>'1000000005/0100'],'metadata-reserve-'.$index,$this->f['actor']);
        }
        return $out;
    }
    private function export(array $reservations):array
    {
        $supplier=$this->f['supplier'];
        $this->db->pdo()->prepare("INSERT INTO supplier_bank_accounts (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,analytic_suffix) VALUES (?,'Synthetic metadata account','1000000005','0100','0100','CZK','1000000005','100')")->execute([$supplier]);
        $request=['reservation_ids'=>array_column($reservations,'id'),'expected_versions'=>array_column($reservations,'row_version','id'),'bank_account_id'=>(int)$this->db->pdo()->lastInsertId(),'due_date'=>'2026-02-01','reason'=>'Synthetic metadata export','payment_symbols'=>[]];
        foreach($reservations as $r)$request['payment_symbols'][$r['id']]=['allow_missing_variable_symbol'=>true];
        $repo=$this->repo();$preview=$repo->preview($supplier,$request);
        return $repo->confirm($supplier,$request,$preview['preview_hash'],'metadata-export',$this->f['actor']);
    }
    private function repo():PaymentExportRepository {return $this->paymentFixtureContainer()->get(PaymentExportRepository::class);}
    /** Odeslaný příkaz uvolní jen výpis bez provedené vratky; samotná žádost o zrušení ho drží. */
    private function cancelAndRelease(int $id):void
    {
        $supplier=$this->f['supplier'];$cancel=$this->repo()->cancelSubmission($supplier,$id,2,'Synthetic cancellation','Synthetic bank confirmation','metadata-cancel',$this->f['actor']);
        $this->db->pdo()->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic non-execution proof',?,'1000000005','0100','CZK','2026-02-02')")->execute([$supplier,hash('sha256',random_bytes(20))]);
        $this->repo()->releaseUnexecuted($supplier,$id,$cancel['row_version'],(int)$this->db->pdo()->lastInsertId(),'Synthetic statement without the refund','metadata-release',$this->f['actor']);
    }
    private function counts():array
    {
        $out=[];foreach(['unit_payment_exports','unit_payment_export_items','unit_payment_audit','journal_entries'] as $table){$q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM '.$table.' WHERE supplier_id=?');$q->execute([$this->f['supplier']]);$out[$table]=(int)$q->fetchColumn();}return $out;
    }
}
