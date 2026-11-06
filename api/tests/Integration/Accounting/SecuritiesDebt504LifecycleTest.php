<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository,SecuritiesDebt504Repository};
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder,PostingService};
use MyInvoice\Service\Accounting\Closing\{ClosingException,SecuritiesDebt504UseGuard,SecuritiesDebt504Workflow};
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Úplný životní cyklus syntetického dluhopisu: jmenovitá hodnota 1 000 000 Kč, pořizovací
 * cena 980 000 Kč, kupón 4 % ročně vyplácený 1. 7. (ACT/ACT ICMA), splatnost za tři roky.
 */
final class SecuritiesDebt504LifecycleTest extends TestCase
{
    private Connection $db;
    private PostingService $posting;
    private SecuritiesDebt504Workflow $service;
    private int $supplier;
    private int $actor;
    private array $dims;

    protected function setUp(): void
    {
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));
        $c=Bootstrap::buildContainer();
        $this->db=$c->get(Connection::class);
        $p=$this->db->pdo();
        self::assertSame(getenv('MYINVOICE_DB_NAME'),$p->query('SELECT DATABASE()')->fetchColumn());
        $p->beginTransaction();
        $p->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) SELECT 'Synthetic bond lifecycle','Test','Test','10000','bond@example.invalid',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->supplier=(int)$p->lastInsertId();
        $this->actor=(int)$p->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        foreach([2025,2026,2027,2028] as $year)$c->get(AccountingPeriodRepository::class)->create($this->supplier,$year,"$year-01-01","$year-12-31");
        $p->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2025-01-01','cz_504')")->execute([$this->supplier]);
        $catalog=(new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier,'2025-01-01');
        $p->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,'portfolio','Synthetic portfolio')")->execute([$catalog['activity_kind']['id'],$this->supplier]);
        $this->dims=[
            $catalog['activity']['id']=>$catalog['activity']['values']['main'],
            $catalog['activity_kind']['id']=>(int)$p->lastInsertId(),
            $catalog['tax_regime']['id']=>$catalog['tax_regime']['values']['subject'],
        ];
        foreach(['644'=>'revenue','653'=>'revenue','544'=>'expense','553'=>'expense'] as $code=>$type)
            $p->prepare('INSERT IGNORE INTO chart_of_accounts(supplier_id,account_code,name,account_type,is_active) VALUES(?,?,?,?,1)')->execute([$this->supplier,$code,'Synthetic '.$code,$type]);
        $this->posting=$c->get(PostingService::class);
        $this->service=$c->get(SecuritiesDebt504Workflow::class);
    }

    protected function tearDown(): void
    {
        if(isset($this->db)&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();
    }

    public function testHeldToMaturityCouponBondAccruesExactCouponsAndAmortisesDiscountToNominal(): void
    {
        $lot=$this->register('held_to_maturity');

        $accrual=$this->service->preview($this->supplier,$this->op('accrual',$lot,'2025-12-31'),$this->actor);
        self::assertSame('20164.38',$accrual['plan']['amount']);
        self::assertSame([['063','debit','20164.38'],['644','credit','20164.38']],$this->sides($accrual['plan']['lines']));
        $this->apply($this->op('accrual',$lot,'2025-12-31'));
        $amortisation=$this->service->preview($this->supplier,$this->op('amortisation',$lot,'2025-12-31'),$this->actor);
        self::assertSame('3357.66',$amortisation['plan']['amount']);
        self::assertSame([['063','debit','3357.66'],['644','credit','3357.66']],$this->sides($amortisation['plan']['lines']));
        $this->apply($this->op('amortisation',$lot,'2025-12-31'));
        $state=$this->service->detail($this->supplier,$lot,'2025-12-31');
        self::assertSame('1003522.04',$state['book_czk']);
        self::assertSame('20164.38',$state['unpaid_interest']);
        self::assertSame([],$this->closingFindings('2025-12-31'));

        self::assertSame('19835.62',$this->apply($this->op('accrual',$lot,'2026-06-30'))['amount']);
        $this->apply($this->op('coupon_receipt',$lot,'2026-07-01')+$this->bank('40000.00','2026-07-01','063'));
        self::assertSame('20164.38',$this->apply($this->op('accrual',$lot,'2026-12-31'))['amount']);
        self::assertSame('6660.59',$this->apply($this->op('amortisation',$lot,'2026-12-31'))['amount']);
        self::assertSame('19835.62',$this->apply($this->op('accrual',$lot,'2027-06-30'))['amount']);
        $this->apply($this->op('coupon_receipt',$lot,'2027-07-01')+$this->bank('40000.00','2027-07-01','063'));
        self::assertSame('20109.29',$this->apply($this->op('accrual',$lot,'2027-12-31'))['amount']);
        self::assertSame('6660.58',$this->apply($this->op('amortisation',$lot,'2027-12-31'))['amount']);
        self::assertSame('19890.71',$this->apply($this->op('accrual',$lot,'2028-06-30'))['amount']);
        self::assertSame('3321.17',$this->apply($this->op('amortisation',$lot,'2028-06-30'))['amount']);

        $state=$this->service->detail($this->supplier,$lot,'2028-06-30');
        self::assertSame('120000.00',$state['earned']);
        self::assertSame('20000.00',$state['amortised']);
        self::assertSame('1000000.00',$state['principal_book_czk']);
        self::assertSame('40000.00',$state['unpaid_interest']);

        $this->apply($this->op('coupon_receipt',$lot,'2028-07-01')+$this->bank('40000.00','2028-07-01','063'));
        $this->apply($this->op('principal_redemption',$lot,'2028-07-01')+['nominal_amount'=>'1000000.00']+$this->bank('1000000.00','2028-07-01','063'));
        $state=$this->service->detail($this->supplier,$lot,'2028-07-01');
        self::assertSame('0.00',$state['book_czk']);
        self::assertSame('0.00',$state['principal_balance']);
        self::assertSame('0.00',$state['unpaid_interest']);
        self::assertSame('140000.00',$this->turnover('644','credit'));
        self::assertSame('0.00',$this->balance('063'));
    }

    public function testTradingBondSaleDerecognisesBookValueWithAccruedInterestAndCanBeReversed(): void
    {
        $lot=$this->register('trading');
        $this->apply($this->op('accrual',$lot,'2025-12-31'));
        $proceeds=$this->bank('1015027.40','2026-03-31','653');

        try{$this->service->preview($this->supplier,$this->op('sale',$lot,'2026-03-31')+$proceeds,$this->actor);self::fail('Sale without interest earned to the sale date was accepted.');}
        catch(ClosingException $e){self::assertSame('debt_sale_accrual_required',$e->errorCode);}

        self::assertSame('9863.02',$this->apply($this->op('accrual',$lot,'2026-03-31'))['amount']);
        $preview=$this->service->preview($this->supplier,$this->op('sale',$lot,'2026-03-31')+$proceeds,$this->actor);
        self::assertSame([['253','credit','1010027.40'],['553','debit','1010027.40']],$this->sides($preview['plan']['lines']));
        self::assertSame('1015027.40',$preview['plan']['amount']);
        self::assertSame(['principal'=>'1000000.00','interest'=>'30027.40','principal_czk'=>'980000.00','interest_czk'=>'30027.40'],$preview['plan']['sold']);
        $sale=$this->apply($this->op('sale',$lot,'2026-03-31')+$proceeds);

        $state=$this->service->detail($this->supplier,$lot,'2026-03-31');
        self::assertSame('0.00',$state['book_czk']);
        self::assertSame('0.00',$state['principal_balance']);
        self::assertSame('0.00',$state['unpaid_interest']);
        self::assertSame('2026-03-31',$state['sold_on']);
        self::assertSame('5000.00',bcsub($this->turnover('653','credit'),$this->turnover('553','debit'),2));
        self::assertSame([],$this->closingFindings('2026-12-31'));
        try{$this->service->preview($this->supplier,$this->op('accrual',$lot,'2026-12-31'),$this->actor);self::fail('A sold bond accrued further interest.');}
        catch(ClosingException $e){self::assertSame('debt_sold',$e->errorCode);}
        try{(new SecuritiesDebt504UseGuard($this->db))->assertEntriesMutable($this->supplier,[$proceeds['entry_id']]);self::fail('Bound sale proceeds were mutable.');}
        catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('debt_source_immutable',$e->errorCode);}

        $this->apply($this->op('reverse',$lot,'2026-03-31')+['event_id'=>$sale['event_id']]);
        $state=$this->service->detail($this->supplier,$lot,'2026-03-31');
        self::assertNull($state['sold_on']);
        self::assertSame('1010027.40',$state['book_czk']);
        self::assertSame('0.00',bcsub($this->turnover('553','debit'),$this->turnover('553','credit'),2));
        self::assertContains($proceeds['line_id'],array_map(fn($row)=>(int)$row['line_id'],$this->service->sources($this->supplier,'2026-03-31','proceeds')));
    }

    /**
     * Částečný prodej 400 000 Kč nominálu z 1 000 000 Kč k 31. 3. 2026 (ČÚS 406 bod 3.2.1):
     * jistina 985 000 Kč (980 000 + rozpuštěný diskont 5 000) se vyřadí poměrem 0,4 = 394 000 Kč,
     * prodaný alikvotní úrok je úrok běžného kupónového období z prodaného nominálu
     * 30 027,40 - 18 016,44 = 12 010,96 Kč. Zbytek nabíhá úrok i diskont jen ze 600 000 Kč
     * a doprodej zbytku vyřadí jen to, co po prvním prodeji zůstalo.
     */
    public function testHeldToMaturityPartialSaleDerecognisesProportionAndRemainderContinuesWithoutDoubleRelease(): void
    {
        $lot=$this->register('held_to_maturity');
        $this->apply($this->op('accrual',$lot,'2025-12-31'));
        $this->apply($this->op('amortisation',$lot,'2025-12-31'));
        self::assertSame('9863.02',$this->apply($this->op('accrual',$lot,'2026-03-31'))['amount']);
        self::assertSame('1642.34',$this->apply($this->op('amortisation',$lot,'2026-03-31'))['amount']);
        $proceeds=$this->bank('406500.00','2026-03-31','653');
        $sale=$this->op('sale',$lot,'2026-03-31')+$proceeds+['nominal_amount'=>'400000.00'];

        $preview=$this->service->preview($this->supplier,$sale,$this->actor);
        self::assertSame([['063','credit','406010.96'],['553','debit','406010.96']],$this->sides($preview['plan']['lines']));
        self::assertSame('406500.00',$preview['plan']['amount']);
        self::assertSame(['principal'=>'400000.00','interest'=>'12010.96','principal_czk'=>'394000.00','interest_czk'=>'12010.96',
            'principal_native'=>'394000.00','fx_principal_czk'=>'0.00','fx_interest_czk'=>'0.00'],$preview['plan']['sold']);
        $first=$this->apply($sale);

        $state=$this->service->detail($this->supplier,$lot,'2026-03-31');
        self::assertNull($state['sold_on']);
        self::assertSame('600000.00',$state['principal_balance']);
        self::assertSame('591000.00',$state['principal_book_czk']);
        self::assertSame('18016.44',$state['unpaid_interest']);
        self::assertSame('609016.44',$state['book_czk']);
        self::assertSame([],$this->closingFindings('2026-03-31'));
        try{$this->service->preview($this->supplier,$this->op('amortisation',$lot,'2026-03-31'),$this->actor);self::fail('The sold part was amortised again.');}
        catch(ClosingException $e){self::assertSame('debt_no_change',$e->errorCode);}

        $this->apply($this->op('reverse',$lot,'2026-03-31')+['event_id'=>$first['event_id']]);
        $state=$this->service->detail($this->supplier,$lot,'2026-03-31');
        self::assertSame('1000000.00',$state['principal_balance']);
        self::assertSame('1015027.40',$state['book_czk']);
        $this->apply($sale);

        self::assertSame('5983.56',$this->apply($this->op('accrual',$lot,'2026-06-30'))['amount']);
        $this->apply($this->op('coupon_receipt',$lot,'2026-07-01')+$this->bank('24000.00','2026-07-01','063'));
        self::assertSame('0.00',$this->service->detail($this->supplier,$lot,'2026-07-01')['unpaid_interest']);
        self::assertSame('3010.95',$this->apply($this->op('amortisation',$lot,'2026-12-31'))['amount']);
        self::assertSame('12098.63',$this->apply($this->op('accrual',$lot,'2026-12-31'))['amount']);
        self::assertSame([],$this->closingFindings('2026-12-31'));

        $rest=$this->op('sale',$lot,'2026-12-31')+$this->bank('610000.00','2026-12-31','653');
        $preview=$this->service->preview($this->supplier,$rest,$this->actor);
        self::assertSame([['063','credit','606109.58'],['553','debit','606109.58']],$this->sides($preview['plan']['lines']));
        self::assertSame(['principal'=>'600000.00','interest'=>'12098.63','principal_czk'=>'594010.95','interest_czk'=>'12098.63'],$preview['plan']['sold']);
        $this->apply($rest);

        $state=$this->service->detail($this->supplier,$lot,'2026-12-31');
        self::assertSame('2026-12-31',$state['sold_on']);
        self::assertSame('0.00',$state['book_czk']);
        self::assertSame('0.00',$state['principal_balance']);
        self::assertSame('0.00',$state['unpaid_interest']);
        self::assertSame('0.00',$this->balance('063'));
        self::assertSame('1012120.54',bcsub($this->turnover('553','debit'),$this->turnover('553','credit'),2));
        self::assertSame('4379.46',bcsub(bcsub($this->turnover('653','credit'),$this->turnover('653','debit'),2),bcsub($this->turnover('553','debit'),$this->turnover('553','credit'),2),2));
        try{$this->service->preview($this->supplier,$this->op('accrual',$lot,'2027-03-31'),$this->actor);self::fail('A sold bond accrued further interest.');}
        catch(ClosingException $e){self::assertSame('debt_sold',$e->errorCode);}
    }

    public function testPartialSaleNominalMustNotExceedRemainingHolding(): void
    {
        $lot=$this->register('trading');
        $this->apply($this->op('accrual',$lot,'2026-03-31'));
        try{$this->service->preview($this->supplier,$this->op('sale',$lot,'2026-03-31')+$this->bank('1020000.00','2026-03-31','653')+['nominal_amount'=>'1000000.01'],$this->actor);self::fail('A sale above the remaining nominal was accepted.');}
        catch(ClosingException $e){self::assertSame('debt_sale_nominal_invalid',$e->errorCode);}
    }

    public function testBoundCouponReceiptCanBeUnboundOnlyOnItsOwnDate(): void
    {
        $lot=$this->register('held_to_maturity');
        $this->apply($this->op('accrual',$lot,'2026-06-30'));
        $bank=$this->bank('40000.00','2026-07-01','063');
        $receipt=$this->apply($this->op('coupon_receipt',$lot,'2026-07-01')+$bank);
        self::assertSame('0.00',$this->service->detail($this->supplier,$lot,'2026-07-01')['unpaid_interest']);

        try{$this->service->preview($this->supplier,$this->op('reverse',$lot,'2026-07-02')+['event_id'=>$receipt['event_id']],$this->actor);self::fail('A coupon receipt was unbound on another day.');}
        catch(ClosingException $e){self::assertSame('debt_reverse_date_invalid',$e->errorCode);}

        $this->apply($this->op('reverse',$lot,'2026-07-01')+['event_id'=>$receipt['event_id']]);
        $state=$this->service->detail($this->supplier,$lot,'2026-07-01');
        self::assertSame('40000.00',$state['unpaid_interest']);
        (new SecuritiesDebt504UseGuard($this->db))->assertEntriesMutable($this->supplier,[$bank['entry_id']]);
        $this->apply($this->op('coupon_receipt',$lot,'2026-07-01')+$bank);
        self::assertSame('0.00',$this->service->detail($this->supplier,$lot,'2026-07-01')['unpaid_interest']);
    }

    public function testRehashedSaleBreakdownCannotMoveInterestIntoPrincipal(): void
    {
        $lot=$this->register('trading');
        $this->apply($this->op('accrual',$lot,'2026-03-31'));
        $sale=$this->apply($this->op('sale',$lot,'2026-03-31')+$this->bank('1015027.40','2026-03-31','653'));
        $q=$this->db->pdo()->prepare('SELECT receipt_json FROM accounting_securities_debt_events WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplier,$sale['event_id']]);
        $receipt=SecuritiesDebt504Repository::decode($q->fetchColumn());
        $receipt['sold']['principal_czk']='990000.00';
        $receipt['sold']['interest_czk']='20027.40';
        $this->db->pdo()->prepare('UPDATE accounting_securities_debt_events SET receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([SecuritiesDebt504Repository::json($receipt),SecuritiesDebt504Repository::hash($receipt),$this->supplier,$sale['event_id']]);
        $this->expectException(ClosingException::class);
        $this->service->detail($this->supplier,$lot,'2026-03-31');
    }

    private function terms(string $classification): array
    {
        $period=fn(string $from,string $to)=>['from'=>$from,'to_exclusive'=>$to,'annual_rate_fraction'=>'0.04','day_count'=>'ACT/ACT-ICMA','frequency'=>1,'rounding'=>'half_away_from_zero_2_cumulative','terms_reference'=>'Synthetic bond prospectus'];
        $terms=['lot_reference'=>'SYN-BOND-'.$classification,'instrument_identity'=>'SYN-ISSUER-BOND','contract_reference'=>'Synthetic custody confirmation','classification_reference'=>'Synthetic classification decision',
            'debt_type'=>'coupon','classification'=>$classification,'acquired_on'=>'2025-07-01','maturity_on'=>'2028-07-01','nominal'=>'1000000.00','clean_cost'=>'980000.00','acquired_interest'=>'0.00','clean_cost_czk'=>'980000.00','acquired_interest_czk'=>'0.00',
            'coupon_periods'=>[$period('2025-07-01','2026-07-01'),$period('2026-07-01','2027-07-01'),$period('2027-07-01','2028-07-01')]];
        if($classification==='held_to_maturity')$terms['amortisation']=['method'=>'actual_days_linear','policy_reference'=>'Synthetic amortisation policy','rounding'=>'half_away_from_zero_2_cumulative'];
        return $terms;
    }

    private function register(string $classification): int
    {
        $terms=$this->terms($classification);
        $account=$classification==='held_to_maturity'?'063':'253';
        $entry=$this->posting->postDocument($this->supplier,'manual',null,[
            ['account_code'=>$account,'side'=>'debit','amount'=>'980000.00','dimensions'=>$this->dims],
            ['account_code'=>'221','side'=>'credit','amount'=>'980000.00','dimensions'=>$this->dims],
        ],['entry_date'=>'2025-07-01','posted'=>true,'posted_by'=>$this->actor]);
        return $this->apply(['operation'=>'register','terms'=>$terms,'entry_id'=>$entry,'line_id'=>$this->line($entry,$account)])['lot_id'];
    }

    private function op(string $operation,int $lot,string $date): array
    {
        return ['operation'=>$operation,'lot_id'=>$lot,'accounting_date'=>$date,'evidence'=>'Synthetic '.$operation.' '.$date];
    }

    /** Provede náhled a potvrdí ho; vrací výsledek doplněný o částku z náhledu. */
    private function apply(array $command): array
    {
        $preview=$this->service->preview($this->supplier,$command,$this->actor);
        $done=$this->service->execute($this->supplier,$command,$this->actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-'.bin2hex(random_bytes(12)));
        return $done+['amount'=>$preview['plan']['amount']??null];
    }

    private function bank(string $amount,string $date,string $account): array
    {
        $p=$this->db->pdo();
        $p->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,source) VALUES (?,'Synthetic bond receipt',?,'1000000005','0100','CZK',?,'gpc')")->execute([$this->supplier,hash('sha256',random_bytes(20)),$date]);
        $statement=(int)$p->lastInsertId();
        $p->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,counterparty_name) VALUES (?,?,?,'CZK','Synthetic issuer')")->execute([$statement,$date,$amount]);
        $bank=(int)$p->lastInsertId();
        $entry=$this->posting->postDocument($this->supplier,'bank',$bank,[
            ['account_code'=>'221','side'=>'debit','amount'=>$amount,'dimensions'=>$this->dims],
            ['account_code'=>$account,'side'=>'credit','amount'=>$amount,'dimensions'=>$this->dims],
        ],['entry_date'=>$date,'posted'=>true,'posted_by'=>$this->actor]);
        return ['entry_id'=>$entry,'line_id'=>$this->line($entry,$account)];
    }

    private function line(int $entry,string $account): int
    {
        $q=$this->db->pdo()->prepare('SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code=? ORDER BY l.line_no LIMIT 1');
        $q->execute([$this->supplier,$entry,$account]);
        return (int)$q->fetchColumn();
    }

    private function sides(array $lines): array
    {
        $out=array_map(fn(array $line)=>[$line['account_code'],$line['side'],$line['amount']],$lines);
        sort($out);
        return $out;
    }

    private function closingFindings(string $date): array
    {
        return (new SecuritiesDebt504Repository($this->db))->transaction($this->supplier,fn()=>(new SecuritiesDebt504Repository($this->db))->closingProof($this->supplier,$date)['findings']);
    }

    private function turnover(string $account,string $side): string
    {
        $q=$this->db->pdo()->prepare('SELECT COALESCE(SUM(l.amount),0) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id JOIN journal_entries e ON e.id=l.entry_id AND e.supplier_id=l.supplier_id WHERE l.supplier_id=? AND a.account_code=? AND l.side=? AND e.posted_at IS NOT NULL');
        $q->execute([$this->supplier,$account,$side]);
        return number_format((float)$q->fetchColumn(),2,'.','');
    }

    private function balance(string $account): string
    {
        return bcsub($this->turnover($account,'debit'),$this->turnover($account,'credit'),2);
    }
}
