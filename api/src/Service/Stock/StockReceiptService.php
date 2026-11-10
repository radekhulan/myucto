<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockDocumentRepository;
use PDO;

/**
 * Příjem na sklad z přijaté faktury (Epic SKLAD §5.6) — návrh řádků (zbývá
 * přijmout, párování {@see StockDocumentRepository::receivedQtyByPurchaseInvoiceItem()}),
 * založení DRAFT příjemky vč. rozpuštění vedlejších pořizovacích nákladů
 * ({@see LandedCostAllocator}). Čtení `purchase_invoices`/`purchase_invoice_items`
 * přímým SQL (tenant-scoped) — repository té domény patří modulu PF (fáze 1),
 * tady jde jen o čtení pro návrh (žádná mutace PF).
 */
final class StockReceiptService
{
    /**
     * Druhy přijatých dokladů, ze kterých se sklad NEPOHYBUJE — zrcadlo § 5.4
     * z výdejové strany ({@see StockIssueService}, kde je totéž pro proforma /
     * tax_document / cancellation / credit_note).
     *
     * Bez tohoto filtru šlo založit příjemku ze zálohové faktury i z DDKP a pak
     * ZNOVU z vyúčtovací faktury — dvojí naskladnění i dvojí vedlejší náklady.
     * Dedup je totiž per doklad (`receivedQtyByPurchaseInvoiceItem` filtruje
     * `purchase_invoice_id`), takže záloha a finál mají každý vlastní kvótu
     * a vazba `advance_purchase_invoice_id` se skladu nijak netýká.
     *
     * `credit_note` je tu taky, a je to nutnější, než by se zdálo: vratka dodavateli
     * je VÝDEJ, ne příjem. Dobropis se zápornou quantity dnes spadne na `over_receipt`
     * (remaining < 0), ale dobropis s KLADNOU quantity a zápornou cenou projde
     * a vyrobí příjemku se ZÁPORNOU pořizovací cenou — tichá deformace ocenění zásob.
     *
     * @var array<string,string>
     */
    private const NOT_RECEIVABLE_MESSAGES = [
        'advance'      => 'Ze zálohové faktury se sklad nepohybuje — příjemku vystav až z vyúčtovací faktury.',
        'tax_document' => 'Z daňového dokladu k záloze se sklad nepohybuje — nese jen DPH, ne zboží.',
        'credit_note'  => 'Dobropis je vratka dodavateli — použij výdejku, ne příjemku.',
    ];

    public const CARD_WRITE_FORBIDDEN = 'Kód dodavatele ani údaje pro Intrastat nelze na kartu zapsat: nemáte právo upravovat skladové karty.';

    public function __construct(
        private readonly Connection $db,
        private readonly StockDocumentService $documents,
        private readonly StockDocumentRepository $docs,
        private readonly StockAcquisitionCostService $costs,
        private readonly StockUnitConverter $units,
        private readonly PurchaseLineStockMatcher $matcher,
    ) {}

    private static function isNotReceivableKind(string $documentKind): bool
    {
        return isset(self::NOT_RECEIVABLE_MESSAGES[$documentKind]);
    }

