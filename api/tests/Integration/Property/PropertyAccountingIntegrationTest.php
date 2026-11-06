<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository, JournalEntryRepository};
use MyInvoice\Repository\Property\{PropertyProfileRepository, PropertyReadRepository, PropertyRegistryRepository};
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder, PostingException, PostingService};
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\TestCase;

final class PropertyAccountingIntegrationTest extends TestCase
{
    use IsolatedSupplierTrait;
    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private int $supplier;
    private int $actor;
    private int $period;
    private int $type;
    private int $value;
    private array $accounts;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $seed = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($pdo, $seed);
        $this->actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2096-01-01','cz_504')")->execute([$this->supplier]);
        $this->period = $this->container->get(AccountingPeriodRepository::class)->create($this->supplier,2096,'2096-01-01','2096-12-31');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier,'2096-01-01');
        $q = $pdo->prepare('SELECT account_code,id FROM chart_of_accounts WHERE supplier_id=?');$q->execute([$this->supplier]);$this->accounts=$q->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->profile(true,0,'2096-01-01T00:00:00+01:00');
        $service = (new PropertyRegistryRepository($this->db))->create($this->supplier,'service',[
            'code'=>'SYN-WATER','expected_revision'=>0,'status'=>'confirmed','effective_from'=>'2096-01-01T00:00:00+01:00',
            'state'=>['name'=>'Synthetic water','active'=>true,'measure'=>'unit','measure_label'=>''],
            'reason'=>'Synthetic registry','title_ref'=>'SYN-DEED','idempotency_key'=>'service'], $this->actor);
        $this->value=(int)(new PropertyReadRepository($this->db))->find($this->supplier,$service['id'],'2096-06-01T00:00:00+02:00')['dimension_value_id'];
        $q=$pdo->prepare("SELECT id FROM dimension_types WHERE supplier_id=? AND system_key='service'");$q->execute([$this->supplier]);$this->type=(int)$q->fetchColumn();
    }

    protected function tearDown(): void { if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack(); }

    public function testJournalRejectsValidServiceWhenProfileIsDisabledBeforeAnyWrite(): void
    {
        $this->profile(false,1,'2096-07-01T00:00:00+02:00');
        $this->refused('property_profile_required', fn () => $this->journal('2096-08-01'));
        self::assertSame(0,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id='.$this->supplier)->fetchColumn());
    }

    public function testJournalRejectsActualPropertyIn500BeforeLegacyEarlyReturn(): void
    {
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2096-07-01','cz_500')")->execute([$this->supplier]);
        $this->refused('property_framework_required',fn () => $this->journal('2096-08-01'));
    }

    public function testJournalRequiresFullActivePragueDay(): void
    {
        self::assertGreaterThan(0,$this->journal('2096-06-01'));
        $this->profile(false,1,'2096-07-01T12:00:00+02:00');
        $this->refused('property_profile_required',fn () => $this->journal('2096-07-01'));
    }

    public function testPurchaseMetadataUsesTaxDateAndRejectsDisabledProfileAtomically(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT ?, 'Synthetic vendor','Test','Test','10000',country_id,'test@example.test',default_currency_id FROM supplier WHERE id=?")->execute([$this->supplier,$this->supplier]);
        $vendor = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO purchase_invoices(supplier_id,vendor_id,vendor_invoice_number,issue_date,tax_date,due_date,received_at,currency_id,vendor_snapshot,status,created_by) SELECT ?,?,'SYN-S1','2096-06-01','2096-08-01','2096-08-01','2096-06-01',default_currency_id,'{}','received',? FROM supplier WHERE id=?")->execute([$this->supplier,$vendor,$this->actor,$this->supplier]);
        $id = (int) $pdo->lastInsertId();
        $this->profile(false,1,'2096-07-01T00:00:00+02:00');
        try {
            $this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class)->saveDocument($this->supplier,'purchase_invoice',$id,[$this->type=>$this->value],null);
            self::fail('Document metadata must validate actual tax date before writing.');
        } catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) { self::assertSame('property_profile_required',$e->errorCode); }
        $q=$pdo->prepare('SELECT COUNT(*) FROM document_dimensions WHERE supplier_id=?');$q->execute([$this->supplier]);self::assertSame(0,(int)$q->fetchColumn());
        $entry=$this->container->get(JournalEntryRepository::class)->insert(['supplier_id'=>$this->supplier,'period_id'=>$this->period,'entry_date'=>'2096-06-01','source_type'=>'purchase_invoice','source_id'=>$id,'posted_at'=>'2096-06-01 12:00:00'],[
            ['account_id'=>(int)$this->accounts['221'],'side'=>'debit','amount'=>'49.99'],['account_id'=>(int)$this->accounts['211'],'side'=>'credit','amount'=>'49.99']]);
        $result=$this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class)->saveDocument($this->supplier,'purchase_invoice',$id,[$this->type=>$this->value],null);
        self::assertSame($this->value,$result['header'][$this->type],'Existing journal date is authoritative over changed document tax date.');
        $q=$pdo->prepare('SELECT COUNT(*) FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id=d.line_id WHERE l.entry_id=? AND d.dimension_value_id=?');$q->execute([$entry,$this->value]);self::assertGreaterThan(0,(int)$q->fetchColumn());
    }

    public function testScheduleChecksTargetDateAndRollsBackWholeMetadataChange(): void
    {
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare("INSERT INTO other_items(supplier_id,side,title,issued_on,accounting_on,due_on,amount,status) VALUES (?,'payable','Synthetic','2096-06-01',?,'2096-08-01',49.99,'draft')");
        $insert->execute([$this->supplier,'2096-06-01']); $source = (int) $pdo->lastInsertId();
        $insert->execute([$this->supplier,'2096-08-01']); $target = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO other_item_schedules(supplier_id,source_item_id,frequency,anchor_on,due_days,template_json) VALUES (?,?,'monthly','2096-06-01',0,'{}')")->execute([$this->supplier,$source]);
        $schedule = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO other_item_schedule_occurrences(supplier_id,schedule_id,occurrence_index,item_id) VALUES (?,?,1,?)')->execute([$this->supplier,$schedule,$target]);
        $this->profile(false,1,'2096-07-01T00:00:00+02:00');
        try {
            $this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class)->saveDocument($this->supplier,'other_item',$source,[$this->type=>$this->value],null);
            self::fail('Every schedule target needs its own current accounting date.');
        } catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) { self::assertSame('property_profile_required',$e->errorCode); }
        $q=$pdo->prepare('SELECT COUNT(*) FROM document_dimensions WHERE supplier_id=?');$q->execute([$this->supplier]);self::assertSame(0,(int)$q->fetchColumn());
        self::assertTrue($pdo->inTransaction());
    }

    public function testUndatedTemplateBindingAndRetainedItemIdentity(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO journal_entry_templates(supplier_id,name) VALUES (?,'Synthetic template')")->execute([$this->supplier]); $id=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO journal_entry_template_lines(template_id,line_no,account_code,side) VALUES (?,1,'221','debit')")->execute([$id]);
        $this->profile(false,1,'2096-07-01T00:00:00+02:00');
        $service=$this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        $result=$service->saveDocument($this->supplier,'journal_template',$id,[$this->type=>$this->value],null);
        self::assertSame($this->value,$result['header'][$this->type]);
        try {
            $service->saveDocument($this->supplier,'journal_template',$id,[$this->type=>$this->value],[9=>[$this->type=>$this->value]]);
            self::fail('A new item number must not manufacture a retained property identity.');
        } catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) { self::assertSame('property_document_item_missing',$e->errorCode); }
    }

    public function testRetainedJournalNoOpAndExactReversalAfterDisable(): void
    {
        $id=$this->journal('2096-08-01');
        $this->profile(false,1,'2096-07-01T00:00:00+02:00');
        $q=$this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE entry_id=? ORDER BY line_no,id');$q->execute([$id]);$line=(int)$q->fetchColumn();
        self::assertSame(0,$this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class)->saveEntryLines($this->supplier,$id,[$line=>[$this->type=>$this->value]]));
        $reversal=$this->container->get(PostingService::class)->reverse($this->supplier,$id,['posted_by'=>$this->actor,'entry_date'=>'2096-08-02']);
        self::assertGreaterThan(0,$reversal);
        $q=$this->db->pdo()->prepare('SELECT SUM(CASE side WHEN \'debit\' THEN amount ELSE -amount END) FROM journal_entry_lines WHERE supplier_id=? AND account_id=?');$q->execute([$this->supplier,$this->accounts['221']]);self::assertSame('0.00',$q->fetchColumn());
    }

    public function testDocumentDatesTemplatesAndBankOwnership(): void
    {
        $pdo=$this->db->pdo();
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT ?,'Synthetic party','Test','Test','10000',country_id,'test@example.test',default_currency_id FROM supplier WHERE id=?")->execute([$this->supplier,$this->supplier]);$client=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoices(supplier_id,client_id,issue_date,tax_date,due_date,currency_id,status,created_by) SELECT ?,?,'2096-06-01','2096-08-01','2096-08-01',default_currency_id,'draft',? FROM supplier WHERE id=?")->execute([$this->supplier,$client,$this->actor,$this->supplier]);$invoice=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cash_registers(supplier_id,name,currency_code,account_code,is_default,is_active) VALUES (?,'Synthetic','CZK','211',1,1)")->execute([$this->supplier]);$register=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cash_documents(supplier_id,register_id,doc_type,purpose,issue_date,tax_date,description,total_amount,status,counter_account_code) VALUES (?,?,'in','sale','2096-06-01','2096-08-01','Synthetic',49.99,'draft','221')")->execute([$this->supplier,$register]);$cash=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO other_items(supplier_id,side,title,issued_on,accounting_on,due_on,amount,status) VALUES (?,'payable','Synthetic','2096-06-01','2096-08-01','2096-08-01',49.99,'draft')")->execute([$this->supplier]);$other=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO assets(supplier_id,inventory_number,name,input_price,acquisition_date) VALUES (?,'SYN-ASSET','Synthetic',49.99,'2096-08-01')")->execute([$this->supplier]);$asset=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO recurring_invoice_templates(supplier_id,client_id,currency_id,name,frequency,anchor_date,next_run_date,created_by) SELECT ?,?,default_currency_id,'Synthetic','monthly','2096-08-01','2096-08-01',? FROM supplier WHERE id=?")->execute([$this->supplier,$client,$this->actor,$this->supplier]);$recurring=(int)$pdo->lastInsertId();
        $account='19-1000000005';
        $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES (?,'CZK','Synthetic','Kč','Synthetic','Synthetic',2,1,1,?,'0100')")->execute([$this->supplier,$account]);
        $pdo->prepare("INSERT INTO bank_statements(file_name,file_hash,account_number,bank_code,currency,statement_date,transaction_count,matched_count) VALUES ('synthetic.gpc',?,?,'0100','CZK','2096-08-01',1,0)")->execute([hash('sha256',(string)$this->supplier),$account]);$statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,description) VALUES (?,'2096-08-01',49.99,'CZK','Synthetic')")->execute([$statement]);$bank=(int)$pdo->lastInsertId();
        $this->profile(false,1,'2096-07-01T00:00:00+02:00');
        $service=$this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        foreach ([['invoice',$invoice],['other_item',$other],['bank_transaction',$bank]] as [$type,$id]) {
            try {$service->saveDocument($this->supplier,$type,$id,[$this->type=>$this->value],null);self::fail($type.' must use its actual accounting date.');}
            catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) {self::assertSame('property_profile_required',$e->errorCode,$type);}
        }
        foreach ([['cash_document',$cash],['asset',$asset],['recurring_template',$recurring]] as [$type,$id]) {
            self::assertSame($this->value,$service->saveDocument($this->supplier,$type,$id,[$this->type=>$this->value],null)['header'][$this->type]);
        }
        $pdo->prepare('DELETE FROM document_dimensions WHERE supplier_id=? AND doc_type=? AND doc_id=?')->execute([$this->supplier,'asset',$asset]);
        $pdo->prepare("UPDATE assets SET put_into_use_date='2096-08-01' WHERE id=?")->execute([$asset]);
        try {$service->saveDocument($this->supplier,'asset',$asset,[$this->type=>$this->value],null);self::fail('Dated asset cannot use structural-only validation.');}
        catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) {self::assertSame('property_profile_required',$e->errorCode);}
        $otherSupplier=$this->createIsolatedSupplier($pdo,$this->supplier);
        $pdo->prepare('UPDATE bank_statements SET supplier_id=? WHERE id=?')->execute([$otherSupplier,$statement]);
        try {$service->saveDocument($this->supplier,'bank_transaction',$bank,[],null);self::fail('Authoritative statement owner must win over legacy account.');}
        catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) {self::assertSame('not_found',$e->errorCode);}
    }

    public function testPostingRollbackAndCustom500Parity(): void
    {
        $pdo=$this->db->pdo();
        $this->profile(false,1,'2096-07-01T00:00:00+02:00');
        $lines=[['account_code'=>'221','side'=>'debit','amount'=>'49.99','dimensions'=>[$this->type=>$this->value]],['account_code'=>'211','side'=>'credit','amount'=>'49.99']];
        $posting=$this->container->get(PostingService::class);
        $this->refused('property_profile_required',fn ()=>$posting->postDocument($this->supplier,'manual',null,$lines,['entry_date'=>'2096-08-01']));
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id='.$this->supplier)->fetchColumn());
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2096-07-01','cz_500')")->execute([$this->supplier]);
        $pdo->prepare("INSERT INTO dimension_types(supplier_id,code,name,kind) VALUES (?,'custom-service','Synthetic service','custom')")->execute([$this->supplier]);$customType=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO dimension_values(supplier_id,type_id,code,name) VALUES (?,?,'SYN','Synthetic')")->execute([$this->supplier,$customType]);$customValue=(int)$pdo->lastInsertId();
        $lines[0]['dimensions']=[$customType=>$customValue];
        $id=$posting->postDocument($this->supplier,'manual',null,$lines,['entry_date'=>'2096-08-01']);
        self::assertGreaterThan(0,$id);
        $q=$pdo->prepare('SELECT amount FROM journal_entry_lines WHERE entry_id=? ORDER BY line_no,id');$q->execute([$id]);self::assertSame(['49.99','49.99'],$q->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testPostingExactRetainedRetryDoesNotAuthorizeNewRow(): void
    {
        $posting=$this->container->get(PostingService::class);
        $lines=[['account_code'=>'221','side'=>'debit','amount'=>'49.99','dimensions'=>[$this->type=>$this->value]],['account_code'=>'211','side'=>'credit','amount'=>'49.99']];
        $id=$posting->postDocument($this->supplier,'manual',192837,$lines,['entry_date'=>'2096-08-01']);
        $this->profile(false,1,'2096-07-01T00:00:00+02:00');
        self::assertSame($id,$posting->postDocument($this->supplier,'manual',192837,$lines,['entry_date'=>'2096-08-01']));
        $lines[]=['account_code'=>'221','side'=>'debit','amount'=>'1.00','dimensions'=>[$this->type=>$this->value]];
        $lines[]=['account_code'=>'211','side'=>'credit','amount'=>'1.00'];
        $this->refused('property_profile_required',fn ()=>$posting->postDocument($this->supplier,'manual',192837,$lines,['entry_date'=>'2096-08-01']));
        self::assertCount(2,$this->container->get(JournalEntryRepository::class)->linesForEntry($id,$this->supplier));
    }

    public function testHeaderInheritanceCannotAuthorizeNewExplicitRetiredItemAssignment(): void
    {
        $pdo=$this->db->pdo();
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT ?,'Synthetic vendor','Test','Test','10000',country_id,'test@example.test',default_currency_id FROM supplier WHERE id=?")->execute([$this->supplier,$this->supplier]);$vendor=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO purchase_invoices(supplier_id,vendor_id,vendor_invoice_number,issue_date,tax_date,due_date,received_at,currency_id,vendor_snapshot,status,created_by) SELECT ?,?,'SYN-RETAINED','2096-08-01','2096-08-01','2096-08-01','2096-08-01',default_currency_id,'{}','received',? FROM supplier WHERE id=?")->execute([$this->supplier,$vendor,$this->actor,$this->supplier]);$id=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO purchase_invoice_items(purchase_invoice_id,description,unit_price_without_vat,vat_rate_id,vat_rate_snapshot) SELECT ?,'Synthetic',49.99,id,0 FROM vat_rates ORDER BY id LIMIT 1")->execute([$id]);
        $service=$this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        $service->saveDocument($this->supplier,'purchase_invoice',$id,[$this->type=>$this->value],null);
        $this->profile(false,1,'2096-07-01T00:00:00+02:00');
        self::assertSame($this->value,$service->saveDocument($this->supplier,'purchase_invoice',$id,[$this->type=>$this->value],null)['header'][$this->type]);
        try {$service->saveDocument($this->supplier,'purchase_invoice',$id,[$this->type=>$this->value],[1=>[$this->type=>$this->value]]);self::fail('Inherited header is not a previous explicit item assignment.');}
        catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) {self::assertSame('property_profile_required',$e->errorCode);}
        $q=$pdo->prepare('SELECT COUNT(*) FROM document_dimensions WHERE supplier_id=? AND doc_id=? AND item_no>0');$q->execute([$this->supplier,$id]);self::assertSame(0,(int)$q->fetchColumn());
    }

    private function journal(string $date): int
    {
        return $this->container->get(JournalEntryRepository::class)->insert(['supplier_id'=>$this->supplier,'period_id'=>$this->period,'entry_date'=>$date,'source_type'=>'manual','posted_at'=>'2096-01-01 00:00:00'],[
            ['account_id'=>(int)$this->accounts['221'],'side'=>'debit','amount'=>'49.99','dimensions'=>[$this->type=>$this->value]],
            ['account_id'=>(int)$this->accounts['211'],'side'=>'credit','amount'=>'49.99']]);
    }

    private function profile(bool $enabled,int $version,string $at): void
    {
        (new PropertyProfileRepository($this->db))->save($this->supplier,['expected_revision'=>$version,'effective_from'=>$at,'enabled'=>$enabled,
            'reason'=>'Synthetic profile','title_ref'=>'SYN-DEED','idempotency_key'=>'profile-'.$version],$this->actor);
    }

    private function refused(string $code,callable $work): void
    {
        try {$work();self::fail('Actual accounting writer must reject '.$code);} catch (PostingException $e) {self::assertSame($code,$e->errorCode);}
    }
}


