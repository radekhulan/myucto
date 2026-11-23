<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Service\Accounting\JournalRebookService;

/**
 * Operace hromadných akcí podle klíče a druhy záznamů, nad kterými jdou spustit.
 */
final class BulkOperations
{
    /** Klíč operace => druhy záznamů. */
    public const TARGETS = [
        JournalRebookService::KEY     => ['invoice', 'purchase_invoice', 'journal_entry'],
        SetDimensionsOperation::KEY   => ['invoice', 'purchase_invoice', 'journal_entry'],
        SetPostingRuleOperation::KEY  => ['invoice', 'purchase_invoice'],
        DeleteOperation::KEY          => ['invoice', 'purchase_invoice', 'journal_entry'],
        SetNoteOperation::KEY         => ['invoice', 'purchase_invoice'],
        SetVariableSymbolOperation::KEY => ['bank_transaction'],
        SetVatClassificationOperation::KEY => ['invoice', 'purchase_invoice'],
    ];

    /** Operace nad položkami dokladů (agenda Položky dokladů): klíč => druhy záznamů. */
    public const ITEM_TARGETS = [
        SetVatClassificationOperation::KEY => ['invoice_item', 'purchase_invoice_item'],
        JournalRebookService::KEY          => ItemAccountOperation::TARGETS,
        SetDimensionsOperation::KEY        => ItemDimensionsOperation::TARGETS,
    ];

    public function __construct(
        private readonly JournalRebookService $rebook,
        private readonly SetDimensionsOperation $dimensions,
        private readonly SetPostingRuleOperation $postingRule,
        private readonly DeleteOperation $delete,
        private readonly SetNoteOperation $note,
        private readonly SetVariableSymbolOperation $variableSymbol,
        private readonly SetVatClassificationOperation $vatClassification,
        private readonly ItemAccountOperation $itemAccount,
        private readonly ItemDimensionsOperation $itemDimensions,
    ) {}

    /** @throws BulkActionException neznámá operace nebo druh záznamů */
    public function get(string $key, string $target): BulkOperation
    {
        if (!in_array($target, self::TARGETS[$key] ?? [], true) && !in_array($target, self::ITEM_TARGETS[$key] ?? [], true)) {
            throw new BulkActionException('validation_failed', 'Tuto operaci nad zvolenými záznamy spustit nejde.', 422);
        }
        if (DocumentItemSearch::isItemTarget($target)) {
            return match ($key) {
                JournalRebookService::KEY => $this->itemAccount,
                SetDimensionsOperation::KEY => $this->itemDimensions,
                default => $this->vatClassification,
            };
        }
        return match ($key) {
            SetVatClassificationOperation::KEY => $this->vatClassification,
            JournalRebookService::KEY => $this->rebook,
            SetDimensionsOperation::KEY => $this->dimensions,
            SetPostingRuleOperation::KEY => $this->postingRule,
            SetNoteOperation::KEY => $this->note,
            SetVariableSymbolOperation::KEY => $this->variableSymbol,
            default => $this->delete,
        };
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TARGETS);
    }

    /**
     * Klíče operací spustitelných nad daným druhem záznamů.
     *
     * @return list<string>
     */
    public static function keysFor(string $target): array
    {
        $map = DocumentItemSearch::isItemTarget($target) ? self::ITEM_TARGETS : self::TARGETS;
        return array_keys(array_filter($map, static fn (array $targets): bool => in_array($target, $targets, true)));
    }
}
