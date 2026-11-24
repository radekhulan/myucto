<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\AssetSale;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\IssuedCzkAmounts;
use PDO;

/**
 * Automat prodeje majetku z vydané faktury (migrace 1177).
 *
 * Řádek faktury navázaný na kartu (`invoice_items.small_asset_id` / `asset_id`) říká dvě
 * věci: KAM jde výnos (řeší PostingService::revenueWeights — 642 u drobného, 641 u
 * dlouhodobého) a CO se má stát s kartou, jakmile je faktura vystavená. To druhé dělá
 * tenhle service:
 *
 *   • drobný majetek   → {@see SmallAssetService::sell()} — karta přejde na 'sold' a dostane
 *     vazbu na doklad. NIC se neúčtuje: náklad na 501 padl při pořízení, zůstatková cena je 0.
 *   • dlouhodobý       → {@see AssetService::dispose()} s type='sold' — doúčtuje účetní odpis
 *     do měsíce prodeje, daňový půlodpis §26/7, zůstatkovou cenu 541/08x a vyřazení 08x/02x.
 *
 * MĚKKÝ KONTRAKT jako u {@see \MyInvoice\Service\Accounting\DocumentAutoPoster::maybeAutoPost()}:
 * faktura je v okamžiku volání už vystavená a zákazník ji má. Selhání kartové části (zavřené
 * období, karta mezitím vyřazená ručně) proto NESMÍ vystavení shodit — zaloguje se audit
 * warning a účetní kartu dořeší z evidence majetku. Opačné pořadí (nejdřív karta, pak
 * vystavení) by znamenalo vyřazený majetek bez dokladu, což je horší.
 *
 * Idempotence stojí na stavu karty: prodaná/vyřazená TÍMTO dokladem se přeskočí, takže
 * opakované vystavení (re-issue po stornu, force-edit) nezaloží druhé vyřazení.
 */
