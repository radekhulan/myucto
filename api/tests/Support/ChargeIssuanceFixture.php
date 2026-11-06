<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Service\Property\Charges\ChargePreviewService;

trait ChargeIssuanceFixture
{
    use PropertyChargeFixture;

    private static function issuanceIdentity(): array
    {
        return ['schema'=>1,'case_ref'=>'SYNTHETIC-ANNUAL','occurrence_ref'=>'2026','title_ref'=>'Synthetic annual prescription',
            'decision_ref'=>'Synthetic decision 2025/12','decided_on'=>'2025-12-01','evidence_ref'=>'Synthetic approved annual schedule'];
    }

    private function issuanceFixture(Connection $db): array
    {
        $f=$this->chargeFixture($db);
        (new AccountingPeriodRepository($db))->create($f['supplier'],2026,'2026-01-01','2026-12-31');
        (new ChartOfAccountsSeeder($db))->seedForSupplier($f['supplier'],'2026-01-01');
        $request=self::chargeRequest($f);
        $preview=(new ChargePreviewService($db))->preview($f['supplier'],$request);
        $version=(new ChargeRepository($db))->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'synthetic-version'],$f['actor']);
        $units=[];
        foreach($f['units'] as $code=>$id) {
            $groups=[];
            foreach($f['states'][$code]['ownerships'] as $ownership) $groups[]=[
                'group_key'=>$ownership['group_key'],'role'=>'debtor','mode'=>$ownership['kind']==='marital_community'?'solidary':'individual',
                'basis'=>$ownership['kind']==='marital_community'?'sjm_713':'documented_title','members'=>$ownership['members'],'extent'=>$ownership['share'],
                'evidence_ref'=>'Synthetic assessed deed','legal_reason'=>'Explicit synthetic liability assessment',
                'valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null];
            $units[]=['unit_id'=>$id,'expected_revision'=>0,'previous_charge_id'=>null,'liability_groups'=>$groups,
                'notices'=>$code==='G12'?[]:[['component_id'=>$f['components']['WATER'],'notice'=>['mode'=>'initial','title_ref'=>'Synthetic advance decision']]]];
        }
        $accounts=[];
        foreach($f['components'] as $code=>$id) $accounts[]=['component_id'=>$id,'receivable_account'=>'315','counter_account'=>$code==='LONG'?'955':($code==='ADMIN'?'324.200':'324.110'),
            'basis_ref'=>'Synthetic accounting decision','settlement_due_on'=>$code==='LONG'?'2028-12-31':null,'dimensions'=>[]];
        $request=['version_id'=>$version['id'],'source_key'=>'SYNTHETIC-ANNUAL-2026','title'=>'prescription','issue_mode'=>'initial',
            'period_from'=>'2026-01-01','period_to'=>'2026-12-31','claim_origin_at'=>'2026-01-01T00:00:00+01:00',
            'issued_at'=>'2026-01-01T10:00:00+01:00','accounting_date'=>'2026-01-01','due_date'=>'2026-01-15','reason'=>'Synthetic annual issue',
            'units'=>$units,'accounts'=>$accounts,'case_identity'=>self::issuanceIdentity()];
        return $f+['issue_request'=>$request];
    }
}
