<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * DUZP přijatého dokladu založeného importem, když doklad nese jen datum vystavení.
 *
 * Účtenka nebo doklad zaplacený kartou mívá jediné datum („Datum faktury / platby").
 * Datum plnění se na dokladu uvádí, jen když se liší od data vystavení (§ 29 odst. 1
 * písm. h ZDPH), takže chybějící DUZP znamená právě datum vystavení.
 *
 * Volají ji {@see AiPdfExtractor} a {@see IsdocToPurchaseInvoiceMapper}. iDoklad a Fakturoid
 * přebírají DUZP přímo z dat protistrany. U vydaných dokladů DUZP určuje firma sama.
 *
 * Na období DPH to nemá vliv: prázdné DUZP se v evidenci i bez doplnění bere jako datum
 * vystavení ({@see \MyInvoice\Service\Report\VatLedgerService}), proto doplnění nenese
 * varování, na rozdíl od náhradního data vystavení.
 *
 * Záměrně zůstává prázdné:
 * - záloha: není zdanitelné plnění a DUZP nemá,
 * - dobropis: období opravy se řídí § 42, ne datem vystavení,
 * - doklad s vytěženým datem dodání: nejde o doklad s jediným datem.
 *
 * Pořízení zboží z JČS sem volající neposílá. DUZP tam určuje § 25 z data dodání
 * ({@see \MyInvoice\Service\Tax\Vat\EuAcquisitionTaxDate}) a bez něj má zůstat prázdné
 * s varováním.
 */
final class ImportedTaxDatePolicy
{
    private const KINDS_WITHOUT_ISSUE_DATE_FALLBACK = ['advance', 'credit_note'];

    public static function resolve(mixed $taxDate, mixed $deliveryDate, mixed $issueDate, string $documentKind): ?string
    {
        $tax = self::text($taxDate);
        if ($tax !== '') {
            return $tax;
        }
        if (in_array($documentKind, self::KINDS_WITHOUT_ISSUE_DATE_FALLBACK, true) || self::text($deliveryDate) !== '') {
            return null;
        }
        $issue = self::text($issueDate);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue) === 1 ? $issue : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