final class InvoiceAssetSaleService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SmallAssetService $smallAssets,
        private readonly AssetService $assets,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Vrací varování o kartách, které se NEPODAŘILO uzavřít — volající je přilepí k odpovědi
     * /issue, ať to uživatel vidí hned. Bez toho by jediná stopa byla v auditním logu a účetní
     * by se o neuzavřené kartě dozvěděla až při inventarizaci.
     *
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return list<array{code:string, name:string, message:string}>
     */
    public function applyForIssuedInvoice(int $supplierId, int $invoiceId, array $meta = []): array
    {
        $header = $this->header($supplierId, $invoiceId);
        if ($header === null) {
            return [];
        }
        // Prodej eviduje jen SKUTEČNÁ faktura. Proforma je výzva k platbě (majetek přechází až
        // s vyúčtovací fakturou, kam se vazba kopíruje), dobropis a storno vyřazení naopak ruší
        // — a penalizační či zálohový doklad s majetkem nesouvisí vůbec.
        if ($header['invoice_type'] !== 'invoice') {
            return [];
        }

        $warnings = [];
        foreach ($this->linkedItems($invoiceId) as $item) {
            $price = IssuedCzkAmounts::itemBase($item, $header['czk_rate']);
            try {
                if ($item['small_asset_id'] !== null) {
                    $this->sellSmallAsset($supplierId, (int) $item['small_asset_id'], $invoiceId, $header['sale_date'], $price, $meta);
                } elseif ($item['asset_id'] !== null) {
                    $skipped = $this->sellAsset($supplierId, (int) $item['asset_id'], $invoiceId, $header['sale_date'], $price, $meta);
                    if ($skipped !== null) {
                        $warnings[] = $skipped;
                    }
                }
            } catch (\Throwable $e) {
                // Odvolanou transakci zopakoval vlastník (AssetService::dispose); uvnitř cizí
                // transakce ji musí dostat její vlastník, jinak by další zápisy běžely v autocommitu.
                if (TransactionFailure::aborted($e) && $this->db->pdo()->inTransaction()) {
                    throw $e;
                }
                // Karta zůstane v užívání a faktura vystavená — účetní to uvidí v auditu i
                // v soupisu majetku (věc, kterou firma prodala, ale karta ji pořád eviduje).
                $this->activity->log(
                    'asset_sale.auto_failed',
                    $meta['user_id'] ?? null,
                    'invoice',
                    $invoiceId,
                    [
                        'small_asset_id' => $item['small_asset_id'] !== null ? (int) $item['small_asset_id'] : null,
                        'asset_id'       => $item['asset_id'] !== null ? (int) $item['asset_id'] : null,
                        'message'        => $e->getMessage(),
                    ],
                    $meta['ip'] ?? null,
                    $meta['user_agent'] ?? null,
                    $supplierId,
                );
                $warnings[] = [
                    'code' => 'auto_failed',
                    'name' => $this->cardName($item, $supplierId),
                    // Zpráva ze služby je adresná („rok 2025 nemá potvrzený daňový odpis") —
                    // právě ta uživateli řekne, co má dodělat, takže ji posíláme dál.
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $warnings;
    }

    /** Název karty pro varování; při nenalezení radši id než prázdno. */
    private function cardName(array $item, int $supplierId): string
    {
        $table = $item['small_asset_id'] !== null ? 'small_assets' : 'assets';
        $id    = (int) ($item['small_asset_id'] ?? $item['asset_id']);
        $card  = $this->cardState($table, $supplierId, $id);
        return (string) ($card['name'] ?? ('#' . $id));
    }

    private function sellSmallAsset(int $supplierId, int $cardId, int $invoiceId, string $soldAt, float $price, array $meta): void
    {
        $card = $this->cardState('small_assets', $supplierId, $cardId);
        if ($card === null || ($card['status'] === 'sold' && (int) $card['sale_invoice_id'] === $invoiceId)) {
            return;   // neexistuje (smazaná mezitím) nebo už prodaná tímhle dokladem
        }

        $this->smallAssets->sell($supplierId, $cardId, $invoiceId, $soldAt, $price);
        $this->activity->log(
            'small_asset.sold',
            $meta['user_id'] ?? null,
            'small_asset',
            $cardId,
            ['sale_invoice_id' => $invoiceId, 'sold_at' => $soldAt, 'sale_price' => $price, 'trigger' => 'invoice_issued'],
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null,
            $supplierId,
        );
    }

    /** @return array{code:string, name:string, message:string}|null varování, když se vyřazení nespustilo */
    private function sellAsset(int $supplierId, int $assetId, int $invoiceId, string $soldAt, float $price, array $meta): ?array
    {
        $card = $this->cardState('assets', $supplierId, $assetId);
        if ($card === null || ($card['status'] === 'disposed' && (int) $card['sale_invoice_id'] === $invoiceId)) {
            return null;
        }
        // Vyřazení dlouhodobého majetku JE účetní zápis (541/08x + 08x/02x). V daňové evidenci
        // se deník nevede a celý modul majetku je za GuardsAccountingMode::requireDoubleEntry —
        // volat sem dispose() by skončilo chybou období. Radši explicitní audit záznam.
        if (!$this->isDoubleEntry($supplierId)) {
            $this->activity->log(
                'asset_sale.auto_skipped',
                $meta['user_id'] ?? null,
                'invoice',
                $invoiceId,
                ['asset_id' => $assetId, 'reason' => 'single_entry_mode'],
                $meta['ip'] ?? null,
                $meta['user_agent'] ?? null,
                $supplierId,
            );
            return [
                'code' => 'single_entry_mode',
                'name' => (string) ($card['name'] ?? ('#' . $assetId)),
                'message' => 'Vyřazení dlouhodobého majetku je účetní zápis — v daňové evidenci se neprovádí. '
                    . 'Kartu vyřaďte ručně v evidenci majetku.',
            ];
        }

        $this->assets->dispose($supplierId, $assetId, [
            'date'  => $soldAt,
            'type'  => 'sold',
            'price' => $price,
            'sale_invoice_id' => $invoiceId,
        ], [
            'user_id'    => $meta['user_id'] ?? null,
            'posted_by'  => $meta['user_id'] ?? null,
            'ip'         => $meta['ip'] ?? null,
            'user_agent' => $meta['user_agent'] ?? null,
        ]);
        $this->activity->log(
            'asset.disposed',
            $meta['user_id'] ?? null,
            'asset',
            $assetId,
            ['type' => 'sold', 'date' => $soldAt, 'price' => $price, 'sale_invoice_id' => $invoiceId, 'trigger' => 'invoice_issued'],
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null,
            $supplierId,
        );
        return null;
    }

    /**
     * Vrátí kartu zpět do užívání — volá se při stornu faktury prodeje. Chyby polyká stejně
     * jako prodejní směr: storno faktury nesmí spadnout kvůli kartě.
     *
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string} $meta
     */
    public function revertForInvoice(int $supplierId, int $invoiceId, array $meta = []): void
    {
        foreach ($this->linkedItems($invoiceId) as $item) {
            try {
                if ($item['small_asset_id'] !== null) {
                    $card = $this->cardState('small_assets', $supplierId, (int) $item['small_asset_id']);
                    if ($card !== null && (int) $card['sale_invoice_id'] === $invoiceId) {
                        $this->smallAssets->restore($supplierId, (int) $item['small_asset_id']);
                    }
                } elseif ($item['asset_id'] !== null) {
                    $card = $this->cardState('assets', $supplierId, (int) $item['asset_id']);
                    if ($card !== null && (int) $card['sale_invoice_id'] === $invoiceId && $card['status'] === 'disposed') {
                        // R24: storna disposal zápisu i odpisu roku vyřazení; jen dokud je
                        // období vyřazení otevřené — jinak vyhodí AssetException do catch níž.
                        $this->assets->revertDisposal($supplierId, (int) $item['asset_id'], [
                            'user_id'    => $meta['user_id'] ?? null,
                            'posted_by'  => $meta['user_id'] ?? null,
                            'ip'         => $meta['ip'] ?? null,
                            'user_agent' => $meta['user_agent'] ?? null,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                if (TransactionFailure::aborted($e) && $this->db->pdo()->inTransaction()) {
                    throw $e;
                }
                $this->activity->log(
                    'asset_sale.revert_failed',
                    $meta['user_id'] ?? null,
                    'invoice',
                    $invoiceId,
                    [
                        'small_asset_id' => $item['small_asset_id'] !== null ? (int) $item['small_asset_id'] : null,
                        'asset_id'       => $item['asset_id'] !== null ? (int) $item['asset_id'] : null,
                        'message'        => $e->getMessage(),
                    ],
                    $meta['ip'] ?? null,
                    $meta['user_agent'] ?? null,
                    $supplierId,
                );
            }
        }
    }

    /**
     * @return array{invoice_type:string, sale_date:string, czk_rate:float}|null
     */
    private function header(int $supplierId, int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.invoice_type, COALESCE(i.tax_date, i.issue_date) AS sale_date,
                    i.exchange_rate, COALESCE(cur.code, 'CZK') AS currency
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?"
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['sale_date'] === null) {
            return null;
        }
        // Karta majetku i prodejní cena na ní jsou vždy v CZK (stejně jako práh §26/2 ZDP),
        // takže cizoměnovou fakturu přepočítáme kurzem dokladu; u CZK je kurz 1,0.
        $rate = ($row['exchange_rate'] !== null && (float) $row['exchange_rate'] > 0.0)
            ? (float) $row['exchange_rate'] : 1.0;

        return [
            'invoice_type' => (string) $row['invoice_type'],
            'sale_date'    => (string) $row['sale_date'],
            'czk_rate'     => ((string) $row['currency'] === 'CZK') ? 1.0 : $rate,
        ];
    }

    /** @return list<array{small_asset_id:?string, asset_id:?string, total_without_vat:string, import_tax_base_czk:?string}> */
    private function linkedItems(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT small_asset_id, asset_id, total_without_vat, import_tax_base_czk
               FROM invoice_items
              WHERE invoice_id = ? AND (small_asset_id IS NOT NULL OR asset_id IS NOT NULL)
              ORDER BY order_index, id'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{status:string, sale_invoice_id:?string, name:string}|null */
    private function cardState(string $table, int $supplierId, int $id): ?array
    {
        // $table je literál z volajícího (nikdy user input), id/supplier jdou přes parametry.
        $stmt = $this->db->pdo()->prepare(
            "SELECT status, sale_invoice_id, name FROM {$table} WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() === 'double_entry';
    }
}
