<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\{AnonymizationPolicy,ServiceLifecycleCloneScrubber};
use MyInvoice\Service\Property\PropertyResetCleanup;
use MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard;
use MyInvoice\Service\System\GlobalSeedTables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServiceLifecycleResetTest extends TestCase
{
    public static function survivors(): array
    {
        $out = [];
        foreach ([...ServiceLifecycleCloneScrubber::TABLES,ServiceLifecycleCloneScrubber::COMMANDS] as $table) $out[$table] = [$table];
        return $out;
    }

    public function testLifecycleTablesAreBusinessWipesAndCommandsHaveExplicitCloneInvalidation(): void
    {
        foreach ([...ServiceLifecycleCloneScrubber::TABLES,ServiceLifecycleCloneScrubber::COMMANDS] as $table) self::assertContains($table,GlobalSeedTables::RESET_KEEP_USERS_SUPPLIER_WIPES);
        self::assertArrayHasKey(ServiceLifecycleCloneScrubber::COMMANDS,AnonymizationPolicy::TRUNCATE);
        foreach (ServiceLifecycleCloneScrubber::TABLES as $table) self::assertArrayHasKey($table,AnonymizationPolicy::COLUMNS);
    }

    #[DataProvider('survivors')]
    public function testEveryLifecycleSurvivorPreventsDimensionDeletion(string $survivor): void
    {
        $db = Connection::withoutSharedTestConnection(fn()=>Bootstrap::buildContainer()->get(Connection::class));
        $pdo = $db->pdo();
        self::assertStringEndsWith('_test',(string) getenv('MYINVOICE_DB_NAME'));
        self::assertSame(getenv('MYINVOICE_DB_NAME'),$pdo->query('SELECT DATABASE()')->fetchColumn());
        $tables = array_unique([...PropertyResetCleanup::TABLES,...SettlementUseGuard::TABLES,...ServiceLifecycleCloneScrubber::TABLES,ServiceLifecycleCloneScrubber::COMMANDS,'journal_entries','joint_allocation_batches','dimension_types','dimension_values','joint_allocation_key_versions','joint_allocation_keys','dimension_account_rules','dimension_account_map','payroll_dimensions']);
        try {
            foreach ($tables as $table) {
                $columns = match ($table) {'dimension_types'=>'id BIGINT,system_key VARCHAR(32)','dimension_values'=>'id BIGINT,type_id BIGINT,parent_id BIGINT','joint_allocation_key_versions'=>'id BIGINT,key_id BIGINT','dimension_account_rules','dimension_account_map'=>'dimension_type_id BIGINT','payroll_dimensions'=>'dimension_value_id BIGINT,row_version INT',default=>'id BIGINT'};
                $pdo->exec('CREATE TEMPORARY TABLE '.$table.' ('.$columns.') ENGINE=InnoDB');
            }
            $pdo->exec("INSERT INTO dimension_types VALUES(1,'unit')");
            $pdo->exec('INSERT INTO dimension_values VALUES(2,1,NULL)');
            $pdo->exec('INSERT INTO '.$survivor.' VALUES(1)');
            $plan = PropertyResetCleanup::plan($pdo);
            try {PropertyResetCleanup::apply($pdo,$plan);self::fail('Lifecycle survivor was orphaned by dimension cleanup.');}
            catch (\RuntimeException $error) {self::assertSame('Reset registru SVJ nebyl dokončen. Související dimenze zůstaly zachované.',$error->getMessage());}
            self::assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM dimension_types')->fetchColumn());
            self::assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM dimension_values')->fetchColumn());
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($tables as $table) $pdo->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);
            $db->close();
        }
    }
}
