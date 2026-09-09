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

    public function __construct(
        private readonly Connection $db,
        private readonly StockDocumentService $documents,
        private readonly StockDocumentRepository $docs,
        private readonly StockAcquisitionCostService $costs,
    ) {}

    private static function isNotReceivableKind(string $documentKind): bool
    {
        return isset(self::NOT_RECEIVABLE_MESSAGES[$documentKind]);
    }

    /**
     * Návrh příjemky: řádky PF se stock_item_id (zbývá přijmout, návrh PC),
     * řádky bez stock_item_id jako kandidáti na vedlejší náklady.
     *
     * @return array<string,mixed>
     */
    public function proposeForPurchaseInvoice(int $supplierId, int $piId): array
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
                'cost_candidates'     => [],
                'not_receivable_kind' => (string) $pi['document_kind'],
            ];
        }

        $costContext = $this->costs->context($supplierId, $pi);
        $received   = $this->docs->receivedQtyByPurchaseInvoiceItem($supplierId, $piId);

        $stockLines    = [];
        $costCandidates = [];
        foreach ($this->purchaseInvoiceItems($supplierId, $piId) as $it) {
            $qty            = (float) $it['quantity'];
            $alreadyReceived = isset($received[(int) $it['id']]) ? (float) $received[(int) $it['id']] : 0.0;
            $remaining      = round($qty - $alreadyReceived, 3);
            if ($remaining < 0) {
                $remaining = 0.0;
            }

            if ($it['stock_item_id'] !== null) {
                $unitCost = $this->costs->unitCost($costContext, $it);
                $stockLines[] = [
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
                ];
            } else {
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
     * @param array<string,mixed> $body {warehouse_id, doc_date, description?, lines:list, landed_costs?:list}
     * @return array<string,mixed>
     */
    public function createReceipt(int $supplierId, int $piId, array $body, ?int $userId): array
    {
        return $this->runInTransaction(function () use ($supplierId, $piId, $body, $userId): array {
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

            $docLines = [];
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

                $qty = (float) ($rl['quantity'] ?? $rl['qty'] ?? 0);
                if ($qty <= 0) {
                    throw new StockException('invalid_document', 'Množství musí být větší než 0.', 422, [
                        'purchase_invoice_item_id' => $piItemId,
                    ]);
                }
                $already   = isset($received[$piItemId]) ? (float) $received[$piItemId] : 0.0;
                $remaining = (float) $piItem['quantity'] - $already;
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
                    $unitCost = $this->costs->unitCost($costContext, $piItem);
                }

                $docLines[] = [
                    'stock_item_id'            => $stockItemId,
                    'qty'                      => number_format($qty, 3, '.', ''),
                    'unit_cost'                => number_format($unitCost, 6, '.', ''),
                    'extra_cost'               => '0',
                    'purchase_invoice_item_id' => $piItemId,
                    'purchase_order_line_id'   => $piItem['purchase_order_line_id'] ?? null,
                    'source_description'       => (string) $piItem['description'],
                    'source_qty'               => (string) $piItem['quantity'],
                ];
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

            return $this->docs->findWithLines($supplierId, (int) $draft['id']) ?? $draft;
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
            'SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
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

    /** @return list<array<string,mixed>> */
    private function purchaseInvoiceItems(int $supplierId, int $piId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.stock_item_id, pii.purchase_order_line_id, pii.description,
                    pii.quantity, pii.total_without_vat, pii.total_with_vat
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pi.supplier_id = ? AND pii.purchase_invoice_id = ?
              ORDER BY pii.order_index ASC, pii.id ASC'
        );
        $stmt->execute([$supplierId, $piId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']            = (int) $r['id'];
            $r['stock_item_id'] = $r['stock_item_id'] !== null ? (int) $r['stock_item_id'] : null;
            $r['purchase_order_line_id'] = $r['purchase_order_line_id'] !== null ? (int) $r['purchase_order_line_id'] : null;
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
