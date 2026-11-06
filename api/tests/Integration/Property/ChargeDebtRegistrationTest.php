<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Benchmark\PropertyBenchmarkDataset;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Repository\Property\Payments\DebtRepository;
use MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar;
use MyInvoice\Repository\Property\Receivables\PostedChargeDebtBackfill;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/bin/Support/PropertyBenchmarkDataset.php';

/** Hromadná registrace dluhů při zaúčtování dávky předpisů (audit P1-6). */
final class ChargeDebtRegistrationTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture;

    private Connection $db;
    private \DI\Container $container;
    private array $f;

    protected function setUp(): void
    {
        $this->container=Bootstrap::buildContainer();
        $this->db=$this->container->get(Connection::class);
        $this->container->set(Connection::class,$this->db);
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testWholeHouseYearPostsWithCompleteDebtInventory(): void
    {
        // Akceptační rozsah 400 jednotek × 12 měsíců trvá minuty; běžná sada ověřuje stejný průběh
        // (úplná evidence dluhů každého měsíce, žádný duplicitní dluh) na 8 jednotkách.
        $units=(int)(getenv('MYINVOICE_PROPERTY_SCALE_UNITS')?:8);
        $months=12;
        $dataset=(new PropertyBenchmarkDataset($this->db,$this->container))->create($units,'2026-01-01','Synthetic debt inventory');
        $supplier=$dataset['supplier'];
        for($month=1;$month<=$months;$month++) $this->postMonth($dataset,sprintf('2026-%02d',$month),ChargeDebtRegistrar::MODE_AUTOMATIC);

        self::assertSame($units*$months,$this->scalar('SELECT COUNT(*) FROM unit_charges WHERE supplier_id=? AND status=\'posted\'',[$supplier]));
        self::assertSame($units*$months,$this->scalar('SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=?',[$supplier]));
        self::assertSame(0,$this->scalar('SELECT COUNT(*) FROM unit_charge_lines l LEFT JOIN unit_payment_debt_lines d ON d.supplier_id=l.supplier_id AND d.charge_line_id=l.id'
            .' WHERE l.supplier_id=? AND l.amount_cents>0 AND d.debt_id IS NULL',[$supplier]));
        self::assertSame(0,$this->scalar('SELECT COUNT(*) FROM (SELECT charge_id FROM unit_payment_debts WHERE supplier_id=? GROUP BY charge_id HAVING COUNT(*)>1) duplicated',[$supplier]));

        $sample=array_map('intval',$this->db->pdo()->query('SELECT id FROM unit_payment_debts WHERE supplier_id='.$supplier.' ORDER BY id LIMIT 3')->fetchAll(\PDO::FETCH_COLUMN));
        $debts=(new DebtRepository($this->db))->currentMany($supplier,$sample);
        self::assertCount(3,$debts);
        foreach($debts as $debt) {
            self::assertTrue($debt['active']);
            self::assertSame('180000',$debt['snapshot']['amount_cents']);
            self::assertSame('statutory',$debt['snapshot']['legal']['basis']);
            self::assertSame('unsecured',$debt['snapshot']['security']['kind']);
        }
    }

    public function testRegistrationStatementCountDoesNotGrowWithBatchSize(): void
    {
        // Počty příkazů se porovnávají přesně, dávka 25 předpisů proto N+1 odhalí stejně jako 400.
        $dataset=(new PropertyBenchmarkDataset($this->db,$this->container))->create(25,'2026-01-01','Synthetic bulk debts');
        $supplier=$dataset['supplier'];
        $batch=$this->postMonth($dataset,'2026-01',ChargeDebtRegistrar::MODE_MANUAL);
        self::assertSame(0,$this->scalar('SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=?',[$supplier]));

        $registrar=new ChargeDebtRegistrar($this->db);
        $before=$this->statements();
        $created=$registrar->registerPosted($supplier,$batch['charges'],$dataset['actor']);
        $after=$this->statements();
        self::assertCount(25,$created);
        // Trigger property_party_claim_keys_debt přidá v serveru jeden INSERT na řádek dluhu, aplikace posílá jen tři.
        self::assertSame(3+count($created),$after['Com_insert']-$before['Com_insert'],'Debts, debt lines and audit must be inserted in one statement each.');
        self::assertLessThanOrEqual(3,$after['Com_select']-$before['Com_select']);

        $before=$this->statements();
        self::assertSame([],$registrar->registerPosted($supplier,$batch['charges'],$dataset['actor']));
        $after=$this->statements();
        self::assertSame(0,$after['Com_insert']-$before['Com_insert']);
        self::assertSame(25,$this->scalar('SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=?',[$supplier]));
    }

    public function testAutomaticInventoryLetsStatutoryPaymentReachAllocation(): void
    {
        $this->f=$this->issuanceFixture($this->db);
        $charge=$this->postFixture(ChargeDebtRegistrar::MODE_AUTOMATIC);
        $debtor=$charge['snapshot']['liability_groups'][0]['members'][0];
        $debts=array_map('intval',$this->column('SELECT id FROM unit_payment_debts WHERE supplier_id=? AND charge_id=?',[$this->f['supplier'],$charge['id']]));
        self::assertCount(1,$debts);

        // Úplná úhrada předpisu: rozdělení mezi složky není potřeba deklarovat.
        $preview=$this->correctionPayments()->preview($this->f['supplier'],$this->statutoryPayment($debtor,$debts,$charge['amount_cents']));
        self::assertSame([$debts[0]],array_values(array_unique(array_column($preview['snapshot']['allocations'],'debt_id'))));
    }

    public function testPartialPaymentSplitsPrincipalProportionallyUnlessDeclared(): void
    {
        $this->f=$this->issuanceFixture($this->db);
        $charge=$this->postFixture(ChargeDebtRegistrar::MODE_AUTOMATIC);
        $debtor=$charge['snapshot']['liability_groups'][0]['members'][0];
        $debts=array_map('intval',$this->column('SELECT id FROM unit_payment_debts WHERE supplier_id=? AND charge_id=?',[$this->f['supplier'],$charge['id']]));
        $lines=[];
        foreach($this->db->pdo()->query('SELECT id,amount_cents FROM unit_charge_lines WHERE supplier_id='.$this->f['supplier'].' AND charge_id='.$charge['id'].' AND amount_cents>0 ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $row) $lines[(int)$row['id']]=$row['amount_cents'];
        self::assertGreaterThan(1,count($lines));

        // Bez určení dlužníkem se částečná úhrada jistiny rozdělí poměrně mezi řádky předpisu.
        $amount='1001';
        $preview=$this->correctionPayments()->preview($this->f['supplier'],$this->statutoryPayment($debtor,$debts,$amount));
        $parts=[];
        foreach($preview['snapshot']['allocations'] as $allocation) {
            self::assertSame('principal',$allocation['component']);
            $parts[$allocation['charge_line_id']]=$allocation['amount_cents'];
        }
        ksort($parts);
        self::assertSame(\MyInvoice\Service\Property\Payments\ClaimPortionAllocator::distribute($amount,$lines,null,true),$parts);
        self::assertSame($amount,array_reduce($parts,fn($sum,$value)=>bcadd($sum,$value,0),'0'));

        // Deklarované rozdělení má přednost.
        $first=array_key_first($lines);
        $declared=$this->statutoryPayment($debtor,$debts,$amount);
        $declared['payment']['principal_distribution']=[$debts[0]=>['evidence'=>'Synthetic debtor designation with creditor consent','lines'=>[['line_id'=>$first,'amount_cents'=>$amount]]]];
        $preview=$this->correctionPayments()->preview($this->f['supplier'],$declared);
        self::assertSame([[$first,$amount]],array_map(fn($a)=>[$a['charge_line_id'],$a['amount_cents']],$preview['snapshot']['allocations']));
    }

    public function testManualModeKeepsExceptionPathAndInventoryGuard(): void
    {
        $this->f=$this->issuanceFixture($this->db);
        $charge=$this->postFixture(ChargeDebtRegistrar::MODE_MANUAL);
        self::assertSame(0,$this->scalar('SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=?',[$this->f['supplier']]));
        $debtor=$charge['snapshot']['liability_groups'][0]['members'][0];
        $line=(int)$this->column('SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? AND component_id=?',[$this->f['supplier'],$charge['id'],$this->f['components']['ADMIN']])[0];
        $debt=(new DebtRepository($this->db))->register($this->f['supplier'],['charge_id'=>$charge['id'],'expected_version'=>2,'principal_line_ids'=>[$line],'title_ref'=>'Synthetic separate administration debt',
            'due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding'],'damages_for'=>null],'manual-debt',$this->f['actor']);
        try {
            $this->correctionPayments()->preview($this->f['supplier'],$this->statutoryPayment($debtor,[$debt['id']]));
            self::fail('Payment allocated while part of the posted charge had no registered debt.');
        } catch(PaymentException $e) {
            self::assertSame('property_payment_debt_inventory_incomplete',$e->errorCode);
        }
    }

    public function testBackfillRegistersPreviouslyPostedChargesOnceAndKeepsManualExceptions(): void
    {
        $this->f=$this->issuanceFixture($this->db);
        $supplier=$this->f['supplier'];
        $issuance=$this->container->get(IssuanceRepository::class);
        $preview=(new IssuancePreviewService($this->db))->preview($supplier,$this->f['issue_request']);
        $batch=$issuance->issue($supplier,['request'=>$this->f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'debt-backfill'],$this->f['actor']);
        $batch=$issuance->post($supplier,$batch['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>1],$batch['charges']),$this->f['actor'],ChargeDebtRegistrar::MODE_MANUAL);
        $positive=array_values(array_filter($batch['charges'],fn($c)=>bccomp($c['amount_cents'],'0',0)>0));
        self::assertGreaterThan(1,count($positive));
        $manual=array_values(array_filter($positive,fn($c)=>$c['unit_id']===$this->f['units']['G12']))[0];
        $line=(int)$this->column('SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? AND component_id=?',[$supplier,$manual['id'],$this->f['components']['ADMIN']])[0];
        (new DebtRepository($this->db))->register($supplier,['charge_id'=>$manual['id'],'expected_version'=>2,'principal_line_ids'=>[$line],'title_ref'=>'Synthetic separate administration debt',
            'due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding'],'damages_for'=>null],'manual-backfill-debt',$this->f['actor']);

        $backfill=new PostedChargeDebtBackfill($this->db);
        $expected=count($positive);
        $dry=$backfill->run($supplier,true,1);
        self::assertSame(['candidates'=>$expected,'registered'=>$expected-1,'partial'=>1,'debts'=>[]],$dry);
        self::assertSame(1,$this->scalar('SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=?',[$supplier]));

        $done=$backfill->run($supplier,false,1);
        self::assertSame($expected-1,$done['registered']);
        self::assertSame(1,$done['partial']);
        self::assertArrayNotHasKey($manual['id'],$done['debts']);
        self::assertSame($expected,$this->scalar('SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=?',[$supplier]));
        self::assertSame((string)$this->f['actor'],(string)$this->column('SELECT DISTINCT created_by FROM unit_payment_debts WHERE supplier_id=? AND idempotency_key LIKE ?',[$supplier,'charge-debt-%'])[0]);

        $again=$backfill->run($supplier,false);
        self::assertSame(['candidates'=>1,'registered'=>0,'partial'=>1,'debts'=>[]],$again);
        self::assertSame($expected,$this->scalar('SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=?',[$supplier]));

        $debts=(new DebtRepository($this->db))->currentMany($supplier,array_values($done['debts']));
        foreach($debts as $debt) {
            self::assertSame(ChargeDebtRegistrar::automaticLegal(['id'=>$debt['snapshot']['charge_id'],'batch_id'=>$batch['id']]),$debt['snapshot']['legal']);
            self::assertSame(ChargeDebtRegistrar::automaticSecurity(),$debt['snapshot']['security']);
        }
    }

    public function testAutomaticDebtUsesStatutoryGeneralUnsecuredDefaults(): void
    {
        $this->f=$this->issuanceFixture($this->db);
        $charge=$this->postFixture(ChargeDebtRegistrar::MODE_AUTOMATIC);
        $debt=(int)$this->column('SELECT id FROM unit_payment_debts WHERE supplier_id=? AND charge_id=?',[$this->f['supplier'],$charge['id']])[0];
        $snapshot=(new DebtRepository($this->db))->current($this->f['supplier'],$debt)['snapshot'];
        // Vlastník v zákonném vztahu k SVJ není bez doložení spotřebitelem a předpis nedokládá zajištění.
        self::assertSame(['basis'=>'statutory','evidence'=>'Zaúčtovaný předpis P-'.$charge['id'].' (dávka '.$charge['batch_id'].')','regime'=>'general'],$snapshot['legal']);
        self::assertSame('unsecured',$snapshot['security']['kind']);
    }

    public function testUnknownRegistrationModeIsRejected(): void
    {
        $this->expectException(\MyInvoice\Service\Property\PropertyException::class);
        ChargeDebtRegistrar::mode('later');
    }

    private function postFixture(string $mode): array
    {
        $issuance=$this->container->get(IssuanceRepository::class);
        $request=$this->f['issue_request'];
        $preview=(new IssuancePreviewService($this->db))->preview($this->f['supplier'],$request);
        $batch=$issuance->issue($this->f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'debt-inventory'],$this->f['actor']);
        $batch=$issuance->post($this->f['supplier'],$batch['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>1],$batch['charges']),$this->f['actor'],$mode);
        return array_values(array_filter($batch['charges'],fn($c)=>$c['unit_id']===$this->f['units']['G12']))[0];
    }

    private function statutoryPayment(int $debtor,array $debts,string $amount='1000'): array
    {
        $bank=$this->correctionBank(bcdiv($amount,'100',2));
        return ['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>$amount,'accounting_date'=>'2026-02-01','reason'=>'Synthetic statutory payment','debts'=>$debts,
            'payment'=>['payer_id'=>$debtor,'debtor_id'=>$debtor,'received_at'=>'2026-02-01T10:00:00+01:00']];
    }

    private function postMonth(array $f,string $month,string $mode): array
    {
        $supplier=$f['supplier'];
        $first=$month.'-01';
        $end=(new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        $instant=(new \DateTimeImmutable($first,new \DateTimeZone('Europe/Prague')))->format('c');
        $year=(int)substr($month,0,4);
        $periods=new AccountingPeriodRepository($this->db);
        if($periods->findByYear($supplier,$year)===null) $periods->create($supplier,$year,$year.'-01-01',$year.'-12-31');
        $units=[];
        foreach($f['units'] as $id) {
            $groups=[];
            foreach($f['states'][$id]['ownerships'] as $g) $groups[]=['group_key'=>$g['group_key'],'role'=>'debtor','mode'=>$g['kind']==='marital_community'?'solidary':'individual',
                'basis'=>$g['kind']==='marital_community'?'sjm_713':'documented_title','members'=>$g['members'],'extent'=>$g['share'],'evidence_ref'=>'Synthetic assessed deed',
                'legal_reason'=>'Synthetic explicit debtor assessment','valid_from'=>$f['start'].'T00:00:00+01:00','valid_to'=>null];
            $units[]=['unit_id'=>$id,'expected_revision'=>0,'previous_charge_id'=>null,'liability_groups'=>$groups,
                'notices'=>[['component_id'=>$f['components']['WATER'],'notice'=>['mode'=>'initial','title_ref'=>'Synthetic monthly advance decision']]]];
        }
        $accounts=[];
        foreach($f['components'] as $code=>$id) $accounts[]=['component_id'=>$id,'receivable_account'=>'315','counter_account'=>match($code){'LONG'=>'955.100','ADMIN'=>'324.200',default=>'324.110'},
            'basis_ref'=>'Synthetic monthly accounting decision','settlement_due_on'=>$code==='LONG'?($year+2).'-12-31':null,'dimensions'=>[]];
        $request=['version_id'=>$f['versions'][0]['id'],'source_key'=>'DEBT-MONTH-'.$month,'title'=>'prescription','issue_mode'=>'initial','period_from'=>$first,'period_to'=>$end,
            'claim_origin_at'=>$instant,'issued_at'=>$first.'T10:00:00+01:00','accounting_date'=>$first,'due_date'=>$month.'-15','reason'=>'Synthetic monthly issuance','units'=>$units,'accounts'=>$accounts,
            'case_identity'=>['schema'=>1,'case_ref'=>'DEBT-MONTH','occurrence_ref'=>$month,'title_ref'=>'Synthetic monthly prescription','decision_ref'=>'Synthetic decision','decided_on'=>$first,'evidence_ref'=>'Synthetic approved monthly schedule']];
        $issuance=$this->container->get(IssuanceRepository::class);
        $preview=(new IssuancePreviewService($this->db))->preview($supplier,$request);
        $batch=$issuance->issue($supplier,['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'DEBT-MONTH-'.$month],$f['actor']);
        return $issuance->post($supplier,$batch['id'],array_map(static fn($row)=>['id'=>$row['id'],'row_version'=>$row['row_version']],$batch['charges']),$f['actor'],$mode);
    }

    private function statements(): array
    {
        $out=[];
        foreach($this->db->pdo()->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_insert','Com_select')")->fetchAll(\PDO::FETCH_NUM) as [$name,$value]) $out[$name]=(int)$value;
        return $out;
    }

    private function scalar(string $sql,array $params): int
    {
        return (int)$this->column($sql,$params)[0];
    }

    private function column(string $sql,array $params): array
    {
        $statement=$this->db->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }
}
