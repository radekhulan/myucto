<?php

declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\AccountDefinitionRepository;
use PDO;
final class AccountDefinitionCloneScrubber
{
    public static function run(Connection $db,TextScrubber $scrubber): void
    {
        $p=$db->pdo();if($p->inTransaction()||TriggerMetadata::any($p))throw new \LogicException('Account definition clone requires its own transaction before trigger installation.');
        $p->beginTransaction();try{
            $rows=$p->query('SELECT * FROM accounting_account_definition_history ORDER BY supplier_id,account_id,effective_from,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);$checked=[];$read=new AccountDefinitionRepository($db);
            foreach($rows as $row){$supplier=(int)$row['supplier_id'];if(!isset($checked[$supplier])){$read->listForDate($supplier,'9999-12-31',true);$checked[$supplier]=true;}$definition=AccountDefinitionRepository::definition($row);$definition['name']=$scrubber->scrub($definition['name']);$json=AccountDefinitionRepository::encode($definition);$hash=AccountDefinitionRepository::definitionHash($supplier,(int)$row['account_id'],$row['effective_from'],$definition);$q=$p->prepare('UPDATE accounting_account_definition_history SET name=?,definition_json=?,definition_hash=?,evidence=? WHERE supplier_id=? AND id=? AND definition_hash=?');$q->execute([$definition['name'],$json,$hash,$scrubber->scrub($row['evidence']),$supplier,$row['id'],$row['definition_hash']]);}
            foreach(array_keys($checked) as $supplier)$read->listForDate($supplier,'9999-12-31',true);$p->commit();
        }catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }
}
