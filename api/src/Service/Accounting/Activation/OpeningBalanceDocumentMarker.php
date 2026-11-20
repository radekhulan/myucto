<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Activation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AdvanceSettlementSync;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceLinks;
use MyInvoice\Support\Sql\AdvanceDrawSql;
use PDO;

/**
 * Ruční označení dokladu jako převzatého z počátečních stavů a jeho zrušení.
 *
 * Převody z cizích programů navazují doklady na otevírací zápis samy
 * ({@see \MyInvoice\Service\Migration\Shared\JournalEntryLinker::linkOpening()}). Integrace,
 * která doklady zakládá přes REST API (zálohový list a DDKP přijaté před otevřením knih,
 * neuhrazená faktura minulého roku), potřebuje stejnou vazbu bez převodu. Vazba je řádek
 * `journal_entry_document_links` na živý zaúčtovaný otevírací zápis s poznámkou
 * {@see OpeningBalanceDocuments::NOTE}; co z ní plyne, čte {@see OpeningBalanceDocuments}
 * (doklad se nedoúčtovává, DDKP nejde zaúčtovat, záloha se zúčtuje proti 324 z otevíracího
 * zápisu, saldokonto ho ukáže proti počátečnímu stavu).
 *
 * Pojistky označení: podvojné účetnictví, doklad vystavený (ne koncept ani storno), bez
 * vlastního živého zápisu, datovaný před otevíracím zápisem, jehož období je otevřené.
 * Vazba jde na nejbližší otevírací zápis po datu dokladu. Zrušení odmítne doklad, který už
 * čerpá konečná faktura, nebo proti kterému je zaúčtovaná úhrada: obojí stojí na vazbě
 * a bez ní by v deníku zůstalo zúčtování bez zdroje.
 */
final class OpeningBalanceDocumentMarker
{
    private const DOC_TYPES = ['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices'];

    public function __construct(
        private readonly Connection $db,
        private readonly AdvanceSettlementSync $settlementSync,
    ) {}

