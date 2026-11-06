<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionResetCleanup;
use MyInvoice\Service\System\GlobalSeedTables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class FrameworkConversionResetTest extends TestCase
{
    private FrameworkConversionCloneTest $fixture;
    protected function setUp():void
    {$this->fixture=new FrameworkConversionCloneTest('testActualFullClonePreserves931DecisionAndCannotCancelConsumedOpening');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);}
    protected function tearDown():void
    {
        // Reset zničí obsah zapůjčené databáze sandboxu: nesmí se vrátit k dalšímu použití, úklid fixtury ji zahodí.
        foreach((new \ReflectionProperty($this->fixture,'owned'))->getValue($this->fixture) as $schema)\MyInvoice\Tests\Support\CloneSandbox::forget($schema);
        (new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);
    }
    public static function modes():array{return ['keep_configuration'=>[true],'full_reset'=>[false]];}
    #[DataProvider('modes')]
    public function testActualNativeResetClearsConversionGraphAndClassifiesItsBusinessTables(bool $keep):void
    {
        (new \ReflectionMethod($this->fixture,'prepare'))->invoke($this->fixture,true);$db=(new \ReflectionProperty($this->fixture,'source'))->getValue($this->fixture);$owned=(new \ReflectionProperty($this->fixture,'owned'))->getValue($this->fixture);$schema=$owned[0];self::assertMatchesRegularExpression('/^myucto_clonesandbox_[a-f0-9]{10}_test$/D',$schema);self::assertSame($schema,$db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        foreach(FrameworkConversionResetCleanup::TABLES as $table){self::assertContains($table,GlobalSeedTables::RESET_KEEP_USERS_SUPPLIER_WIPES);self::assertGreaterThan(0,(int)$db->pdo()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),$table);}
        $before=(int)$db->pdo()->query('SELECT COUNT(*) FROM accounting_account_definition_history')->fetchColumn();self::assertGreaterThan(0,$before);
        $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.$schema.'_reset_'.bin2hex(random_bytes(4));mkdir($dir);
        try{$env=getenv();$env['MYINVOICE_DB_NAME']=$schema;$env['MYINVOICE_DATA_DIR']=$dir;$command=[PHP_BINARY];$prepend=getenv('MYINVOICE_FRAMEWORK_CONVERSION_PREPEND');if(is_string($prepend)&&$prepend!==''){self::assertFileExists($prepend);$command[]='-d';$command[]='auto_prepend_file='.$prepend;}$command[]=Bootstrap::rootDir().'/api/bin/reset.php';$command[]='--yes';$command[]='--skip-files';if($keep)$command[]='--keep-users-supplier';$process=proc_open($command,[0=>['pipe','r'],1=>['file',$dir.'/output.log','w'],2=>['file',$dir.'/error.log','w']],$pipes,Bootstrap::rootDir(),$env);self::assertIsResource($process);fclose($pipes[0]);$exit=proc_close($process);self::assertSame(0,$exit,file_get_contents($dir.'/output.log').file_get_contents($dir.'/error.log'));
            foreach(FrameworkConversionResetCleanup::TABLES as $table)self::assertSame(0,(int)$db->pdo()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),$table);self::assertSame(0,(int)$db->pdo()->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());self::assertSame($keep?$before:0,(int)$db->pdo()->query('SELECT COUNT(*) FROM accounting_account_definition_history')->fetchColumn());
        }finally{foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($dir);}
    }
}
