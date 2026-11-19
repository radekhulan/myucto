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
    ];

    public function __construct(
        private readonly JournalRebookService $rebook,
        private readonly SetDimensionsOperation $dimensions,
        private readonly SetPostingRuleOperation $postingRule,
        private readonly DeleteOperation $delete,
    ) {}

    /** @throws BulkActionException neznámá operace nebo druh záznamů */
    public function get(string $key, string $target): BulkOperation
    {
        if (!in_array($target, self::TARGETS[$key] ?? [], true)) {
            throw new BulkActionException('validation_failed', 'Tuto operaci nad zvolenými záznamy spustit nejde.', 422);
        }
        return match ($key) {
            JournalRebookService::KEY => $this->rebook,
            SetDimensionsOperation::KEY => $this->dimensions,
            SetPostingRuleOperation::KEY => $this->postingRule,
            default => $this->delete,
        };
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TARGETS);
    }
}
