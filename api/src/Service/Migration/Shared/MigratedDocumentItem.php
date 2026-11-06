<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Service\Migration\OssMigrationPolicy;

/**
 * Položka převzatého dokladu (`invoice_items` / `purchase_invoice_items`).
 *
 * Vydaná položka se zakládá přes {@see issued()} a režim OSS musí uvést vždy:
 * `oss_applicable` má v databázi DEFAULT 0, takže nezapsaný sloupec by nebyl chybějící
 * údaj, ale tiché zařazení do tuzemska. Tuzemskou položku zdroj označí
 * {@see OssMigrationPolicy::DOMESTIC_COLUMNS}. Přijatá položka OSS nezná ({@see purchase()}).
 */
final class MigratedDocumentItem
{
    private const OSS_KEYS = ['oss_applicable', 'oss_consumer_country', 'oss_rate_type', 'oss_supply_type', 'oss_needs_manual_review'];

    /** @param array{oss_applicable:int,oss_consumer_country:?string,oss_rate_type:?string,oss_supply_type:?string,oss_needs_manual_review:int,oss_taxable_amount_return?:?float,oss_vat_amount_return?:?float} $oss */
    private function __construct(
        public readonly string $description,
        public readonly float $quantity,
        public readonly string $unit,
        public readonly float $unitPrice,
        public readonly int $vatRateId,
        public readonly float $vatRateSnapshot,
        public readonly float $totalWithoutVat,
        public readonly float $totalVat,
        public readonly float $totalWithVat,
        public readonly ?string $vatClassificationCode,
        public readonly array $oss,
        public readonly bool $isFixedAsset,
        public readonly ?string $expenseKind,
        public readonly ?string $expenseAccountCode = null,
        public readonly ?string $revenueAccountCode = null,
    ) {}

    /**
     * @param array<string,mixed> $oss sloupce OSS položky ({@see OssMigrationPolicy::DOMESTIC_COLUMNS}
     *                                 nebo `columns` z plánu {@see OssMigrationPolicy::planItem()})
     */
    public static function issued(
        string $description,
        float $quantity,
        string $unit,
        float $unitPrice,
        int $vatRateId,
        float $vatRateSnapshot,
        float $totalWithoutVat,
        float $totalVat,
        float $totalWithVat,
        ?string $vatClassificationCode,
        array $oss,
        ?string $revenueAccountCode = null,
    ): self {
        $missing = array_diff(self::OSS_KEYS, array_keys($oss));
        if ($missing !== []) {
            throw new \InvalidArgumentException('Položce vydaného dokladu chybí sloupce OSS: ' . implode(', ', $missing));
        }
        return new self(
            $description, $quantity, $unit, $unitPrice, $vatRateId, $vatRateSnapshot,
            $totalWithoutVat, $totalVat, $totalWithVat, $vatClassificationCode,
            [
                'oss_applicable' => $oss['oss_applicable'],
                'oss_consumer_country' => $oss['oss_consumer_country'],
                'oss_rate_type' => $oss['oss_rate_type'],
                'oss_supply_type' => $oss['oss_supply_type'],
                'oss_needs_manual_review' => $oss['oss_needs_manual_review'],
                // Nepovinné: částky pro OSS přiznání, když je zdroj zná v měně podání
                // ({@see OssMigrationPolicy::returnAmounts()}); jinak je dopočte náhled podání.
                'oss_taxable_amount_return' => $oss['oss_taxable_amount_return'] ?? null,
                'oss_vat_amount_return' => $oss['oss_vat_amount_return'] ?? null,
            ],
            false,
            null,
            null,
            $revenueAccountCode,
        );
    }

    /**
     * Příznak pořízení majetku (ř. 47) položky, když zdroj zařazuje DPH celým dokladem
     * (Money S3 `KodDPH`, POHODA členění): nesou ho jen položky s odpočtem, tedy se sazbou,
     * daní nebo kódem zařazení. Položka mimo DPH (základ 0 %, rozdíl kurzu u samovyměření)
     * pořízením majetku v přiznání není. Zdroj s kódem na položce (PREMIER) bere příznak
     * přímo z klasifikace kódu ({@see VatReturnLineClassifier::purchaseFromLineSet()}).
     */
    public static function fixedAssetLine(bool $documentFixedAsset, float $rate, float $vat, ?string $code): bool
    {
        return $documentFixedAsset && ($rate > 0.0 || abs($vat) >= 0.005 || $code !== null);
    }

    /**
     * Příznak na hlavičce přijatého dokladu: jen když je pořízením majetku každá položka.
     *
     * @param list<bool>|array<int,bool> $itemFlags
     */
    public static function wholeDocumentFixedAsset(array $itemFlags): bool
    {
        return $itemFlags !== [] && !in_array(false, $itemFlags, true);
    }

    public static function purchase(
        string $description,
        float $quantity,
        string $unit,
        float $unitPrice,
        int $vatRateId,
        float $vatRateSnapshot,
        float $totalWithoutVat,
        float $totalVat,
        float $totalWithVat,
        ?string $vatClassificationCode,
        bool $isFixedAsset = false,
        ?string $expenseKind = null,
        ?string $expenseAccountCode = null,
    ): self {
        return new self(
            $description, $quantity, $unit, $unitPrice, $vatRateId, $vatRateSnapshot,
            $totalWithoutVat, $totalVat, $totalWithVat, $vatClassificationCode,
            OssMigrationPolicy::DOMESTIC_COLUMNS,
            $isFixedAsset,
            // Aplikace drží expense_kind='fixed_asset' ⇔ is_fixed_asset=1 (PurchaseInvoiceRepository);
            // bez druhu by první uložení nebo auto-klasifikace příznak ř. 47 shodily. Druh daný
            // zdrojem (PREMIER podle účtu položky) má přednost.
            $expenseKind ?? ($isFixedAsset ? 'fixed_asset' : null),
            $expenseAccountCode,
        );
    }
}
