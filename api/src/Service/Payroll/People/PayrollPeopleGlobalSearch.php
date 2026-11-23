<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\People;

use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Search\CrossSupplierHits;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Zaměstnanci v globálním hledání (Alt+Q, Ctrl+K) napříč firmami uživatele.
 *
 * Nejdřív se levně hledá ve všech firmách, kam uživatel může patřit, a teprve
 * firmy, ve kterých se někdo našel, projdou rozhodnutím o přístupu
 * ({@see CrossSupplierHits}: právo `payroll` ke čtení) a musí mít zapnuté mzdy.
 * Rozhodovat předem o každé firmě by u správce s desítkami firem stálo desítky
 * rozhodnutí na každé písmeno.
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
        private readonly CrossSupplierHits $hits,
    ) {}

    /**
     * @return list<array{id:int,supplier_id:int,supplier_name:string,full_name:string,is_active:bool,code:?string}>
     */
    public function search(Request $request, int $currentSupplierId, string $query, int $limit = 8): array
    {
        if (!RequestAuthorization::actsAsSession($request) || !$this->access->isLicensed()) {
            return [];
        }
        $found = $this->people->searchAcrossSuppliers(
            $this->authorization->candidateSupplierIds($request),
            $query,
            $currentSupplierId,
            self::PREFETCH,
        );

        /** @var list<array{id:int,supplier_id:int,supplier_name:string,full_name:string,is_active:bool,code:?string}> */
        return $this->hits->authorize(
            $request,
            $found,
            ['payroll'],
            $limit,
            fn (int $supplierId): bool => $this->access->isEnabled($supplierId),
        );
    }
}
