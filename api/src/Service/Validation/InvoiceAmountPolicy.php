<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

use MyInvoice\Service\Invoice\InvoiceItemKind;
use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Invoice\InvoiceRounding;
use MyInvoice\Service\Invoice\RefundDocument;
use MyInvoice\Service\Invoice\TimeBilling;

final class InvoiceAmountPolicy
{
    public const MANUAL_PRICE_MISSING_MESSAGE = 'Některé řádky čekají na zadání ceny. Doplňte je, než fakturu vystavíte.';
    public const NON_POSITIVE_DRAFT_MESSAGE = 'Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.';
    public const NON_POSITIVE_MARK_PAID_MESSAGE = 'Fakturu s částkou k úhradě 0 nebo méně nelze označit jako zaplacenou.';
    public const NON_POSITIVE_REMINDER_MESSAGE = 'Upomínat lze jen faktury s kladnou částkou k úhradě.';

    public const ITEM_QUANTITY_ZERO_MESSAGE = 'Množství nesmí být 0.';
    public const ITEM_BOTH_NEGATIVE_MESSAGE = 'Záporné množství i záporná cena zároveň nejsou povolené.';

    /** Pod tuto hodnotu považuj qty za 0 (InvoiceMath::round2 stejně zaokrouhlí na 2 desetinná místa). */
    private const QTY_EPSILON = 1e-9;

    public static function requiresPositiveDraftAmountToPay(string $invoiceType, mixed $parentInvoiceId = null): bool
    {
        if (!in_array($invoiceType, ['invoice', 'proforma'], true)) {
            return false;
        }

        // Finální daňový doklad k zaplacené proformě je vedený jako `invoice`
        // s parent_invoice_id a typicky amount_to_pay = 0 po odečtu zálohy.
        if ($invoiceType === 'invoice' && (int) $parentInvoiceId > 0) {
            return false;
        }

        return true;
    }

    public static function requiresPositiveAmountToPay(string $invoiceType): bool
    {
        return in_array($invoiceType, ['invoice', 'proforma'], true);
    }

    /**
     * @param array<int, float> $vatRates
     * @param bool    $allowRefundInvoice `supplier.allow_refund_invoices`: faktura s aspoň
     *                jedním kladným řádkem smí skončit zápornou částkou (doklad k vyplacení)
     * @param ?string $currency kód měny dokladu; bez něj se k vyplacení nezaokrouhluje
     */
    public static function validatePositiveAmountToPay(
        array $data,
        array $vatRates,
        bool $allowRefundInvoice = false,
        ?string $currency = null,
    ): ?string {
        $type = (string) ($data['invoice_type'] ?? 'invoice');
        if (!self::requiresPositiveDraftAmountToPay($type, $data['parent_invoice_id'] ?? null)) {
            return null;
        }

        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return null;
        }

        // Koncept s řádkem „cenu zadá člověk" smí být zatím nulový. Hlídá ho vystavení: příznak
        // nese jen koncept (replaceItems ho jinde neuloží, force-edit vystavené ho zahodí) a každá
        // cesta, která vystavuje existující koncept, volá hasMissingManualPrice(): IssueInvoiceAction
        // (UI, API v1, MCP `issue_invoice`), AutoIssueAndSendService (schválení výkazu) a už žádost
        // o schválení (RequestApprovalAction). Pravidelná faktura, účtenka a storno vystavují vlastní
        // nově založený doklad bez příznaku; kopie, dobropis a konečná faktura ze zálohy ho nepřenáší.
        if (array_filter($items, static fn (mixed $item): bool => is_array($item) && !empty($item['manual_price'])) !== []) {
            return null;
        }

        // Malformed items přispějí 0 — per-item errors se reportují jinde (validateItem).
        // Nevracíme null, aby kontrola pozitivity nebyla závislá na pořadí validátorů
        // a uživatel viděl všechny chyby naráz.
        $mathItems = [];
        foreach ($items as $item) {
            if (
                !is_array($item)
                || InvoiceItemKind::isText($item)
                || !isset($item['quantity'], $item['unit_price_without_vat'], $item['vat_rate_id'])
                || !is_numeric($item['quantity'])
                || !is_numeric($item['unit_price_without_vat'])
                || !is_numeric($item['vat_rate_id'])
            ) {
                continue;
            }

            try {
                $durationMinutes = TimeBilling::durationMinutes($item);
            } catch (\InvalidArgumentException) {
                $durationMinutes = null;
            }

            $vatRateId = (int) $item['vat_rate_id'];
            $mathItems[] = [
                'quantity' => (float) $item['quantity'],
                'duration_minutes' => $durationMinutes,
                'unit_price_without_vat' => (float) $item['unit_price_without_vat'],
                'vat_rate_snapshot' => $vatRates[$vatRateId] ?? 0.0,
            ];
        }

