<?php

declare(strict_types=1);

namespace MyInvoice\Service\Search;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\CrossSupplierAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výsledky hledání z jiných firem než aktivní: propustí jen firmy, kde smí uživatel
 * daná data číst. Jediné místo tohoto rozhodnutí pro globální hledání (zaměstnanci,
 * klienti, vydané i přijaté faktury).
 *
 * Hledá se předem levně ve všech firmách, kam uživatel může patřit
 * ({@see CrossSupplierAuthorization::candidateSupplierIds()}), a teprve firmy, ve
 * kterých se něco našlo, projdou plným rozhodnutím o přístupu
 * ({@see CrossSupplierAuthorization::allowsIn()}: členství, zámek domény, role firmy
 * a právo ke čtení). Z firmy, která rozhodnutím neprojde, se nevrací nic, ani počet.
 */
final class CrossSupplierHits
{
    public function __construct(private readonly CrossSupplierAuthorization $authorization) {}

    /**
     * Firmy, ve kterých se smí předběžně hledat, bez aktivní firmy.
     *
     * @return list<int>
     */
    public function candidates(Request $request, int $currentSupplierId): array
    {
        return array_values(array_filter(
            $this->authorization->candidateSupplierIds($request),
            static fn (int $id): bool => $id !== $currentSupplierId,
        ));
    }

    /**
     * @param list<array<string,mixed>> $hits každý se `supplier_id`
     * @param list<string> $permissions všechna musí ve firmě platit ke čtení
     * @param (callable(int):bool)|null $alsoRequires další podmínka firmy (např. zapnutý modul)
     * @return list<array<string,mixed>> výsledky doplněné o `supplier_name`
     */
    public function authorize(Request $request, array $hits, array $permissions, int $limit, ?callable $alsoRequires = null): array
    {
        $allowed = [];
        $out = [];
        foreach ($hits as $hit) {
            $supplierId = (int) $hit['supplier_id'];
            $allowed[$supplierId] ??= $this->authorization->allowsIn($request, $supplierId, $permissions, AccessLevel::READ)
                && ($alsoRequires === null || $alsoRequires($supplierId));
            if (!$allowed[$supplierId]) {
                continue;
            }
            $out[] = $hit;
            if (count($out) >= $limit) {
                break;
            }
        }

        $names = [];
        foreach ($this->authorization->describe(array_values(array_unique(array_map(
            static fn (array $hit): int => (int) $hit['supplier_id'],
            $out,
        )))) as $supplier) {
            $names[$supplier['id']] = $supplier['name'];
        }

        return array_map(
            static fn (array $hit): array => $hit + ['supplier_name' => $names[(int) $hit['supplier_id']] ?? ''],
            $out,
        );
    }
}