    /**
     * Návrh příjemky: řádky PF s kartou (ručně přiřazenou, nebo spárovanou podle kódu;
     * zbývá přijmout, návrh PC), řádky bez karty jako kandidáti na vedlejší náklady.
     * Nespárované řádky s kódem jdou navíc do `unmatched_lines`, ať jim člověk kartu vybere.
     *
     * @param array<int,int> $cardOverrides purchase_invoice_item_id => karta, kterou člověk vybral: návrh
     *                                      řádku se přepočte podle balení této karty (množství, cena)
     * @return array<string,mixed>
     */
    public function proposeForPurchaseInvoice(int $supplierId, int $piId, array $cardOverrides = []): array
    {
        $pi = $this->findPurchaseInvoice($supplierId, $piId);
        if ($pi === null) {
            throw new StockException('not_found', 'Přijatá faktura nenalezena.', 404);
        }
        // Doklady, ze kterých se sklad nepohybuje — návrh je prázdný, ať UI tlačítko
        // „Příjem na sklad" vůbec nenabídne (zrcadlo §5.4 z výdejové strany).
        if (self::isNotReceivableKind((string) ($pi['document_kind'] ?? 'invoice'))) {
            return [
                'purchase_invoice_id' => $piId,
                'lines'               => [],
                'unmatched_lines'     => [],
                'cost_candidates'     => [],
                'not_receivable_kind' => (string) $pi['document_kind'],
            ];
        }

        $costContext = $this->costs->context($supplierId, $pi);
        $received   = $this->docs->receivedQtyByPurchaseInvoiceItem($supplierId, $piId);

        $stockLines    = [];
        $unmatchedLines = [];
        $costCandidates = [];
        $items = $this->purchaseInvoiceItems($supplierId, $piId, $this->ownGoodsCards($supplierId, $cardOverrides));
        $codeHolders = $this->matcher->cardsHoldingCodes($supplierId, (int) $pi['vendor_id'], array_column($items, 'seller_item_code'));
        foreach ($items as $it) {
            $holders = $codeHolders[mb_strtolower(trim((string) $it['seller_item_code']))] ?? [];
            // Množství řádku v ZÁKLADNÍ jednotce karty (balení 10 KT = 80 ks);
            // příjemka i „zbývá přijmout" se počítají v něm.
            $qty            = (float) $it['base_quantity'];
            $alreadyReceived = isset($received[(int) $it['id']]) ? (float) $received[(int) $it['id']] : 0.0;
            $remaining      = round($qty - $alreadyReceived, 3);
            if ($remaining < 0) {
                $remaining = 0.0;
            }

            if ($it['stock_item_id'] !== null && $it['item_type'] !== 'service') {
                $unitCost = $this->costs->unitCost($costContext, ['quantity' => $it['base_quantity']] + $it);
                $stockLines[] = [
                    'order_index'              => (int) $it['order_index'],
                    'seller_item_code'         => $it['seller_item_code'],
                    'vendor_code_cards'        => $holders,
                    'intrastat_cn8_code'       => $it['intrastat_cn8_code'],
                    'intrastat_country_of_origin' => $it['intrastat_country_of_origin'],
                    'matched_stock_item_id'    => $it['matched_stock_item_id'],
                    'match_source'             => $it['match_source'],
                    'purchase_invoice_item_id' => (int) $it['id'],
                    // Příjem z faktury musí zavírat i objednávku, na kterou je řádek
                    // napárovaný — bez toho by zboží zůstalo věčně „na cestě",
                    // přestože fyzicky leží ve skladu.
                    'purchase_order_line_id'   => $it['purchase_order_line_id'],
                    'stock_item_id'            => (int) $it['stock_item_id'],
                    'description'              => (string) $it['description'],
                    'quantity'                 => number_format($qty, 3, '.', ''),
                    'already_received'         => number_format($alreadyReceived, 3, '.', ''),
                    'remaining_qty'            => number_format($remaining, 3, '.', ''),
                    'unit_cost'                => number_format($unitCost, 6, '.', ''),
                    'invoice_quantity'         => number_format((float) $it['quantity'], 3, '.', ''),
                    'invoice_unit'             => (string) $it['unit'],
                ];
            } else {
                if ($it['stock_item_id'] === null && $remaining > 0 && $it['expense_kind'] !== 'service'
                    && ($it['seller_item_code'] !== null || $it['item_ean'] !== null)) {
                    $unmatchedLines[] = [
                        'order_index'              => (int) $it['order_index'],
                        'vendor_code_cards'        => $holders,
                        'purchase_invoice_item_id' => (int) $it['id'],
                        'purchase_order_line_id'   => $it['purchase_order_line_id'],
                        'stock_item_id'            => null,
                        'seller_item_code'         => $it['seller_item_code'],
                        'intrastat_cn8_code'       => $it['intrastat_cn8_code'],
                        'intrastat_country_of_origin' => $it['intrastat_country_of_origin'],
                        'matched_stock_item_id'    => $it['matched_stock_item_id'],
                        'match_source'             => $it['match_source'],
                        'description'              => (string) $it['description'],
                        'quantity'                 => number_format($qty, 3, '.', ''),
                        'already_received'         => number_format($alreadyReceived, 3, '.', ''),
                        'remaining_qty'            => number_format($remaining, 3, '.', ''),
                        'unit_cost'                => number_format($this->costs->unitCost($costContext, ['quantity' => $it['base_quantity']] + $it), 6, '.', ''),
                    ];
                }
                $base = $this->costs->amount($costContext, $it);
                $costCandidates[] = [
                    'purchase_invoice_item_id' => (int) $it['id'],
                    'description'              => (string) $it['description'],
                    'amount'                   => number_format($base, 2, '.', ''),
                ];
            }
        }

        return [
            'purchase_invoice' => [
                'id'                    => (int) $pi['id'],
                'varsymbol'             => $pi['varsymbol'],
                'vendor_invoice_number' => (string) $pi['vendor_invoice_number'],
                'vendor_name'           => $pi['vendor_name'],
                'currency_code'         => (string) $pi['currency_code'],
                'exchange_rate'         => $pi['exchange_rate'],
            ],
            'lines'                    => $stockLines,
            'unmatched_lines'          => $unmatchedLines,
            'cost_candidates'          => $costCandidates,
            // B6: existuje posted příjemka k této PF, jejíž zdrojový řádek PF byl
            // od té doby přepsán (SetPurchaseInvoiceItemsAction je replace-all,
            // purchase_invoice_item_id → NULL) — „zbývá přijmout" pak nespolehlivé.
            'pf_changed_after_receipt' => $this->hasOrphanedReceiptLines($supplierId, $piId),
        ];
    }

