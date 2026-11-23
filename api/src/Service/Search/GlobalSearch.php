<?php

declare(strict_types=1);

namespace MyInvoice\Service\Search;

use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\People\PayrollPeopleGlobalSearch;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Globální hledání (Alt+Q a paleta Ctrl+K): klienti, vydané a přijaté faktury
 * a zaměstnanci.
 *
 * Každá skupina se nejdřív hledá v aktivní firmě, a jen s právem ke čtení dané
 * agendy. Když v ní nic nenajde, hledá se v ostatních firmách uživatele, kde to
 * právo má ({@see CrossSupplierHits}); takový výsledek nese `supplier_id`
 * a `supplier_name` a frontend před otevřením přepne firmu. Zaměstnanci jdou
 * napříč firmami vždy ({@see PayrollPeopleGlobalSearch}).
 *
 * Do ostatních firem hledá jen přihlášená relace (API token je vázaný na firmu)
 * a až od tří znaků: čísla dokladů se tam porovnávají od začátku a kratší
 * předpona by trefila skoro každý doklad všech firem.
 */
final class GlobalSearch
{
    public const MIN_LENGTH = 2;
    public const CROSS_SUPPLIER_MIN_LENGTH = 3;
    private const LIMIT = 6;
    private const PREFETCH = 40;

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchases,
        private readonly PayrollPeopleGlobalSearch $employees,
        private readonly CrossSupplierHits $hits,
    ) {}

    /**
     * @return array{q:string, clients:list<array<string,mixed>>, invoices:list<array<string,mixed>>,
     *   purchase_invoices:list<array<string,mixed>>, employees:list<array<string,mixed>>}
     */
    public function search(Request $request, int $supplierId, string $q): array
    {
        $q = mb_substr(trim($q), 0, 100);
        $result =['q' => $q, 'clients' => [], 'invoices' => [], 'purchase_invoices' => [], 'employees' => []];
        if (mb_strlen($q) < self::MIN_LENGTH) {
            return $result;
        }

        $groups = [
            'clients' => [
                'clients',
                fn (): array => $this->clients->searchQuick($q, $supplierId, self::LIMIT),
                fn (array $ids): array => $this->clients->searchQuickAcrossSuppliers($ids, $q, self::PREFETCH),
            ],
            'invoices' => [
                'invoices',
                fn (): array => $this->invoices->searchQuick($q, $supplierId, self::LIMIT),
                fn (array $ids): array => $this->invoices->searchQuickAcrossSuppliers($ids, $q, self::PREFETCH),
            ],
            'purchase_invoices' => [
                'purchase_invoices',
                fn (): array => $this->purchases->searchQuick($q, $supplierId, self::LIMIT),
                fn (array $ids): array => $this->purchases->searchQuickAcrossSuppliers($ids, $q, self::PREFETCH),
            ],
        ];

        // Klientská role vidí jen svá data a ta má v portálu; adresář a doklady
        // firmy jí globální hledání nevydá, i kdyby měla právo k agendě.
        $internal = !RequestAuthorization::isClientType($request);
        foreach ($groups as $key => [$permission, $inCurrent]) {
            if ($internal && RequestAuthorization::allows($request, $permission, AccessLevel::READ)) {
                $result[$key] = $inCurrent();
            }
        }
        $result['employees'] = $this->employees->search($request, $supplierId, $q);

        if (!RequestAuthorization::actsAsSession($request) || mb_strlen($q) < self::CROSS_SUPPLIER_MIN_LENGTH) {
            return $result;
        }
        $candidates = null;
        foreach ($groups as $key => [$permission, , $acrossSuppliers]) {
            if ($result[$key] !== []) {
                continue;
            }
            $candidates ??= $this->hits->candidates($request, $supplierId);
            if ($candidates === []) {
                break;
            }
            $result[$key] = $this->hits->authorize($request, $acrossSuppliers($candidates), [$permission], self::LIMIT);
        }

        return $result;
    }
}