    /**
     * Stav vazby dokladu na počáteční stavy.
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @return array{in_opening:bool, entry_id:?int, entry_date:?string}
     */
    public function state(int $supplierId, string $docType, int $docId): array
    {
        self::table($docType);
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id, e.entry_date FROM journal_entry_document_links l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.doc_type = ? AND l.doc_id = ?
                AND e.source_type = 'opening' AND e.reversed_by IS NULL
              ORDER BY e.entry_date, e.id LIMIT 1"
        );
        $stmt->execute([$supplierId, $docType, $docId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? ['in_opening' => false, 'entry_id' => null, 'entry_date' => null]
            : ['in_opening' => true, 'entry_id' => (int) $row['id'], 'entry_date' => (string) $row['entry_date']];
    }

    /**
     * Naváže doklad na otevírací zápis. Už navázaný doklad nechá beze změny.
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @return array{in_opening:bool, entry_id:?int, entry_date:?string, changed:bool}
     */
    public function mark(int $supplierId, string $docType, int $docId, ?int $userId): array
    {
        $changed = $this->transactional(function () use ($supplierId, $docType, $docId, $userId): bool {
            $doc = $this->lockedDocument($supplierId, $docType, $docId);
            if ($this->state($supplierId, $docType, $docId)['in_opening']) {
                return false;
            }
            if ((new DocumentPostingGroup($this->db))->hasActive($supplierId, $docType, $docId)) {
                throw new OpeningBalanceDocumentException(
                    'document_posted',
                    'Doklad má vlastní zápis v účetním deníku. Doklad převzatý z počátečních stavů zápis mít nesmí, '
                        . 'jeho částku nese otevírací zápis. Zápis nejdřív stornujte.',
                );
            }
            $entry = $this->openingEntryAfter($supplierId, (string) $doc['doc_date']);
            $this->assertPeriodOpen($supplierId, (int) $entry['period_id']);
            $this->db->pdo()->prepare(
                'INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$supplierId, (int) $entry['id'], $docType, $docId, OpeningBalanceDocuments::NOTE, $userId]);
            return true;
        });
        if ($changed) {
            $this->syncFinalInvoices($supplierId, $docType, $docId, $userId);
        }
        return $this->state($supplierId, $docType, $docId) + ['changed' => $changed];
    }

    /**
     * Zruší vazbu dokladu na počáteční stavy. Nenavázaný doklad je chyba (409 `not_in_opening`).
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @return array{in_opening:bool, entry_id:?int, entry_date:?string, changed:bool}
     */
    public function unmark(int $supplierId, string $docType, int $docId): array
    {
        $this->transactional(function () use ($supplierId, $docType, $docId): void {
            $doc = $this->lockedDocument($supplierId, $docType, $docId, false);
            $state = $this->state($supplierId, $docType, $docId);
            if (!$state['in_opening']) {
                throw new OpeningBalanceDocumentException('not_in_opening', 'Doklad není označený jako převzatý z počátečních stavů.');
            }
            $period = $this->db->pdo()->prepare('SELECT period_id FROM journal_entries WHERE id = ? AND supplier_id = ?');
            $period->execute([(int) $state['entry_id'], $supplierId]);
            $this->assertPeriodOpen($supplierId, (int) $period->fetchColumn());
            if ($this->drawnByFinal($supplierId, $docType, $doc)) {
                throw new OpeningBalanceDocumentException(
                    'opening_document_in_use',
                    'Zálohu z dokladu už čerpá konečná faktura. Bez vazby na počáteční stavy by její zúčtování nemělo zdroj. '
                        . 'Nejdřív zrušte čerpání zálohy (nebo stornujte konečnou fakturu).',
                );
            }
            if ($this->hasPostedPayment($supplierId, $docType, $docId)) {
                throw new OpeningBalanceDocumentException(
                    'opening_document_in_use',
                    'Proti dokladu je zaúčtovaná úhrada z banky nebo pokladny, která vyrovnává jeho počáteční stav. '
                        . 'Nejdřív zrušte párování úhrady.',
                );
            }
            $this->db->pdo()->prepare(
                "DELETE l FROM journal_entry_document_links l
                   JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
                  WHERE l.supplier_id = ? AND l.doc_type = ? AND l.doc_id = ? AND e.source_type = 'opening'"
            )->execute([$supplierId, $docType, $docId]);
        });
        return $this->state($supplierId, $docType, $docId) + ['changed' => true];
    }

    /** @return array<string,mixed> doklad s `doc_date` (DUZP, jinak datum vystavení) */
    private function lockedDocument(int $supplierId, string $docType, int $docId, bool $forMark = true): array
    {
        $table = self::table($docType);
        $kind = $docType === 'invoice' ? 'invoice_type' : 'document_kind';
        $parent = $docType === 'invoice' ? 'parent_invoice_id' : 'parent_purchase_invoice_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, status, {$kind} AS kind, {$parent} AS parent_id, COALESCE(tax_date, issue_date) AS doc_date
               FROM {$table} WHERE id = ? AND supplier_id = ? FOR UPDATE"
        );
        $stmt->execute([$docId, $supplierId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($doc === false) {
            throw new OpeningBalanceDocumentException('not_found', 'Doklad nenalezen.', 404);
        }
        if (!$forMark) {
            return $doc;
        }
        $mode = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);
        if ((string) $mode->fetchColumn() !== 'double_entry') {
            throw new OpeningBalanceDocumentException(
                'not_double_entry',
                'Počáteční stavy dokladů vede jen firma s podvojným účetnictvím.',
                422,
            );
        }
        if (in_array((string) $doc['status'], ['draft', 'cancelled'], true)) {
            throw new OpeningBalanceDocumentException(
                'document_not_eligible',
                'Koncept ani stornovaný doklad nelze označit jako převzatý z počátečních stavů.',
                422,
            );
        }
        if ($doc['doc_date'] === null) {
            throw new OpeningBalanceDocumentException('document_not_eligible', 'Doklad nemá datum, nelze ho přiřadit k počátečním stavům.', 422);
        }
        return $doc;
    }

    /**
     * Nejbližší živý zaúčtovaný otevírací zápis po datu dokladu.
     *
     * @return array{id:int|string, period_id:int|string, entry_date:string}
     */
    private function openingEntryAfter(int $supplierId, string $docDate): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, period_id, entry_date FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'opening' AND posted_at IS NOT NULL AND reversed_by IS NULL
              ORDER BY entry_date, id"
        );
        $stmt->execute([$supplierId]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($entries === []) {
            throw new OpeningBalanceDocumentException(
                'opening_entry_missing',
                'Firma nemá zaúčtovaný otevírací zápis. Doklad lze označit jako převzatý z počátečních stavů až po zaúčtování počátečních stavů.',
            );
        }
        foreach ($entries as $entry) {
            if ((string) $entry['entry_date'] > $docDate) {
                return $entry;
            }
        }
        throw new OpeningBalanceDocumentException(
            'document_after_opening',
            'Doklad je datovaný ' . $docDate . ', tedy až po otevření knih (' . (string) end($entries)['entry_date'] . '). '
                . 'Z počátečních stavů lze převzít jen doklad z doby před otevíracím zápisem.',
            422,
        );
    }

    private function assertPeriodOpen(int $supplierId, int $periodId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, fiscal_year FROM accounting_periods WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$periodId, $supplierId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($period === false || (string) $period['status'] !== 'open') {
            throw new OpeningBalanceDocumentException(
                'period_closed',
                'Účetní období otevíracího zápisu' . ($period !== false ? ' ' . $period['fiscal_year'] : '')
                    . ' není otevřené. Počáteční stavy uzavřeného období nelze měnit.',
            );
        }
    }

    /**
     * Čerpá zálohu z dokladu konečná faktura (nestornovaná, i koncept), nebo čerpání
     * v saldokontu? U DDKP rozhoduje jeho záloha.
     *
     * @param array<string,mixed> $doc
     */
    private function drawnByFinal(int $supplierId, string $docType, array $doc): bool
    {
        $advanceId = (string) $doc['kind'] === 'tax_document' && $doc['parent_id'] !== null ? (int) $doc['parent_id'] : (int) $doc['id'];
        if ($docType === 'invoice') {
            $finals = 'SELECT 1 FROM (' . AdvanceDrawSql::finalIds('?', '?') . ") fx
                         JOIN invoices f ON f.id = fx.id
                        WHERE f.supplier_id = ? AND f.status <> 'cancelled'
                       UNION ALL
                       SELECT 1 FROM invoice_advance_deductions d WHERE d.supplier_id = ? AND d.advance_invoice_id = ?";
            $params = [$supplierId, $advanceId, $advanceId, $supplierId, $supplierId, $supplierId, $advanceId];
        } else {
            $finals = 'SELECT 1 FROM (' . PurchaseAdvanceLinks::finalIdsSql('?', '?') . ") fx
                         JOIN purchase_invoices f ON f.id = fx.id
                        WHERE f.supplier_id = ? AND f.status <> 'cancelled'
                       UNION ALL
                       SELECT 1 FROM purchase_advance_deductions d WHERE d.supplier_id = ? AND d.advance_invoice_id = ?";
            $params = [$supplierId, $advanceId, $supplierId, $advanceId, $supplierId, $supplierId, $advanceId];
        }
        $stmt = $this->db->pdo()->prepare($finals . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /** Je proti dokladu úhrada zaúčtovaná živým bankovním nebo pokladním zápisem? */
    private function hasPostedPayment(int $supplierId, string $docType, int $docId): bool
    {
        [$bank, $cashColumn] = $docType === 'invoice'
            ? ['invoice_payments x', 'cd.invoice_id']
            : ['payment_matches x', 'cd.purchase_invoice_id'];
        $docColumn = $docType === 'invoice' ? 'x.invoice_id' : 'x.purchase_invoice_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM {$bank}
               JOIN journal_entries je ON je.supplier_id = x.supplier_id AND je.source_type = 'bank'
                AND je.source_id = x.bank_transaction_id AND je.reversed_by IS NULL
              WHERE x.supplier_id = ? AND {$docColumn} = ?
             UNION ALL
             SELECT 1 FROM cash_documents cd
               JOIN journal_entries je ON je.supplier_id = cd.supplier_id AND je.source_type = 'cash'
                AND je.source_id = cd.id AND je.reversed_by IS NULL
              WHERE cd.supplier_id = ? AND {$cashColumn} = ?
             LIMIT 1"
        );
        $stmt->execute([$supplierId, $docId, $supplierId, $docId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Zaúčtovaná konečná faktura zálohy si zúčtování dorovná ({@see AdvanceSettlementSync}),
     * typicky faktura, která zálohu bez DDKP vyúčtovala dřív, než se záloha převzala.
     */
    private function syncFinalInvoices(int $supplierId, string $docType, int $docId, ?int $userId): void
    {
        $kind = $docType === 'invoice' ? 'invoice_type' : 'document_kind';
        $stmt = $this->db->pdo()->prepare('SELECT ' . $kind . ' FROM ' . self::table($docType) . ' WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$docId, $supplierId]);
        $value = (string) $stmt->fetchColumn();
        if ($docType === 'invoice' && $value === 'proforma') {
            $this->settlementSync->syncAdvances($supplierId, ['sale' => [$docId]], $userId);
        } elseif ($docType === 'purchase_invoice' && $value === 'advance') {
            $this->settlementSync->syncAdvances($supplierId, ['purchase' => [$docId]], $userId);
        }
    }

    private static function table(string $docType): string
    {
        return self::DOC_TYPES[$docType] ?? throw new \InvalidArgumentException('Neplatný typ dokladu.');
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transactional(callable $fn): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $result = $fn();
            if ($owns) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
