<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\{AnonymizationOptions,AnonymizationService,DatabaseCloner};
use MyInvoice\Service\Tax\Nonprofit\{NonprofitLegalProfileService,NonprofitTaxRules};
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use PDO;
use PHPUnit\Framework\TestCase;
use MyInvoice\Tests\Support\CloneSandbox;

final class NonprofitDppoCloneIntegrationTest extends TestCase
{
    public function testFullCloneRebasesLegalProfileBeforeCurrentJ6Receipt():void
    {
        $this->withOwnedSource(function(Config $config,string $source,string $target,PDO $server):void{
            $fixture=new NonprofitDppoDataProviderTest('testCanonicalJ6AndNativeStatementsUseTrueFiscalInterval');(new \ReflectionMethod($fixture,'setUp'))->invoke($fixture);
            $db=(new \ReflectionProperty($fixture,'db'))->getValue($fixture);$supplier=(new \ReflectionProperty($fixture,'supplier'))->getValue($fixture);$period=(new \ReflectionProperty($fixture,'period'))->getValue($fixture);
            $actor=(new \ReflectionProperty($fixture,'actor'))->getValue($fixture);(new NonprofitLegalProfileService($db))->confirm($supplier,$period,['expected_revision'=>0,'idempotency_key'=>'clone-legal','reason'=>'Synthetic reason dppo-private@example.test','profile'=>['kind'=>'narrow_vpp','entity_type'=>'association','main_activity_nonbusiness'=>true,'legal_basis'=>'zdp_17a','evidence'=>'Synthetic statutes dppo-private@example.test']],$actor);(new \ReflectionMethod($fixture,'post'))->invoke($fixture,'602','800000.00');$before=(new \ReflectionMethod($fixture,'confirm'))->invoke($fixture);
            $legal=(new NonprofitLegalProfileService($db))->snapshotProof($supplier,$period);$db->pdo()->commit();
            try{
                $target=CloneSandbox::anonymized($server,$config,$source,'synthetic-dppo-profile-j6');
                $settings=$config->all();$settings['db']['name']=$target;$cloned=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($settings,$config->dataDir())));
                try{$proof=(new NonprofitLegalProfileService($cloned))->snapshotProof($supplier,$period);self::assertNotSame($legal['record_hash'],$proof['record_hash']);self::assertSame($legal['id'],$proof['id']);self::assertStringNotContainsString('dppo-private@example.test',NonprofitTaxRules::canonicalJson($proof));
                    $container=Bootstrap::buildContainer();$container->set(Connection::class,$cloned);$row=(new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($cloned))->transaction(function()use($cloned,$container,$supplier,$period):array{(new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($cloned))->lockPeriods($supplier);$row=$container->get(\MyInvoice\Repository\NonprofitTaxAssessmentRepository::class)->latest($supplier,$period,true);$container->get(\MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity::class)->assertCurrentReceipt($supplier,$row);return$row;});
                    self::assertSame($proof['record_hash'],$row['snapshot']['legal_profile']['record_hash']);self::assertSame($before['result']['accounting_result_cents'],$row['result']['accounting_result_cents']);self::assertSame($before['id'],$row['id']);self::assertStringNotContainsString('dppo-private@example.test',NonprofitTaxRules::canonicalJson($row));
                }finally{$cloned->close();}
                self::assertSame($legal,(new NonprofitLegalProfileService($db))->snapshotProof($supplier,$period));
            }finally{(new \ReflectionMethod($fixture,'tearDown'))->invoke($fixture);}
        });
    }
    public static function unsupportedGraphs():array
    {
        return [
            ['command','Anonymizace evidence daňových úspor DPPO 504'],
            ['orphan_binding','Anonymizace evidence daňových úspor DPPO 504'],
            ['drifted_copy','kopie XML v daňovém podání se liší od archivu'],
        ];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedGraphs')]
    public function testFinancialArchiveCloneFailsClosedBeforeTextMutationAndPreservesSource(string $graph,string $expected):void
    {
        $this->withOwnedSource(function(Config $config,string $source,string $target,PDO $server)use($graph,$expected):void{
            $fixture=new NonprofitDppoStoredArchiveGuardTest('testPublicTaxReturnRemainsClosedWithValidSavedArchive');(new \ReflectionMethod($fixture,'setUp'))->invoke($fixture);
            $db=(new \ReflectionProperty($fixture,'db'))->getValue($fixture);if($graph==='command'){$supplier=(new \ReflectionProperty($fixture,'supplier'))->getValue($fixture);$actor=(new \ReflectionProperty($fixture,'actor'))->getValue($fixture);$request=['operation'=>'use','source_id'=>999999,'evidence'=>'Synthetic pending-command-private@example.test'];$db->pdo()->prepare('INSERT INTO nonprofit_tax_saving_commands(supplier_id,idempotency_key,request_json,request_hash,created_by)VALUES(?,?,?,?,?)')->execute([$supplier,'synthetic-pending',NonprofitTaxRules::canonicalJson($request),NonprofitTaxRules::hash(['request'=>$request,'idempotency_key'=>'synthetic-pending','actor'=>$actor]),$actor]);}elseif($graph==='orphan_binding'){$supplier=(new \ReflectionProperty($fixture,'supplier'))->getValue($fixture);$db->pdo()->exec('SET SESSION FOREIGN_KEY_CHECKS=0');try{$db->pdo()->prepare('INSERT INTO nonprofit_tax_saving_cost_bindings(supplier_id,source_id,event_id,cost_line_id)VALUES(?,999999,999999,999999)')->execute([$supplier]);}finally{$db->pdo()->exec('SET SESSION FOREIGN_KEY_CHECKS=1');}}else{
                (new \ReflectionMethod($fixture,'savingFixture'))->invoke($fixture);
                // Kopie podání se stejným otiskem jako archiv, ale jinými bajty: zachycení ji nesmí přepsat naslepo.
                $supplier=(new \ReflectionProperty($fixture,'supplier'))->getValue($fixture);
                $archive=$db->pdo()->query('SELECT xml_content,xml_sha256 FROM income_tax_return_snapshots WHERE supplier_id='.(int)$supplier.' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
                $copy=(new \MyInvoice\Repository\TaxSubmissionRepository($db))->archive($supplier,'dppdp9',2026,null,null,$archive['xml_content'],[],'passed',[],null);
                $db->pdo()->prepare('UPDATE tax_submissions SET xml_content=CONCAT(xml_content,?) WHERE id=?')->execute(["\n<!-- drift -->",$copy]);
                $db->pdo()->prepare('UPDATE tax_submissions SET xml_sha256=? WHERE id=?')->execute([$archive['xml_sha256'],$copy]);
            }
            $db->pdo()->commit();
            $query=match($graph){'command'=>'SELECT * FROM nonprofit_tax_saving_commands ORDER BY id','orphan_binding'=>'SELECT * FROM nonprofit_tax_saving_cost_bindings ORDER BY event_id',default=>'SELECT s.id,s.snapshot_json,s.source_manifest,s.xml_content,t.xml_content AS copy_xml FROM income_tax_return_snapshots s JOIN tax_submissions t ON t.supplier_id=s.supplier_id AND t.xml_sha256=s.xml_sha256 ORDER BY s.id,t.id'};$before=$db->pdo()->query($query)->fetchAll(PDO::FETCH_ASSOC);self::assertNotEmpty($before);
            try{$target=CloneSandbox::acquire($server,$source);try{(new AnonymizationService($config))->anonymizeCopy(new AnonymizationOptions($source,$target,seed:'synthetic-dppo-archive-rejection'),static function():void{});self::fail('Unsupported financial archive was anonymized');}catch(\RuntimeException $e){self::assertStringContainsString($expected,$e->getMessage());}
                self::assertSame($before,$db->pdo()->query($query)->fetchAll(PDO::FETCH_ASSOC));$server->exec('USE '.DatabaseCloner::quote($target));self::assertSame($before,$server->query($query)->fetchAll(PDO::FETCH_ASSOC),'Kopie se před odmítnutím nesmí přepsat.');
            }finally{(new \ReflectionMethod($fixture,'tearDown'))->invoke($fixture);}
        },$graph!=='orphan_binding');
    }
    private function withOwnedSource(callable $test,bool $installTriggers=true):void
    {
        $base=Config::load(Bootstrap::rootDir());$previous=(string)getenv('MYINVOICE_DB_NAME');self::assertStringEndsWith('_test',$previous);self::assertContains($base->get('db.host'),['localhost','127.0.0.1','::1']);
        $prefix='myucto_dppo_lifecycle_'.bin2hex(random_bytes(5));$source=$prefix.'_source_test';$target=$prefix.'_target_test';$server=new PDO('mysql:host='.$base->get('db.host').';port='.$base->get('db.port',3306).';charset=utf8mb4',$base->get('db.user'),$base->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        try{$source=CloneSandbox::acquire($server,$previous,$installTriggers);$this->select($source);$config=Config::load(Bootstrap::rootDir());$test($config,$source,$target,$server);
        }finally{$this->select($previous);CloneSandbox::releaseAll($server);}
    }
    private function select(string $database):void
    {putenv('MYINVOICE_DB_NAME='.$database);$_ENV['MYINVOICE_DB_NAME']=$database;$_SERVER['MYINVOICE_DB_NAME']=$database;}
}
