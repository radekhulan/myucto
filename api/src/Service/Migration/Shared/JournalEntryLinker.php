<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments;
use MyInvoice\Service\Accounting\JournalDescriptionBuilder;
use MyInvoice\Service\Accounting\JournalDescriptionRebuilder;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Vazba převedeného deníku na převedené doklady, společná převodům z cizích účetních
 * programů (Money S3, POHODA, PREMIER a další konektory).
 *
 * Zaúčtování se při převodu nepřepočítává: zápis převzatého deníku dostane
 * `source_type`/`source_id` dokladu a vazbu v `journal_entry_document_links`. Vlastní
 * předpisy faktur navíc patří do `DocumentPostingGroup`; informativní vazba na úhradu
 * nebo počáteční stav sama zaúčtování dokladu netvoří.
 *
 * Tahle třída drží jen zápis vazeb. Jak se doklad a zápis v deníku najdou (číslo dokladu,
 * sborník, řádek deníku), je strategie každého zdroje a zůstává v jeho DocumentLinkeru.
 */
final class JournalEntryLinker
{
    /** Převzatý doklad stále patří cílové firmě. */
    public function hasDocument(int $supplierId, string $sourceType, int $documentId): bool
    {
        $table = match ($sourceType) {
            'invoice' => 'invoices',
            'purchase_invoice' => 'purchase_invoices',
            default => throw new \InvalidArgumentException('Unsupported linked document type.'),
        };
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$documentId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Ruční převzatý zápis nebo zápis již navázaný na stejný doklad. */
    public function hasLinkableEntry(int $supplierId, int $entryId, string $date, string $sourceType, int $sourceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT entry_date, source_type, source_id, reversed_by FROM journal_entries WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$entryId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false && (string) $row['entry_date'] === $date && $row['reversed_by'] === null
            && (($row['source_type'] === 'manual' && $row['source_id'] === null)
                || ($row['source_type'] === $sourceType && (int) $row['source_id'] === $sourceId));
    }

    /** Pohyb bez prokázané vazby na deník zůstane uživateli ke kontrole. */
    public function markUnverifiedMovement(int $supplierId, string $kind, int $movementId,
        string $reason, string $note): void
    {
        if ($kind === 'bank') {
            $this->db->pdo()->prepare("UPDATE bank_transactions t JOIN bank_statements s ON s.id=t.statement_id
                SET t.match_status=CASE WHEN t.match_status='unmatched' AND NOT EXISTS (
                    SELECT 1 FROM payment_matches pm
                    WHERE pm.supplier_id=s.supplier_id AND pm.bank_transaction_id=t.id
                ) THEN 'ignored' ELSE t.match_status END,
                    t.match_reason=?, t.ignore_note=?
                WHERE t.id=? AND s.supplier_id=? AND t.match_status <> 'ignored'
                  AND (t.match_reason IS NULL OR t.match_reason=?)")
                ->execute([$reason, $note, $movementId, $supplierId, $reason]);
            return;
        }
        if ($kind !== 'cash') throw new \InvalidArgumentException('Unsupported movement type.');
        $this->db->pdo()->prepare("UPDATE cash_documents SET status='draft' WHERE id=? AND supplier_id=? AND status <> 'draft'")
            ->execute([$movementId, $supplierId]);
    }

    /**
     * @param string $sourceName název zdroje v 2. pádě do poznámek vazeb („Money S3",
     *        „Pohody", „PREMIER")
     * @param bool $newOnlyWhenSourceChanged kdy {@see attach()} hlásí novou vazbu, když zápis
     *        vazbu ještě nemá, ale přeznačení `source_type`/`source_id` žádný řádek nezměnilo
     *        (zápis už nese jiný zdroj): false = i tak nová vazba (Money S3, POHODA),
     *        true = nová jen podle skutečně změněných řádků (PREMIER). Rozdíl se promítá
     *        jen do počtů v protokolu (`linked` × `existing`).
     */
    public function __construct(
        private readonly Connection $db,
        private readonly string $sourceName,
        private readonly bool $newOnlyWhenSourceChanged = false,
    ) {}

    /** Poznámka vazby zápisu na doklad („Převzato z Pohody"). */
    public function linkNote(): string
    {
        return 'Převzato z ' . $this->sourceName;
    }

    /**
     * Zápisy převzatého deníku dostanou vazbu na doklad. Zdroj (`source_type`, `source_id`)
     * dostane jen PRVNÍ zápis a jen tehdy, když doklad ještě žádný nestornovaný zápis
     * s tímto zdrojem nemá a zápis sám zdroj nemá (`source_id IS NULL`).
     *
     * @param string $sourceType zdroj zápisu po navázání (`invoice`, `purchase_invoice`, `bank`…)
     * @param string $entrySourceType zdroj, se kterým převod zápis do deníku zapsal; přeznačí
     *        se jen zápis s tímto zdrojem (Money a POHODA vedou zdroj podle agendy deníku,
     *        PREMIER zapisuje deník jako `manual`)
     * @param list<int> $entryIds zápisy dokladu v pořadí data (první dostane zdroj)
     * @return bool true = nová vazba
     */
    public function attach(int $supplierId, ?int $userId, string $sourceType, string $entrySourceType, string $docType, int $docId, array $entryIds): bool
    {
        if ($entryIds === []) return false;
        $pdo = $this->db->pdo();
        if ($sourceType === $docType && in_array($docType, ['invoice', 'purchase_invoice'], true)
            && in_array($entrySourceType, ['manual', $docType], true)) {
            // Část převodů touto cestou přidává i informativní vazbu na úhradu.
            // Předpis tvoří jen zápisy zdrojové agendy, nikoli banka/pokladna.
            // Skupinu zamkneme před přeznačením zdroje: období → doklad → zápis.
            $in = implode(',', array_fill(0, count($entryIds), '?'));
            $candidates = $pdo->prepare("SELECT id FROM journal_entries WHERE supplier_id = ? AND id IN ({$in})
                AND ((source_type = ? AND source_id IS NULL) OR (source_type = ? AND source_id = ?))
                ORDER BY id");
            $candidates->execute([$supplierId, ...$entryIds, $entrySourceType, $docType, $docId]);
            $postingIds = array_map('intval', $candidates->fetchAll(\PDO::FETCH_COLUMN));
            if ($postingIds !== []) (new DocumentPostingGroup($this->db))->attach($supplierId, $docType, $docId, $postingIds);
        }
        $owner = $pdo->prepare(
            'SELECT id FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL LIMIT 1'
        );
        $owner->execute([$supplierId, $sourceType, $docId]);
        $isNew = false;
        if ($owner->fetchColumn() === false) {
            $stmt = $pdo->prepare(
                'UPDATE journal_entries SET source_type = ?, source_id = ?
                  WHERE id = ? AND supplier_id = ? AND source_type = ? AND source_id IS NULL'
            );
            $stmt->execute([$sourceType, $docId, $entryIds[0], $supplierId, $entrySourceType]);
            $isNew = $this->newOnlyWhenSourceChanged ? $stmt->rowCount() > 0 : true;
        }
        $link = $pdo->prepare(
            'INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($entryIds as $entryId) {
            $link->execute([$supplierId, $entryId, $docType, $docId, $this->linkNote(), $userId]);
            $isNew = $isNew || $link->rowCount() > 0;
        }
        if ($docType === 'cash') {
            $pdo->prepare('UPDATE cash_documents SET journal_entry_id = ? WHERE id = ? AND supplier_id = ? AND journal_entry_id IS NULL')
                ->execute([$entryIds[0], $docId, $supplierId]);
        }
        return $isNew;
    }

    /**
     * Doklad zastoupený v počátečních stavech (neuhrazený doklad minulého roku, pokladní
     * doklad počátečního stavu) dostane vazbu na otevírací zápis období. Zápis vlastní mít
     * nesmí a Doúčtování dokladů ho podle té vazby vynechá ({@see OpeningBalanceDocuments}).
     *
     * @return bool true = nová vazba (false i tehdy, když období nemá otevírací zápis)
     */
    public function linkOpening(int $supplierId, ?int $userId, ?int $periodId, string $docType, int $docId): bool
    {
        if ($periodId === null) {
            return false;
        }
        $pdo = $this->db->pdo();
        $opening = $pdo->prepare(
            "SELECT id FROM journal_entries WHERE supplier_id = ? AND period_id = ? AND source_type = 'opening' AND reversed_by IS NULL ORDER BY id LIMIT 1"
        );
        $opening->execute([$supplierId, $periodId]);
        $entryId = $opening->fetchColumn();
        if ($entryId === false) {
            return false;
        }
        $link = $pdo->prepare(
            'INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $link->execute([$supplierId, (int) $entryId, $docType, $docId, OpeningBalanceDocuments::NOTE . ' (převzato z ' . $this->sourceName . ')', $userId]);
        return $link->rowCount() > 0;
    }

    /**
     * Pohyby, které zdrojový program zaúčtoval bez dokladu (poplatky, převody mezi vlastními
     * účty, mzdy, odvody), jsou vyřízené: v MyÚčtu pro ně faktura není a výpis by jinak
     * trvale svítil jako nedopárovaný. Označí se jako ignorované s poznámkou; jen pohyby,
     * které jsou ještě nespárované. Uživatel to u pohybu může vrátit. Pohyb přiřazený
     * ostatní pohledávce či závazku je vyřízený alokací a jeho stav měnit nelze (trigger).
     *
     * @param list<int> $transactionIds pohyby převodu, o kterých se rozhoduje
     * @param string $reason `bank_transactions.match_reason` (`money_s3_booked`…)
     * @param string $note poznámka k ignorování, kterou uživatel u pohybu uvidí
     * @param string $condition další podmínka nad `t` (pohyb) a `s` (výpis), např. že pohyb
     *        má vlastní zápis deníku; prázdná = bez podmínky
     * @return int počet označených pohybů
     */
    public function markBankBookedWithoutDocument(int $supplierId, ?int $userId, array $transactionIds, string $reason, string $note, string $condition = ''): int
    {
        $marked = 0;
        foreach (array_chunk(array_values(array_unique($transactionIds)), 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                    SET t.match_status = 'ignored', t.match_reason = ?, t.matched_at = NOW(), t.matched_by = ?, t.ignore_note = ?
                  WHERE s.supplier_id = ? AND t.match_status = 'unmatched'
                    AND NOT EXISTS (SELECT 1 FROM other_item_allocations oia WHERE oia.bank_transaction_id = t.id)"
                    . ($condition !== '' ? ' AND ' . $condition : '') . '
                    AND t.id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $stmt->execute(array_merge([$reason, $userId, $note, $supplierId], $chunk));
            $marked += $stmt->rowCount();
        }
        return $marked;
    }

    /**
     * Popisy zápisů se dogenerují AŽ po navázání dokladů: převzatý deník nese jen volný
     * text, který je u celé řady dokladů shodný („Fakturujeme Vám za …"), a teprve
     * navázáním `source_id` je z čeho doplnit číslo dokladu a protistranu
     * ({@see JournalDescriptionRebuilder}).
     *
     * Ručních zápisů, zápisů bez dokladu ani popisů změněných uživatelem se to netýká —
     * rozhoduje o tom rebuilder, ne tenhle krok. Selhání se jen ohlásí: popis je
     * komfort, kvůli kterému nesmí spadnout celý převod.
     *
     * @param bool $scriptHint připojit k chybě odkaz na skript, který popisy doplní později
     */
    public function refreshDescriptions(int $supplierId, ImportProtocol $p, string $step, bool $scriptHint = true): void
    {
        try {
            $rebuilder = new JournalDescriptionRebuilder($this->db, new JournalDescriptionBuilder($this->db));
            $changed = $rebuilder->rebuild(['supplier_id' => $supplierId]);
            if ($changed > 0) {
                $p->info($step, 'descriptions_rebuilt', "U {$changed} převedených zápisů se popis doplnil o číslo dokladu a protistranu.");
            }
        } catch (\Throwable $e) {
            $p->warn($step, 'descriptions_rebuild_failed', 'Popisy převedených zápisů se nepodařilo doplnit: ' . $e->getMessage()
                . ($scriptHint ? ' Doplníš je kdykoli později skriptem api/bin/rebuild-journal-descriptions.php.' : ''));
        }
    }
}
