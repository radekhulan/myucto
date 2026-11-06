<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Doklad s historií EET se fyzicky nemaže (EET-07).
 *
 * Platební události a EET záznamy odkazují na fakturu a pokladní doklad bez cizího
 * klíče (migrace 2182), aby jejich smazání historii nevzalo s sebou. Smazáním
 * dokladu by ale tržba ztratila doklad, ke kterému patří. Faktura s platební
 * událostí (i jen potvrzeným QR příkazem) nebo pokladní doklad s evidovanou tržbou
 * proto jde jen stornovat; peníze vrací oprava EET. Smazání celé firmy
 * (`api/bin/delete-company.php`) tudy nevede a smí smazat vše.
 */
final class EetHistoryGuard
{
    public const ERROR_CODE = 'eet_history_exists';

    /** QR záměr, který nikdy nebyl potvrzen (rozpracovaný nebo zrušený) a nemá tržbu. Alias `e`. */
    private const UNUSED_INTENT_SQL = "e.origin = 'payment_intent' AND e.payment_order_status IN ('initiated', 'cancelled')
                AND NOT EXISTS (SELECT 1 FROM eet_records r WHERE r.supplier_id = e.supplier_id AND r.payment_event_id = e.id)
                AND NOT EXISTS (SELECT 1 FROM payment_event_allocations a WHERE a.supplier_id = e.supplier_id AND a.payment_event_id = e.id)";

    public function __construct(private readonly Connection $db) {}

    /**
     * Mazaná faktura: nepoužité QR záměry ({@see UNUSED_INTENT_SQL}) se smažou s ní, aby
     * nezůstaly viset na neexistujícím dokladu. Volat po {@see assertInvoiceDeletable()}.
     *
     * @param list<int> $invoiceIds
     */
    public function releaseUnusedIntents(int $supplierId, array $invoiceIds): void
    {
        $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static fn (int $id): bool => $id > 0)));
        if ($invoiceIds === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($invoiceIds), '?'));
        $this->db->pdo()->prepare(
            "DELETE e FROM payment_events e WHERE e.supplier_id = ? AND e.invoice_id IN ($in) AND " . self::UNUSED_INTENT_SQL
        )->execute([$supplierId, ...$invoiceIds]);
    }

    /**
     * Hláška pro první fakturu ze seznamu, která má historii EET, jinak null.
     *
     * @param list<int> $invoiceIds
     */
    public function invoiceConflict(int $supplierId, array $invoiceIds): ?string
    {
        $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static fn (int $id): bool => $id > 0)));
        if ($invoiceIds === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($invoiceIds), '?'));
        // Nepotvrzený nebo zrušený QR záměr bez tržby není historie: nebyla platba ani
        // evidence. Smazání faktury ho uklidí ({@see releaseUnusedIntents()}).
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM payment_events e
              WHERE e.supplier_id = ? AND e.invoice_id IN ($in)
                AND NOT (" . self::UNUSED_INTENT_SQL . ")
             UNION ALL
             SELECT 1 FROM payment_event_allocations WHERE supplier_id = ? AND invoice_id IN ($in)
             LIMIT 1"
        );
        $stmt->execute([$supplierId, ...$invoiceIds, $supplierId, ...$invoiceIds]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }
        return 'Doklad má evidenci tržeb (EET), proto ho nelze smazat. Stornujte ho; vrácení peněz zadejte jako opravu EET.';
    }

    /**
     * Upozornění ke stornu faktury: storno ani dobropis evidovanou tržbu samy
     * neopravují (vrácení peněz je samostatný úkon). Null, když faktura nemá
     * žádnou dosud neopravenou tržbu.
     */
    public function cancellationWarning(int $supplierId, int $invoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.total_amount + COALESCE((SELECT SUM(c.total_amount) FROM eet_records c
                                                WHERE c.supplier_id = r.supplier_id AND c.corrects_record_id = r.id
                                                  AND c.kind = 'correction'), 0)
               FROM payment_events e
               JOIN eet_records r ON r.payment_event_id = e.id AND r.supplier_id = e.supplier_id AND r.kind = 'sale'
              WHERE e.supplier_id = ? AND e.invoice_id = ?"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $count = 0;
        $cents = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $remaining) {
            $value = EetValues::toCents(EetValues::amount((string) $remaining));
            if ($value > 0) {
                $count++;
                $cents += $value;
            }
        }
        if ($count === 0) {
            return null;
        }
        return 'Faktura má evidované tržby EET (' . $count . ', neopraveno ' . EetValues::fromCents($cents)
            . ' Kč). Storno faktury tržby neopravuje; pokud jste zákazníkovi vrátili peníze, vytvořte opravu EET.';
    }

    /**
     * Dobropis, jehož vrácení peněz je v EET zaevidované jako záporná tržba, nejde vrátit
     * do stavu „nevráceno": záporná zpráva už odešla a EET ji vzít zpět neumí.
     */
    public function creditNoteRefundConflict(int $supplierId, int $creditNoteId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1
               FROM payment_event_allocations a
               JOIN payment_events e ON e.id = a.payment_event_id AND e.supplier_id = a.supplier_id
                                    AND e.kind = 'refund' AND e.origin = 'correction'
              WHERE a.supplier_id = ? AND a.invoice_id = ?
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $creditNoteId]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }
        return 'Vrácení peněz k dobropisu je zaevidované v EET jako záporná tržba, proto ho nelze vrátit zpět. '
            . 'Pokud vám zákazník peníze vrátil, přijměte je jako novou platbu.';
    }

    public function cashDocumentConflict(int $supplierId, int $cashDocumentId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.sequence_number
               FROM payment_event_allocations a
               JOIN eet_records r ON r.payment_event_id = a.payment_event_id AND r.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.cash_document_id = ?
              ORDER BY r.id
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $cashDocumentId]);
        $sequence = $stmt->fetchColumn();
        if ($sequence === false) {
            return null;
        }
        return 'Pokladní doklad má evidovanou tržbu EET (' . $sequence . '), proto ho nelze smazat. '
            . 'Stornujte ho; storno vytvoří opravu EET.';
    }

    /**
     * Pojistka v repozitáři pro všechny cesty mazání faktur.
     *
     * @throws EetHistoryExistsException
     */
    public function assertInvoiceDeletable(int $invoiceId): void
    {
        $this->invoiceDeletionScope($invoiceId);
    }

    /**
     * Pojistka a úklid před smazáním faktury: doklad s historií EET nepustí, nepoužité
     * QR záměry faktury (i jejích potomků) smaže.
     *
     * @throws EetHistoryExistsException
     */
    public function prepareInvoiceDeletion(int $invoiceId): void
    {
        $scope = $this->invoiceDeletionScope($invoiceId);
        if ($scope !== null) {
            $this->releaseUnusedIntents($scope[0], $scope[1]);
        }
    }

    /**
     * @return array{0:int, 1:list<int>}|null
     * @throws EetHistoryExistsException
     */
    private function invoiceDeletionScope(int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $supplierId = $stmt->fetchColumn();
        if ($supplierId === false) {
            return null;
        }
        $children = $this->db->pdo()->prepare('SELECT id FROM invoices WHERE parent_invoice_id = ? AND supplier_id = ?');
        $children->execute([$invoiceId, (int) $supplierId]);
        $ids = array_map('intval', $children->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        $ids[] = $invoiceId;
        $message = $this->invoiceConflict((int) $supplierId, $ids);
        if ($message !== null) {
            throw new EetHistoryExistsException($message);
        }
        return [(int) $supplierId, $ids];
    }
}
