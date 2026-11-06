<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/accounting/journal/for-document/{source}/{id} — zaúčtování faktury
 * (vydané i přijaté) i s řádky.
 *
 * Podklad pro sbalenou sekci „Zaúčtování" na detailu faktury. Detail dosud uměl
 * jen odskok do deníku, takže účetní musel kvůli jednomu pohledu na kontaci
 * opustit doklad. Pokladní doklad sekci nemá — tam kontace vede jen jeden zápis
 * a řádek pokladny na něj rovnou odkazuje.
 *
 * Vrací VŠECHNY zápisy dokladu (původní i storno), řazené od nejstaršího —
 * doklad může mít protizápis a účetní musí vidět obojí.
 *
 * V daňové evidenci deník neexistuje; místo chyby se vrací prázdný seznam, aby
 * volající sekci prostě nezobrazil (načítá se na pozadí, chyba by tam byla šum).
 *
 * Právo řeší RoutePermissionMap: GET /api/accounting/journal(/|$) → 'accounting' READ.
 * Tenant se bere ze session a filtruje se jím dotaz — cizí doklad nevrátí nic.
 */
final class JournalForDocumentAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    /** URL segment → journal_entries.source_type */
    private const SOURCES = [
        'invoices'           => 'invoice',
        'purchase-invoices'  => 'purchase_invoice',
    ];

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly Connection $db,
        private readonly DimensionAssignmentRepository $dimensions,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $sourceType = self::SOURCES[(string) ($args['source'] ?? '')] ?? null;
        $docId = (int) ($args['id'] ?? 0);
        if ($sourceType === null || $docId <= 0) {
            return Json::error($response, 'validation_failed', 'Neznámý typ dokladu.', 422);
        }

        if (!$this->accountingModeIs($this->db, $supplierId, 'double_entry')) {
            return Json::ok($response, ['items' => []]);
        }

        $entries = array_map(
            static fn (array $e): array => $e + ['relation' => 'own'],
            $this->journal->listBySourceWithLines($supplierId, $sourceType, $docId),
        );
        if ($entries === []) {
            $entries = $this->advanceEntries($supplierId, $sourceType, $docId);
        }
        if ($sourceType === 'invoice') {
            array_push($entries, ...$this->otherItemEntries($supplierId, $docId));
        }
        if ($entries !== []) {
            $accMap = $this->accounts->idToAccountMap($supplierId);
            // Dimenze řádků (Firma → Dimenze) — štítky v sekci Zaúčtování.
            $lineIds = [];
            foreach ($entries as $entry) {
                foreach ($entry['lines'] as $line) {
                    $lineIds[] = (int) $line['id'];
                }
            }
            $lineDims = $this->dimensions->lineDimensions($supplierId, $lineIds);
            foreach ($entries as $i => $entry) {
                $entries[$i]['lines'] = array_map(static function (array $line) use ($accMap, $lineDims): array {
                    $acc = $accMap[(int) $line['account_id']] ?? null;
                    $line['account_code'] = $acc['code'] ?? null;
                    $line['account_name'] = $acc['name'] ?? null;
                    $line['dimensions'] = (object) ($lineDims[(int) $line['id']] ?? []);
                    return $line;
                }, $entry['lines']);
            }
        }

        return Json::ok($response, ['items' => $entries]);
    }

    /**
     * Faktura krytá ostatní pohledávkou (migrace 2246) vlastní zápis nemá, účtuje ji živý
     * zápis pohledávky. Sekce ho ukáže jen ke čtení, opravuje se v detailu pohledávky.
     *
     * @return list<array<string,mixed>>
     */
    private function otherItemEntries(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT oi.id, oi.document_no FROM invoices i
               JOIN other_items oi ON oi.supplier_id = i.supplier_id AND oi.id = i.other_item_id
              WHERE i.id = ? AND i.supplier_id = ?'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $item = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($item === false) {
            return [];
        }
        $out = [];
        foreach ($this->journal->listBySourceWithLines($supplierId, 'other_item', (int) $item['id']) as $entry) {
            if ($entry['reversed_by'] !== null || $entry['posted_at'] === null || $entry['source_id'] === null) {
                continue;
            }
            $entry['relation'] = 'other_item';
            $entry['other_item_id'] = (int) $item['id'];
            $entry['other_item_document_no'] = $item['document_no'];
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Záloha (zálohová PF, proforma) vlastní zápis nemá — zaúčtuje se její úhrada
     * (314/221, 221/324) a zúčtování v konečné faktuře. Prázdná sekce Zaúčtování
     * vypadala jako chyba „nic se nezaúčtovalo", proto se ukážou živé zápisy úhrad
     * (banka, pokladna) a konečné faktury, označené vztahem k záloze.
     *
     * @return list<array<string,mixed>>
     */
    private function advanceEntries(int $supplierId, string $sourceType, int $docId): array
    {
        $pdo = $this->db->pdo();
        if ($sourceType === 'purchase_invoice') {
            $kind = $pdo->prepare('SELECT document_kind FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
            $kind->execute([$docId, $supplierId]);
            if ($kind->fetchColumn() !== 'advance') {
                return [];
            }
            $sources = [
                'bank' => 'SELECT DISTINCT bank_transaction_id FROM payment_matches
                            WHERE supplier_id = ? AND purchase_invoice_id = ? AND bank_transaction_id IS NOT NULL',
                'cash' => 'SELECT id FROM cash_documents WHERE supplier_id = ? AND purchase_invoice_id = ?',
                'purchase_invoice' => "SELECT id FROM purchase_invoices
                            WHERE supplier_id = ? AND advance_purchase_invoice_id = ? AND status <> 'cancelled'",
            ];
        } else {
            $kind = $pdo->prepare('SELECT invoice_type FROM invoices WHERE id = ? AND supplier_id = ?');
            $kind->execute([$docId, $supplierId]);
            if ($kind->fetchColumn() !== 'proforma') {
                return [];
            }
            $sources = [
                'bank' => 'SELECT DISTINCT bank_transaction_id FROM invoice_payments
                            WHERE supplier_id = ? AND invoice_id = ? AND bank_transaction_id IS NOT NULL',
                'cash' => 'SELECT id FROM cash_documents WHERE supplier_id = ? AND invoice_id = ?',
                'invoice' => "SELECT id FROM invoices
                            WHERE supplier_id = ? AND parent_invoice_id = ? AND invoice_type = 'invoice' AND status <> 'cancelled'",
            ];
        }

        $out = [];
        foreach ($sources as $type => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplierId, $docId]);
            foreach (array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
                foreach ($this->journal->listBySourceWithLines($supplierId, $type, $id) as $entry) {
                    if ($entry['reversed_by'] !== null || $entry['posted_at'] === null || $entry['source_id'] === null) {
                        continue; // jen živé zaúčtování, historii ukazuje doklad, kterému patří
                    }
                    $entry['relation'] = in_array($type, ['bank', 'cash'], true) ? 'advance_payment' : 'advance_final';
                    $out[] = $entry;
                }
            }
        }
        return $out;
    }
}
