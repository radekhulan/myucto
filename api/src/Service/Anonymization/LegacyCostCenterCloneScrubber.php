<?php
declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use PDO;
final class LegacyCostCenterCloneScrubber
{
    private const TARGETS=[['cost_centers','code',null],['journal_entry_lines','cost_center',null],['journal_entry_template_lines','cost_center',null],['payroll_posting_allocations','cost_center',null],['payroll_dimensions','code',"dimension_type='cost_center'"]];
    public static function run(PDO $pdo,TextScrubber $scrubber):void
    {
        if($pdo->inTransaction()||TriggerMetadata::any($pdo))throw new \LogicException('Legacy cost center clone requires its own transaction before trigger installation.');
        $pdo->beginTransaction();try{
            foreach(self::TARGETS as [$table,$column,$filter]){
                $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$columns=$q->fetchAll(PDO::FETCH_COLUMN);if(!in_array($column,$columns,true))continue;
                $where=$column.' IS NOT NULL'.($filter===null?'':' AND '.$filter);$rows=$pdo->query('SELECT id,'.$column.' AS value FROM '.$table.' WHERE '.$where.' ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);$keep=in_array('updated_at',$columns,true)?',updated_at=updated_at':'';$update=$pdo->prepare('UPDATE '.$table.' SET '.$column.'=?'.$keep.' WHERE id=?');
                foreach($rows as $row){$value=$scrubber->byStrategy('cost_center',$row['value']);if($value===$row['value'])continue;if(mb_strlen($value,'UTF-8')>50)throw new \LogicException('Anonymized legacy cost center exceeds the shared identity limit.');$update->execute([$value,$row['id']]);}
            }
            $pdo->commit();
        }catch(\Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }
}
