<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountDefinitionRepository as R;
use MyInvoice\Service\Anonymization\{AnonymizationService,AnonymizationOptions,DatabaseCloner};
use MyInvoice\Tests\Support\CloneSandbox;
use PDO;
use PHPUnit\Framework\TestCase;
final class AccountDefinitionCloneTest extends TestCase
{
    private array $owned=[];
    private PDO $server;
    private Config $config;
    private Connection $source;
    private ?Connection $target=null;
    private AccountDefinitionHistoryTest $fixture;
    private string|false $priorDatabase;
    protected function setUp(): void
    {
        $this->priorDatabase=getenv('MYINVOICE_DB_NAME');$this->config=Config::load(Bootstrap::rootDir());$base=(string)$this->config->get('db.name');
        self::assertStringEndsWith('_test',$base);self::assertContains($this->config->get('db.host'),['localhost','127.0.0.1','::1']);
        $token=bin2hex(random_bytes(5));$this->owned=['myucto_nno_metadata_clone_'.$token.'_source_test','myucto_nno_metadata_clone_'.$token.'_target_test'];
        $this->server=new PDO('mysql:host='.$this->config->get('db.host').';port='.$this->config->get('db.port',3306).';charset=utf8mb4',$this->config->get('db.user'),$this->config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $q=$this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$q->execute($this->owned);self::assertSame(0,(int)$q->fetchColumn());
        $this->owned[0]=CloneSandbox::acquire($this->server,$base,true);
        putenv('MYINVOICE_DB_NAME='.$this->owned[0]);$this->fixture=new AccountDefinitionHistoryTest('testNative500HistoryAnd504ReportsUseExplicitDatedSidesWithoutRewritingCustomAnalytics');
        Connection::withoutSharedTestConnection(fn()=>(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture));$this->source=(new \ReflectionProperty($this->fixture,'db'))->getValue($this->fixture);
    }
    protected function tearDown(): void
    {
        try{
            if(isset($this->fixture))(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);
            if(isset($this->source))$this->source->close();
            if($this->target!==null){if($this->target->pdo()->inTransaction())$this->target->pdo()->rollBack();$this->target->close();}
            if(isset($this->server))foreach(array_reverse($this->owned) as $name)CloneSandbox::dropOrRelease($this->server,$name,$this->owned);
        }finally{if(isset($this->priorDatabase))putenv($this->priorDatabase===false?'MYINVOICE_DB_NAME':'MYINVOICE_DB_NAME='.$this->priorDatabase);}
    }
    private function call(string $method,mixed ...$args): mixed{return(new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$args);}
    private function supplier(): int{return(new \ReflectionProperty($this->fixture,'supplier'))->getValue($this->fixture);}
    public function testActualFullCloneScrubsHistoricalDefinitionsAndRebasesCanonicalHashes(): void
    {
        $this->call('targetDefinitions');$ids=(new \ReflectionProperty($this->fixture,'ids'))->getValue($this->fixture);$read=new R($this->source);$read->change($this->supplier(),$ids['336'],'2027-01-01',['name'=>'Synthetic metadata-private@synthetic.invalid'],$read->stateHash($this->supplier(),$ids['336']),null,'Synthetic metadata-evidence@synthetic.invalid');$before=$read->stateHash($this->supplier(),$ids['336']);$this->source->pdo()->commit();$source=$this->fingerprint($this->source);
        $this->owned[1]=CloneSandbox::anonymized($this->server,$this->config,$this->owned[0],'synthetic-metadata-clone');self::assertSame($source,$this->fingerprint($this->source));$cfg=$this->config->all();$cfg['db']['name']=$this->owned[1];$this->target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($cfg,$this->config->dataDir())));$this->target->pdo()->beginTransaction();$after=new R($this->target);
        self::assertSame('credit',$after->findForDate($this->supplier(),$ids['336'],'2025-12-31')['normal_side']);self::assertNull($after->findForDate($this->supplier(),$ids['336'],'2026-01-01')['normal_side']);self::assertSame('credit',$after->findForDate($this->supplier(),$ids['574'],'2026-01-01')['normal_side']);self::assertSame('debit',$after->findForDate($this->supplier(),$ids['336.007'],'2026-01-01')['normal_side']);
        $text=json_encode($this->target->pdo()->query('SELECT * FROM accounting_account_definition_history ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);foreach(['metadata-private@synthetic.invalid','metadata-evidence@synthetic.invalid'] as $private)self::assertStringNotContainsString($private,$text);self::assertNotSame($before,$after->stateHash($this->supplier(),$ids['336']));
        foreach(['id','account_id','effective_from','normal_side','tax_deductibility','is_active'] as $field){$a=$this->source->pdo()->query('SELECT '.$field.' FROM accounting_account_definition_history ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);$b=$this->target->pdo()->query('SELECT '.$field.' FROM accounting_account_definition_history ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);self::assertSame($a,$b);}
    }
    private function fingerprint(Connection $db): string
    {
        $out=[];foreach(['accounting_account_definition_history','chart_of_accounts','journal_entries','journal_entry_lines'] as $table)$out[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',json_encode($out,JSON_THROW_ON_ERROR));
    }
}
