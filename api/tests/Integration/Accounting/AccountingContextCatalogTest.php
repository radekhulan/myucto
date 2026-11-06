<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Repository\AccountDefinitionRepository;
use MyInvoice\Tests\Support\CuratedReadBearerFixture;
use PHPUnit\Framework\TestCase;

final class AccountingContextCatalogTest extends TestCase
{
    use CuratedReadBearerFixture;
    public function testActualBearerReadsNativeDatedMetadataAndFrameworkWithoutRawEvidence():void
    {
        $this->framework($this->supplier,'2026-01-01','cz_500');$this->framework($this->supplier,'2026-07-01','cz_504');
        $this->mode($this->supplier,'2026-01-01','tax_evidence');$this->mode($this->supplier,'2026-07-01','double_entry');
        $id=$this->account($this->supplier,'549','debit');$definitions=$this->container->get(AccountDefinitionRepository::class);
        $definitions->initializeCreated($this->supplier,$id,'2026-01-01',$this->actor);
        $definitions->change($this->supplier,$id,'2026-07-01',['normal_side'=>'credit','tax_deductibility'=>'non_deductible','is_active'=>0],$definitions->stateHash($this->supplier,$id),$this->actor,'PRIVATE-EVIDENCE@example.test');
        $token=$this->token();[$status,$before]=$this->http('GET','/api/v1/accounting/catalog/context',$token,['date'=>'2026-06-30']);self::assertSame(200,$status,json_encode($before));
        [$status,$after]=$this->http('GET','/api/v1/accounting/catalog/context',$token,['date'=>'2026-07-01']);self::assertSame(200,$status,json_encode($after));
        self::assertSame('cz_500',$before['framework']['value']);self::assertSame('2026-07-01',$before['framework']['effective_until']);self::assertSame('tax_evidence',$before['method']['value']);
        self::assertSame('cz_504',$after['framework']['value']);self::assertSame('double_entry',$after['method']['value']);self::assertSame('current_knowledge_effective_date',$after['temporal_basis']);self::assertSame(['basis'=>'dated_framework_current_role_catalog','historical_rule_versions_available'=>false,'legal_profile_used'=>false,'property_flags_used'=>false,'legal_basis_assessed'=>false],$after['role_profile']);
        $old=$before['accounts'][0];$new=$after['accounts'][0];self::assertArrayNotHasKey('is_clearing',$new);self::assertSame($id,$old['id']);self::assertSame($id,$new['id']);self::assertSame('debit',$old['normal_side']);self::assertSame('credit',$new['normal_side']);self::assertSame('non_deductible',$new['tax_deductibility']);self::assertFalse($new['is_active']);self::assertSame('2026-07-01',$old['effective_until']);self::assertSame('dated_history',$new['temporal_source']);self::assertGreaterThan(0,$new['metadata_revision']['id']);self::assertSame(1,$new['metadata_revision']['row_version']);
        self::assertSame('961',array_column($after['account_roles'],'account_code','role')['closing.opening_balance']);
        self::assertStringNotContainsString('PRIVATE-EVIDENCE',json_encode($after));self::assertStringNotContainsString('definition_json',json_encode($after));self::assertStringNotContainsString('created_by',json_encode($after));self::assertStringNotContainsString('Synthetic hidden account label',json_encode($after));
    }
    public function testLegacyFallbackIsResolvedAndExplicitlyIncompleteAndDoesNotEnsureDimensions():void
    {
        $id=$this->account($this->supplier,'961','debit');$this->framework($this->supplier,'2026-01-01','cz_504');$token=$this->token();$before=$this->counts();
        [$status,$body]=$this->http('GET','/api/v1/accounting/catalog/context',$token,['date'=>'2026-04-01']);self::assertSame(200,$status,json_encode($body));self::assertFalse($body['complete']);self::assertSame('current_base',$body['accounts'][0]['temporal_source']);self::assertSame($id,$body['accounts'][0]['id']);self::assertNull($body['accounts'][0]['metadata_revision']);
        self::assertContains('account_definition_not_historized',array_column($body['findings'],'code'));self::assertContains('system_dimension_missing',array_column($body['findings'],'code'));self::assertSame($before,$this->counts());
    }
    public function testImmutableDimensionIdentitiesAreCurrentOnlyAndMutableLabelsAndStateAreNotReturned():void
    {
        $this->framework($this->supplier,'2026-01-01','cz_504');$pdo=$this->db->pdo();$pdo->prepare("INSERT INTO dimension_types(supplier_id,code,name,kind,system_key)VALUES(?,'activity','PRIVATE-LABEL@example.test','custom','activity')")->execute([$this->supplier]);$type=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name,semantic_key)VALUES(?,?,'main','PRIVATE-DIMENSION@example.test','main')")->execute([$type,$this->supplier]);$value=(int)$pdo->lastInsertId();
        [$status,$body]=$this->http('GET','/api/v1/accounting/catalog/context',$this->token(),['date'=>'2026-01-01']);self::assertSame(200,$status,json_encode($body));$activity=$body['system_dimensions'][0];self::assertSame($type,$activity['type_id']);self::assertSame($value,$activity['value_roles'][0]['value_id']);self::assertSame('current_identity',$activity['temporal_source']);self::assertFalse($activity['historical_state_available']);self::assertStringNotContainsString('PRIVATE-',json_encode($body));self::assertArrayNotHasKey('is_active',$activity);self::assertContains('system_dimension_state_not_historized',array_column($body['findings'],'code'));
    }
    public function testForeignSelectorsCannotChangeBoundTenantAndNoReadGrantOrWriteAccess():void
    {
        $this->account($this->supplier,'701','debit');$this->account($this->otherSupplier,'999','credit');$token=$this->token();[$status,$own]=$this->http('GET','/api/v1/accounting/catalog/context',$token,['date'=>'2026-01-01']);self::assertSame(200,$status,json_encode($own));[$status,$foreign]=$this->http('GET','/api/v1/accounting/catalog/context',$token,['date'=>'2026-01-01','supplier_id'=>$this->otherSupplier],['X-Supplier-Id'=>(string)$this->otherSupplier]);self::assertSame(200,$status,json_encode($foreign));self::assertSame($own,$foreign);self::assertSame(['701'],array_column($foreign['accounts'],'account_code'));
        [$status]=$this->http('GET','/api/v1/accounting/catalog/context',$this->token(['property'=>1]),['date'=>'2026-01-01']);self::assertSame(403,$status);
        foreach(['POST','PUT','PATCH','DELETE']as$method){[$status]=$this->http($method,'/api/v1/accounting/catalog/context',$token);self::assertSame(403,$status,$method);}
    }
    public function testUnknownHistoricalKnowledgeAndInvalidQueryAreExplicitlyRejected():void
    {
        $token=$this->token();[$status,$body]=$this->http('GET','/api/v1/accounting/catalog/context',$token,['date'=>'2026-01-01','known_at'=>'2026-01-02T00:00:00Z']);self::assertSame(409,$status,json_encode($body));self::assertSame('accounting_context_known_at_unsupported',$body['error']['code']);
        foreach([[],['date'=>'1899-12-31'],['date'=>'2026-02-30'],['date'=>['2026-01-01']],['date'=>'2026-01-01','payload'=>'hidden']]as$query){[$status]=$this->http('GET','/api/v1/accounting/catalog/context',$token,$query);self::assertSame(422,$status);}
    }
    public function testFutureAccountCreationIsOmittedAndSameDateMetadataRefreshHasStableIdNewRevision():void
    {
        $id=$this->account($this->supplier,'701','debit');$definitions=$this->container->get(AccountDefinitionRepository::class);$definitions->initializeCreated($this->supplier,$id,'2026-07-01',$this->actor);$token=$this->token();
        [$status,$before]=$this->http('GET','/api/v1/accounting/catalog/context',$token,['date'=>'2026-06-30']);self::assertSame(200,$status);self::assertSame([],$before['accounts']);
        [$status,$first]=$this->http('GET','/api/v1/accounting/catalog/context',$token,['date'=>'2026-07-01']);self::assertSame(200,$status);$revision=$first['accounts'][0]['metadata_revision'];
        $definitions->change($this->supplier,$id,'2026-07-01',['normal_side'=>'credit'],$definitions->stateHash($this->supplier,$id),$this->actor,'Synthetic replacement');
        [$status,$second]=$this->http('GET','/api/v1/accounting/catalog/context',$token,['date'=>'2026-07-01']);self::assertSame(200,$status);self::assertSame($revision['id'],$second['accounts'][0]['metadata_revision']['id']);self::assertSame(2,$second['accounts'][0]['metadata_revision']['row_version']);self::assertNotSame($revision['definition_hash'],$second['accounts'][0]['metadata_revision']['definition_hash']);self::assertSame('credit',$second['accounts'][0]['normal_side']);
    }
    public function testNativeMetadataCorruptionCannotProduceApparentlyCompleteContext():void
    {
        $id=$this->account($this->supplier,'701','debit');$this->container->get(AccountDefinitionRepository::class)->initializeCreated($this->supplier,$id,'2026-01-01',$this->actor);$this->db->pdo()->prepare("UPDATE accounting_account_definition_history SET definition_json=CONCAT(' ',definition_json),definition_hash=SHA2(CONCAT(supplier_id,':',account_id,':',effective_from,':',definition_json),256) WHERE supplier_id=? AND account_id=?")->execute([$this->supplier,$id]);
        [$status,$body]=$this->http('GET','/api/v1/accounting/catalog/context',$this->token(),['date'=>'2026-01-01']);self::assertSame(409,$status);self::assertSame('account_definition_corrupt',$body['error']['code']);self::assertArrayNotHasKey('accounts',$body);
    }
    public function testFullyHistorized500ContextCanBeCompleteWithoutCreatingSystemDimensions():void
    {
        $this->framework($this->supplier,'1900-01-01','cz_500');$this->mode($this->supplier,'1900-01-01','double_entry');$definitions=$this->container->get(AccountDefinitionRepository::class);
        foreach(array_unique((new \MyInvoice\Service\Accounting\AccountingRoleProfile('cz_500'))->accounts())as$code){$id=$this->account($this->supplier,$code,'debit');$definitions->initializeCreated($this->supplier,$id,'1900-01-01',$this->actor);}
        [$status,$body]=$this->http('GET','/api/v1/accounting/catalog/context',$this->token(),['date'=>'2026-01-01']);self::assertSame(200,$status,json_encode($body));self::assertTrue($body['complete']);self::assertSame([],$body['findings']);self::assertSame([],$body['system_dimensions']);self::assertGreaterThan(0,$body['method']['id']);self::assertSame('transaction_snapshot',$body['consistency']);
    }
    public function testStandaloneProjectionStartsAndEndsItsOwnReadOnlySnapshot():void
    {
        $pdo=$this->db->pdo();$pdo->rollBack();$supplier=(int)$pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();self::assertGreaterThan(0,$supplier);$before=$this->counts();
        $result=$this->container->get(\MyInvoice\Service\Accounting\Catalog\AccountingContextProjection::class)->forDate($supplier,'2026-01-01')->jsonSerialize();self::assertSame('transaction_snapshot',$result['consistency']);self::assertFalse($pdo->inTransaction());self::assertSame($before,$this->counts());
    }
    public function testBorrowedReadCommittedTransactionIsRejectedWithoutChangingIt():void
    {
        $pdo=$this->db->pdo();$pdo->rollBack();$supplier=(int)$pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');$pdo->beginTransaction();
        try {
            try {$this->container->get(\MyInvoice\Service\Accounting\Catalog\AccountingContextProjection::class)->forDate($supplier,'2026-01-01');self::fail('A borrowed non-snapshot transaction must not promise consistency.');}
            catch(\MyInvoice\Service\Accounting\AccountDefinitionException $e){self::assertSame('accounting_context_snapshot_required',$e->errorCode);self::assertSame(409,$e->httpStatus);self::assertTrue($pdo->inTransaction());}
        } finally {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');}
    }
    private function framework(int $supplier,string $date,string $framework):void{$this->db->pdo()->prepare('INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework,created_by)VALUES(?,?,?,?)')->execute([$supplier,$date,$framework,$this->actor]);}
    private function mode(int $supplier,string $date,string $mode):void{$this->db->pdo()->prepare('INSERT INTO supplier_accounting_modes(supplier_id,effective_from,accounting_mode)VALUES(?,?,?)')->execute([$supplier,$date,$mode]);}
    private function account(int $supplier,string $code,string $side):int{$this->db->pdo()->prepare("INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_synthetic,normal_side,tax_deductibility,is_active)VALUES(?,?,'Synthetic hidden account label','expense',1,?,'deductible',1)")->execute([$supplier,$code,$side]);return(int)$this->db->pdo()->lastInsertId();}
    private function counts():array{$out=[];foreach(['dimension_types','dimension_values','activity_log','accounting_account_definition_history','supplier_accounting_frameworks','supplier_accounting_modes']as$table)$out[$table]=(int)$this->db->pdo()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();return$out;}
}
