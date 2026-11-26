<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

/** Náhrada položek by odpojila řádek, na který už je navázaná příjemka ({@see PurchaseItemReferences}). */
final class PurchaseItemReferenceException extends \RuntimeException
{
    public const RECEIVED = 'purchase_item_already_received';
    public const DRAFT_RECEIPT = 'purchase_item_on_draft_receipt';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
