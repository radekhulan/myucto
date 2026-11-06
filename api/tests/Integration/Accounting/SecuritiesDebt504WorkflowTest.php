<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository,SecuritiesDebt504Repository};
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder,PostingService};
use MyInvoice\Service\Accounting\Closing\{SecuritiesDebt504Workflow,SecuritiesDebt504Terms,ClosingException};
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use PHPUnit\Framework\TestCase;

final class SecuritiesDebt504WorkflowTest extends TestCase
{
    private Connection $db;
    private \DI\Container $container;
    private PostingService $posting;
    private SecuritiesDebt504Workflow $service;
    private int $supplier;
    private int $actor;
    private array $periods;
    private array $dims;

    protected function setUp(): void
    {
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));
        $c=Bootstrap::buildContainer();$this->container=$c;$this->db=$c->get(Connection::class);$p=$this->db->pdo();
        self::assertSame(getenv('MYINVOICE_DB_NAME'),$p->query('SELECT DATABASE()')->fetchColumn());$p->beginTransaction();
        $p->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) SELECT 'Synthetic debt workflow','Test','Test','10000','debt@example.invalid',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");$this->supplier=(int)$p->lastInsertId();
        $this->actor=(int)$p->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();self::assertGreaterThan(0,$this->actor);
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $this->periods=[];foreach([2025,2026] as $year)$this->periods[$year]=$c->get(AccountingPeriodRepository::class)->create($this->supplier,$year,"$year-01-01","$year-12-31");
        $p->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2025-01-01','cz_504')")->execute([$this->supplier]);
        $catalog=(new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier,'2025-01-01');
        $p->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,'portfolio','Synthetic portfolio')")->execute([$catalog['activity_kind']['id'],$this->supplier]);
        $this->dims=[$catalog['activity']['id']=>$catalog['activity']['values']['main'],$catalog['activity_kind']['id']=>(int)$p->lastInsertId(),$catalog['tax_regime']['id']=>$catalog['tax_regime']['values']['subject']];
        foreach(['644'=>'revenue','645'=>'revenue','649'=>'revenue','549'=>'expense','544'=>'expense','545'=>'expense','653'=>'revenue','553'=>'expense'] as $code=>$type)$p->prepare('INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES(?,?,?,?,1)')->execute([$this->supplier,$code,'Synthetic '.$code,$type]);
        $this->posting=$c->get(PostingService::class);$this->service=$c->get(SecuritiesDebt504Workflow::class);
    }
    protected function tearDown(): void {if(isset($this->db)&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}

    public function testNativeCouponAcquisitionAccrualAcrossYearPartialReceiptsAndPrincipalAreNotIncome(): void
    {
        $terms=$this->coupon();$lot=$this->register($terms);
        $one=$this->applyCommand(['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic year-end accrual']);
        $state=$this->service->detail($this->supplier,$lot,'2025-12-31');self::assertSame('17.00',$state['earned']);self::assertSame('31.00',$state['unpaid_interest']);
        $this->applyCommand(['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2026-01-10','evidence'=>'Synthetic new-year earned']);
        $bank=$this->bank('20.00','2026-01-15','253');
        $this->applyCommand(['operation'=>'coupon_receipt','lot_id'=>$lot,'accounting_date'=>'2026-01-15','evidence'=>'Synthetic first partial coupon']+$bank);
        $state=$this->service->detail($this->supplier,$lot,'2026-01-15');self::assertSame('27.00',$state['earned']);self::assertSame('21.00',$state['unpaid_interest']);self::assertSame('1000.00',$state['principal_balance']);
        $this->applyCommand(['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2026-01-31','evidence'=>'Synthetic remaining earned']);
        $this->applyCommand(['operation'=>'coupon_receipt','lot_id'=>$lot,'accounting_date'=>'2026-02-01','evidence'=>'Synthetic final coupon']+$this->bank('42.00','2026-02-01','253'));
        $this->applyCommand(['operation'=>'principal_redemption','nominal_amount'=>'1000.00','lot_id'=>$lot,'accounting_date'=>'2026-02-01','evidence'=>'Synthetic principal return']+$this->bank('1000.00','2026-02-01','253'));
        $state=$this->service->detail($this->supplier,$lot,'2026-02-01');self::assertSame('48.00',$state['earned']);self::assertSame('0.00',$state['unpaid_interest']);self::assertSame('0.00',$state['principal_balance']);self::assertSame('0.00',$state['book_czk']);
        self::assertTrue($this->db->pdo()->inTransaction());self::assertIsInt($one['event_id']);
    }

    public function testNativeHtmDiscountAndPremiumUseCumulativeScheduleAndExactReversal(): void
    {
        foreach(['900.00','1100.00'] as $cost){
            $terms=$this->discount($cost);$lot=$this->register($terms);$command=['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic HTM amortisation'];
            $preview=$this->service->preview($this->supplier,$command,$this->actor);
            self::assertSame($cost==='900.00'?'644':'544',$preview['plan']['lines'][1]['account_code']);
            $event=$this->applyCommand($command);$state=$this->service->detail($this->supplier,$lot,'2025-12-31');self::assertSame(SecuritiesDebt504Terms::amortised($terms,'2025-12-31'),$state['amortised']);
            $this->applyCommand(['operation'=>'reverse','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic correction','event_id'=>$event['event_id']]);
            self::assertSame('0.00',$this->service->detail($this->supplier,$lot,'2025-12-31')['amortised']);
            $this->applyCommand($command);$this->applyCommand(['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2026-06-30','evidence'=>'Synthetic maturity accretion']);
            $state=$this->service->detail($this->supplier,$lot,'2026-06-30');self::assertSame('1000.00',$state['principal_balance']);
            $this->applyCommand(['operation'=>'principal_redemption','nominal_amount'=>'1000.00','lot_id'=>$lot,'accounting_date'=>'2026-07-01','evidence'=>'Synthetic HTM redemption']+$this->bank('1000.00','2026-07-01','063'));
            self::assertSame('0.00',$this->service->detail($this->supplier,$lot,'2026-07-01')['book_czk']);
        }
    }

    public function testPreviewDoesNotWriteAndExecuteRetainsActorBoundIdempotenceAndRejectsStaleNativeSource(): void
    {
        $terms=$this->coupon();$source=$this->acquisition($terms);$command=['operation'=>'register','terms'=>$terms]+$source;
        $before=$this->countRows();$preview=$this->service->preview($this->supplier,$command,$this->actor);self::assertSame($before,$this->countRows());
        $done=$this->service->execute($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-register');
        self::assertSame($done,$this->service->execute($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-register'));
        $accrual=['operation'=>'accrual','lot_id'=>$done['lot_id'],'accounting_date'=>'2025-12-31','evidence'=>'Synthetic stale accrual'];$review=$this->service->preview($this->supplier,$accrual,$this->actor);
        $this->db->pdo()->prepare('UPDATE journal_entry_lines SET amount=amount+1 WHERE supplier_id=? AND id=?')->execute([$this->supplier,$source['line_id']]);
        $this->expectException(\MyInvoice\Service\Property\Payments\PaymentException::class);
        $this->service->execute($this->supplier,$accrual,$this->actor,$review['state_hash'],$review['preview_hash'],'synthetic-stale');
    }

    public function testRehashedReceiptAmountCannotForgeNativeAccrualAndMissingClaimsCannotPass(): void
    {
        $lot=$this->register($this->coupon());$event=$this->applyCommand(['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic canonical proof']);
        $q=$this->db->pdo()->prepare('SELECT receipt_json FROM accounting_securities_debt_events WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$event['event_id']]);$receipt=json_decode($q->fetchColumn(),true);$receipt['amount']='18.00';
        $this->db->pdo()->prepare('UPDATE accounting_securities_debt_events SET receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([SecuritiesDebt504Repository::json($receipt),SecuritiesDebt504Repository::hash($receipt),$this->supplier,$event['event_id']]);
        $this->expectException(ClosingException::class);$this->service->detail($this->supplier,$lot,'2025-12-31');
    }

    public function testHtmNativeClosingProofSurvivesActualCloseOpenAndContinuesWithoutDuplicateIncome(): void
    {
        $terms=$this->discount('900.00');$lot=$this->register($terms);
        $this->applyCommand(['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic year-end accretion']);
        $c=$this->container;$closing=$c->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);$fx=$c->get(\MyInvoice\Service\Accounting\Closing\Fx504Service::class);$p=$this->db->pdo();$period=$this->periods[2025];
        $p->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$period]);
        foreach(['901','911','931','932','933','961','962'] as $code)$p->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,?,?,'equity',1)")->execute([$this->supplier,$code,'Synthetic '.$code]);
        $version=fn()=>(int)$p->query('SELECT row_version FROM accounting_periods WHERE id='.$period)->fetchColumn();
        $inventory=[];foreach($closing->inventoryPreview($this->supplier,$period)['rows'] as $row)$inventory[(int)$row['account_id']]=['counted_balance'=>(float)$row['book_balance'],'resolution'=>'resolved','note'=>null];
        $closing->saveInventory($this->supplier,$period,$version(),['complete'=>true],$inventory);$closing->runPrecheck($this->supplier,$period,$version());
        foreach(['depreciation','estimates','deferrals','provisions','income_tax'] as $step)$closing->confirmStep($this->supplier,$period,$step,'skipped',null,$version());
        $preview=$fx->preview($this->supplier,$period);self::assertTrue($preview['complete'],json_encode($preview['securities']['findings']));
        $fx->run($this->supplier,$period,['snapshot_hash'=>$preview['snapshot_hash']],$version());
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($c,$this->supplier,$period,$this->actor);
        $closing->closeBooks($this->supplier,$period,$version());$opened=$closing->openNext($this->supplier,$period,$version());self::assertSame($this->periods[2026],$opened['next_period_id']);
        $before=$this->service->detail($this->supplier,$lot,'2025-12-31');$this->applyCommand(['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2026-06-30','evidence'=>'Synthetic next year remaining accretion']);
        $after=$this->service->detail($this->supplier,$lot,'2026-06-30');self::assertSame('100.00',$after['amortised']);self::assertSame('1000.00',$after['principal_balance']);
        self::assertSame($before['amortised'],$this->service->detail($this->supplier,$lot,'2025-12-31')['amortised']);
        self::assertSame($preview['snapshot_hash'],$fx->preview($this->supplier,$period)['snapshot_hash']);
    }

    public function testOneNativePaymentLineCannotBeBothCouponAndPrincipal(): void
    {
        $lot=$this->register($this->coupon());$this->applyCommand(['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic earned coupon']);$bank=$this->bank('10.00','2026-02-01','253');
        $this->applyCommand(['operation'=>'coupon_receipt','lot_id'=>$lot,'accounting_date'=>'2026-02-01','evidence'=>'Synthetic coupon classification']+$bank);
        $this->expectException(ClosingException::class);$this->service->preview($this->supplier,['operation'=>'principal_redemption','nominal_amount'=>'1000.00','lot_id'=>$lot,'accounting_date'=>'2026-02-01','evidence'=>'Synthetic prohibited duplicate classification']+$bank,$this->actor);
    }

    public function testMissingAcquisitionClaimAndForeignTenantCannotProduceCurrentProof(): void
    {
        $lot=$this->register($this->coupon());$this->db->pdo()->prepare('DELETE FROM accounting_securities_debt_claims WHERE supplier_id=? AND lot_id=?')->execute([$this->supplier,$lot]);
        $this->expectException(ClosingException::class);$this->service->detail($this->supplier,$lot,'2025-12-31');
    }

    public function testDiscountTradingUsesInterestRouteAndReturnsContractNominalWithoutCountingItAsIncome(): void
    {
        $terms=$this->discount('900.00');$terms['classification']='trading';$terms['lot_reference']='SYN-DISCOUNT-TRADING';unset($terms['amortisation']);$lot=$this->register($terms);
        $command=['operation'=>'valuation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic regulated clean price','profile'=>$this->profile(),'quote'=>$this->quote('950.00')];
        $preview=$this->service->preview($this->supplier,$command,$this->actor);self::assertSame('644',$preview['plan']['lines'][1]['account_code']);self::assertSame('50.00',$preview['plan']['book_change_czk']);$this->applyCommand($command);
        $state=$this->service->detail($this->supplier,$lot,'2025-12-31');self::assertSame('950.00',$state['book_czk']);self::assertSame('1000.00',$state['principal_balance']);
        $this->applyCommand(['operation'=>'principal_redemption','nominal_amount'=>'1000.00','lot_id'=>$lot,'accounting_date'=>'2026-07-01','evidence'=>'Synthetic contract principal returned']+$this->bank('1000.00','2026-07-01','253','950.00'));
        $state=$this->service->detail($this->supplier,$lot,'2026-07-01');self::assertSame('0.00',$state['principal_balance']);self::assertSame('0.00',$state['book_czk']);self::assertSame('0.00',$state['earned']);
    }

    public function testTradingCouponCleanPriceIsSeparateFromPurchasedAndEarnedInterest(): void
    {
        $lot=$this->register($this->coupon());$this->applyCommand(['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic coupon earned']);
        $command=['operation'=>'valuation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic independent clean quote','profile'=>$this->profile(),'quote'=>$this->quote('1005.00')];
        $preview=$this->service->preview($this->supplier,$command,$this->actor);self::assertSame('649',$preview['plan']['lines'][1]['account_code']);self::assertSame('5.00',$preview['plan']['book_change_czk']);$this->applyCommand($command);
        $state=$this->service->detail($this->supplier,$lot,'2025-12-31');self::assertSame('31.00',$state['unpaid_interest']);self::assertSame('1036.00',$state['book_czk']);
        $fx=$this->container->get(\MyInvoice\Service\Accounting\Closing\Fx504Service::class)->preview($this->supplier,$this->periods[2025],[],false,null,['valuations'=>[],'profile'=>$this->profile()]);self::assertTrue($fx['complete'],json_encode($fx['securities']['findings']));
        $command['quote']['quote_type']='dirty';$this->expectException(ClosingException::class);$this->service->preview($this->supplier,$command,$this->actor);
    }

    public function testTradingCouponPremiumRedeemsContractNominalAgainstItsHigherNativeCarryingLine(): void
    {
        $lot=$this->register($this->coupon());$this->applyCommand(['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic earned coupon']);$this->applyCommand(['operation'=>'valuation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic premium quote','profile'=>$this->profile(),'quote'=>$this->quote('1050.00')]);$this->applyCommand(['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2026-01-31','evidence'=>'Synthetic earned final coupon']);$this->applyCommand(['operation'=>'coupon_receipt','lot_id'=>$lot,'accounting_date'=>'2026-02-01','evidence'=>'Synthetic actual final coupon']+$this->bank('62.00','2026-02-01','253'));
        $state=$this->service->detail($this->supplier,$lot,'2026-02-01');self::assertSame('1050.00',$state['principal_book_czk']);self::assertSame('1000.00',$state['principal_balance']);$this->applyCommand(['operation'=>'principal_redemption','nominal_amount'=>'1000.00','lot_id'=>$lot,'accounting_date'=>'2026-02-01','evidence'=>'Synthetic premium principal redemption']+$this->bank('1000.00','2026-02-01','253','1050.00'));$state=$this->service->detail($this->supplier,$lot,'2026-02-01');self::assertSame('0.00',$state['book_czk']);self::assertSame('0.00',$state['principal_balance']);
    }

    public function testUseGuardProtectsWholeAcquisitionAndGeneratedLedgerAndPhysicalBankSource(): void
    {
        $terms=$this->coupon();$source=$this->acquisition($terms);$lot=$this->applyCommand(['operation'=>'register','terms'=>$terms]+$source)['lot_id'];
        $event=$this->applyCommand(['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic immutable owned accrual']);
        $state=$this->service->detail($this->supplier,$lot,'2025-12-31');$entry=$state['events'][0]['receipt']['ledger']['entry']['id'];
        $bank=$this->bank('10.00','2026-01-15','253');$this->applyCommand(['operation'=>'coupon_receipt','lot_id'=>$lot,'accounting_date'=>'2026-01-15','evidence'=>'Synthetic bound native receipt']+$bank);
        $q=$this->db->pdo()->prepare('SELECT e.source_id,b.statement_id FROM journal_entries e JOIN bank_transactions b ON b.id=e.source_id WHERE e.supplier_id=? AND e.id=?');$q->execute([$this->supplier,$bank['entry_id']]);$physical=$q->fetch(\PDO::FETCH_ASSOC);
        $guard=new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504UseGuard($this->db);
        foreach([fn()=> $guard->assertEntriesMutable($this->supplier,[$source['entry_id']]),fn()=> $guard->assertLinesMutable($this->supplier,[$source['line_id']]),fn()=> $guard->assertEntriesMutable($this->supplier,[$entry]),fn()=> $guard->assertEntriesMutable($this->supplier,[$bank['entry_id']]),fn()=> $guard->assertSourceMutable($this->supplier,'bank',(int)$physical['source_id']),fn()=> $guard->assertBankStatementMutable($this->supplier,(int)$physical['statement_id'])] as $mutation){
            try{$mutation();self::fail('An owned financial source was mutable.');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('debt_source_immutable',$e->errorCode);}
        }
        $this->expectException(ClosingException::class);$guard->reverseLatest($this->supplier,$lot,$event['event_id'],$entry,'2026-01-15',fn()=>null);
    }

    public function testValuationPreviewIsInvalidatedByNewPureProfitAndLossTurnoverMembership(): void
    {
        $terms=$this->discount('900.00');$terms['classification']='trading';unset($terms['amortisation']);$lot=$this->register($terms);
        $command=['operation'=>'valuation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic category-bound quote','profile'=>$this->profile(),'quote'=>$this->quote('950.00')];$preview=$this->service->preview($this->supplier,$command,$this->actor);
        $this->posting->postDocument($this->supplier,'manual',null,[['account_code'=>'549','side'=>'debit','amount'=>'10.00','dimensions'=>$this->dims],['account_code'=>'649','side'=>'credit','amount'=>'10.00','dimensions'=>$this->dims]],['entry_date'=>'2025-12-30','posted'=>true]);
        try{$this->service->execute($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-new-turnover');self::fail('Changed category membership accepted a reviewed debt valuation.');}catch(ClosingException $e){self::assertSame('debt_preview_stale',$e->errorCode);}
    }

    public function testForeignHtmNativeFxCarryIsIncludedInTheNextPeriodBookBasis(): void
    {
        $lot=$this->foreignFx(true);
        $state=$this->service->detail($this->supplier,$lot,'2026-01-01');self::assertSame('23760.25',$state['principal_book_czk']);self::assertSame('23760.25',$state['book_czk']);self::assertSame('50.41',$state['amortised']);
    }

    /**
     * Částečný prodej 400 EUR z 1 000 EUR nominálu HTM diskontního dluhopisu (900 EUR po 24 Kč,
     * splatnost 1. 7. 2027). Jistina 937,53 EUR v knihách za 23 432,09 Kč (po uzávěrce 2025 kurzem 25)
     * se ke dni prodeje přepočte kurzem ČNB 24,50 na 22 969,49 Kč, kurzová ztráta 462,60 Kč jde na 545.
     * Vyřadí se 0,4 jistiny = 375,01 EUR za 9 187,75 Kč na 553. Zbytek 562,52 EUR rozpouští diskont
     * 37,48 EUR do splatnosti a uzávěrka 2026 ho přecení kurzem 25,50.
     */
    public function testForeignPartialSaleRevaluesAtSaleDateRateAndRemainderIsRevaluedAtBalanceSheetDate(): void
    {
        $terms=$this->discount('900.00');$terms['lot_reference']='SYN-EUR-HTM-2Y';$terms['maturity_on']='2027-07-01';$terms['clean_cost_czk']='21600.00';
        $p=$this->db->pdo();$p->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,'901','Synthetic capital','equity',1)")->execute([$this->supplier]);
        $entry=$this->posting->postDocument($this->supplier,'manual',null,[['account_code'=>'063','side'=>'debit','amount'=>'21600.00','currency_code'=>'EUR','amount_foreign'=>'900.00','fx_rate'=>'24.000000','dimensions'=>$this->dims],['account_code'=>'901','side'=>'credit','amount'=>'21600.00','dimensions'=>$this->dims]],['entry_date'=>'2025-07-01','posted'=>true]);
        $lot=$this->applyCommand(['operation'=>'register','terms'=>$terms,'entry_id'=>$entry,'line_id'=>$this->line($entry,'063')])['lot_id'];
        foreach(['2025-12-31'=>'25','2026-03-31'=>'24.5','2026-12-31'=>'25.5'] as $day=>$rate)$p->prepare("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES(?,'EUR',?) ON DUPLICATE KEY UPDATE rate=VALUES(rate)")->execute([$day,$rate]);
        self::assertSame('25.21',$this->service->preview($this->supplier,['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic EUR year-end accretion'],$this->actor)['plan']['amount']);
        $this->applyCommand(['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic EUR year-end accretion']);
        $this->runFx($this->periods[2025]);
        self::assertSame('23130.25',$this->service->detail($this->supplier,$lot,'2026-01-01')['book_czk']);
        self::assertSame('12.32',$this->service->preview($this->supplier,['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2026-03-31','evidence'=>'Synthetic EUR sale-date accretion'],$this->actor)['plan']['amount']);
        $this->applyCommand(['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2026-03-31','evidence'=>'Synthetic EUR sale-date accretion']);
        self::assertSame('23432.09',$this->service->detail($this->supplier,$lot,'2026-03-31')['principal_book_czk']);

        $sale=['operation'=>'sale','lot_id'=>$lot,'accounting_date'=>'2026-03-31','evidence'=>'Synthetic EUR partial sale','nominal_amount'=>'400.00']+$this->foreignProceeds('380.00','24.600000','2026-03-31');
        $preview=$this->service->preview($this->supplier,$sale,$this->actor);
        $sides=array_map(fn(array $l)=>[$l['account_code'],$l['side'],$l['amount'],$l['amount_foreign']??null],$preview['plan']['lines']);sort($sides);
        self::assertSame([['063','credit','462.60','0.00'],['063','credit','9187.75','375.01'],['545','debit','462.60',null],['553','debit','9187.75',null]],$sides);
        self::assertSame('380.00',$preview['plan']['amount']);
        self::assertSame('24.500000',$preview['plan']['rate']['rate']);
        self::assertSame(['principal'=>'400.00','interest'=>'0.00','principal_czk'=>'9187.75','interest_czk'=>'0.00','principal_native'=>'375.01','fx_principal_czk'=>'-462.60','fx_interest_czk'=>'0.00'],$preview['plan']['sold']);
        $this->applyCommand($sale);

        $state=$this->service->detail($this->supplier,$lot,'2026-03-31');
        self::assertNull($state['sold_on']);
        self::assertSame('600.00',$state['principal_balance']);
        self::assertSame('562.52',$state['principal_book_native']);
        self::assertSame('13781.74',$state['principal_book_czk']);
        self::assertSame('13781.74',$state['book_czk']);

        self::assertSame('22.60',$this->service->preview($this->supplier,['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2026-12-31','evidence'=>'Synthetic EUR remainder accretion'],$this->actor)['plan']['amount']);
        $this->applyCommand(['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2026-12-31','evidence'=>'Synthetic EUR remainder accretion']);
        self::assertSame('14358.04',$this->service->detail($this->supplier,$lot,'2026-12-31')['book_czk']);
        $this->runFx($this->periods[2026]);
        $state=$this->service->detail($this->supplier,$lot,'2026-12-31');
        self::assertSame('14920.56',$state['book_czk']);
        self::assertSame('585.12',$state['principal_book_native']);
        self::assertCount(2,$state['fx_carries']);
    }

    public function testForeignWholeSaleRevaluesAndDerecognisesAtSaleDateRate(): void
    {
        $lot=$this->foreignFx(true);
        $p=$this->db->pdo();foreach(['2026-03-31'=>'24.5','2026-12-31'=>'25.5'] as $day=>$rate)$p->prepare("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES(?,'EUR',?) ON DUPLICATE KEY UPDATE rate=VALUES(rate)")->execute([$day,$rate]);
        self::assertSame('24.66',$this->service->preview($this->supplier,['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2026-03-31','evidence'=>'Synthetic EUR sale-date accretion'],$this->actor)['plan']['amount']);
        $this->applyCommand(['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2026-03-31','evidence'=>'Synthetic EUR sale-date accretion']);
        $sale=['operation'=>'sale','lot_id'=>$lot,'accounting_date'=>'2026-03-31','evidence'=>'Synthetic EUR whole sale']+$this->foreignProceeds('980.00','24.600000','2026-03-31');
        $preview=$this->service->preview($this->supplier,$sale,$this->actor);
        $sides=array_map(fn(array $l)=>[$l['account_code'],$l['side'],$l['amount'],$l['amount_foreign']??null],$preview['plan']['lines']);sort($sides);
        self::assertSame([['063','credit','475.20','0.00'],['063','credit','23889.22','975.07'],['545','debit','475.20',null],['553','debit','23889.22',null]],$sides);
        self::assertSame(['principal'=>'1000.00','interest'=>'0.00','principal_czk'=>'23889.22','interest_czk'=>'0.00','principal_native'=>'975.07','fx_principal_czk'=>'-475.20','fx_interest_czk'=>'0.00'],$preview['plan']['sold']);
        $this->applyCommand($sale);
        $state=$this->service->detail($this->supplier,$lot,'2026-03-31');
        self::assertSame('2026-03-31',$state['sold_on']);
        self::assertSame('0.00',$state['book_czk']);
        self::assertSame('0.00',$state['principal_book_native']);
        self::assertSame('0.00',bcsub($this->turnover('063','debit'),$this->turnover('063','credit'),2));
        $this->runFx($this->periods[2026]);
        self::assertCount(1,$this->service->detail($this->supplier,$lot,'2026-12-31')['fx_carries']);
    }

    private function turnover(string $account,string $side): string
    {
        $q=$this->db->pdo()->prepare('SELECT COALESCE(SUM(l.amount),0) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id JOIN journal_entries e ON e.id=l.entry_id AND e.supplier_id=l.supplier_id WHERE l.supplier_id=? AND a.account_code=? AND l.side=? AND e.posted_at IS NOT NULL');
        $q->execute([$this->supplier,$account,$side]);
        return number_format((float)$q->fetchColumn(),2,'.','');
    }

    private function runFx(int $period): void
    {
        $p=$this->db->pdo();$fx=$this->container->get(\MyInvoice\Service\Accounting\Closing\Fx504Service::class);
        $preview=$fx->preview($this->supplier,$period);self::assertTrue($preview['complete'],json_encode($preview['securities']['findings']));
        $p->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$period]);
        $version=(int)$p->query('SELECT row_version FROM accounting_periods WHERE id='.$period)->fetchColumn();
        $fx->run($this->supplier,$period,['snapshot_hash'=>$preview['snapshot_hash']],$version);
        (new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504FxCarryRegistrar($this->db))->synchronize($this->supplier,$period);
    }

    private function foreignProceeds(string $foreign,string $rate,string $date): array
    {
        $p=$this->db->pdo();$czk=\MyInvoice\Service\Accounting\JournalLineAmount::decimal(bcmul($foreign,$rate,16));
        $p->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,source) VALUES (?,'Synthetic EUR proceeds',?,'1000000005','0100','EUR',?,'gpc')")->execute([$this->supplier,hash('sha256',random_bytes(20)),$date]);$statement=(int)$p->lastInsertId();
        $p->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,counterparty_name) VALUES (?,?,?,'EUR','Synthetic buyer')")->execute([$statement,$date,$foreign]);$bank=(int)$p->lastInsertId();
        $entry=$this->posting->postDocument($this->supplier,'bank',$bank,[['account_code'=>'221','side'=>'debit','amount'=>$czk,'currency_code'=>'EUR','amount_foreign'=>$foreign,'fx_rate'=>$rate,'dimensions'=>$this->dims],['account_code'=>'653','side'=>'credit','amount'=>$czk,'currency_code'=>'EUR','amount_foreign'=>$foreign,'fx_rate'=>$rate,'dimensions'=>$this->dims]],['entry_date'=>$date,'posted'=>true,'posted_by'=>$this->actor]);
        return ['entry_id'=>$entry,'line_id'=>$this->line($entry,'653')];
    }

    public function testFxOwnerWorkflowRegistersNativeCarryBoundary(): void
    {
        $lot=$this->foreignFx(false);$q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM accounting_securities_debt_fx_carries WHERE supplier_id=? AND lot_id=?');$q->execute([$this->supplier,$lot]);self::assertSame(1,(int)$q->fetchColumn(),'The native FX owner did not register its debt carry.');
    }

    public function testConsumedNativeFxCannotBeRerunThroughItsOwnerBoundary(): void
    {
        $lot=$this->foreignFx(true);$this->db->pdo()->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES('2026-06-30','EUR',26) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");
        $this->applyCommand(['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2026-06-30','evidence'=>'Synthetic subsequent EUR amortisation']);
        $fx=$this->container->get(\MyInvoice\Service\Accounting\Closing\Fx504Service::class);$preview=$fx->preview($this->supplier,$this->periods[2025]);
        try{$fx->run($this->supplier,$this->periods[2025],['snapshot_hash'=>$preview['snapshot_hash']],2);self::fail('Consumed native FX was rewritten.');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('debt_fx_carry_consumed',$e->errorCode);}
    }

    public function testUnconsumedNativeFxCanBeRefreshedAndRevertedThroughItsOwner(): void
    {
        $lot=$this->foreignFx(true);$fx=$this->container->get(\MyInvoice\Service\Accounting\Closing\Fx504Service::class);$preview=$fx->preview($this->supplier,$this->periods[2025]);
        $fx->run($this->supplier,$this->periods[2025],['snapshot_hash'=>$preview['snapshot_hash']],2);$state=$this->service->detail($this->supplier,$lot,'2026-01-01');self::assertCount(1,$state['fx_carries']);self::assertSame('23760.25',$state['book_czk']);
        $fx->revert($this->supplier,$this->periods[2025],3);$state=$this->service->detail($this->supplier,$lot,'2026-01-01');self::assertSame([],$state['fx_carries']);self::assertSame('22860.25',$state['book_czk']);
    }

    public function testPaddedContractReferenceStillProducesCanonicalNativeFxOrigin(): void
    {
        $lot=$this->foreignAcquisition('  Synthetic padded contract  ');$preview=$this->container->get(\MyInvoice\Service\Accounting\Closing\Fx504Service::class)->preview($this->supplier,$this->periods[2025]);self::assertTrue($preview['complete'],json_encode($preview['securities']['findings']));$state=$this->service->detail($this->supplier,$lot,'2025-12-31');self::assertSame('Synthetic padded contract',$state['facts']['terms']['contract_reference']);
    }

    public function testRehashedFxPrincipalInterestAllocationCannotForgeNativeContractBasis(): void
    {
        $lot=$this->foreignFx(true);$p=$this->db->pdo();$q=$p->prepare("SELECT receipt_json FROM accounting_securities_debt_fx_carries WHERE supplier_id=? AND lot_id=? AND slot='saldo'");$q->execute([$this->supplier,$lot]);$receipt=SecuritiesDebt504Repository::decode($q->fetchColumn());$line=array_key_first($receipt['lines']);
        $receipt['lines'][$line]['principal_czk']=bcsub($receipt['lines'][$line]['principal_czk'],'1.00',2);$receipt['lines'][$line]['interest_czk']='1.00';$receipt['principal_czk']=bcsub($receipt['principal_czk'],'1.00',2);$receipt['interest_czk']='1.00';
        $p->prepare("UPDATE accounting_securities_debt_fx_carries SET receipt_json=?,receipt_hash=? WHERE supplier_id=? AND lot_id=? AND slot='saldo'")->execute([SecuritiesDebt504Repository::json($receipt),SecuritiesDebt504Repository::hash($receipt),$this->supplier,$lot]);
        try{$this->service->detail($this->supplier,$lot,'2026-01-01');self::fail('Rehashed allocation bypassed the native contract basis.');}catch(ClosingException $e){self::assertSame('debt_fx_carry_corrupt',$e->errorCode);}
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function testActualForeignCloseOpenPreservesOrReversesNativeCarryAndRedeemsExactBookBasis(bool $reverse): void
    {
        $lot=$this->foreignAcquisition();$p=$this->db->pdo();$period=$this->periods[2025];$c=$this->container;$closing=$c->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);$fx=$c->get(\MyInvoice\Service\Accounting\Closing\Fx504Service::class);
        $p->prepare('INSERT INTO accounting_supplier_settings(supplier_id,fx_reversal_at_open) VALUES(?,?) ON DUPLICATE KEY UPDATE fx_reversal_at_open=VALUES(fx_reversal_at_open)')->execute([$this->supplier,$reverse?1:0]);
        $p->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$period]);foreach(['901','911','931','932','933','961','962'] as $code)$p->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,?,?,'equity',1)")->execute([$this->supplier,$code,'Synthetic '.$code]);
        $version=fn()=>(int)$p->query('SELECT row_version FROM accounting_periods WHERE id='.$period)->fetchColumn();$inventory=[];foreach($closing->inventoryPreview($this->supplier,$period)['rows'] as $row)$inventory[(int)$row['account_id']]=['counted_balance'=>(float)$row['book_balance'],'resolution'=>'resolved','note'=>null];$closing->saveInventory($this->supplier,$period,$version(),['complete'=>true],$inventory);$closing->runPrecheck($this->supplier,$period,$version());foreach(['depreciation','estimates','deferrals','provisions','income_tax'] as $step)$closing->confirmStep($this->supplier,$period,$step,'skipped',null,$version());
        $preview=$fx->preview($this->supplier,$period);self::assertTrue($preview['complete'],json_encode($preview['securities']['findings']));$fx->run($this->supplier,$period,['snapshot_hash'=>$preview['snapshot_hash']],$version());(new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504FxCarryRegistrar($this->db))->synchronize($this->supplier,$period);
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($c,$this->supplier,$period,$this->actor);$closing->closeBooks($this->supplier,$period,$version());$closing->openNext($this->supplier,$period,$version());(new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504FxCarryRegistrar($this->db))->synchronize($this->supplier,$period);
        $state=$this->service->detail($this->supplier,$lot,'2026-01-01');self::assertSame($reverse?'22860.25':'23760.25',$state['book_czk']);self::assertCount($reverse?2:1,$state['fx_carries']);
        $p->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES('2026-06-30','EUR',26) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");$this->applyCommand(['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2026-06-30','evidence'=>'Synthetic next-year EUR maturity accretion']);$state=$this->service->detail($this->supplier,$lot,'2026-06-30');self::assertSame('100.00',$state['amortised']);self::assertSame($reverse?'24149.59':'25049.59',$state['book_czk']);
        $bank=$this->foreignBank($state['book_czk']);$this->applyCommand(['operation'=>'principal_redemption','nominal_amount'=>'1000.00','lot_id'=>$lot,'accounting_date'=>'2026-07-01','evidence'=>'Synthetic actual EUR maturity receipt']+$bank);$state=$this->service->detail($this->supplier,$lot,'2026-07-01');self::assertSame('0.00',$state['book_czk']);self::assertSame('0.00',$state['principal_balance']);self::assertSame($preview['snapshot_hash'],$fx->preview($this->supplier,$period)['snapshot_hash']);
    }
    private function foreignBank(string $book): array
    {
        $p=$this->db->pdo();$p->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,source) VALUES (?,'Synthetic EUR receipt',?,'1000000005','0100','EUR','2026-07-01','gpc')")->execute([$this->supplier,hash('sha256',random_bytes(20))]);$statement=(int)$p->lastInsertId();$p->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,counterparty_name) VALUES (?,'2026-07-01','1000.00','EUR','Synthetic issuer')")->execute([$statement]);$bank=(int)$p->lastInsertId();
        $entry=$this->posting->postDocument($this->supplier,'bank',$bank,[['account_code'=>'221','side'=>'debit','amount'=>'26000.00','currency_code'=>'EUR','amount_foreign'=>'1000.00','fx_rate'=>'26.000000','dimensions'=>$this->dims],['account_code'=>'063','side'=>'credit','amount'=>$book,'currency_code'=>'EUR','amount_foreign'=>'1000.00','fx_rate'=>bcdiv($book,'1000',6),'dimensions'=>$this->dims],['account_code'=>'645','side'=>'credit','amount'=>bcsub('26000.00',$book,2),'dimensions'=>$this->dims]],['entry_date'=>'2026-07-01','posted'=>true,'posted_by'=>$this->actor]);return['entry_id'=>$entry,'line_id'=>$this->line($entry,'063')];
    }

    private function foreignFx(bool $register): int
    {
        $lot=$this->foreignAcquisition();$p=$this->db->pdo();
        $fx=$this->container->get(\MyInvoice\Service\Accounting\Closing\Fx504Service::class);$preview=$fx->preview($this->supplier,$this->periods[2025]);self::assertTrue($preview['complete'],json_encode($preview['securities']['findings']));
        $p->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$this->periods[2025]]);$fx->run($this->supplier,$this->periods[2025],['snapshot_hash'=>$preview['snapshot_hash']],1);
        if($register)(new \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504FxCarryRegistrar($this->db))->synchronize($this->supplier,$this->periods[2025]);
        return $lot;
    }

    private function foreignAcquisition(?string $reference=null): int
    {
        $terms=$this->discount('900.00');$terms['lot_reference']='SYN-EUR-HTM';$terms['contract_reference']=$reference??'Synthetic private-eur-contract@synthetic.invalid';$terms['clean_cost_czk']='21600.00';
        $p=$this->db->pdo();$p->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,'901','Synthetic capital','equity',1)")->execute([$this->supplier]);
        $entry=$this->posting->postDocument($this->supplier,'manual',null,[['account_code'=>'063','side'=>'debit','amount'=>'21600.00','currency_code'=>'EUR','amount_foreign'=>'900.00','fx_rate'=>'24.000000','dimensions'=>$this->dims],['account_code'=>'901','side'=>'credit','amount'=>'21600.00','dimensions'=>$this->dims]],['entry_date'=>'2025-07-01','posted'=>true]);
        $lot=$this->applyCommand(['operation'=>'register','terms'=>$terms,'entry_id'=>$entry,'line_id'=>$this->line($entry,'063')])['lot_id'];
        $p->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES('2025-12-31','EUR',25) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");
        $this->applyCommand(['operation'=>'amortisation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic EUR cumulative amortisation']);
        return $lot;
    }

    public function testUnchangedYearEndQuoteIsRetainedWithoutInventingJournalMovement(): void
    {
        $lot=$this->register($this->coupon());$this->applyCommand(['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic earned coupon before unchanged quote']);
        $command=['operation'=>'valuation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic unchanged regulated quote','profile'=>$this->profile(),'quote'=>$this->quote('1000.00')];
        $before=$this->countRows();$preview=$this->service->preview($this->supplier,$command,$this->actor);self::assertSame([],$preview['plan']['lines']);
        $done=$this->service->execute($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-unchanged-quote');
        self::assertSame($done,$this->service->execute($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-unchanged-quote'));
        self::assertSame($before['journal_entries'],$this->countRows()['journal_entries']);$state=$this->service->detail($this->supplier,$lot,'2025-12-31');self::assertSame('1000.00',$state['principal_book_czk']);self::assertCount(2,$state['events']);
        $fx=$this->container->get(\MyInvoice\Service\Accounting\Closing\Fx504Service::class);$evidence=['valuations'=>[],'profile'=>$this->profile()];$closing=$fx->preview($this->supplier,$this->periods[2025],securitiesEvidence:$evidence);self::assertTrue($closing['complete'],json_encode($closing['securities']['findings']));
    }

    public function testForeignFairValuedDiscountHasNoPrincipalInMonetaryFxCarry(): void
    {
        $terms=$this->discount('900.00');$terms['classification']='trading';$terms['lot_reference']='SYN-EUR-TRADING-DISCOUNT';$terms['clean_cost_czk']='21600.00';unset($terms['amortisation']);$p=$this->db->pdo();
        $p->prepare("INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES (?,'901','Synthetic capital','equity',1)")->execute([$this->supplier]);
        $entry=$this->posting->postDocument($this->supplier,'manual',null,[['account_code'=>'253','side'=>'debit','amount'=>'21600.00','currency_code'=>'EUR','amount_foreign'=>'900.00','fx_rate'=>'24.000000','dimensions'=>$this->dims],['account_code'=>'901','side'=>'credit','amount'=>'21600.00','dimensions'=>$this->dims]],['entry_date'=>'2025-07-01','posted'=>true]);
        $lot=$this->applyCommand(['operation'=>'register','terms'=>$terms,'entry_id'=>$entry,'line_id'=>$this->line($entry,'253')])['lot_id'];$p->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES('2025-12-31','EUR',25) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");
        $quote=$this->quote('950.00');$quote['currency']='EUR';$this->applyCommand(['operation'=>'valuation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic foreign fair value','profile'=>$this->profile(),'quote'=>$quote]);
        $fx=$this->container->get(\MyInvoice\Service\Accounting\Closing\Fx504Service::class);$period=$this->periods[2025];$evidence=['valuations'=>[],'profile'=>$this->profile()];$preview=$fx->preview($this->supplier,$period,securitiesEvidence:$evidence);self::assertTrue($preview['complete'],json_encode($preview['securities']['findings']));self::assertSame([],$preview['securities']['monetary_members']);
        $p->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$period]);$fx->run($this->supplier,$period,['snapshot_hash'=>$preview['snapshot_hash'],'securities_evidence'=>$evidence],1);
        $state=$this->service->detail($this->supplier,$lot,'2025-12-31');self::assertSame('23750.00',$state['principal_book_czk']);self::assertSame([],$state['fx_carries']);self::assertSame($preview['snapshot_hash'],$fx->preview($this->supplier,$period,securitiesEvidence:$evidence)['snapshot_hash']);
    }

    public function testRehashedNonzeroValuationCannotDiscardItsNativeLedger(): void
    {
        $lot=$this->register($this->coupon());$event=$this->applyCommand(['operation'=>'valuation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic physical valuation','profile'=>$this->profile(),'quote'=>$this->quote('950.00')]);
        $q=$this->db->pdo()->prepare('SELECT receipt_json FROM accounting_securities_debt_events WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$event['event_id']]);$receipt=SecuritiesDebt504Repository::decode($q->fetchColumn());unset($receipt['ledger']);$receipt['lines']=[];
        $this->db->pdo()->prepare('UPDATE accounting_securities_debt_events SET journal_entry_id=NULL,receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([SecuritiesDebt504Repository::json($receipt),SecuritiesDebt504Repository::hash($receipt),$this->supplier,$event['event_id']]);
        $this->expectException(ClosingException::class);$this->service->detail($this->supplier,$lot,'2025-12-31');
    }

    private function profile(): array
    {
        return ['scope'=>'full','category'=>'micro','micro_exception'=>'investment_fund','registration_reference'=>'Synthetic licence','registration_from'=>'2025-01-01','reference'=>'Synthetic category review','as_of'=>'2025-12-31','scope_reference'=>'Synthetic full books','inception_reference'=>'Synthetic incorporation','first_period_start'=>'2025-01-01','category_facts'=>[['period_id'=>$this->periods[2025],'starts_on'=>'2025-01-01','ends_on'=>'2025-12-31','assets_net'=>'0.00','net_turnover'=>'0.00','average_employees'=>'0','statement_reference'=>'Synthetic statement','employees_reference'=>'Synthetic payroll']]];
    }
    private function quote(string $amount): array
    {
        return ['quote_type'=>'clean','currency'=>'CZK','as_of'=>'2025-12-31','reference'=>'Synthetic custody quote','market'=>'Synthetic regulated market','market_reference'=>'Synthetic official closing quote','market_type'=>'regulated','market_calendar_reference'=>'Synthetic market calendar','market_calendar_as_of'=>'2025-12-31','last_trading_date'=>'2025-12-31','valuation_date'=>'2025-12-31','clean_value'=>$amount];
    }

    private function coupon(): array
    {
        $period=fn($from,$to)=>['from'=>$from,'to_exclusive'=>$to,'annual_rate_fraction'=>'0.365','day_count'=>'ACT/365F','rounding'=>'half_away_from_zero_2_cumulative','terms_reference'=>'Synthetic coupon contract'];
        return ['lot_reference'=>'SYN-COUPON','instrument_identity'=>'SYN-ISSUER-COUPON','contract_reference'=>'Synthetic signed contract','classification_reference'=>'Synthetic trading policy','debt_type'=>'coupon','classification'=>'trading','acquired_on'=>'2025-12-15','maturity_on'=>'2026-02-01','nominal'=>'1000.00','clean_cost'=>'1000.00','acquired_interest'=>'14.00','clean_cost_czk'=>'1000.00','acquired_interest_czk'=>'14.00','coupon_periods'=>[$period('2025-12-01','2026-01-01'),$period('2026-01-01','2026-02-01')]];
    }
    private function discount(string $cost): array
    {
        return ['lot_reference'=>'SYN-HTM-'.$cost,'instrument_identity'=>'SYN-ISSUER-'.$cost,'contract_reference'=>'Synthetic signed discount contract','classification_reference'=>'Synthetic held-to-maturity policy','debt_type'=>'discount','classification'=>'held_to_maturity','acquired_on'=>'2025-07-01','maturity_on'=>'2026-07-01','nominal'=>'1000.00','clean_cost'=>$cost,'acquired_interest'=>'0.00','clean_cost_czk'=>$cost,'acquired_interest_czk'=>'0.00','coupon_periods'=>[],'amortisation'=>['method'=>'actual_days_linear','policy_reference'=>'Synthetic confirmed amortisation policy','rounding'=>'half_away_from_zero_2_cumulative']];
    }
    private function acquisition(array $terms): array
    {
        $account=$terms['classification']==='held_to_maturity'?'063':'253';$amount=bcadd($terms['clean_cost_czk'],$terms['acquired_interest_czk'],2);
        $entry=$this->posting->postDocument($this->supplier,'manual',null,[['account_code'=>$account,'side'=>'debit','amount'=>$amount,'dimensions'=>$this->dims],['account_code'=>'221','side'=>'credit','amount'=>$amount,'dimensions'=>$this->dims]],['entry_date'=>$terms['acquired_on'],'posted'=>true,'posted_by'=>$this->actor]);
        return ['entry_id'=>$entry,'line_id'=>$this->line($entry,$account)];
    }
    private function register(array $terms): int{return $this->applyCommand(['operation'=>'register','terms'=>$terms]+$this->acquisition($terms))['lot_id'];}
    private function applyCommand(array $command): array
    {
        $preview=$this->service->preview($this->supplier,$command,$this->actor);
        return $this->service->execute($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-'.bin2hex(random_bytes(12)));
    }
    private function bank(string $amount,string $date,string $account,?string $bookAmount=null): array
    {
        $p=$this->db->pdo();$p->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,source) VALUES (?,'Synthetic debt receipt',?,'1000000005','0100','CZK',?,'gpc')")->execute([$this->supplier,hash('sha256',random_bytes(20)),$date]);$statement=(int)$p->lastInsertId();
        $p->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,counterparty_name) VALUES (?,?,?,'CZK','Synthetic issuer')")->execute([$statement,$date,$amount]);$bank=(int)$p->lastInsertId();
        $bookAmount??=$amount;$lines=[['account_code'=>'221','side'=>'debit','amount'=>$amount,'dimensions'=>$this->dims],['account_code'=>$account,'side'=>'credit','amount'=>$bookAmount,'dimensions'=>$this->dims]];
        if($bookAmount!==$amount){$positive=bccomp($amount,$bookAmount,2)>0;$lines[]=['account_code'=>$positive?'644':'549','side'=>$positive?'credit':'debit','amount'=>$positive?bcsub($amount,$bookAmount,2):bcsub($bookAmount,$amount,2),'dimensions'=>$this->dims];}
        $entry=$this->posting->postDocument($this->supplier,'bank',$bank,$lines,['entry_date'=>$date,'posted'=>true,'posted_by'=>$this->actor]);
        return ['entry_id'=>$entry,'line_id'=>$this->line($entry,$account)];
    }
    private function line(int $entry,string $account): int
    {
        $q=$this->db->pdo()->prepare('SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code=? ORDER BY l.line_no LIMIT 1');$q->execute([$this->supplier,$entry,$account]);return(int)$q->fetchColumn();
    }
    private function countRows(): array
    {
        $out=[];foreach(['journal_entries','accounting_securities_debt_lots','accounting_securities_debt_events','accounting_securities_debt_claims','accounting_securities_debt_commands'] as $table){$q=$this->db->pdo()->prepare('SELECT COUNT(*) FROM '.$table.' WHERE supplier_id=?');$q->execute([$this->supplier]);$out[$table]=(int)$q->fetchColumn();}return $out;
    }
}