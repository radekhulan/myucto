<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Service\Property\Charges\ChargeCalculator;

trait PropertyChargeFixture
{
    use IsolatedSupplierTrait;
    private function chargeFixture(Connection $db): array
    {
        $pdo=$db->pdo(); $supplier=$this->createIsolatedSupplier($pdo,(int)$pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $actor=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'1900-01-01','cz_504')")->execute([$supplier]);
        (new PropertyProfileRepository($db))->save($supplier,['expected_revision'=>0,'effective_from'=>'2026-01-01T00:00:00+01:00','enabled'=>true,'reason'=>'Synthetic SVJ','title_ref'=>'Synthetic deed','idempotency_key'=>'profile'],$actor);
        $country=(int)$pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $currency=(int)$pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $clients=[];
        foreach(range(1,18) as $n) {
            $pdo->prepare('INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$supplier,'Synthetic '.$n,'Synthetic','Synthetic','10000',$country,'charge'.$n.'@example.test',$currency]);
            $clients[$n]=(int)$pdo->lastInsertId();
        }
        $registry=new PropertyRegistryRepository($db);
        $house=$registry->create($supplier,'house',self::registryRequest('LIP',['name'=>'Dům U Lip','active'=>true,'address'=>['street'=>'Synthetic 12','city'=>'Synthetic','postal_code'=>'10000','country'=>'CZ'],'declaration_ref'=>'Synthetic deed','share_scope'=>'LIP','registry_complete'=>true]),$actor);
        $service=$registry->create($supplier,'service',self::registryRequest('WATER',['name'=>'Synthetic water','active'=>true,'measure'=>'m3','measure_label'=>'']),$actor);
        $units=[]; $states=[];
        foreach(require dirname(__DIR__).'/Fixtures/property-house-at-limes.php' as $code=>$state) {
            foreach($state['ownerships'] as &$group) $group['members']=array_map(fn($n)=>$clients[$n],$group['members']); unset($group);
            foreach($state['roles'] as &$role) $role['client_id']=$clients[$role['client_id']]; unset($role);
            $state['services']=$state['services']===[]?[]:[$service['id']];
            $state['attributes']['floor_area']=['num'=>'60','den'=>'1'];
            $state['attributes']['persons']=['num'=>$code==='G12'?'0':'2','den'=>'1'];
            $states[$code]=$state;
            $units[$code]=$registry->create($supplier,'unit',self::registryRequest($code,$state)+['parent_id'=>$house['id']],$actor)['id'];
        }
        $repo=new ChargeRepository($db); $components=[];
        foreach(['LONG'=>'long_term_advance','ADMIN'=>'admin_advance','WATER'=>'service_advance'] as $code=>$kind) $components[$code]=$repo->createComponent($supplier,
            ['code'=>$code,'name'=>'Synthetic '.$code,'kind'=>$kind,'service_id'=>$code==='WATER'?$service['id']:null,'effective_at'=>'2026-01-01T00:00:00+01:00','account_code'=>'','idempotency_key'=>$code],$actor)['id'];
        return compact('supplier','actor','house','service','units','states','clients','components');
    }
    private static function registryRequest(string $code,array $state): array
    {
        return ['code'=>$code,'expected_revision'=>0,'effective_from'=>'2026-01-01T00:00:00+01:00','status'=>'confirmed','state'=>$state,'reason'=>'Synthetic registry','title_ref'=>'Synthetic deed','idempotency_key'=>$code];
    }
    private static function chargeRequest(array $f): array
    {
        $all=array_values($f['units']); sort($all,SORT_NUMERIC); $water=array_values(array_diff($all,[$f['units']['G12']]));
        $rates=[];
        foreach([['LONG','share','120000','total_budget','management_share',$all],['ADMIN','equal','1000','rate_per_basis','own_administration_equal',$all],['WATER','unit','12000','rate_per_basis','service_decision',$water]] as [$code,$method,$amount,$mode,$legal,$ids])
            $rates[]=['component_id'=>$f['components'][$code],'unit_ids'=>$ids,'method'=>$method,'amount'=>$amount,'mode'=>$mode,'rounding'=>ChargeCalculator::ROUNDING,'legal_basis'=>$legal,'scope_ref'=>'Synthetic decision '.$code,'correction'=>false,'correction_reason'=>''];
        return ['house_id'=>$f['house']['id'],'expected_revision'=>0,'effective_from'=>'2026-01-01T00:00:00+01:00','effective_to'=>null,'basis_at'=>'2026-01-01T00:00:00+01:00',
            'frequency'=>'annual','basis_policy'=>'fixed_registry_snapshot','period_policy'=>'no_automatic_proration','decision_ref'=>'Synthetic decision 2026','decided_at'=>'2025-12-15T18:00:00+01:00','reason'=>'Synthetic annual schedule','rates'=>$rates];
    }
}