        if ($mathItems === []) {
            return null;
        }

        $computed = InvoiceMath::compute($mathItems, !empty($data['reverse_charge']));
        // Sleva na úrovni dokladu (0–100 %) sníží celkovou částku — kontrola pozitivity
        // musí počítat s částkou PO slevě (sleva se materializuje až v repo při uložení).
        $discount = max(0.0, min(100.0, (float) ($data['discount_percent'] ?? 0)));
        $withVat = round((float) $computed['totals']['with_vat'] * (1 - $discount / 100.0), 2);
        $advance = round((float) ($data['advance_paid_amount'] ?? 0), 2);
        $amountToPay = round($withVat - $advance, 2);

        if ($amountToPay > 0) {
            return null;
        }
        if ($allowRefundInvoice && $amountToPay < 0 && $type === 'invoice' && self::hasPositiveItem($items)) {
            $mode = (string) ($data['rounding_mode'] ?? 'auto');
            if ($currency !== null && in_array($mode, InvoiceRounding::MODES, true)) {
                $amountToPay = round($amountToPay + InvoiceRounding::adjustment(
                    $amountToPay,
                    $mode,
                    $currency,
                    (string) ($data['payment_method'] ?? ''),
                    $type,
                ), 2);
            }
            if ($amountToPay < 0) {
                return null;
            }
        }

