<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Catalog;

use MyInvoice\Repository\Property\Catalog\PropertyLegalRoleRepository;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInstant;

final class PropertyLegalRoleCatalog
{
    public function __construct(private readonly PropertyLegalRoleRepository $repository) {}

    public function read(int $supplierId, string $asOfInput, string $knownAtInput, ?string $cursor, int $limit, ?int $unitId = null): array
    {
        if ($supplierId <= 0 || $limit < 1 || $limit > 100 || ($unitId !== null && $unitId <= 0)) {
            throw new PropertyException('property_invalid_input', 'Zkontrolujte filtr právních rolí.', 422);
        }
        return PropertyInstant::withNormalizationContext(function () use ($supplierId, $asOfInput, $knownAtInput, $cursor, $limit, $unitId): array {
            $asOf = PropertyInstant::normalize($asOfInput);
            $knownAt = PropertyInstant::normalize($knownAtInput);
            $filters = ['as_of' => $asOf, 'known_at' => $knownAt];
            $after = $cursor === null ? 0 : PropertyLegalRoleCursor::decode($cursor, $supplierId, $filters);
            if ($unitId !== null && $cursor !== null) throw new PropertyException('property_legal_role_cursor_invalid', 'Detail jednotky nepřijímá kurzor.', 422);
            $objects = $this->repository->units($supplierId, $knownAt, $after, $unitId === null ? $limit + 1 : 1, $unitId);
            $hasMore = $unitId === null && count($objects) > $limit;
            if ($hasMore) array_pop($objects);
            $items = $this->repository->proven($supplierId, $objects, $asOf, $knownAt);
            $next = $hasMore && $objects !== [] ? PropertyLegalRoleCursor::encode($supplierId, $filters, (int) end($objects)['id']) : null;
            return ['as_of' => $asOf, 'known_at' => $knownAt, 'items' => $items, 'next_cursor' => $next,
                'proof_basis' => 'native_append_only_revision_chain_and_materialized_roles', 'financial_authorization' => false];
        });
    }
}
