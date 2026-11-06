<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyResetCleanup;
use MyInvoice\Service\System\GlobalSeedTables;
final class NonprofitDppoResetGuardTest extends TestCase
{
    private const TABLES=['nonprofit_legal_profiles','nonprofit_tax_saving_sources','nonprofit_tax_saving_commands','nonprofit_tax_saving_events','nonprofit_tax_saving_cost_bindings'];
    public function testDppoTablesAreExplicitBusinessWipes():void
    {
        foreach(self::TABLES as $table)self::assertContains($table,GlobalSeedTables::RESET_KEEP_USERS_SUPPLIER_WIPES);
    }
    public static function survivors():array{return array_map(static fn(string $table):array=>[$table],self::TABLES);}
    #[\PHPUnit\Framework\Attributes\DataProvider('survivors')]
    public function testPartialResetSurvivorPreventsDimensionCleanup(string $survivor):void
    {
        $db=Bootstrap::buildContainer()->get(Connection::class);$pdo=$db->pdo();$configured=(string)getenv('MYINVOICE_DB_NAME');self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+_test$/D',$configured);self::assertLessThanOrEqual(64,strlen($configured));self::assertSame($configured,(string)$pdo->query('SELECT DATABASE()')->fetchColumn());self::assertSame($pdo,Bootstrap::buildContainer()->get(Connection::class)->pdo());self::assertFalse($pdo->inTransaction());
        $tables=[...PropertyResetCleanup::TABLES,'journal_entries','joint_allocation_batches','dimension_types','dimension_values','joint_allocation_key_versions','joint_allocation_keys','dimension_account_rules','dimension_account_map','payroll_dimensions',...self::TABLES];
        try{
            foreach(array_unique($tables) as $table){$columns=match($table){'joint_allocation_key_versions'=>'id BIGINT,key_id BIGINT','dimension_types'=>'id BIGINT,system_key VARCHAR(32)','dimension_values'=>'id BIGINT,type_id BIGINT,parent_id BIGINT','dimension_account_rules','dimension_account_map'=>'dimension_type_id BIGINT','payroll_dimensions'=>'dimension_value_id BIGINT,row_version INT',default=>'id BIGINT'};$pdo->exec('CREATE TEMPORARY TABLE '.$table.' ('.$columns.') ENGINE=InnoDB');}
            $pdo->exec("INSERT INTO dimension_types VALUES (1,'unit')");$pdo->exec('INSERT INTO dimension_values VALUES (2,1,NULL)');$pdo->exec('INSERT INTO '.$survivor.' VALUES (1)');$plan=PropertyResetCleanup::plan($pdo);
            try{PropertyResetCleanup::apply($pdo,$plan);self::fail('Partial reset removed dimensions while a tax-saving survivor remained');}catch(RuntimeException $e){self::assertSame('Reset registru SVJ nebyl dokončen. Související dimenze zůstaly zachované.',$e->getMessage());}
            self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM dimension_types')->fetchColumn());self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM dimension_values')->fetchColumn());
        }finally{if($pdo->inTransaction())$pdo->rollBack();foreach(array_unique($tables) as $table)$pdo->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);}
    }
}
