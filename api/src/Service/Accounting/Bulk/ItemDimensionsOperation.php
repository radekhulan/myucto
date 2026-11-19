<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Dimenze vybraných POLOŽEK (agenda Položky dokladů). Mění jen vybrané položky dokladu
 * týmž mechanismem jako rozsah „položky" nad doklady ({@see SetDimensionsOperation},
 * {@see \MyInvoice\Service\Accounting\Dimension\DimensionService::bulkPatchDocument()}),
 * ostatní položky téhož dokladu zůstanou. Záznamem hromadné akce je doklad.
 */
final class ItemDimensionsOperation implements BulkOperation, BulkCandidateSource
{
    public const TARGETS = ['invoice_item', 'purchase_invoice_item'];

    public function __construct(
        private readonly SetDimensionsOperation $dimensions,
        private readonly DocumentItemSearch $search,
    ) {}

    public function key(): string
    {
        return SetDimensionsOperation::KEY;
    }

    public function supportsRollback(): bool
    {
        return true;
    }

    public function unavailableReason(Request $request, int $supplierId, string $target): ?string
    {
        if (!in_array($target, self::TARGETS, true)) {
            return 'Dimenze položek jde měnit jen nad položkami dokladů.';
        }
        return $this->dimensions->unavailableReason($request, $supplierId, $target);
    }

    public function normalizeParams(int $supplierId, string $target, array $raw): array
    {
        return $this->dimensions->normalizeParams($supplierId, $target, $raw);
    }

    public function candidates(BulkContext $ctx): array
    {
        return $this->search->rows($ctx->supplierId, $ctx->target, array_map('intval', (array) ($ctx->params['ids'] ?? [])));
    }

    public function evaluate(BulkContext $ctx, array $row): array
    {
        return $this->dimensions->evaluate($ctx, $row);
    }

    public function apply(BulkContext $ctx, array $dry): array
    {
        return $this->dimensions->apply($ctx, $dry);
    }

    public function rollback(BulkContext $ctx, array $applied): array
    {
        return $this->dimensions->rollback($ctx, $applied);
    }
}