    /**
     * Založí DRAFT příjemku z vybraných řádků PF (částečné příjmy, B6) vč.
     * rozpuštění vedlejších nákladů (A8, jen draft). Post provádí uživatel ručně
     * z editoru dokladu ({@see StockDocumentService::post()}).
     *
     * Volby `lines[].remember_seller_code` a `lines[].fill_intrastat` = true chystají zápis do karty,
     * ten proběhne až při zaúčtování ({@see ReceiptCardUpdater}). Bez práva `stock.items.write`
     * ($canWriteCards) se celý požadavek odmítne a nic nevznikne.
     *
     * @param array<string,mixed> $body {warehouse_id, doc_date, description?, lines:list, landed_costs?:list}
     * @return array<string,mixed>
     */
    public function createReceipt(int $supplierId, int $piId, array $body, ?int $userId, bool $canWriteCards = false): array
    {
        return $this->runInTransaction(function () use ($supplierId, $piId, $body, $userId, $canWriteCards): array {
            $pi = $this->findPurchaseInvoice($supplierId, $piId);
            if ($pi === null) {
                throw new StockException('not_found', 'Přijatá faktura nenalezena.', 404);
            }
            // Obranná linie proti přímému volání API — filtr v propose() jen skryje tlačítko.
            $kind = (string) ($pi['document_kind'] ?? 'invoice');
            if (self::isNotReceivableKind($kind)) {
                throw new StockException(
                    'invalid_document',
                    self::NOT_RECEIVABLE_MESSAGES[$kind] ?? 'Z tohoto dokladu se sklad nepohybuje.',
                    422,
                );
            }

            $warehouseId = (int) ($body['warehouse_id'] ?? 0);
            $docDate     = trim((string) ($body['doc_date'] ?? ''));
            if (!self::isDate($docDate)) {
                throw new StockException('invalid_document', 'Datum příjemky je povinné (YYYY-MM-DD).');
            }

            $rawLines = is_array($body['lines'] ?? null) ? $body['lines'] : [];
            if ($rawLines === []) {
                throw new StockException('invalid_document', 'Příjemka musí mít aspoň jeden řádek.');
            }

            $costContext = $this->costs->context($supplierId, $pi);
            $piItemsById = $this->purchaseInvoiceItemsById($supplierId, $piId);
            $received    = $this->docs->receivedQtyByPurchaseInvoiceItem($supplierId, $piId);
            $earlierCards = $this->docs->receiptCardsByPurchaseInvoiceItem($supplierId, $piId);

            if (!$canWriteCards) {
                foreach ($rawLines as $rl) {
                    if (is_array($rl) && (($rl['remember_seller_code'] ?? false) === true || ($rl['fill_intrastat'] ?? false) === true)) {
                        throw new StockException('card_write_forbidden', self::CARD_WRITE_FORBIDDEN, 403);
                    }
                }
            }

            $docLines = [];
            $rememberCodes = [];
            foreach ($rawLines as $rl) {
                if (!is_array($rl)) {
                    continue;
                }
                $piItemId = (int) ($rl['purchase_invoice_item_id'] ?? 0);
                $piItem   = $piItemsById[$piItemId] ?? null;
                if ($piItem === null) {
                    throw new StockException('invalid_document', 'Položka přijaté faktury nenalezena.', 422, [
                        'purchase_invoice_item_id' => $piItemId,
                    ]);
                }
                $stockItemId = (int) ($rl['stock_item_id'] ?? ($piItem['stock_item_id'] ?? 0));
                if ($stockItemId <= 0) {
                    throw new StockException('invalid_document', 'Řádek musí mít přiřazenou skladovou kartu.', 422, [
                        'purchase_invoice_item_id' => $piItemId,
                    ]);
                }
                // Přijaté množství je v jednotce karty dřívější příjemky: na jinou kartu by se sčítaly různé jednotky.
                $otherCards = array_values(array_diff($earlierCards[$piItemId] ?? [], [$stockItemId]));
                if ($otherCards !== []) {
                    throw new StockException('receipt_card_mismatch', 'Řádek faktury už má příjemku na jinou skladovou kartu. Zbytek přijměte na stejnou kartu.', 409, [
                        'purchase_invoice_item_id' => $piItemId,
                        'stock_item_ids'           => $otherCards,
                    ]);
                }
                // Balení přepočti podle odeslané karty, ne podle karty z faktury nebo z návrhu párování.
                if ($stockItemId !== (int) ($piItem['stock_item_id'] ?? $piItem['matched_stock_item_id'] ?? 0)) {
                    $ratio = $this->units->ratios($supplierId, [['stock_item_id' => $stockItemId, 'unit' => $piItem['unit']]])[0];
                    $piItem['base_quantity'] = StockUnitConverter::applyRatio((string) $piItem['quantity'], $ratio['numerator'], $ratio['denominator']);
                }

                $qty = (float) ($rl['quantity'] ?? $rl['qty'] ?? 0);
                if ($qty <= 0) {
                    throw new StockException('invalid_document', 'Množství musí být větší než 0.', 422, [
                        'purchase_invoice_item_id' => $piItemId,
                    ]);
                }
                $already   = isset($received[$piItemId]) ? (float) $received[$piItemId] : 0.0;
                $remaining = (float) $piItem['base_quantity'] - $already;
                if ($qty > $remaining + 0.0005) {
                    throw new StockException('over_receipt', 'Množství přesahuje zbývající k příjmu z faktury.', 409, [
                        'purchase_invoice_item_id' => $piItemId,
                        'requested'                => number_format($qty, 3, '.', ''),
                        'remaining'                => number_format(max(0, $remaining), 3, '.', ''),
                    ]);
                }

                if (isset($rl['unit_cost']) && $rl['unit_cost'] !== '' && $rl['unit_cost'] !== null) {
                    $unitCost = (float) $rl['unit_cost'];
                } else {
                    // Pořizovací cena za ZÁKLADNÍ jednotku: hodnota řádku se nemění,
                    // jen se rozloží na množství v základní jednotce.
                    $unitCost = $this->costs->unitCost($costContext, ['quantity' => $piItem['base_quantity']] + $piItem);
                }

                $remember = ($rl['remember_seller_code'] ?? false) === true && $piItem['seller_item_code'] !== null;
                if ($remember) {
                    $rememberCodes[] = [$stockItemId, (string) $piItem['seller_item_code']];
                }

                $docLines[] = [
                    'stock_item_id'            => $stockItemId,
                    'qty'                      => number_format($qty, 3, '.', ''),
                    'unit_cost'                => number_format($unitCost, 6, '.', ''),
                    'extra_cost'               => '0',
                    'purchase_invoice_item_id' => $piItemId,
                    'purchase_order_line_id'   => $piItem['purchase_order_line_id'] ?? null,
                    'remember_seller_code'     => $remember,
                    'fill_intrastat'           => ($rl['fill_intrastat'] ?? false) === true,
                    'source_description'       => (string) $piItem['description'],
                    'source_qty'               => (string) $piItem['base_quantity'],
                ];
            }

            // Jeden kód patří jedné kartě: dvě různé karty v jednom požadavku by tiše přepsal poslední řádek.
            $cardByCode = [];
            foreach ($rememberCodes as [$stockItemId, $code]) {
                $codeKey = mb_strtolower($code);
                if (isset($cardByCode[$codeKey]) && $cardByCode[$codeKey] !== $stockItemId) {
                    throw new StockException('invalid_document', 'Stejný kód dodavatele nelze uložit ke dvěma různým kartám.', 422, [
                        'seller_item_code' => $code,
                        'stock_item_ids'   => [$cardByCode[$codeKey], $stockItemId],
                    ]);
                }
                $cardByCode[$codeKey] = $stockItemId;
            }

            $description = trim((string) ($body['description'] ?? ''));
            if ($description === '') {
                $description = 'Příjem z PF ' . ((string) ($pi['varsymbol'] ?? ('#' . $piId)));
            }

            $draft = $this->documents->create($supplierId, [
                'doc_type'            => 'receipt',
                'origin'              => 'purchase_invoice',
                'warehouse_id'        => $warehouseId,
                'doc_date'            => $docDate,
                'description'         => $description,
                'partner_name'        => $pi['vendor_name'],
                'purchase_invoice_id' => $piId,
                'lines'               => $docLines,
            ], $userId);

            $this->costs->applyLandedCosts($supplierId, (int) $draft['id'], $docDate, is_array($body['landed_costs'] ?? null) ? $body['landed_costs'] : []);

            $result = $this->docs->findWithLines($supplierId, (int) $draft['id']) ?? $draft;
            return $result;
        });
    }

