<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\{DatabaseCloner,NonprofitDppoCloneScrubber,Pseudonymizer,TextScrubber};
use MyInvoice\Service\Tax\Nonprofit\{NonprofitLegalProfileService,NonprofitTaxRules};
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use PDO;
use PHPUnit\Framework\TestCase;

final class NonprofitDppoProfileCloneTest extends TestCase
{
    public function testActualImmutableProfileHistoryRemainsCanonicalAfterClonePrivacyRewrite():void
    {
        $fixture=new NonprofitLegalProfileTest('testNativeFiscalPeriodRevisionHashAndExactRetry');(new \ReflectionMethod($fixture,'setUp'))->invoke($fixture);
        $target=null;$server=null;$schema='myucto_dppo_profile_clone_'.bin2hex(random_bytes(5)).'_test';
        try{
            $db=(new \ReflectionProperty($fixture,'db'))->getValue($fixture);$supplier=(new \ReflectionProperty($fixture,'supplier'))->getValue($fixture);$period=(new \ReflectionProperty($fixture,'period'))->getValue($fixture);$actor=(new \ReflectionProperty($fixture,'actor'))->getValue($fixture);$legal=new NonprofitLegalProfileService($db);
            foreach([0,1]as$revision){$input=(new \ReflectionMethod($fixture,'input'))->invoke($fixture,'clone-profile-'.$revision,$revision);$input['profile']['evidence']='Synthetic profile-private@synthetic.invalid';$input['reason']='Synthetic reason-private@synthetic.invalid';$legal->confirm($supplier,$period,$input,$actor);}
            $history=$legal->history($supplier,$period);$query=$db->pdo()->prepare('SELECT * FROM nonprofit_legal_profiles WHERE supplier_id=? ORDER BY revision_no');$query->execute([$supplier]);$raw=$query->fetchAll(PDO::FETCH_ASSOC);
            $config=Config::load(Bootstrap::rootDir());self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);$source=(string)$db->pdo()->query('SELECT DATABASE()')->fetchColumn();self::assertStringEndsWith('_test',$source);
            $server=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $server->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $cloner=new DatabaseCloner($server);$plan=$cloner->plan($source);$plan['tables']=array_intersect_key($plan['tables'],['nonprofit_legal_profiles'=>true]);$cloner->copyTables($plan,$schema,static function():void{});
            $settings=$config->all();$settings['db']['name']=$schema;$target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($settings,$config->dataDir())));$target->pdo()->exec('SET SESSION FOREIGN_KEY_CHECKS=0');
            foreach($raw as$row){$columns=array_keys($row);$target->pdo()->prepare('INSERT INTO nonprofit_legal_profiles ('.implode(',',array_map(DatabaseCloner::quote(...),$columns)).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));}
            $hashes=NonprofitDppoCloneScrubber::runProfiles($target,new TextScrubber(new Pseudonymizer('synthetic-profile-clone')),['database'=>$schema,'profiles'=>$history]);
            $actual=(new NonprofitLegalProfileService($target))->history($supplier,$period);self::assertCount(2,$actual);self::assertStringNotContainsString('profile-private@synthetic.invalid',NonprofitTaxRules::canonicalJson($actual));self::assertStringNotContainsString('reason-private@synthetic.invalid',NonprofitTaxRules::canonicalJson($actual));
            self::assertSame($actual[1]['record_hash'],$hashes[$history[1]['record_hash']]);self::assertSame($history[1]['profile']['kind'],$actual[1]['profile']['kind']);self::assertSame($history[1]['profile']['reduction_band'],$actual[1]['profile']['reduction_band']);self::assertSame($history,$legal->history($supplier,$period));
        }finally{if($target!==null)$target->close();if($server!==null)OwnedTestDatabaseCleanup::drop($server,$schema,[$schema]);(new \ReflectionMethod($fixture,'tearDown'))->invoke($fixture);}
    }
}
