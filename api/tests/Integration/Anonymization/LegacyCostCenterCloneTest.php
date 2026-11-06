<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Anonymization;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Anonymization\{DatabaseCloner,LegacyCostCenterCloneScrubber,Pseudonymizer,TextScrubber};
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use PDO;
use PHPUnit\Framework\TestCase;
final class LegacyCostCenterCloneTest extends TestCase
{
    public function testAllLegacyReferencesKeepOneIdentityAndFinancialFacts():void
    {
        $config=Config::load(Bootstrap::rootDir());self::assertStringEndsWith('_test',$config->get('db.name'));self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);$pdo=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$schema='myucto_costcenter_'.bin2hex(random_bytes(5)).'_test';$pdo->exec('CREATE DATABASE '.DatabaseCloner::quote($schema));
        try{
            $pdo->exec('USE '.DatabaseCloner::quote($schema));$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$original='synthetic.context@example.test';$targets=['cost_centers'=>'code','journal_entry_lines'=>'cost_center','journal_entry_template_lines'=>'cost_center','payroll_posting_allocations'=>'cost_center','payroll_dimensions'=>'code'];
            foreach($targets as $table=>$column){$extra=$table==='payroll_dimensions'?",dimension_type VARCHAR(30) NOT NULL DEFAULT 'cost_center'":'';$pdo->exec('CREATE TABLE '.$table.' (id INT PRIMARY KEY,'.$column." VARCHAR(50) NULL,amount DECIMAL(12,2) NOT NULL DEFAULT 132000.00,updated_at TIMESTAMP NOT NULL DEFAULT '2026-01-01 12:00:00' ON UPDATE CURRENT_TIMESTAMP".$extra.')');$insert=$pdo->prepare('INSERT INTO '.$table.' (id,'.$column.') VALUES (?,?)');$insert->execute([17,$original]);$insert->execute([18,'MZ-EX-123']);$insert->execute([19,null]);}
            $pdo->exec("INSERT INTO payroll_dimensions (id,code,dimension_type) VALUES (20,'SYNTHETIC-PROJECT','project')");$scrubber=new TextScrubber(new Pseudonymizer('synthetic-cost-center-key-123'));$code=$scrubber->byStrategy('cost_center',$original);LegacyCostCenterCloneScrubber::run($pdo,$scrubber);$first=[];
            foreach($targets as $table=>$column){$rows=$pdo->query('SELECT id,'.$column.' AS code,amount,updated_at FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);self::assertSame($code,$rows[0]['code']);self::assertSame('MZ-EX-123',$rows[1]['code']);self::assertNull($rows[2]['code']);self::assertSame('132000.00',$rows[0]['amount']);self::assertSame('2026-01-01 12:00:00',$rows[0]['updated_at']);self::assertSame(17,$rows[0]['id']);$first[$table]=$rows;}
            self::assertSame('SYNTHETIC-PROJECT',$first['payroll_dimensions'][3]['code']);LegacyCostCenterCloneScrubber::run($pdo,$scrubber);foreach($targets as $table=>$column)self::assertSame($first[$table],$pdo->query('SELECT id,'.$column.' AS code,amount,updated_at FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        }finally{OwnedTestDatabaseCleanup::drop($pdo,$schema,[$schema]);}
    }
}
