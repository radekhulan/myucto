<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository,TaxReturnRepository};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Tax\Return\{TaxReturnException,TaxReturnService};
use PDO;

final class NonprofitDppoFilingGate
{
    public function __construct(private readonly Connection $db) {}

    public function submission(int $supplier,array $submission,bool $requireCurrent):array
    {
        return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$submission,$requireCurrent):array{
            (new PaymentLocks($this->db))->acquire($supplier);$xml=$submission['xml_content']??null;if(!is_string($xml)||$xml==='')$this->invalid();
            $q=$this->db->pdo()->prepare('SELECT * FROM income_tax_return_snapshots WHERE supplier_id=? AND xml_sha256=? ORDER BY id DESC FOR UPDATE');$q->execute([$supplier,hash('sha256',$xml)]);$stored=$q->fetch(PDO::FETCH_ASSOC);
            if(!$stored||$stored['business_status']!=='passed'||$stored['xml_content']!==$xml||!hash_equals($stored['source_sha256'],hash('sha256',$stored['source_manifest'])))$this->invalid();
            $snapshot=json_decode($stored['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$manifest=json_decode($stored['source_manifest'],true,flags:JSON_THROW_ON_ERROR);$proof=NonprofitDppoStoredProof::verify($snapshot,$manifest,$xml);
            $period=(new AccountingPeriodRepository($this->db))->findById($supplier,(int)$proof['period']['id']);if($period===null||$period['starts_on']!==$proof['period']['starts_on']||$period['ends_on']!==$proof['period']['ends_on']||(isset($submission['period_year'])&&(int)$submission['period_year']!==(int)$period['fiscal_year']))$this->invalid();
            if($requireCurrent){
                $returns=new TaxReturnRepository($this->db);$effective=$returns->findLastFinalized($supplier,(int)$period['fiscal_year'],'po');
                if($effective===null||(int)$effective['final_snapshot_id']!==(int)$stored['id'])throw new TaxReturnException('nonprofit_dppo_filing_snapshot_stale','K podání vyberte aktuální finální přiznání příslušného období.',409);
                $c=Bootstrap::buildContainer();$c->set(Connection::class,$this->db);$inputs=(array)$effective['inputs'];$current=$c->get(TaxReturnService::class)->currentNonprofitProof($supplier,(int)$period['fiscal_year'],$inputs);
                if(!hash_equals($proof['comparison_hash'],NonprofitDppoStoredProof::comparisonHash($current,$proof['comparison_profile'])))throw new TaxReturnException('nonprofit_dppo_filing_sources_changed','Účetní nebo právní podklady se po finalizaci změnily. Zkontrolujte a znovu potvrďte přiznání.',409);
            }
            return$proof+['snapshot_id'=>(int)$stored['id']];
        });
    }

    private function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_filing_proof_required','Podání 504 vyžaduje úplný nativní finální snapshot se shodným XML a účetním obdobím.',422);}
}
