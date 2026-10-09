<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;

/**
 * Přepočítá sumy přijaté faktury (totals + per-item) a vat breakdown.
 *
 * Paralel k `InvoiceCalculator`, ale nad tabulkami purchase_invoices / purchase_invoice_items.
 * Vlastní výpočty delegovány na `InvoiceMath` (pure function, sdílená logika).
 *
 *  - Per item: total_without_vat = round(qty * unit_price, 2)
 *              total_vat         = round(base * rate/100, 2)
 *              total_with_vat    = base + vat
 *  - Reverse charge: daň 0 u řádků, které se samovyměřují; tuzemský řádek smíšeného
 *    dokladu (kód 40/41) nese daň dodavatele podle sazby (pravidlo z evidence DPH)
 *  - amount_to_pay je generated STORED column (total_with_vat - advance_paid_amount)
 */
final class PurchaseInvoiceCalculator
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Přepočítá fakturu — per-item totals + invoice totals. Volat po každé změně items.
     *
     * @return array{totals: array{without_vat: float, vat: float, with_vat: float}, vat_breakdown: list<array<string,mixed>>}
     */
    public function recompute(int $purchaseInvoiceId): array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare('SELECT supplier_id, reverse_charge, prices_include_vat, vat_overrides, vat_classification_code
               FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$purchaseInvoiceId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($header === false) {
            throw new \RuntimeException("Purchase invoice {$purchaseInvoiceId} not found");
        }
        $reverseCharge    = (bool) $header['reverse_charge'];
        $pricesIncludeVat = (bool) $header['prices_include_vat'];
        // Ruční rekapitulace DPH dle dokladu (§ 73 ZDPH) — viz InvoiceMath::compute.
        $vatOverrides = [];
        if (!empty($header['vat_overrides'])) {
            $decoded = json_decode((string) $header['vat_overrides'], true);
            if (is_array($decoded)) {
                $vatOverrides = $decoded;
            }
        }

        $stmt = $pdo->prepare(
            'SELECT id, quantity, duration_minutes, unit_price_without_vat, vat_rate_snapshot, vat_classification_code
               FROM purchase_invoice_items
              WHERE purchase_invoice_id = ?
              ORDER BY order_index, id'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Smíšený doklad s přenesením v hlavičce: o tom, zda řádek nese daň dodavatele,
        // rozhoduje klasifikace řádku stejně jako v evidenci DPH, KH a zaúčtování.
        if ($reverseCharge && $items !== []) {
            $map = VatLedgerService::classificationMapFor($pdo, (int) $header['supplier_id']);
            $headerCode = $header['vat_classification_code'] !== null ? (string) $header['vat_classification_code'] : null;
            foreach ($items as $i => $item) {
                $code = $item['vat_classification_code'] !== null ? (string) $item['vat_classification_code'] : $headerCode;
                $items[$i]['reverse_charge'] = VatLedgerService::isSelfAssessedDocumentLine(true, $code, $map);
            }
        }

        $computed = InvoiceMath::compute($items, $reverseCharge, $pricesIncludeVat, $vatOverrides);

        // Persist per-item totals
        $updateItem = $pdo->prepare(
            'UPDATE purchase_invoice_items
                SET total_without_vat = ?, total_vat = ?, total_with_vat = ?
              WHERE id = ?'
        );
        foreach ($items as $i => $item) {
            $r = $computed['items'][$i];
            $updateItem->execute([$r['base'], $r['vat'], $r['with'], (int) $item['id']]);
        }

        // Persist invoice totals (amount_to_pay je generated column).
        // POZOR: rounding NEpřepisujeme — uchová value z DB (typicky AI import extract
        // 'total_with_vat_rounded' rozdíl, nebo manuální user edit).
        $stmt = $pdo->prepare(
            'UPDATE purchase_invoices
                SET total_without_vat = ?, total_vat = ?, total_with_vat = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $computed['totals']['without_vat'],
            $computed['totals']['vat'],
            $computed['totals']['with_vat'],
            $purchaseInvoiceId,
        ]);

        $this->assertIntegrity($purchaseInvoiceId);

        return [
            'totals'        => $computed['totals'],
            'vat_breakdown' => $computed['vat_breakdown'],
        ];
    }

    /**
     * FR4 (vendor audit 2026-08) — preventivní pojistka: znovu načte PRÁVĚ
     * uložený stav (hlavička + položky) a ověří `základ + DPH = celkem` a
     * `SUM(položky) = hlavička`, obojí s malou tolerancí. Za normálního běhu tahle
     * kontrola vždy projde — {@see recompute()} odvozuje hlavičku ze stejného průchodu
     * položkami, který persistuje, takže drift je matematicky vyloučený. Jde o
     * bezpečnostní síť proti BUDOUCÍ regresi (nová cesta zápisu mimo `recompute()`,
     * částečně selhavší UPDATE, DECIMAL(12,2) truncation na hraně float zaokrouhlení),
     * ne proti dnešnímu chování — nejrizikovější je doklad s `vat_overrides` (§73
     * ZDPH), kde InvoiceMath::applyRateOverrides rozděluje zaokrouhlovací reziduum na
     * nejsilnější řádek dané sazby a rozjezd mezi hlavičkou a položkami by jinak nikdo
     * nezachytil až do podkladů DPH.
     *
     * Veřejná (ne jen interní krok recompute()) — volitelně callable i mimo write
     * path, např. z auditní brány nebo testu, který ji ověřuje na záměrně
     * poškozeném stavu (regresní test proti tomu, že guard nikdy nic nechytí).
     */
    public function assertIntegrity(int $purchaseInvoiceId): void
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT total_without_vat, total_vat, total_with_vat
               FROM purchase_invoices WHERE id = ?'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($header === false) {
            return; // doklad mezitím smazán — nic k ověření
        }

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(total_without_vat), 0) AS base, COALESCE(SUM(total_vat), 0) AS vat
               FROM purchase_invoice_items WHERE purchase_invoice_id = ?'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $itemSums = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['base' => 0, 'vat' => 0];

        // Tolerance: dvojnásobek jednoho zaokrouhlovacího haléře — bezpečná rezerva
        // proti akumulaci zaokrouhlení na dokladu s víc řádky/sazbami, ale dost malá,
        // aby chytila skutečný rozjezd (FR4: "s malou tolerancí").
        $tol = 0.02;

        $hdrBase = (float) $header['total_without_vat'];
        $hdrVat  = (float) $header['total_vat'];
        $hdrWith = (float) $header['total_with_vat'];
        $itemsBase = (float) $itemSums['base'];
        $itemsVat  = (float) $itemSums['vat'];

        if (abs($hdrBase - $itemsBase) > $tol || abs($hdrVat - $itemsVat) > $tol) {
            throw new PurchaseInvoiceArithmeticException(sprintf(
                'Přijatá faktura #%d: součet položek (základ %s, DPH %s) neodpovídá hlavičce '
                    . '(základ %s, DPH %s) — uložení bylo zamítnuto.',
                $purchaseInvoiceId,
                number_format($itemsBase, 2, ',', ' '),
                number_format($itemsVat, 2, ',', ' '),
                number_format($hdrBase, 2, ',', ' '),
                number_format($hdrVat, 2, ',', ' '),
            ));
        }

        if (abs(round($hdrBase + $hdrVat, 2) - $hdrWith) > $tol) {
            throw new PurchaseInvoiceArithmeticException(sprintf(
                'Přijatá faktura #%d: základ + DPH (%s) neodpovídá uloženému celku (%s) — uložení bylo zamítnuto.',
                $purchaseInvoiceId,
                number_format(round($hdrBase + $hdrVat, 2), 2, ',', ' '),
                number_format($hdrWith, 2, ',', ' '),
            ));
        }
    }
}
