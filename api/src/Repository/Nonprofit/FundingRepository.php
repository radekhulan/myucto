<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Nonprofit;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Nonprofit\Funding\FundingPolicy;
use MyInvoice\Service\Nonprofit\Funding\FundingRevisionShape;
use PDO;
final class FundingRepository
{
    public const TABLES = ['nonprofit_funding_revisions'];
    public function __construct(private readonly Connection $db) {}
    public function all(int $supplier, bool $lock = false): array
    {
        if ($lock && !$this->db->pdo()->inTransaction()) throw new \LogicException('Funding lock requires a transaction.');
        $s = $this->db->pdo()->prepare('SELECT '.FundingRevisionShape::selectColumns().' FROM nonprofit_funding_revisions WHERE supplier_id = ? ORDER BY source_key,revision_no'.($lock?' FOR UPDATE':''));
        $s->execute([$supplier]);
        $out = []; $previous = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['source_key'];
            $row = self::verifiedRevision($row, $previous[$key] ?? null);
            $previous[$key] = $row; $out[]=$row;
        }
        return $out;
    }
    public function latest(int $supplier, bool $lock = false): array
    {
        $latest=[]; foreach($this->all($supplier,$lock) as $row) $latest[$row['source_key']]=$row;
        return $latest;
    }
    public function append(int $supplier,int $period,string $key,?array $previous,array $policy,array $basis,string $reason,string $token,string $requestHash,int $actor,array $request): array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Funding append requires a transaction.');
        $row=['supplier_id'=>$supplier,'period_id'=>$period,'source_key'=>$key,'revision_no'=>($previous['revision_no']??0)+1,
            'previous_id'=>$previous['id']??null,'parent_hash'=>$previous['record_hash']??str_repeat('0',64),'provider_id'=>$policy['provider_id'],
            'assessment_id'=>$basis['assessment_id'],'policy'=>$policy,'basis'=>$basis,'reason'=>$reason,'idempotency_key'=>$token,'request_hash'=>$requestHash,'created_by'=>$actor,'request'=>$request];
        $row['record_hash']=self::recordHash($row);
        $s=$this->db->pdo()->prepare('INSERT INTO nonprofit_funding_revisions (supplier_id,period_id,source_key,revision_no,previous_id,parent_hash,provider_id,assessment_id,policy_json,basis_json,request_json,reason,idempotency_key,request_hash,record_hash,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$supplier,$period,$key,$row['revision_no'],$row['previous_id'],$row['parent_hash'],$policy['provider_id'],$basis['assessment_id'],NonprofitTaxRules::canonicalJson($policy),NonprofitTaxRules::canonicalJson($basis),NonprofitTaxRules::canonicalJson($request),$reason,$token,$requestHash,$row['record_hash'],$actor]);
        $all=$this->latest($supplier,true);
        return $all[$key];
    }
    public static function recordHash(array $row): string
    {
        return NonprofitTaxRules::hash(array_intersect_key($row,array_flip(['supplier_id','period_id','source_key','revision_no','previous_id','parent_hash','provider_id','assessment_id','policy','basis','reason','idempotency_key','request_hash','created_by','request'])));
    }
    public static function verifiedRevision(array $row, ?array $previous): array
    {
        foreach (['id','supplier_id','period_id','provider_id','revision_no','created_by','assessment_id'] as $field) $row[$field] = (int)$row[$field];
        $row['previous_id'] = $row['previous_id'] === null ? null : (int)$row['previous_id'];
        try {
            $row['policy'] = json_decode((string)($row['policy_json'] ?? ''),true,flags:JSON_THROW_ON_ERROR);
            $row['basis'] = json_decode((string)($row['basis_json'] ?? ''),true,flags:JSON_THROW_ON_ERROR);
            $row['request']=json_decode((string)($row['request_json'] ?? ''),true,flags:JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            FundingPolicy::invalid('Historie účelového zdroje není průkazná.','nonprofit_funding_integrity');
        }
        if (!is_array($row['policy']) || !is_array($row['basis']) || !is_array($row['request'])) {
            FundingPolicy::invalid('Historie účelového zdroje není průkazná.','nonprofit_funding_integrity');
        }
        FundingRevisionShape::verify($row['policy'],$row['basis'],$row['request']);
        unset($row['policy_json'],$row['basis_json'],$row['request_json']);
        if ($row['revision_no'] !== ($previous['revision_no'] ?? 0)+1 || $row['previous_id'] !== ($previous['id'] ?? null)
            || $row['parent_hash'] !== ($previous['record_hash'] ?? str_repeat('0',64)) || !hash_equals($row['record_hash'],self::recordHash($row)) || !hash_equals($row['request_hash'],NonprofitTaxRules::hash($row['request']))) FundingPolicy::invalid('Historie účelového zdroje není průkazná.','nonprofit_funding_integrity');
        return $row;
    }
}
