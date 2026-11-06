<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{SecuritiesDebt504Repository as R,SecuritiesValuation504ReadRepository};
use MyInvoice\Service\Accounting\{AccountRoleResolver,JournalLineAmount,PostingService};
use MyInvoice\Service\Currency\FixedExchangeRateService;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class SecuritiesDebt504Workflow
{
    public function __construct(private readonly Connection $db,private readonly R $lots,private readonly PostingService $posting,private readonly FixedExchangeRateService $fixed) {}

    public function preview(int $supplier,array $command,int $actor): array
    {
        return $this->lots->transaction($supplier,function()use($supplier,$command,$actor){
            $command=$this->normalize($command);$plan=$this->plan($supplier,$command);
            return ['state_hash'=>$plan['state_hash'],'preview_hash'=>R::hash([$supplier,$actor,$command,$plan]),'plan'=>$plan];
        });
    }

    public function execute(int $supplier,array $command,int $actor,string $stateHash,string $previewHash,string $key): array
    {
        SecuritiesDebt504Terms::need($actor>0 && preg_match('/^[a-f0-9]{64}$/D',$stateHash)===1 && preg_match('/^[a-f0-9]{64}$/D',$previewHash)===1 && preg_match('/^[a-zA-Z0-9_.:-]{1,190}$/D',$key)===1,'debt_command_invalid');
        return $this->lots->transaction($supplier,function()use($supplier,$command,$actor,$stateHash,$previewHash,$key){
            $command=$this->normalize($command);$identity=R::hash([$actor,$command,$stateHash,$previewHash]);
            $q=$this->db->pdo()->prepare('SELECT request_hash,response_json FROM accounting_securities_debt_commands WHERE supplier_id=? AND idempotency_key=? FOR UPDATE');$q->execute([$supplier,$key]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if($old){SecuritiesDebt504Terms::need(hash_equals($old['request_hash'],$identity),'debt_idempotency_conflict');return R::decode($old['response_json']);}
            $plan=$this->plan($supplier,$command);
            SecuritiesDebt504Terms::need(hash_equals($stateHash,$plan['state_hash']) && hash_equals($previewHash,R::hash([$supplier,$actor,$command,$plan])),'debt_preview_stale');
            if($command['operation']==='register')$result=['lot_id'=>$this->lots->register($supplier,$plan['facts'],$actor),'event_id'=>null];
            else {
                $receipt=['amount'=>$plan['amount'],'book_change_czk'=>$plan['book_change_czk'],'rate'=>$plan['rate']??null,'lines'=>$plan['lines']];$ledger=null;
                if(isset($plan['payment']))$receipt['payment']=$plan['payment'];
                if(isset($plan['sold']))$receipt['sold']=$plan['sold'];
                if($command['operation']==='reverse'){
                    if(isset($plan['reverse_entry']))$ledger=(new PaymentJournalProof($this->db))->current($supplier,(new SecuritiesDebt504UseGuard($this->db))->reverseLatest($supplier,$command['lot_id'],$command['event_id'],$plan['reverse_entry'],$command['accounting_date'],fn()=>$this->posting->reverse($supplier,$plan['reverse_entry'],['entry_date'=>$command['accounting_date'],'posted_by'=>$actor,'user_id'=>$actor])));
                    $this->lots->releaseClaims($supplier,$command['lot_id'],$command['event_id']);
                }
                elseif($plan['lines']!==[])$ledger=(new PaymentJournalProof($this->db))->current($supplier,$this->posting->postDocument($supplier,'manual',null,$plan['lines'],['entry_date'=>$command['accounting_date'],'document_date'=>$command['accounting_date'],'document_no'=>'CP/'.$command['lot_id'].'/'.$command['operation'].'/'.$command['accounting_date'],'description'=>'Dluhový cenný papír: '.$command['evidence'],'posted'=>true,'posted_by'=>$actor,'user_id'=>$actor]));
                if($ledger!==null)$receipt['ledger']=$ledger;
                $event=$this->lots->event($supplier,$command['lot_id'],$command+['actor'=>$actor],$receipt,$command['operation']==='reverse'?$command['event_id']:null);
                $result=['lot_id'=>$command['lot_id'],'event_id'=>$event];
                $this->lots->current($supplier,$command['lot_id'],$command['accounting_date']);
            }
            $q=$this->db->pdo()->prepare('INSERT INTO accounting_securities_debt_commands(supplier_id,idempotency_key,request_hash,response_json,created_by,recorded_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$supplier,$key,$identity,R::json($result),$actor]);return $result;
        });
    }

    public function detail(int $supplier,int $lot,string $date): array
    {
        SecuritiesDebt504Policy::date($date);return $this->lots->transaction($supplier,fn()=> $this->lots->current($supplier,$lot,$date));
    }

    public function plan(int $supplier,array $c): array
    {
        $op=$c['operation'];
        if($op==='register'){
            $facts=$this->lots->acquisition($supplier,$c['terms'],$c['entry_id'],$c['line_id']);
            return ['operation'=>$op,'facts'=>$facts,'state_hash'=>R::hash($facts),'lines'=>[]];
        }
        $date=$c['accounting_date'];$this->lots->assertFramework($supplier,$date);
        $state=$this->lots->current($supplier,$c['lot_id'],$date);$facts=$state['facts'];$terms=$facts['terms'];
        SecuritiesDebt504Terms::need($date>=$terms['acquired_on'] && $date>=$state['max_event_date'],'debt_event_date_invalid');
        $plan=['operation'=>$op,'state_hash'=>$state['hash'],'lines'=>[],'amount'=>'0.00','book_change_czk'=>'0.00'];
        if($op==='reverse')return $this->reversePlan($state,$c,$plan);
        if($op==='sale')return $this->salePlan($supplier,$state,$c,$plan);
        SecuritiesDebt504Terms::need($state['sold_on']===null,'debt_sold');
        if(in_array($op,['coupon_receipt','principal_redemption'],true)){
            $payment=(new SecuritiesDebt504PaymentProof($this->db))->current($supplier,$c['entry_id'],$c['line_id'],$facts['asset']);
            SecuritiesDebt504Terms::need($payment['date']===$date,'debt_payment_date_invalid');$amount=$op==='principal_redemption'?$c['nominal_amount']:$payment['amount'];
            $this->lots->available($supplier,$payment['line']['id'],$op);
            if($op==='coupon_receipt')SecuritiesDebt504Terms::need(bccomp($amount,$state['unpaid_interest'],2)<=0 && bccomp($payment['line']['amount'],$state['interest_book_czk'],2)<=0,'debt_coupon_overpaid');
            else {
                SecuritiesDebt504Terms::need($date>=$terms['maturity_on'] && bccomp($amount,$state['principal_balance'],2)<=0 && bccomp($payment['amount'],$state['principal_book_native'],2)<=0 && bccomp($payment['line']['amount'],$state['principal_book_czk'],2)<=0,'debt_principal_redemption_invalid');
                SecuritiesDebt504Terms::need($state['earned']===SecuritiesDebt504Terms::earned($terms,$date,$state['sale_basis']) && ($terms['classification']!=='held_to_maturity'||$state['amortised']===SecuritiesDebt504Terms::amortised($terms,$date,$state['sale_basis'])),'debt_maturity_accrual_required');
                if($amount===$state['principal_balance'])SecuritiesDebt504Terms::need($payment['amount']===$state['principal_book_native'] && $payment['line']['amount']===$state['principal_book_czk'],'debt_principal_partition_required');
            }
            $this->lots->assertPaymentCapacity($supplier,$payment,$amount);
            return array_replace($plan,['amount'=>$amount,'book_change_czk'=>bcsub('0',$payment['line']['amount'],2),'payment'=>$payment]);
        }
        SecuritiesDebt504Terms::need(bccomp($state['principal_redeemed'],'0',2)===0,'debt_redeemed');
        $rate=$this->rate($supplier,$facts['currency'],$date,$op==='valuation');$plan['rate']=$rate;
        if($op==='accrual'){
            SecuritiesDebt504Terms::need($terms['debt_type']==='coupon','debt_coupon_period_required');
            $amount=bcsub(SecuritiesDebt504Terms::earned($terms,$date,$state['sale_basis']),$state['earned'],2);$counterpart=$this->role($supplier,$date,'interest.income');
        } elseif($op==='amortisation'){
            $amount=bcsub(SecuritiesDebt504Terms::amortised($terms,$date,$state['sale_basis']),$state['amortised'],2);$counterpart=$this->role($supplier,$date,bccomp($amount,'0',2)<0?'interest.expense':'interest.income');
        } else {
            SecuritiesDebt504Terms::need($op==='valuation' && $terms['classification']==='trading','debt_valuation_ineligible');
            $plan['category_hash']=$this->assertFairProfile($supplier,$date,$c['profile']);$quote=$c['quote'];
            SecuritiesValuation504Evidence::assertMarketBasis($quote+['quote_currency'=>$quote['currency']??null],$date,$facts['currency']);
            SecuritiesDebt504Terms::need(($quote['quote_type']??null)==='clean' && ($quote['currency']??null)===$facts['currency'] && ($quote['as_of']??null)===$date && SecuritiesDebt504Policy::text($quote['reference']??null) && SecuritiesDebt504Policy::text($quote['market_reference']??null) && ($quote['market_type']??null)==='regulated','debt_clean_quote_required');
            SecuritiesDebt504Policy::decimal($quote['clean_value']??null);$target=JournalLineAmount::decimal(bcmul($quote['clean_value'],$rate['rate'],16));
            $change=bcsub($target,$state['principal_book_czk'],2);
            if($change==='0.00')return $plan;
            $counterpart=$terms['debt_type']==='discount'?$this->role($supplier,$date,'interest.income'):(bccomp($change,'0',2)>0?'649':'549');
            $this->lots->account($supplier,$counterpart,bccomp($change,'0',2)>0||$terms['debt_type']==='discount'?'revenue':'expense');
            $lines=$this->lines($facts['asset'],$counterpart,$change,$facts['currency']==='CZK'?null:'0.00',$rate['rate']);
            return array_replace($plan,['amount'=>$change,'book_change_czk'=>$change,'lines'=>$lines]);
        }
        SecuritiesDebt504Terms::need($amount!=='0.00','debt_no_change');
        $change=JournalLineAmount::decimal(bcmul($amount,$rate['rate'],16));
        $this->lots->account($supplier,$counterpart,bccomp($amount,'0',2)<0?'expense':'revenue');
        return array_replace($plan,['amount'=>$amount,'book_change_czk'=>$change,'lines'=>$this->lines($facts['asset'],$counterpart,$change,$facts['currency']==='CZK'?null:$amount,$rate['rate'])]);
    }

    /**
     * Storno poslední operace. Vygenerovaný zápis (úrok, rozpuštění, přecenění, prodej)
     * se stornuje protizápisem; navázaná úhrada kupónu nebo jistiny se jen odváže ve stejném
     * dni, aby se bankovní nebo pokladní doklad dal opravit, a stav k žádnému dni nevisí
     * na úhradě, která už k cennému papíru nepatří.
     */
    private function reversePlan(array $state,array $c,array $plan): array
    {
        $last=$state['events']===[]?null:$state['events'][array_key_last($state['events'])];
        SecuritiesDebt504Terms::need($last!==null && $last['id']===$c['event_id'],'debt_reverse_dependency');
        $receipt=$last['receipt'];
        if(isset($receipt['ledger'])){
            $plan['reverse_entry']=$receipt['ledger']['entry']['id'];
        } else {
            SecuritiesDebt504Terms::need(isset($receipt['payment']),'debt_reverse_dependency');
            SecuritiesDebt504Terms::need($c['accounting_date']===$last['request']['accounting_date'],'debt_reverse_date_invalid');
        }
        $plan['amount']=$receipt['amount'];
        $plan['book_change_czk']=bcsub('0',$receipt['book_change_czk'],2);
        return $plan;
    }

    /**
     * Prodej celé zbývající držby nebo její části před splatností. Úrok a rozpuštění rozdílu
     * musí být zaúčtované ke dni prodeje, tržbu zaúčtuje účetní bankovním nebo pokladním
     * dokladem na 653 a tady se jen naváže; vygenerovaný zápis vyřadí prodanou účetní hodnotu
     * včetně prodaného naběhlého úroku na 553 (ČÚS 406 bod 3.2.1 a 3.4, směrná osnova vyhlášky
     * 504/2002 Sb.). Rozpis prodeje počítá {@see SecuritiesDebt504Terms::sale()}.
     *
     * U cizí měny se účetní hodnota (včetně kurzového přecenění z rozvahového dne) nejprve
     * přepočte kurzem ČNB ke dni prodeje; kurzový rozdíl jde ve stejném zápisu na 645 nebo 545
     * (kurzové role rámce 504), aby výsledek prodeje 653 - 553 kurz nezkresloval. Zbytek držby
     * pak pokračuje s přepočtenou hodnotou a další uzávěrka ho přecení od ní.
     */
    private function salePlan(int $supplier,array $state,array $c,array $plan): array
    {
        $facts=$state['facts'];$terms=$facts['terms'];$date=$c['accounting_date'];$currency=$facts['currency'];
        SecuritiesDebt504Terms::need($state['sold_on']===null && $date<$terms['maturity_on'] && bccomp($state['principal_balance'],'0',2)>0,'debt_sale_invalid');
        $earnedComplete=$terms['debt_type']!=='coupon' || $state['earned']===SecuritiesDebt504Terms::earned($terms,$date,$state['sale_basis']);
        $amortisedComplete=$terms['classification']!=='held_to_maturity' || $state['amortised']===SecuritiesDebt504Terms::amortised($terms,$date,$state['sale_basis']);
        SecuritiesDebt504Terms::need($earnedComplete && $amortisedComplete,'debt_sale_accrual_required');
        $proceeds=(new SecuritiesDebt504PaymentProof($this->db))->current($supplier,$c['entry_id'],$c['line_id'],$facts['asset'],SecuritiesDebt504Policy::SALE_REVENUE_ACCOUNT);
        SecuritiesDebt504Terms::need($proceeds['date']===$date,'debt_payment_date_invalid');
        SecuritiesDebt504Terms::need(bccomp($proceeds['amount'],$proceeds['money_amount'],2)<=0,'debt_payment_capacity_exceeded');
        $this->lots->available($supplier,$proceeds['line']['id'],'sale');
        $this->lots->account($supplier,SecuritiesDebt504Policy::SALE_COST_ACCOUNT,'expense');
        $book=bcadd($state['principal_book_czk'],$state['interest_book_czk'],2);
        SecuritiesDebt504Terms::need($book===$state['book_czk'] && bccomp($book,'0',2)>0,'debt_sale_invalid');
        $rate=$currency==='CZK'?['rate'=>'1.000000']:$this->rate($supplier,$currency,$date);
        if($currency!=='CZK')$plan['rate']=$rate;
        $sold=SecuritiesDebt504Terms::sale($terms,$state,$c['nominal_amount']??null,$currency,$rate['rate'],$date);
        $lines=[];
        $fx=bcadd($sold['fx_principal_czk']??'0.00',$sold['fx_interest_czk']??'0.00',2);
        if($fx!=='0.00'){
            $gain=bccomp($fx,'0',2)>0;$fxAccount=$this->role($supplier,$date,$gain?'fx.gain':'fx.loss');
            $this->lots->account($supplier,$fxAccount,$gain?'revenue':'expense');
            $lines=$this->lines($facts['asset'],$fxAccount,$fx,'0.00',$rate['rate']);
        }
        $derecognised=bcadd($sold['principal_czk'],$sold['interest_czk'],2);
        SecuritiesDebt504Terms::need(bccomp($derecognised,'0',2)>0,'debt_sale_invalid');
        $foreign=$currency==='CZK'?null:bcadd($sold['principal_native'],$sold['interest'],2);
        $lines=[...$lines,...$this->lines($facts['asset'],SecuritiesDebt504Policy::SALE_COST_ACCOUNT,bcsub('0',$derecognised,2),$foreign,$rate['rate'])];
        return array_replace($plan,[
            'amount'=>$proceeds['amount'],
            'book_change_czk'=>bcsub($fx,$derecognised,2),
            'lines'=>$lines,
            'payment'=>$proceeds,
            'sold'=>$sold,
        ]);
    }

    public function profileChoices(int $supplier,string $date): array
    {
        SecuritiesDebt504Policy::date($date);
        return $this->lots->transaction($supplier,function()use($supplier,$date){
            $q=$this->db->pdo()->prepare('SELECT * FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? FOR UPDATE');$q->execute([$supplier,$date,$date]);$period=$q->fetch(PDO::FETCH_ASSOC);
            SecuritiesDebt504Terms::need($period!==false,'debt_period_required');
            $snapshot=(new SecuritiesValuation504ReadRepository($this->db))->profileSnapshot($supplier,$period,true);
            return ['periods'=>$snapshot['periods'],'history'=>$snapshot['history']];
        });
    }

    public function sources(int $supplier,string $date,string $kind,int $afterEntry=0,int $afterLine=0): array
    {
        SecuritiesDebt504Policy::date($date);SecuritiesDebt504Terms::need(in_array($kind,['acquisitions','receipts','proceeds'],true),'debt_source_kind_invalid');
        SecuritiesDebt504Terms::need($afterEntry>=0 && $afterLine>=0 && (($afterEntry===0)===($afterLine===0)),'debt_source_cursor_invalid');
        return $this->lots->transaction($supplier,function()use($supplier,$date,$kind,$afterEntry,$afterLine){
            $q=$this->db->pdo()->prepare("SELECT e.id,e.entry_date,e.document_no,e.description,l.id line_id,a.account_code,l.amount,l.currency_code,l.amount_foreign FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE e.supplier_id=? AND e.entry_date=? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.source_type NOT IN ('closing','opening') AND NOT EXISTS(SELECT 1 FROM accounting_securities_debt_claims c WHERE c.supplier_id=l.supplier_id AND c.line_id=l.id) AND (IF(?='proceeds',a.account_code LIKE '653%',a.account_code LIKE '063%' OR a.account_code LIKE '253%')) AND l.side=? AND (?=0 OR e.id<? OR (e.id=? AND l.id<?)) ORDER BY e.id DESC,l.id DESC LIMIT 101");$q->execute([$supplier,$date,$kind,$kind==='acquisitions'?'debit':'credit',$afterEntry,$afterEntry,$afterEntry,$afterLine]);return $q->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    private function rate(int $supplier,string $currency,string $date,bool $closing=false): array
    {
        if($currency==='CZK')return ['rate'=>'1.000000','rate_date'=>$date,'source'=>'CZK'];
        if($closing){$rate=(new SecuritiesValuation504ReadRepository($this->db))->rateAt($currency,$date,true);SecuritiesDebt504Terms::need($rate!==null,'debt_rate_required');return $rate+['source'=>'cached_cnb'];}
        $fixed=$this->fixed->resolve($supplier,$currency,new \DateTimeImmutable($date));if($fixed!==null)return ['rate'=>number_format($fixed['rate'],6,'.',''),'rate_date'=>$fixed['rate_date'],'source'=>'fixed'];
        SecuritiesDebt504Terms::need($this->fixed->modeFor($supplier)==='daily','debt_fixed_rate_required');
        $rate=(new SecuritiesValuation504ReadRepository($this->db))->rateAt($currency,$date,true);SecuritiesDebt504Terms::need($rate!==null,'debt_rate_required');return $rate+['source'=>'cached_cnb'];
    }

    private function role(int $supplier,string $date,string $role): string {return AccountRoleResolver::fromConnection($this->db)->forDate($supplier,$date)->account($role);}

    private function lines(array $asset,string $counterpart,string $czk,?string $foreign,string $rate): array
    {
        $positive=bccomp($czk,'0',2)>0;$amount=ltrim($czk,'-');$a=$asset;unset($a['id']);$a['side']=$positive?'debit':'credit';$a['amount']=$amount;
        $a['is_red_storno']=false;
        $a['dimension_origins']=array_fill_keys(array_keys($a['dimensions']),['origin'=>'explicit','ref'=>'securities-debt-contract']);
        if($foreign!==null){$a['amount_foreign']=ltrim($foreign,'-');$a['fx_rate']=$rate;}
        $b=$a;$b['account_code']=$counterpart;$b['side']=$positive?'credit':'debit';$b['currency_code']=$b['amount_foreign']=$b['fx_rate']=null;
        return [$a,$b];
    }

    private function assertFairProfile(int $supplier,string $date,array $profile): string
    {
        $q=$this->db->pdo()->prepare('SELECT * FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? FOR UPDATE');$q->execute([$supplier,$date,$date]);$period=$q->fetch(PDO::FETCH_ASSOC);
        SecuritiesDebt504Terms::need($period!==false && $period['ends_on']===$date,'debt_valuation_date_invalid');
        $snapshot=(new SecuritiesValuation504ReadRepository($this->db))->snapshot($supplier,$period,true);
        SecuritiesDebt504Terms::need(SecuritiesValuation504Evidence::fairValueEligible($snapshot,$profile),'debt_valuation_ineligible');return $snapshot['hash'];
    }

    private function normalize(array $c): array
    {
        $op=$c['operation']??null;$ops=['register','accrual','amortisation','valuation','coupon_receipt','principal_redemption','sale','reverse'];
        SecuritiesDebt504Terms::need(is_string($op)&&in_array($op,$ops,true),'debt_command_invalid');
        $keys=$op==='register'?['operation','terms','entry_id','line_id']:['operation','lot_id','accounting_date','evidence'];
        if(in_array($op,['coupon_receipt','principal_redemption','sale'],true))$keys=[...$keys,'entry_id','line_id'];
        if($op==='principal_redemption'||($op==='sale'&&array_key_exists('nominal_amount',$c)))$keys[]='nominal_amount';
        if($op==='valuation')$keys=[...$keys,'profile','quote'];if($op==='reverse')$keys[]='event_id';
        foreach($keys as $key)SecuritiesDebt504Terms::need(array_key_exists($key,$c)&&$c[$key]!==null,'debt_command_invalid');
        SecuritiesDebt504Terms::need(array_diff(array_keys($c),$keys)===[],'debt_command_invalid');
        foreach(['lot_id','entry_id','line_id','event_id'] as $key)if(array_key_exists($key,$c))PropertyInput::id($c[$key],$key);
        if(in_array('nominal_amount',$keys,true)){SecuritiesDebt504Policy::decimal($c['nominal_amount']);$c['nominal_amount']=JournalLineAmount::decimal($c['nominal_amount']);SecuritiesDebt504Terms::need(bccomp($c['nominal_amount'],'0',2)>0,'debt_nominal_required');}
        if($op==='register')SecuritiesDebt504Terms::need(is_array($c['terms']),'debt_command_invalid');
        else {SecuritiesDebt504Policy::date($c['accounting_date']);SecuritiesDebt504Terms::need(SecuritiesDebt504Policy::text($c['evidence']),'debt_contract_required');}
        if($op==='valuation'){
            SecuritiesDebt504Terms::need(is_array($c['profile'])&&is_array($c['quote']),'debt_command_invalid');
            SecuritiesDebt504Terms::need(array_diff(array_keys($c['quote']),['quote_type','currency','as_of','clean_value','reference','market','market_reference','market_type','market_calendar_reference','market_calendar_as_of','last_trading_date','valuation_date'])===[],'debt_quote_invalid');
        }
        return $c;
    }
}