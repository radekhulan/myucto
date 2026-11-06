<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Support;
use PDO;
final class AccountDefinitionSqliteFixture
{
    public static function install(PDO $pdo):void
    {
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')throw new \LogicException('Account metadata fixture requires SQLite.');
        $columns=$pdo->query('PRAGMA table_info(chart_of_accounts)')->fetchAll(PDO::FETCH_COLUMN,1);
        foreach(['supplier_id'=>'INTEGER NOT NULL DEFAULT 1','name'=>"TEXT NOT NULL DEFAULT ''",'normal_side'=>'TEXT NULL','tax_deductibility'=>"TEXT NOT NULL DEFAULT 'deductible'",'is_active'=>'INTEGER NOT NULL DEFAULT 1','parent_id'=>'INTEGER NULL','is_synthetic'=>'INTEGER NOT NULL DEFAULT 1','created_at'=>'TEXT NULL','is_clearing'=>'INTEGER NOT NULL DEFAULT 0']as$name=>$type)if(!in_array($name,$columns,true))$pdo->exec('ALTER TABLE chart_of_accounts ADD COLUMN '.$name.' '.$type);
        $pdo->exec('CREATE TABLE accounting_account_definition_history(id INTEGER PRIMARY KEY,supplier_id INTEGER NOT NULL,account_id INTEGER NOT NULL,effective_from TEXT NOT NULL,name TEXT NOT NULL,normal_side TEXT NULL,tax_deductibility TEXT NOT NULL,is_active INTEGER NOT NULL)');
    }
}