    /** @return list<array<string,mixed>> */
    public function receiptsForPurchaseInvoice(int $supplierId, int $piId): array
    {
        return $this->docs->listByPurchaseInvoice($supplierId, $piId);
    }

    // ── čtení PF (přímý SQL, tenant-scoped, jen SELECT) ─────────────────────────

    /** @return array<string,mixed>|null */
    private function findPurchaseInvoice(int $supplierId, int $piId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.id, pi.vendor_id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                    pi.currency_id, pi.exchange_rate, pi.tax_date, pi.issue_date,
                    c.code AS currency_code, cl.company_name AS vendor_name
               FROM purchase_invoices pi
               JOIN currencies c ON c.id = pi.currency_id AND c.supplier_id = pi.supplier_id
          LEFT JOIN clients cl ON cl.id = pi.vendor_id AND cl.supplier_id = pi.supplier_id
              WHERE pi.supplier_id = ? AND pi.id = ?'
        );
        $stmt->execute([$supplierId, $piId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<int,int> $overrides purchase_invoice_item_id => karta
     * @return array<int,int> jen karty zboží této firmy
     */
    private function ownGoodsCards(int $supplierId, array $overrides): array
    {
        if ($overrides === []) {
            return [];
        }
        $cards = array_values(array_unique($overrides));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM stock_items WHERE supplier_id = ? AND item_type <> 'service'
                AND id IN (" . implode(',', array_fill(0, count($cards), '?')) . ')'
        );
        $stmt->execute([$supplierId, ...$cards]);
        $own = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        return array_filter($overrides, static fn (int $card): bool => in_array($card, $own, true));
    }

    /**
     * @param array<int,int> $cardOverrides purchase_invoice_item_id => vybraná karta
     * @return list<array<string,mixed>>
     */
    private function purchaseInvoiceItems(int $supplierId, int $piId, array $cardOverrides = []): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.order_index, pii.stock_item_id, pii.purchase_order_line_id, pii.description,
                    pii.quantity, pii.unit, pii.total_without_vat, pii.total_with_vat, si.item_type,
                    pii.seller_item_code, pii.item_ean, pii.expense_kind, pi.vendor_id,
                    pii.intrastat_cn8_code, pii.intrastat_country_of_origin
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
          LEFT JOIN stock_items si ON si.id = pii.stock_item_id AND si.supplier_id = pi.supplier_id
              WHERE pi.supplier_id = ? AND pii.purchase_invoice_id = ?
              ORDER BY pii.order_index ASC, pii.id ASC'
        );
        $stmt->execute([$supplierId, $piId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Párování se počítá při čtení a na řádek faktury se nezapisuje: nemění účtování
        // dokladu a řádek se spáruje i podle karty nebo kódu doplněného až po importu.
        // Řádek vedený jako služba se sám nespáruje, stejně jako ho nenabízí `unmatched_lines`.
        $goods = array_filter($rows, static fn (array $r): bool => $r['expense_kind'] !== 'service');
        $matches = $goods === [] ? [] : $this->matcher->match($supplierId, (int) $rows[0]['vendor_id'], $goods);
        foreach ($rows as $index => &$r) {
            $r['matched_stock_item_id'] = $matches[$index]['stock_item_id'] ?? null;
            $r['match_source'] = $matches[$index]['source'] ?? null;
            // Návrh (kód karty, kód jako EAN) se sám nepoužije, kartu potvrdí člověk nebo volající.
            if ($matches[$index]['auto'] ?? false) {
                $r['stock_item_id'] ??= $r['matched_stock_item_id'];
            }
            if (isset($cardOverrides[(int) $r['id']])) {
                $r['stock_item_id'] = $cardOverrides[(int) $r['id']];
            }
        }
        unset($r);
        // Balení (issue #17): `base_quantity` = množství v základní jednotce karty
        // přes StockUnitConverter; řádek bez karty zůstává 1:1.
        $ratios = $this->units->ratios($supplierId, array_map(
            static fn (array $r): array => ['stock_item_id' => (int) ($r['stock_item_id'] ?? $r['matched_stock_item_id'] ?? 0), 'unit' => $r['unit']],
            $rows,
        ));
        foreach ($rows as $index => &$r) {
            $r['id']            = (int) $r['id'];
            $r['stock_item_id'] = $r['stock_item_id'] !== null ? (int) $r['stock_item_id'] : null;
            $r['purchase_order_line_id'] = $r['purchase_order_line_id'] !== null ? (int) $r['purchase_order_line_id'] : null;
            $r['base_quantity'] = ($r['stock_item_id'] ?? $r['matched_stock_item_id']) !== null
                ? StockUnitConverter::applyRatio((string) $r['quantity'], $ratios[$index]['numerator'], $ratios[$index]['denominator'])
                : (string) $r['quantity'];
        }
        unset($r);
        return $rows;
    }

    /** @return array<int,array<string,mixed>> keyed by purchase_invoice_item_id */
    private function purchaseInvoiceItemsById(int $supplierId, int $piId): array
    {
        $out = [];
        foreach ($this->purchaseInvoiceItems($supplierId, $piId) as $it) {
            $out[(int) $it['id']] = $it;
        }
        return $out;
    }

    private function hasOrphanedReceiptLines(int $supplierId, int $piId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT EXISTS (
                SELECT 1
                  FROM stock_document_lines l
                  JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
                 WHERE l.supplier_id = ? AND d.purchase_invoice_id = ? AND d.doc_type = 'receipt'
                   AND d.status = 'posted' AND l.purchase_invoice_item_id IS NULL
             )"
        );
        $stmt->execute([$supplierId, $piId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function runInTransaction(callable $fn)
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }

        for ($attempt = 0; ; $attempt++) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            try {
                $result = $fn();
                $pdo->commit();
                return $result;
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mysqlCode = (int) ($e->errorInfo[1] ?? 0);
                if ($attempt === 0 && ($mysqlCode === 1213 || $mysqlCode === 1205)) {
                    continue;
                }
                throw $e;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
