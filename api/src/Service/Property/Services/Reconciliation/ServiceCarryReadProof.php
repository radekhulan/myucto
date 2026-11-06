<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Reconciliation;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClosingCarryReadRepository;
use MyInvoice\Service\Accounting\{AccountRoleResolver,AccountingRoleProfile,AccountingFrameworkService,AccountingPeriodStatus,JournalLineComparison};
use MyInvoice\Service\Accounting\Closing\AnnualOpeningReadProof;
use PDO;
final class ServiceCarryReadProof
{
    private array $verified=[];
    public function __construct(private readonly Connection $db){}
    public function matches(int $supplier,int $entry):bool
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Service carry read requires a stable transaction.');$key=$supplier.':'.$entry;if(array_key_exists($key,$this->verified))return $this->verified[$key];
        $q=$this->db->pdo()->prepare('SELECT * FROM journal_entries WHERE supplier_id=? AND id=?');$q->execute([$supplier,$entry]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row||$row['posted_at']===null||$row['reversed_by']!==null)return $this->verified[$key]=false;
        if($row['source_type']==='opening')return $this->verified[$key]=(new AnnualOpeningReadProof($this->db))->matches($supplier,$entry);
        if($row['source_type']!=='closing'||(int)$row['source_id']!==(int)$row['period_id'])return $this->verified[$key]=false;
        $read=new ClosingCarryReadRepository($this->db,false);$period=$read->periods($supplier)[$row['period_id']]??null;if(!$period||!AccountingPeriodStatus::isClosed($period['status'])||$row['entry_date']!==$period['ends_on'])return $this->verified[$key]=false;
        $step=$read->step($supplier,(int)$period['id'],'close_books');$payload=$step['decoded_payload']??[];$record=$payload['carry']['close_books']??[];
        if(($step['status']??null)!=='done'||($record['status']??null)!=='written'||(int)($payload['entry_id']??0)!==$entry||(int)($record['entry_id']??0)!==$entry||(int)($record['entry_version']??0)!==(int)$row['row_version']||(int)($record['target_period_id']??0)!==(int)$period['id']||($record['entry_date']??null)!==$row['entry_date'])return $this->verified[$key]=false;
        $framework=AccountRoleResolver::fromConnection($this->db)->forDate($supplier,$period['ends_on'])->framework;if($framework!==AccountingFrameworkService::CZ_504)return $this->verified[$key]=false;
        $snapshot=$read->snapshot($supplier,$period,$period,'close_books',$period['ends_on'],new AccountingRoleProfile($framework));$q=$this->db->pdo()->prepare('SELECT account_code,id FROM chart_of_accounts WHERE supplier_id=?');$q->execute([$supplier]);$accounts=$q->fetchAll(PDO::FETCH_KEY_PAIR);$expected=$snapshot['lines'];foreach($expected as &$line){$line['account_id']=(int)($accounts[$line['account_code']]??0);if($line['account_id']===0)return $this->verified[$key]=false;}unset($line);
        return $this->verified[$key]=JournalLineComparison::sameLines($expected,$read->entryLines($supplier,$entry));
    }
}
