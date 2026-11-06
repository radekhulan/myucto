<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;

final class IssuanceLocks
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Zámky kontextu SVJ firmy. Důkazy a vlastníci agendy je berou při každém dílčím čtení;
     * dokud transakce nic nezapsala ani neskončila, zamčené řádky jsou pořád její
     * a opakované zamčení by nic nezměnilo ({@see \MyInvoice\Infrastructure\Database\CurrentReadMemo}).
     */
    public function acquire(int $supplier): void
    {
        \MyInvoice\Infrastructure\Database\CurrentReadMemo::remember($this->db,'issuance-locks|'.$supplier,function()use($supplier):bool{$this->lock($supplier);return true;});
    }

    private function lock(int $supplier): void
    {
        (new \MyInvoice\Service\Property\PropertyCatalog($this->db))->lockContext($supplier);
        foreach([
            'SELECT id FROM dimension_types WHERE supplier_id=? ORDER BY id FOR UPDATE',
            'SELECT id FROM dimension_values WHERE supplier_id=? ORDER BY type_id,id FOR UPDATE',
        ] as $sql) {
            $stmt=$this->db->pdo()->prepare($sql); $stmt->execute([$supplier]); $stmt->fetchAll();
        }
        (new PropertyReadRepository($this->db))->lockObjects($supplier);
    }

    public function assertFramework(int $supplier,string $date): void
    {
        $framework=new AccountingFrameworkService($this->db,new AccountingFrameworkRepository($this->db));
        if($framework->forDateForUpdate($supplier,$date)!==AccountingFrameworkService::CZ_504)
            throw new IssuanceException('property_charge_issue_framework_required','Předpis SVJ vyžaduje k účetnímu datu rámec podle vyhlášky 504.',409);
    }

    public function assertFrameworks(int $supplier,array $dates):void
    {
        $dates=array_values(array_unique(array_map('strval',$dates)));sort($dates,SORT_STRING);
        foreach($dates as$date)$this->assertFramework($supplier,$date);
    }
}
