<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Funds;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext;
use MyInvoice\Tests\Support\FundingFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FundingSharedGuardsTest extends TestCase
{
    use FundingFixture;
    use \MyInvoice\Tests\Support\SharedFixtureSavepointTrait;

    /** Firma s předpisy a účty fondů je pro všechny testy stejná; staví se jednou za třídu. */
    protected function setUp():void
    {
        $this->fundingConnect();$this->beginSharedFixtureTest($this->db->pdo());$this->f=$this->sharedFixture('funding-base',fn():array=>$this->fundingBase());
    }
    protected function tearDown():void {$this->endSharedFixtureTest($this->db->pdo());}

    private function income(): array
    {
        $request=$this->fundingRequest('income','2026-03-01','6000000')
            +['bank_id'=>$this->fundingBank('2026-03-01','60000.00'),'key'=>$this->fundingKey('2026-03-01')];
        return [$request,$this->fundingApply($request,'shared-income')];
    }

    public static function mutations(): array
    {
        return [['entry'],['line'],['source'],['statement'],['description'],['cash_purge'],['document_purge']];
    }

    #[DataProvider('mutations')]
    public function testSharedMutationBoundariesKeepConfirmedSourceIntact(string $kind): void
    {
        [$request,$operation]=$this->income();
        $supplier=$this->f['supplier'];$entry=$operation['journal_entry_id'];
        $before=(new PaymentJournalProof($this->db))->current($supplier,$entry);
        $context=new PaymentMutationContext($this->db);
        $q=$this->db->pdo()->prepare('SELECT statement_id FROM bank_transactions WHERE id=?');$q->execute([$request['bank_id']]);$statement=(int)$q->fetchColumn();
        $q=$this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY id LIMIT 1');$q->execute([$supplier,$entry]);$line=(int)$q->fetchColumn();
        try {
            match($kind){
                'entry'=>$context->assertEntriesMutable($supplier,[$entry]),
                'line'=>$context->assertLinesMutable($supplier,[$line]),
                'source'=>$context->assertSourceMutable($supplier,'bank',$request['bank_id']),
                'statement'=>$context->assertBankStatementMutable($supplier,$statement),
                'cash_purge'=>$this->container->get(\MyInvoice\Repository\CashDocumentRepository::class)->deleteJournalEntry($supplier,$entry),
                'document_purge'=>$this->container->get(\MyInvoice\Service\Accounting\DocumentJournalPurge::class)->purge($supplier,'bank',[$request['bank_id']],['user_id'=>$this->f['actor']],'Synthetic source purge'),
                'description'=>$this->container->get(\MyInvoice\Repository\JournalEntryRepository::class)->updateDescription($entry,$supplier,'Synthetic changed source',$this->f['actor']),
            };
            self::fail('Confirmed funding source remained mutable through '.$kind);
        } catch (PostingException $e) {
            self::assertContains($e->errorCode,['property_payment_source_immutable','property_funding_source_owned','description_managed_by_source']);
        }
        self::assertSame($before,(new PaymentJournalProof($this->db))->current($supplier,$entry));
    }

    public function testConsumedManualCostDescriptionIsOwnedByFunding(): void
    {
        $this->fundingOpening();$supplier=$this->f['supplier'];$cost=$this->fundingCost('2026-03-01','549.900','10000.00');
        $this->fundingApply($this->fundingDraw('2026-03-01','repair','1000000',$cost),'shared-draw');self::assertSame('1000000',(new \MyInvoice\Repository\Property\Funds\FundingRepository($this->db))->coveredCost($supplier,$cost));
        $q=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');$q->execute([$supplier,$cost]);$entry=(int)$q->fetchColumn();
        $before=(new PaymentJournalProof($this->db))->current($supplier,$entry);
        try{$this->container->get(\MyInvoice\Repository\JournalEntryRepository::class)->updateDescription($entry,$supplier,'Synthetic changed consumed cost',$this->f['actor']);self::fail('Consumed cost description remained mutable.');}
        catch(PostingException $e){self::assertSame('property_funding_source_owned',$e->errorCode);}
        self::assertSame($before,(new PaymentJournalProof($this->db))->current($supplier,$entry));
    }

    public function testMonthlyAndAnnualClosingDetectUnregisteredFundingLedger():void
    {
        $supplier=$this->f['supplier'];$period=$this->container->get(\MyInvoice\Repository\AccountingPeriodRepository::class)->findByYear($supplier,2026);
        $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($supplier,'manual',null,[['account_code'=>'221','side'=>'debit','amount'=>'100.00'],['account_code'=>'955.100','side'=>'credit','amount'=>'100.00']],['entry_date'=>'2026-03-05','posted_by'=>$this->f['actor']]);
        $closing=$this->container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        foreach(['2026-02-28'=>true,'2026-03-31'=>false] as $date=>$expected){$checks=$closing->buildChecks($supplier,$period,'2026-01-01',$date,onlyKeys:['property_funding_reconciliation']);self::assertCount(1,$checks);self::assertSame('error',$checks[0]['severity']);self::assertSame($expected,$checks[0]['ok']);self::assertSame($date,$checks[0]['value']['accounting_date']);$monthly=$closing->monthlyCheck($supplier,$period['id'],substr($date,0,7).'-01',$date);$matching=array_values(array_filter($monthly['checks'],fn($check)=>$check['key']==='property_funding_reconciliation'));self::assertSame($checks,$matching);}
        $errors=(new \ReflectionMethod($closing,'failingErrorChecks'))->invoke($closing,$supplier,$period);self::assertContains('property_funding_reconciliation',$errors);
    }

    public static function reversedDispositions():array {return [['transfer',false],['transfer',true],['payout',false],['payout',true]];}

    #[DataProvider('reversedDispositions')]
    public function testReversalRestoresOriginalIncomeWithoutCreatingAnotherSpendableLot(string $kind,bool $write):void
    {
        [, $income]=$this->income();$lot=$income['receipt']['effects'][0];$disposition=['source_id'=>$lot['id'],'amount_cents'=>'100','party_ids'=>$lot['parties']['members'],'evidence_ref'=>'Synthetic disposition','recipient_authority_ref'=>'Synthetic mandate'];
        $target=['target'=>'long_term','purpose_ref'=>'Synthetic future repairs','settlement_due_on'=>'2029-12-31'];
        $request=$this->fundingRequest($kind,'2026-03-02','100')+['dispositions'=>[$disposition]]+($kind==='transfer'?$target:['bank_id'=>$this->fundingBank('2026-03-02','-1.00'),'recipient_id'=>$this->f['clients'][1],'recipient_account'=>'1000000005/0100']);
        $operation=$this->fundingApply($request,'review-disposition');$reverse=$this->fundingRequest('reverse','2026-03-03');unset($reverse['amount_cents']);$reversed=$this->fundingApply($reverse+['operation_id'=>$operation['id']],'review-reverse');
        $all=[];foreach($income['receipt']['effects'] as $effect)$all[]=['source_id'=>$effect['id'],'amount_cents'=>$effect['amount_cents'],'party_ids'=>$effect['parties']['members'],'evidence_ref'=>'Synthetic complete legitimate disposition'];
        $this->fundingApply($this->fundingRequest('transfer','2026-03-04','6000000')+['dispositions'=>$all]+$target,'review-consume-original');
        $read=(new \MyInvoice\Service\Property\Funds\FundingRead($this->db))->at($this->f['supplier'],null,'2026-03-04');self::assertSame('0',$read['totals']['379.920']);self::assertTrue($read['matches']);
        if(!$write){self::assertCount(count($income['receipt']['effects']),$read['income_lots']);foreach($read['income_lots'] as $candidate)self::assertSame('0',$candidate['remaining_cents']);return;}
        $fake=array_values(array_filter($reversed['receipt']['effects'],fn($effect)=>$effect['account_code']==='379.920'))[0];$disposition['source_id']=$fake['id'];
        try{$this->fundingApply($this->fundingRequest('transfer','2026-03-05','100')+['dispositions'=>[$disposition]]+$target,'review-spend-reversal');self::fail('Reversal was accepted as a second spendable income source after all real income was consumed.');}
        catch(\MyInvoice\Service\Property\Funds\FundingException $e){self::assertSame('property_funding_income_source',$e->errorCode);}
    }

    private function directlyCoveredCost(bool $multiple):array
    {
        $supplier=$this->f['supplier'];$cost=$this->fundingCost('2026-03-01','549.900','100.00');$proof=(new \MyInvoice\Service\Property\Funds\FundingSourceProof($this->db))->cost($supplier,$cost,'2026-03-01','repair');$context=['dimensions'=>$proof['line']['dimensions'],'dimension_origins'=>$proof['line']['dimension_origins']];
        $lines=[['account_code'=>'549.900','side'=>'debit','amount'=>'100.00']+$context,['account_code'=>'649.100','side'=>'credit','amount'=>'100.00']+$context];
        if($multiple){$lines[]=['account_code'=>'518.200','side'=>'debit','amount'=>'100.00']+$context;$lines[]=['account_code'=>'321','side'=>'credit','amount'=>'100.00'];}
        $entry=$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($supplier,'manual',null,$lines,['entry_date'=>'2026-03-01','posted_by'=>$this->f['actor']]);
        return(new PaymentJournalProof($this->db))->current($supplier,$entry)['lines'];
    }

    public function testRevenueAlreadyCoveringItsNativeCostCannotCoverAnotherCost():void
    {
        $lines=$this->directlyCoveredCost(false);$source=new \MyInvoice\Service\Property\Funds\FundingSourceProof($this->db);$cost=$source->cost($this->f['supplier'],$lines[0]['id'],'2026-03-01','repair');self::assertSame('10000',$cost['direct_coverage_cents']);
        try{$source->coverage($this->f['supplier'],$lines[1]['id'],'2026-03-01');self::fail('Revenue already attributed to a native expense remained reusable for another cost.');}
        catch(\MyInvoice\Service\Property\Funds\FundingException $e){self::assertSame('property_funding_coverage_source',$e->errorCode);}
    }

    public function testDirectCoverageOfSeveralNativeCostsCannotBeAttributedToEachCost():void
    {
        $lines=$this->directlyCoveredCost(true);
        try{(new \MyInvoice\Service\Property\Funds\FundingSourceProof($this->db))->cost($this->f['supplier'],$lines[0]['id'],'2026-03-01','repair');self::fail('One revenue was implicitly attributed to every expense in a multi-cost native journal.');}
        catch(\MyInvoice\Service\Property\Funds\FundingException $e){self::assertSame('property_funding_cost_coverage_scope',$e->errorCode);}
    }

    public static function payoutStates(): array {return [[false],[true]];}

    #[DataProvider('payoutStates')]
    public function testActualClientDeleteRetainsIndependentPayoutRecipient(bool $reversed): void
    {
        [, $income]=$this->income();$supplier=$this->f['supplier'];$pdo=$this->db->pdo();
        $q=$pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT supplier_id,'Synthetic mandated recipient',street,city,zip,country_id,'funding-mandate@example.test',currency_default_id FROM clients WHERE supplier_id=? AND id=?");
        $q->execute([$supplier,$this->f['clients'][1]]);$recipient=(int)$pdo->lastInsertId();
        $dispositions=[];foreach($income['receipt']['effects'] as $effect)$dispositions[]=['source_id'=>$effect['id'],'amount_cents'=>bcdiv($effect['amount_cents'],'6',0),'party_ids'=>$effect['parties']['members'],'evidence_ref'=>'Synthetic owner payout instruction','recipient_authority_ref'=>'Synthetic specific mandate to third person'];
        $payout=$this->fundingApply($this->fundingRequest('payout','2026-03-02','1000000')+['dispositions'=>$dispositions,'bank_id'=>$this->fundingBank('2026-03-02','-10000.00'),'recipient_id'=>$recipient,'recipient_account'=>'1000000005/0100'],'shared-payout');
        if($reversed){$reverse=$this->fundingRequest('reverse','2026-03-03');unset($reverse['amount_cents']);$reverse['operation_id']=$payout['id'];$this->fundingApply($reverse,'shared-payout-reverse');}
        $app=\Slim\Factory\AppFactory::create(new \Slim\Psr7\Factory\ResponseFactory(),$this->container);\MyInvoice\Routes::register($app);
        $request=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('DELETE','/api/clients/'.$recipient)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,'session')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$this->f['actor'],'role'=>'admin'])
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$supplier);
        $response=$app->handle($request);$body=json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame(409,$response->getStatusCode(),json_encode($body));
        self::assertSame('has_dependencies',$body['error']['code']);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM clients WHERE id='.$recipient)->fetchColumn());
    }
}
