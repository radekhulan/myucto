<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\People;

use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Zaměstnanci v globálním hledání (Alt+Q) napříč firmami uživatele.
 *
 * Nejdřív se levně hledá ve všech firmách, kam uživatel může patřit, a teprve
 * firmy, ve kterých se někdo našel, projdou plným rozhodnutím o přístupu
 * ({@see CrossSupplierAuthorization::allowsIn()}: členství, zámek domény,
 * role firmy a právo `payroll` ke čtení). Rozhodovat předem o každé firmě by
 * u správce s desítkami firem stálo desítky rozhodnutí na každé písmeno.
 * Z firmy, která rozhodnutím neprojde, se nevrací nic, ani počet.
 *
 * Mzdová evidence je jen pro přihlášené relace, proto API token zaměstnance
 * v hledání nedostane.
 */
final class PayrollPeopleGlobalSearch
{
    private const PREFETCH = 40;

    public function __construct(
        private readonly PayrollPeopleRepository $people,
        private readonly CrossSupplierAuthorization $authorization,
        private readonly PayrollModuleAccess $access,
    ) {}

    /**
     * @return list<array{id:int,supplier_id:int,supplier_name:string,full_name:string,is_active:bool,code:?string}>
     */
    public function search(Request $request, int $currentSupplierId, string $query, int $limit = 8): array
    {
        if (!RequestAuthorization::isSessionAuth($request) || !$this->access->isLicensed()) {
            return [];
        }
        $hits = $this->people->searchAcrossSuppliers(
            $this->authorization->candidateSupplierIds($request),
            $query,
            $currentSupplierId,
            self::PREFETCH,
        );

        $allowed = [];
        $out = [];
        foreach ($hits as $hit) {
            $supplierId = $hit['supplier_id'];
            $allowed[$supplierId] ??= $this->authorization->allowsIn(
                $request,
                $supplierId,
                ['payroll'],
                AccessLevel::READ,
            ) && $this->access->isEnabled($supplierId);
            if (!$allowed[$supplierId]) {
                continue;
            }
            $out[] = $hit;
            if (count($out) >= $limit) {
                break;
            }
        }

        $names = [];
        foreach ($this->authorization->describe(array_values(array_unique(array_column($out, 'supplier_id')))) as $supplier) {
            $names[$supplier['id']] = $supplier['name'];
        }

        return array_map(
            static fn (array $hit): array => $hit + ['supplier_name' => $names[$hit['supplier_id']] ?? ''],
            $out,
        );
    }
}