        return self::NON_POSITIVE_DRAFT_MESSAGE;
    }

    /**
     * Aspoň jeden řádek s kladnou částkou (množství × cena). Rozlišuje vyúčtování,
     * ve kterém odpočty převážily plnění, od dokladu bez plnění, který patří na dobropis.
     *
     * @param array<mixed> $items
     */
    /**
     * Řádek „cenu zadá člověk" (faktura z příjemky), kterému ještě chybí cena: součet může být
     * kladný díky ostatním řádkům, plnění za 0 Kč by ale odešlo odběrateli.
     *
     * @param array<int, mixed> $items
     */
    public static function hasMissingManualPrice(array $items): bool
    {
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['manual_price']) && (float) ($item['unit_price_without_vat'] ?? 0) === 0.0) {
                return true;
            }
        }
        return false;
    }

    public static function hasPositiveItem(array $items): bool
    {
        foreach ($items as $item) {
            if (!is_array($item) || !is_numeric($item['quantity'] ?? null) || !is_numeric($item['unit_price_without_vat'] ?? null)) {
                continue;
            }
            if (round((float) $item['quantity'] * (float) $item['unit_price_without_vat'], 2) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Uložená faktura k vyplacení, kterou smí vystavit dodavatel se zapnutým
     * `allow_refund_invoices` (volající to ověří sám): typ `invoice`, záporná částka
     * k úhradě po zaokrouhlení a aspoň jeden kladný řádek. Proforma nikdy.
     */
    public static function isAllowedRefundInvoice(array $invoice): bool
    {
        return (string) ($invoice['invoice_type'] ?? 'invoice') === 'invoice'
            && round((float) ($invoice['amount_to_pay'] ?? 0), 2) < 0
            && self::hasPositiveItem((array) ($invoice['items'] ?? []));
    }

    /**
     * Strict — vrátí true jen pro doklady s amount_to_pay > 0.
     * Vhodné pro reminder gating (na finální doklad k záloze upomínat nedává smysl).
     */
    public static function hasPositiveAmountToPay(array $invoice): bool
    {
        $type = (string) ($invoice['invoice_type'] ?? 'invoice');
        if (!self::requiresPositiveAmountToPay($type)) {
            return true;
        }

        return (float) ($invoice['amount_to_pay'] ?? 0) > 0;
    }

    /**
     * Vhodné pro mark-paid / bank-match flow:
     * finální daňový doklad k zaplacené proformě má amount_to_pay = 0 by design,
     * ale označit jako zaplacený (manuálně nebo přes bank match) je legitimní bookkeeping.
     */
    public static function canBeMarkedPaid(array $invoice): bool
    {
        $type = (string) ($invoice['invoice_type'] ?? 'invoice');
        if ($type === 'invoice' && (int) ($invoice['parent_invoice_id'] ?? 0) > 0) {
            return true;
        }
        // Doklad k vyplacení se „zaplatí" vrácením peněz zákazníkovi (InvoicePaymentService::markRefunded).
        return self::hasPositiveAmountToPay($invoice) || RefundDocument::isRefundDocument($invoice);
    }

    /**
     * Má se doklad při vystavení (draft → issued) rovnou označit jako zaplacený?
     * Platí pro finální daňový doklad k zaplacené proformě plně pokrytý zálohou
     * (amount_to_pay <= 0): inkaso (kasová metoda — cash-flow, limit paušální daně)
     * se totiž promítá přes daňový doklad, ne přes proformu, a doklad by jinak zbytečně
     * visel jako nezaplacený/po splatnosti. Dobropisy (type=credit_note, rovněž nekladný
     * amount_to_pay) sem NEpatří — automaticky „zaplacené" být nesmí (vrácení peněz).
     * Totéž platí pro fakturu k vyplacení bez parent_invoice_id; se zálohou (přeplatek
     * zálohy) se označí jako dosud.
     */
    public static function shouldAutoMarkPaidOnIssue(array $invoice): bool
    {
        // 'tax_document' = daňový doklad k přijaté platbě — z podstaty dokumentuje
        // už přijatou úplatu (advance_paid_amount = brutto platby → amount_to_pay = 0),
        // takže se při vystavení označí jako zaplacený stejně jako finál krytý zálohou.
        return in_array((string) ($invoice['invoice_type'] ?? ''), ['invoice', 'tax_document'], true)
            && (int) ($invoice['parent_invoice_id'] ?? 0) > 0
            && (float) ($invoice['amount_to_pay'] ?? 0) <= 0.0;
    }

    /**
     * Sdílená per-item validace (volaná z InvoiceValidation, RecurringTemplateAction
     * a přijatých faktur). `$allowText` povoluje textový řádek (jen vydaná strana).
     * Caller odpovídá za is_array($item) check.
     *
     * @return array<string, string[]> err keyed by "items.{$index}.{field}"
     */
    public static function validateItem(array $item, int $index, bool $allowText = false): array
    {
        $err = [];

        // Textový řádek (InvoiceItemKind::TEXT) nese jen popis: množství, cenu ani sazbu
        // nemá, zápis je stejně vynuluje. Jen tam, kde ho zápis zná ($allowText: vydaná
        // faktura a šablona pravidelné fakturace). Přijatá faktura item_kind nezná
        // a uloží řádek jako běžnou položku, takže se tam kontroluje celý.
        if ($allowText && InvoiceItemKind::isText($item)) {
            if (trim((string) ($item['description'] ?? '')) === '') {
                $err["items.{$index}.description"][] = 'Textový řádek potřebuje text';
            }
            return $err;
        }

        $normalizedItem = null;
        try {
            $normalizedItem = TimeBilling::normalizeInvoiceItem($item);
        } catch (\InvalidArgumentException $e) {
            $err["items.{$index}.duration_minutes"][] = $e->getMessage();
        }

        if (empty($item['description']) || trim((string) $item['description']) === '') {
            $err["items.{$index}.description"][] = 'Popis je povinný';
        }

        $qty = (float) ($item['quantity'] ?? 0);
        if (abs($qty) < self::QTY_EPSILON) {
            $err["items.{$index}.quantity"][] = self::ITEM_QUANTITY_ZERO_MESSAGE;
        }

        if (!isset($item['vat_rate_id']) || !is_numeric($item['vat_rate_id'])) {
            $err["items.{$index}.vat_rate_id"][] = 'DPH sazba je povinná';
        }

        if (!isset($item['unit_price_without_vat']) || !is_numeric($item['unit_price_without_vat'])) {
            $err["items.{$index}.unit_price_without_vat"][] = 'Jednotková cena je povinná';
        } else {
            $price = (float) $item['unit_price_without_vat'];
            $signedQty = $normalizedItem !== null && $normalizedItem['duration_minutes'] !== null
                ? (float) $normalizedItem['quantity']
                : $qty;
            if ($signedQty < 0 && $price < 0) {
                $err["items.{$index}.quantity"][] = self::ITEM_BOTH_NEGATIVE_MESSAGE;
                $err["items.{$index}.unit_price_without_vat"][] = self::ITEM_BOTH_NEGATIVE_MESSAGE;
            }
        }

        return $err;
    }
}
