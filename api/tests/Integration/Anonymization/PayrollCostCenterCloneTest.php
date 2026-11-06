<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Anonymization\{AnonymizationOptions, AnonymizationService, DatabaseCloner, PayrollCostCenterCloneScrubber};
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Posting\PayrollPostingReconciliationService;
use MyInvoice\Service\Payroll\Run\{PayrollRunCommandService, PayrollRunSnapshotBuilder, PayrollRunCalculationPipeline, PayrollRunWorkflow};
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Tests\Support\{OwnedTestDatabaseCleanup, PayrollFullFlowTrait};
use PDO;
use PHPUnit\Framework\TestCase;
use MyInvoice\Tests\Support\CloneSandbox;

final class PayrollCostCenterCloneTest extends TestCase
{
    use PayrollFullFlowTrait;

    public function testFullClonePreservesNativePostedCorrectionGraph(): void
    {
        $base = Config::load(Bootstrap::rootDir());
        self::assertStringEndsWith('_test', $base->get('db.name'));
        self::assertContains($base->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $server = new PDO('mysql:host='.$base->get('db.host').';port='.$base->get('db.port',3306).';charset=utf8mb4', $base->get('db.user'), $base->get('db.pass',''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $previous = getenv('MYINVOICE_DB_NAME');
        try {
            $source = CloneSandbox::acquire($server, (string) $base->get('db.name'), true);
            $this->selectDatabase($source);
            $this->bootPayrollFullFlow();
            $pdo = $this->db->pdo();
            $office = $this->createOffice();
            $this->configureSocialInsuranceOutput($office);
            $this->configureHealthInsuranceOutput();
            $this->configureIncomeTaxOutput();
            $person = $this->createEmployment($office, 'Syntetická Osoba', 71, 'hpp', 'employment', 40, 10000);
            $component = $this->createComponent('PAYROLL_CLONE_BASE', 'base_wage', 'regular');
            $this->createApprovedInput($person, $component, 4_200_000, 'clone-base');
            $this->createApprovedTimeMonth($person['employment_id']);
            $companyDimensions=new \MyInvoice\Repository\DimensionRepository($this->db);
            $typeId=$companyDimensions->createType($this->supplierId,false,['code'=>'PAYROLL_CLONE_ACTIVITY','name'=>'Synthetic payroll activity','kind'=>'custom']);
            $valueId=$companyDimensions->createValue($companyDimensions->findType($this->supplierId,$typeId),['code'=>'SYNTHETIC-ACTIVITY-71','name'=>'Synthetic payroll activity']);
            $pdo->prepare('INSERT INTO payroll_dimensions (supplier_id,dimension_type,code,name,valid_from,created_by,dimension_value_id) VALUES (?,"cost_center","PRIVATE-OFFICE-71","Soukromé středisko","2026-01-01",?,?)')->execute([$this->supplierId,$this->actors[0],$valueId]);
            $dimension = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO payroll_employment_dimensions (supplier_id,employment_id,dimension_id,valid_from,created_by) VALUES (?,?,?,"2026-01-01",?)')->execute([$this->supplierId,$person['employment_id'],$dimension,$this->actors[0]]);
            $this->container->get(AccountingModeRepository::class)->record($this->supplierId,'2026-01-01','double_entry');
            $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
            $chart=$this->container->get(\MyInvoice\Repository\ChartOfAccountsRepository::class);
            $parent=$chart->findByCode($this->supplierId,'521');
            $analytic=$chart->insert($this->supplierId,['account_code'=>'521.071','name'=>'Synthetic payroll analytic','account_type'=>'expense','normal_side'=>'debit','parent_id'=>$parent['id'],'is_synthetic'=>false],'2026-01-01',$this->actors[0]);
            $flow = $this->runPayrollMonth('2026-06-01','2026-07-10',$office,'clone');
            self::assertSame([], $flow['blockers']);
            self::assertSame([], $flow['warnings']);
            self::assertNotNull($flow['approved']);
            $posted = $this->runs->post($this->supplierId,(int)$flow['approved']->run['id'],(int)$flow['approved']->run['row_version'],'clone-post',$this->actors[0]);
            self::assertSame('posted',$posted->run['status']);
            self::assertGreaterThan(0,(int)$pdo->query('SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id='.$this->supplierId.' AND account_id='.$analytic)->fetchColumn());
            $corrected = $this->correctPayrollRun($posted->run,'source-correction','Syntetická oprava');
            $correctionVersion = (int)$corrected->run['row_version'];
            $corrected = $this->runs->post($this->supplierId,(int)$corrected->run['id'],$correctionVersion,'source-correction-post',$this->actors[0]);
            self::assertTrue($chart->update($this->supplierId,$analytic,['is_active'=>false]));
            $before = $this->financialFacts($pdo);
            $sourceSnapshots = $pdo->query('SELECT id,input_snapshot_json,input_snapshot_hash,result_snapshot_json,result_snapshot_hash FROM payroll_run_revisions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(2,$sourceSnapshots);
            $pdo->commit();
            $this->db->close();
            $sourceConfig = Config::load(Bootstrap::rootDir());
            $this->assertSourceProofMutants($server,$sourceConfig,$source);
            $target = CloneSandbox::anonymized($server,$sourceConfig,$source,'synthetic-payroll-clone');
            $this->selectDatabase($target);
            $this->attachClonedRun();
            $pdo = $this->db->pdo();
            $code = $pdo->query('SELECT code FROM payroll_dimensions WHERE id='.$dimension)->fetchColumn();
            self::assertNotSame('PRIVATE-OFFICE-71',$code);
            $snapshots = $pdo->query('SELECT id,input_snapshot_json,input_snapshot_hash,result_snapshot_json,result_snapshot_hash FROM payroll_run_revisions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            foreach($snapshots as $snapshot) {
                $input = json_decode($snapshot['input_snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
                self::assertFalse(str_contains($snapshot['input_snapshot_json'],'PRIVATE-OFFICE-71'));
                foreach($input['people'] as $personInput)foreach($personInput['employments'] as $employmentInput){
                    $registration=$employmentInput['employment']['office_registration'];
                    self::assertSame($registration['sha256'],PayrollRunSnapshotBuilder::officeRegistrationHash($registration));
                }
                self::assertStringContainsString((string)$code,$snapshot['input_snapshot_json']);
                self::assertSame(hash('sha256',$snapshot['input_snapshot_json']),$snapshot['input_snapshot_hash']);
                self::assertSame(hash('sha256',$snapshot['result_snapshot_json']),$snapshot['result_snapshot_hash']);
                $result = json_decode($snapshot['result_snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
                self::assertSame($snapshot['input_snapshot_hash'],$result['source_snapshot_hash']);
            }
            self::assertSame($before,$this->financialFacts($pdo));
            $reconciliation = $this->container->get(PayrollPostingReconciliationService::class)->forPeriod($this->supplierId,'2026-06');
            self::assertSame('posted',$reconciliation['journal_state']);
            self::assertSame('reconciled',$reconciliation['overall_status'],json_encode($reconciliation,JSON_THROW_ON_ERROR));
            self::assertNotEmpty($reconciliation['categories']);
            $this->assertFrozenHashes();
            $replay = $this->runs->post($this->supplierId,(int)$corrected->run['id'],$correctionVersion,'source-correction-post',$this->actors[0]);
            self::assertTrue($replay->idempotentReplay);
            self::assertSame($corrected->revision['id'],$replay->revision['id']);
            $corrected = $this->correctPayrollRun($corrected->run,'clone-correction','Další syntetická oprava');
            $corrected = $this->runs->post($this->supplierId,(int)$corrected->run['id'],(int)$corrected->run['row_version'],'clone-correction-post',$this->actors[0]);
            self::assertSame('posted',$corrected->run['status']);
            self::assertSame($before,$this->financialFacts($pdo));
            $this->assertFrozenHashes();
            $reconciliation = $this->container->get(PayrollPostingReconciliationService::class)->forPeriod($this->supplierId,'2026-06');
            self::assertSame('reconciled',$reconciliation['overall_status']);
            $this->db->close();
            $server->exec('USE '.DatabaseCloner::quote($source));
            self::assertSame($sourceSnapshots,$server->query('SELECT id,input_snapshot_json,input_snapshot_hash,result_snapshot_json,result_snapshot_hash FROM payroll_run_revisions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        } finally {
            if(isset($this->db))$this->tearDownPayrollFullFlow();
            $this->selectDatabase(is_string($previous)?$previous:'');
            CloneSandbox::releaseAll($server);
        }
    }

    private function assertSourceProofMutants(PDO $server,Config $config,string $source): void
    {
        $proof=CloneSandbox::acquire($server,$source);
        $data=$config->all();$data['db']['name']=$proof;
        $connection=Connection::withoutSharedTestConnection(static fn()=>new Connection(new Config($data)));
        try {
            $captured=PayrollCostCenterCloneScrubber::capture($connection);
            self::assertCount(2,$captured['payroll_run_revisions']);
            $row=$captured['payroll_run_revisions'][0];
            $pdo=$connection->pdo();
            self::assertNotEmpty($captured['ledger_proofs']);
            $audit=array_values($captured['ledger_proofs'])[0];
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE activity_log SET payload=JSON_SET(payload,"$.lines[0].amount","0.01") WHERE id=?')->execute([$audit['audit_id']]);
            $this->assertCaptureFails($connection,'posting audit proof is invalid');
            $pdo->rollBack();
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM activity_log WHERE id=?')->execute([$audit['audit_id']]);
            $this->assertCaptureFails($connection,'posting audit proof is missing');
            $pdo->rollBack();
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE payroll_run_revisions SET input_snapshot_hash=REPEAT("0",64) WHERE id=?')->execute([$row['id']]);
            $this->assertCaptureFails($connection,'source snapshot proof is invalid');
            $pdo->rollBack();
            $pdo->beginTransaction();
            $result=json_decode($row['result_snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
            $result['source_snapshot_hash']=str_repeat('0',64);
            $json=\MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode($result);
            $pdo->prepare('UPDATE payroll_run_revisions SET result_snapshot_json=?,result_snapshot_hash=? WHERE id=?')->execute([$json,hash('sha256',$json),$row['id']]);
            $this->assertCaptureFails($connection,'stale input lineage');
            $pdo->rollBack();
            $pdo->beginTransaction();
            $pdo->exec('UPDATE payroll_posting_allocations SET signed_minor=signed_minor+1 ORDER BY id LIMIT 1');
            $this->assertCaptureFails($connection,'native allocations differ');
            $pdo->rollBack();
            $pdo->beginTransaction();
            $pdo->exec('UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id SET l.amount=l.amount+1 WHERE a.account_code="521.071"');
            $this->assertCaptureFails($connection,'native journal differs');
            $pdo->rollBack();
            $pdo->beginTransaction();
            $pdo->exec('UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id JOIN chart_of_accounts p ON p.supplier_id=a.supplier_id AND p.id=a.parent_id SET l.account_id=p.id WHERE a.account_code="521.071"');
            $this->assertCaptureFails($connection,'native journal differs');
            $pdo->rollBack();
            $pdo->beginTransaction();
            self::assertGreaterThan(0,(int)$pdo->query('SELECT COUNT(*) FROM journal_entry_line_dimensions')->fetchColumn());
            $pdo->exec('DELETE d FROM journal_entry_line_dimensions d JOIN journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id ON l.id=d.line_id WHERE a.account_code="521.071"');
            $this->assertCaptureFails($connection,'native journal differs');
            $pdo->rollBack();
            $pdo->beginTransaction();
            $pdo->exec('UPDATE journal_entries e JOIN journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id ON l.entry_id=e.id SET e.source_type="manual" WHERE a.account_code="521.071"');
            $this->assertCaptureFails($connection,'native journal header differs');
            $pdo->rollBack();
            $pdo->beginTransaction();
            $pdo->exec('UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id SET l.currency_code="EUR",l.fx_rate=25,l.amount_foreign=1 WHERE a.account_code="521.071"');
            $this->assertCaptureFails($connection,'native journal currency or storno differs');
            $pdo->rollBack();
        } finally {
            if($connection->pdo()->inTransaction())$connection->pdo()->rollBack();
            $connection->close();
            CloneSandbox::release($server,$proof);
        }
    }

    private function assertCaptureFails(Connection $connection,string $message): void
    {
        try {
            PayrollCostCenterCloneScrubber::capture($connection);
            self::fail('Payroll clone accepted a mutated native proof.');
        } catch(\DomainException $error) {
            self::assertStringContainsString($message,$error->getMessage());
        }
    }

    private function selectDatabase(string $database): void
    {
        putenv('MYINVOICE_DB_NAME='.$database);
        $_ENV['MYINVOICE_DB_NAME']=$database;
        $_SERVER['MYINVOICE_DB_NAME']=$database;
    }

    private function attachClonedRun(): void
    {
        $this->container=Bootstrap::buildContainer();
        $this->db=$this->container->get(Connection::class);
        $this->runs=new PayrollRunCommandService($this->db,$this->container->get(PayrollRunRepository::class),$this->container->get(PayrollRunSnapshotBuilder::class),$this->container->get(PayrollRunCalculationPipeline::class),$this->container->get(PayrollRunWorkflow::class),$this->container->get(PayrollPeriodOwnershipService::class),$this->container->get(PayrollApprovedRevisionPostingService::class));
    }

    private function assertFrozenHashes(): void
    {
        $checks=$this->container->get(\MyInvoice\Service\Payroll\PayrollInvariantService::class)->checkAll();
        $hashCheck=array_values(array_filter($checks,static fn(array $check):bool=>$check['code']==='M8'));
        self::assertCount(1,$hashCheck);
        self::assertTrue($hashCheck[0]['checked']);
        self::assertSame([],$hashCheck[0]['violations']);
    }
    private function financialFacts(PDO $pdo): array
    {
        return ['lines'=>$pdo->query('SELECT account_id,side,SUM(amount) AS amount FROM journal_entry_lines GROUP BY account_id,side ORDER BY account_id,side')->fetchAll(PDO::FETCH_ASSOC),
            'dimensions'=>$pdo->query('SELECT supplier_id,line_id,dimension_type_id,dimension_value_id FROM journal_entry_line_dimensions ORDER BY supplier_id,line_id,dimension_type_id')->fetchAll(PDO::FETCH_ASSOC),
            'splits'=>$pdo->query('SELECT supplier_id,line_id,dimension_type_id,dimension_value_id,share FROM journal_entry_line_dimension_splits ORDER BY supplier_id,line_id,dimension_type_id,dimension_value_id')->fetchAll(PDO::FETCH_ASSOC)];
    }
}