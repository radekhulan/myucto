<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository,FxCaseOriginReadRepository};
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder,PostingService};
use PDO;
use PHPUnit\Framework\TestCase;
final class FxCaseOriginSourceClosureTest extends TestCase
{
    private Connection $db;
    private PostingService $posting;
    private int $supplier;
    private int $client;
    private int $currency;
    protected function setUp(): void
    {
        $c=Bootstrap::buildContainer();$this->db=$c->get(Connection::class);$p=$this->db->pdo();$p->beginTransaction();
        $p->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) SELECT 'Synthetic FX source closure','Test','Test','10000','closure@example.invalid',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");$this->supplier=(int)$p->lastInsertId();$c->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);foreach([2025,2026] as $year)$c->get(AccountingPeriodRepository::class)->create($this->supplier,$year,"$year-01-01","$year-12-31");
        $p->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default) VALUES(?,'EUR','EUR','EUR','Euro','Euro',2,1,0)")->execute([$this->supplier]);$this->currency=(int)$p->lastInsertId();$p->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT id,'Synthetic issuer','Test','Test','10000',country_id,'issuer@example.invalid',? FROM supplier WHERE id=?")->execute([$this->currency,$this->supplier]);$this->client=(int)$p->lastInsertId();$this->posting=$c->get(PostingService::class);
    }
    protected function tearDown(): void{if(isset($this->db)&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    public function testUnrelatedFutureNativeFactsDoNotInvalidateHistoricalSourceClosure(): void
    {
        $entry=$this->post('manual',null,'2025-06-01');$rows=$this->rows([$entry]);$before=$this->snapshot($rows);
        $invoice=$this->invoice('2026-06-01');$bank=$this->bank('2026-06-01',$invoice);$future=$this->post('manual',null,'2026-06-01');$p=$this->db->pdo();
        $p->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source,bank_transaction_id) VALUES(?,?,'2026-06-01',10,'EUR','bank',?)")->execute([$this->supplier,$invoice,$bank]);$p->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,invoice_id,amount,match_type) VALUES(?,?,?,10,'manual')")->execute([$this->supplier,$bank,$invoice]);
        $this->settlement($invoice,$future);$this->offset($invoice,$future);$p->prepare("INSERT INTO journal_entry_document_links(supplier_id,entry_id,doc_type,doc_id) VALUES(?,?,'invoice',?)")->execute([$this->supplier,$future,$invoice]);
        $after=$this->snapshot($rows);self::assertSame($before['origins'],$after['origins']);self::assertSame($before['facts_hash'],$after['facts_hash']);self::assertSame($before['facts'],$after['facts']);
    }
    public function testUsedBankAndLaterLinkedNativeFactsAndCreditParentStayInClosure(): void
    {
        $parent=$this->invoice('2025-01-01');$credit=$this->invoice('2025-06-01',$parent);$document=$this->post('invoice',$credit,'2025-06-01');$bank=$this->bank('2025-06-02',$credit);$payment=$this->post('bank',$bank,'2025-06-02');$rows=$this->rows([$document,$payment]);$before=$this->snapshot($rows);$line=$this->line($payment,'311');self::assertSame([['kind'=>'document','doc_type'=>'invoice','doc_id'=>$parent]],$before['origins'][$line]);
        $later=$this->invoice('2026-06-01',$parent);$this->db->pdo()->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,invoice_id,amount,match_type) VALUES(?,?,?,10,'manual')")->execute([$this->supplier,$bank,$later]);$linked=$this->snapshot($rows);self::assertNotSame($before['facts_hash'],$linked['facts_hash']);self::assertContains($later,array_map('intval',array_column($linked['facts']['invoices'],'id')));
        $this->db->pdo()->prepare('UPDATE bank_transactions SET amount=amount+1 WHERE id=?')->execute([$bank]);$changed=$this->snapshot($rows);self::assertNotSame($linked['facts_hash'],$changed['facts_hash']);
        $this->db->pdo()->prepare("UPDATE invoices SET invoice_type='invoice' WHERE id=?")->execute([$credit]);$changed=$this->snapshot($rows);self::assertNotSame($before['facts_hash'],$changed['facts_hash']);self::assertSame([],$changed['origins'][$line]);
    }
    public function testNeededCyclicParentIsPreservedAndFailsClosed(): void
    {
        $parent=$this->invoice('2025-01-01');$credit=$this->invoice('2025-06-01',$parent);$entry=$this->post('invoice',$credit,'2025-06-01');$rows=$this->rows([$entry]);$before=$this->snapshot($rows);$this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id=? WHERE id=?')->execute([$credit,$credit]);$cycle=$this->snapshot($rows);self::assertNotSame($before['facts_hash'],$cycle['facts_hash']);self::assertCount(1,$cycle['facts']['invoices']);self::assertSame([],$cycle['origins'][$this->line($entry,'311')]);
    }
    public function testDirectSettlementOffsetAndExternalReversalParentReferencesAreRetained(): void
    {
        $invoice=$this->invoice('2025-01-01');$settlement=$this->settlement($invoice,null);$offset=$this->offset($invoice,null);$s=$this->post('settlement',$settlement,'2025-06-01');$o=$this->post('offset',$offset,'2025-06-01');$direct=$this->snapshot($this->rows([$s,$o]));self::assertContains($settlement,array_map('intval',array_column($direct['facts']['invoice_settlements'],'id')));self::assertContains($offset,array_map('intval',array_column($direct['facts']['offset_agreements'],'id')));self::assertCount(1,$direct['facts']['offset_agreement_items']);self::assertContains($invoice,array_map('intval',array_column($direct['facts']['invoices'],'id')));
        $manual=$this->post('manual',null,'2025-06-01');$this->db->pdo()->prepare("INSERT INTO journal_entry_document_links(supplier_id,entry_id,doc_type,doc_id) VALUES(?,?,'invoice',?)")->execute([$this->supplier,$manual,$invoice]);$reverse=$this->posting->reverse($this->supplier,$manual,['entry_date'=>'2026-06-01']);$snapshot=$this->snapshot($this->rows([$reverse]));self::assertContains($manual,array_map('intval',array_column($snapshot['facts']['journal_entry_document_links'],'entry_id')));self::assertContains($invoice,array_map('intval',array_column($snapshot['facts']['invoices'],'id')));
    }
    private function snapshot(array $rows): array{return(new FxCaseOriginReadRepository($this->db))->snapshot($this->supplier,$rows,true);}
    private function invoice(string $date,?int $parent=null): int
    {
        $p=$this->db->pdo();$p->prepare("INSERT INTO invoices(supplier_id,varsymbol,invoice_type,parent_invoice_id,client_id,issue_date,tax_date,due_date,currency_id,exchange_rate,total_without_vat,total_vat,total_with_vat,status,vat_classification_code,created_by) VALUES(?,?,?,?,?,?,?,?,?,24,1000,0,1000,'issued','1',(SELECT id FROM users ORDER BY id LIMIT 1))")->execute([$this->supplier,'SYN-'.bin2hex(random_bytes(5)),$parent===null?'invoice':'credit_note',$parent,$this->client,$date,$date,$date,$this->currency]);return(int)$p->lastInsertId();
    }
    private function bank(string $date,int $invoice): int
    {
        $p=$this->db->pdo();$p->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,source) VALUES(?,'Synthetic closure source',?,'1000000005','0100','EUR',?,'gpc')")->execute([$this->supplier,hash('sha256',random_bytes(20)),$date]);$statement=(int)$p->lastInsertId();$p->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,matched_invoice_id) VALUES(?,?,1000,'EUR',?)")->execute([$statement,$date,$invoice]);return(int)$p->lastInsertId();
    }
    private function post(string $type,?int $source,string $date): int{return$this->posting->postDocument($this->supplier,$type,$source,[['account_code'=>'311','side'=>'debit','amount'=>'24000.00','currency_code'=>'EUR','amount_foreign'=>'1000.00','fx_rate'=>'24.000000'],['account_code'=>'602','side'=>'credit','amount'=>'24000.00']],['entry_date'=>$date,'posted'=>true]);}
    private function rows(array $entries): array{$q=$this->db->pdo()->prepare('SELECT l.*,e.source_type,e.source_id,a.account_code FROM journal_entries e JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE e.supplier_id=? AND e.id IN ('.implode(',',array_fill(0,count($entries),'?')).') ORDER BY e.id,l.line_no');$q->execute([$this->supplier,...$entries]);return$q->fetchAll(PDO::FETCH_ASSOC);}
    private function line(int $entry,string $account): int{foreach($this->rows([$entry]) as $line)if($line['account_code']===$account)return(int)$line['id'];throw new \LogicException('Missing synthetic source line');}
    private function settlement(int $invoice,?int $entry): int{$q=$this->db->pdo()->prepare("INSERT INTO invoice_settlements(supplier_id,doc_type,doc_id,settled_on,amount,account_id,journal_entry_id) VALUES(?,'invoice',?,'2026-06-01',10,(SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code='311'),?)");$q->execute([$this->supplier,$invoice,$this->supplier,$entry]);return(int)$this->db->pdo()->lastInsertId();}
    private function offset(int $invoice,?int $entry): int{$p=$this->db->pdo();$p->prepare("INSERT INTO offset_agreements(supplier_id,partner_id,agreement_date,document_no,total_amount,status,journal_entry_id) VALUES(?,?,'2026-06-01',?,10,'confirmed',?)")->execute([$this->supplier,$this->client,'SYN-'.bin2hex(random_bytes(5)),$entry]);$id=(int)$p->lastInsertId();$p->prepare("INSERT INTO offset_agreement_items(supplier_id,agreement_id,doc_type,doc_id,amount) VALUES(?,?,'invoice',?,10)")->execute([$this->supplier,$id,$invoice]);return$id;}
}
