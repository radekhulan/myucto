<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountDefinitionRepository, AccountingPeriodRepository, ChartOfAccountsRepository, DimensionAccountMapRepository};
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder, PostingService};
use MyInvoice\Service\Accounting\Card\CardClearingSettingsService;
use MyInvoice\Service\Accounting\Dimension\{DimensionService, DimensionAccountMapService, DimensionAccountRouter};
use MyInvoice\Service\Accounting\Expense\ExpenseClassificationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccountDefinitionConsumerTest extends TestCase
{
    use IsolatedSupplierTrait;
    private \DI\Container $container;
    private Connection $db;
    private int $supplier;
    private int $actor;
    private array $ids=[];
    private AccountDefinitionRepository $definitions;

    protected function setUp(): void
    {
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));
        $this->container=Bootstrap::buildContainer();$this->db=$this->container->get(Connection::class);
        $pdo=$this->db->pdo();$pdo->beginTransaction();
        $this->supplier=$this->createIsolatedSupplier($pdo,(int)$pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->actor=(int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier,'2025-01-01');
        $chart=$this->container->get(ChartOfAccountsRepository::class);
        foreach($chart->listForTenant($this->supplier,true,'2025-01-01') as $row)$this->ids[$row['account_code']]=$row['id'];
        $pdo->prepare('DELETE FROM chart_of_accounts WHERE supplier_id=? AND parent_id IN (?,?)')->execute([$this->supplier,$this->ids['511'],$this->ids['548']]);
        foreach([['511.007','511','deductible'],['511.990','511','non_deductible'],['548.007','548','non_deductible']] as [$code,$parent,$tax])$this->ids[$code]=$chart->insert($this->supplier,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>'expense','normal_side'=>'debit','tax_deductibility'=>$tax,'parent_id'=>$this->ids[$parent],'is_synthetic'=>false]);
        foreach([2025,2026,2027] as $year)$this->container->get(AccountingPeriodRepository::class)->create($this->supplier,$year,"$year-01-01","$year-12-31");
        $this->definitions=new AccountDefinitionRepository($this->db);
    }
    protected function tearDown(): void
    {
        if(isset($this->db)&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();
    }
    private function change(string $code,array $fields): void
    {
        $id=$this->ids[$code];$this->definitions->change($this->supplier,$id,'2027-01-01',$fields,$this->definitions->stateHash($this->supplier,$id),$this->actor,'Synthetic prospective account definition');
    }
    public function testExpenseAnalyticUsesEachDateInsteadOfThePhysicalFutureDefinition(): void
    {
        $this->change('511.007',['tax_deductibility'=>'non_deductible']);
        $service=$this->container->get(ExpenseClassificationService::class);
        self::assertSame('511.007',$service->analyticForExpenseAccount($this->supplier,'511','2025-06-01'));
        self::assertSame('511',$service->analyticForExpenseAccount($this->supplier,'511','2027-06-01'));
        self::assertSame('511.007',$service->analyticForExpenseAccount($this->supplier,'511','2025-06-01'));
    }
    public function testPurchaseNativeBuilderAndPostingUseTheActualOverrideDateForNonDeductibleAnalytic(): void
    {
        $this->change('511.007',['tax_deductibility'=>'non_deductible']);
        $purchase=$this->purchase();
        $posting=$this->container->get(PostingService::class);
        $lines=$posting->buildFromPurchaseInvoice($this->supplier,$purchase,['debit_account_code'=>'511.007','entry_date'=>'2025-06-01']);
        $debit=array_values(array_filter($lines,static fn(array $line):bool=>$line['side']==='debit'));
        self::assertSame('511.990',$debit[0]['account_code']);
        $entry=$posting->postDocument($this->supplier,'purchase_invoice',$purchase,$lines,['entry_date'=>'2025-06-01','posted'=>true,'posted_by'=>$this->actor]);
        $q=$this->db->pdo()->prepare('SELECT a.account_code,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.entry_id=? AND l.side="debit"');$q->execute([$entry]);
        self::assertSame([['account_code'=>'511.990','amount'=>'100.00']],$q->fetchAll(PDO::FETCH_ASSOC));
        $future=$posting->buildFromPurchaseInvoice($this->supplier,$purchase,['debit_account_code'=>'511.007','entry_date'=>'2027-06-01']);
        self::assertSame('511.007',array_values(array_filter($future,static fn(array $line):bool=>$line['side']==='debit'))[0]['account_code']);
    }
    public function testDimensionMapAndNativeRoutingUseTheDefinitionAtEachEntryDate(): void
    {
        $dimensions=$this->container->get(DimensionService::class);$dimensions->setEnabled($this->supplier,true);
        $types=$dimensions->ensureDefaultTypes($this->supplier,['stredisko']);$type=$types['cost_center'];
        $value=(int)$dimensions->createValue($this->supplier,$type,['code'=>'SYNTHETIC','name'=>'Synthetic scope'])['id'];
        $dimensions->updateType($this->supplier,$type,['drives_accounts'=>true]);
        $this->container->get(DimensionAccountMapService::class)->saveForValue($this->supplier,$value,[['synthetic_code'=>'511','analytic_code'=>'511.007']],$this->actor);
        $this->change('511.007',['tax_deductibility'=>'non_deductible']);
        $map=new DimensionAccountMapRepository($this->db);
        self::assertSame([$this->ids['511']=>[$value=>$this->ids['511.007']]],$map->activeMap($this->supplier,$type,'2025-06-01'));
        self::assertSame([],$map->activeMap($this->supplier,$type,'2027-06-01'));
        self::assertSame([],$map->invalidRows($this->supplier,$type,'2025-06-01'));
        self::assertCount(1,$map->invalidRows($this->supplier,$type,'2027-06-01'));
        $router=new DimensionAccountRouter($this->db);
        self::assertNotNull($router->context($this->supplier,'2025-06-01'));
        self::assertNull($router->context($this->supplier,'2027-06-01'));
        $entry=$this->container->get(PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>'511','side'=>'debit','amount'=>'20.00','dimensions'=>[$type=>$value]],['account_code'=>'221','side'=>'credit','amount'=>'20.00']],['entry_date'=>'2025-06-01','posted'=>true,'posted_by'=>$this->actor]);
        $q=$this->db->pdo()->prepare('SELECT a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? AND l.side="debit"');$q->execute([$entry]);self::assertSame('511.007',$q->fetchColumn());
    }
    public function testCardWriteoffDefaultUsesTodayInsteadOfAProposedFutureDefinition(): void
    {
        $this->change('548.007',['tax_deductibility'=>'deductible']);
        $this->db->pdo()->prepare('UPDATE chart_of_accounts SET tax_deductibility="deductible" WHERE supplier_id=? AND id=?')->execute([$this->supplier,$this->ids['548.007']]);
        self::assertSame('548.007',$this->container->get(CardClearingSettingsService::class)->defaultWriteoffCode($this->supplier));
    }
    public function testCurrentReadBypassesTheWarmExpenseAnalyticCache(): void
    {
        $service=$this->container->get(ExpenseClassificationService::class);
        self::assertSame('511.007',$service->analyticForExpenseAccount($this->supplier,'511','2027-06-01'));
        $this->change('511.007',['tax_deductibility'=>'non_deductible']);
        self::assertSame('511',$service->analyticForExpenseAccount($this->supplier,'511','2027-06-01',true));
    }
    public function testCurrentReadBypassesTheWarmDimensionContextCache(): void
    {
        [$type,$value]=$this->dimensionMap();
        $router=new DimensionAccountRouter($this->db);
        self::assertNotNull($router->context($this->supplier,'2027-06-01'));
        $this->change('511.007',['tax_deductibility'=>'non_deductible']);
        self::assertNull($router->context($this->supplier,'2027-06-01',true));
    }
    public function testHistoricalInvalidMapReportsATargetThatDidNotExistYet(): void
    {
        $this->change('548.007',['tax_deductibility'=>'deductible']);
        $id=$this->container->get(ChartOfAccountsRepository::class)->insert($this->supplier,['account_code'=>'511.008','name'=>'Synthetic new analytic','account_type'=>'expense','normal_side'=>'debit','tax_deductibility'=>'deductible','parent_id'=>$this->ids['511'],'is_synthetic'=>false],'2025-01-01',$this->actor);
        [$type,$value]=$this->dimensionMap('511.008','2024-01-01');
        $repo=new DimensionAccountMapRepository($this->db);
        self::assertSame([],$repo->activeMap($this->supplier,$type,'2024-06-01'));
        $invalid=$repo->invalidRows($this->supplier,$type,'2024-06-01');
        self::assertCount(1,$invalid);
        self::assertSame($id,$invalid[0]['account_id']);
        self::assertSame([],$repo->invalidRows($this->supplier,$type,'2025-06-01'));
    }
    public function testInvalidMapReportsAnInactiveDatedAccount(): void
    {
        [$type,$value]=$this->dimensionMap();
        $this->change('511.007',['is_active'=>false]);
        $repo=new DimensionAccountMapRepository($this->db);
        self::assertSame([],$repo->activeMap($this->supplier,$type,'2027-06-01'));
        self::assertCount(1,$repo->invalidRows($this->supplier,$type,'2027-06-01'));
    }
    private function dimensionMap(string $analytic='511.007',?string $from=null): array
    {
        $dimensions=$this->container->get(DimensionService::class);$dimensions->setEnabled($this->supplier,true);
        $types=$dimensions->ensureDefaultTypes($this->supplier,['stredisko']);$type=$types['cost_center'];
        $value=(int)$dimensions->createValue($this->supplier,$type,['code'=>'SYNTHETIC','name'=>'Synthetic scope'])['id'];
        $dimensions->updateType($this->supplier,$type,['drives_accounts'=>true]);
        $this->container->get(DimensionAccountMapService::class)->saveForValue($this->supplier,$value,[['synthetic_code'=>'511','analytic_code'=>$analytic,'valid_from'=>$from]],$this->actor);
        return [$type,$value];
    }
    public function testAutomaticPostingPropagatesTheActualAccountingDateToTheNativeBuilder(): void
    {
        $this->change('511.007',['tax_deductibility'=>'non_deductible']);
        $purchase=$this->purchase();
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET expense_account_code="511.007",expense_classification_source=NULL WHERE purchase_invoice_id=?')->execute([$purchase]);
        $entry=$this->container->get(\MyInvoice\Service\Accounting\DocumentAutoPoster::class)->post($this->supplier,'purchase_invoice',$purchase,['entry_date'=>'2025-06-01','posted_by'=>$this->actor,'user_id'=>$this->actor],$this->actor);
        $q=$this->db->pdo()->prepare('SELECT a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? AND l.side="debit"');$q->execute([$entry]);
        self::assertSame('511.990',$q->fetchColumn());
        self::assertSame(['511.990'],array_values($this->container->get(PostingService::class)->purchaseItemExpenseAccounts($this->supplier,$purchase)));
    }
    public function testProductAccountValidationUsesTheSuppliedDocumentDate(): void
    {
        $this->change('511.007',['is_active'=>false]);
        $service=new \MyInvoice\Service\Accounting\Product\ProductPostingDefaults($this->db);
        self::assertSame('511.007',$service->validateAccount($this->supplier,'511.007','expense','2025-06-01'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('aktivní');
        $service->validateAccount($this->supplier,'511.007','expense','2027-06-01');
    }
    private function purchase(): int
    {
        $p=$this->db->pdo();
        $p->prepare('INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en) VALUES (?,"CZK","Synthetic CZK","Kč","Koruna","Crown")')->execute([$this->supplier]);$currency=(int)$p->lastInsertId();
        $p->prepare('INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_vendor,is_customer) VALUES (?,"Synthetic vendor","Test 1","Praha","11000",(SELECT id FROM countries WHERE iso2="CZ" LIMIT 1),"synthetic@example.invalid","cs",?,1,0)')->execute([$this->supplier,$currency]);$vendor=(int)$p->lastInsertId();
        $p->prepare('INSERT INTO purchase_invoices (supplier_id,vendor_id,vendor_invoice_number,vendor_snapshot,document_kind,vat_deduction,issue_date,tax_date,due_date,received_at,currency_id,exchange_rate,reverse_charge,is_fixed_asset,total_without_vat,total_vat,total_with_vat,status,vat_classification_code,tax_deductible,created_by) VALUES (?,?,"SYNTHETIC-METADATA","{}","invoice","none","2027-06-01","2027-06-01","2027-06-01","2027-06-01",?,1,0,0,100,0,100,"received","40",0,?)')->execute([$this->supplier,$vendor,$currency,$this->actor]);$id=(int)$p->lastInsertId();
        $p->prepare('INSERT INTO purchase_invoice_items (purchase_invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index,vat_classification_code) VALUES (?,"Synthetic service",1,"ks",100,(SELECT MIN(id) FROM vat_rates),0,100,0,100,0,"40")')->execute([$id]);
        return $id;
    }
}