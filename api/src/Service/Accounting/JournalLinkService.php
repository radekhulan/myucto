<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryDocumentLinkRepository;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use PDO;

/**
 * Vazba DOKLAD ↔ ÚHRADA promítnutá do účetního deníku.
 *
 * Deník doklad i jeho úhradu vede jako DVA nezávislé zápisy (faktura: 311/6xx,
 * banka: 221/311). Účetní je ale řeší jako jeden případ, takže z jednoho zápisu
 * musí jít na druhý — a na oba zdrojové doklady — bez hledání ve filtrech.
 * Tahle služba je JEDINÝ zdroj pravdy pro ten graf vazeb; drawer i seznam deníku
 * z ní čtou, aby odznak „má protějšek" a obsah panelu nemohly říct něco jiného.
 *
 * ── Hrany grafu ───────────────────────────────────────────────────────────────
 *   invoice          ↔ bank   přes invoice_payments.bank_transaction_id,
 *                             payment_matches a legacy bank_transactions.matched_invoice_id
 *   purchase_invoice ↔ bank   přes payment_matches (N:N, migrace 0034)
 *   invoice / purchase_invoice ↔ cash       přes cash_documents.invoice_id / .purchase_invoice_id
 *   invoice / purchase_invoice ↔ settlement přes invoice_settlements.doc_type + .doc_id
 *   invoice          ↔ gopay  přes gopay_movements.invoice_id / credit_note_id
 *   JAKÝKOLI zápis          ↔ doklad přes journal_entry_document_links (RUČNÍ měkká
 *                             vazba, migrace 1514) — jediná hrana, kterou zakládá
 *                             uživatel, a jediná, kterou má i ruční zápis se
 *                             source_id NULL. Nese ji obousměrně: ze zápisu na
 *                             doklad ('linked_document') a z dokladu na zápis
 *                             ('linked_entry', kind 'journal_entry').
 *
 * ── Bezpečnost ────────────────────────────────────────────────────────────────
 * Klíčem je VŽDY ověřený řádek journal_entries daného tenanta (stejně jako
 * u {@see JournalSourceSummaryService}) — dvojice (source_type, source_id) se
 * nikdy nebere z requestu. Uzávěrkové typy se syntetickým source_id se do DB
 * vůbec nedostanou: LINKABLE whitelist + SYNTHETIC_ID_FLOOR jako druhá pojistka.
 * `bank_transactions` nemá supplier_id — tenant se u nich vynucuje JOINem na
 * `bank_statements`, nikdy ne jen podle id.
 */
final class JournalLinkService
{
    /** Strop položek v panelu; přes limit se vrátí `truncated`. */
    public const MAX_ITEMS = 50;

    /**
     * Typy zápisu, které v grafu vazeb vůbec mají hranu. Cokoli mimo (manual,
     * uzávěrkové typy, odpisy, majetek…) se do DB nedostane.
     */
    private const LINKABLE = ['invoice', 'purchase_invoice', 'bank', 'cash', 'settlement', 'gopay'];

    /** Reálné id dokladu nikdy nedosáhne 1e12, syntetická uzávěrková pásma ano. */
    private const SYNTHETIC_ID_FLOOR = ClosingSourceId::STOCK_SLOT_BASE;

    /** Právo, které FE ověří, než u položky vykreslí proklik na doklad. */
    private const PERMISSION = [
        'invoice'          => 'invoices',
        'purchase_invoice' => 'purchase_invoices',
        'bank'             => 'bank',
        'cash'             => 'cash',
        'settlement'       => 'accounting',
        'gopay'            => 'bank',
        'journal_entry'    => 'accounting',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly JournalEntryDocumentLinkRepository $documentLinks,
    ) {}

    /**
     * Protějšky jednoho zápisu i s jejich zaúčtováním.
     *
     * @param  array<string,mixed> $entry OVĚŘENÝ řádek journal_entries daného tenanta
     * @return array{items:list<array<string,mixed>>, truncated:bool}
     */
    public function related(int $supplierId, array $entry): array
    {
        $refs = $this->neighbourRefs($supplierId, $entry);
        if ($refs === []) {
            return ['items' => [], 'truncated' => false];
        }
        $truncated = count($refs) > self::MAX_ITEMS;

        return [
            'items'     => $this->hydrate($supplierId, array_slice($refs, 0, self::MAX_ITEMS)),
            'truncated' => $truncated,
        ];
    }

    /**
     * Existence protějšku pro CELOU stránku deníku — podklad pro odznak ve sloupci
     * Zdroj. Záměrně BATCH (pár dotazů na stránku), ne volání {@see related()} v cyklu:
     * to by na 50 řádcích znamenalo stovky dotazů na jedno vykreslení seznamu.
     *
     * @param  list<array<string,mixed>> $entries řádky journal_entries daného tenanta
     * @return array<int,true>                    entry_id => má protějšek
     */
    public function hasRelatedMap(int $supplierId, array $entries): array
    {
        /** @var array<string,list<int>> $byRef klíč "typ:id" => entry_id[] */
        $byRef = [];
        foreach ($entries as $e) {
            $type = (string) ($e['source_type'] ?? '');
            $sid  = $this->sourceId($e);
            if ($sid === null || !in_array($type, self::LINKABLE, true)) continue;
            $byRef[$type . ':' . $sid][] = (int) $e['id'];
        }

        // Měkké vazby stránku neomezují na LINKABLE zdroje: ruční zápis (source_id
        // NULL) v $byRef vůbec není, a přesto odznak mít má. Proto se sbírají zvlášť
        // z id zápisů a výsledek se s odvozenými hranami až na konci sloučí.
        $pageIds = [];
        foreach ($entries as $e) {
            $id = (int) ($e['id'] ?? 0);
            if ($id > 0) $pageIds[] = $id;
        }
        $pageIds = array_values(array_unique($pageIds));

        /** @var array<int,true> $linked zápisy, které si samy navázaly doklad */
        $linked = [];
        if ($pageIds !== []) {
            foreach ($this->rows(
                'SELECT DISTINCT entry_id FROM journal_entry_document_links
                  WHERE supplier_id = ? AND entry_id IN (' . $this->placeholders($pageIds) . ')',
                array_merge([$supplierId], $pageIds)
            ) as $r) {
                $linked[(int) $r['entry_id']] = true;
            }
        }

        if ($byRef === []) return $linked;

        $ids = static function (array $byRef, string $type): array {
            $out = [];
            foreach (array_keys($byRef) as $key) {
                [$t, $id] = explode(':', $key, 2);
                if ($t === $type) $out[] = (int) $id;
            }
            return $out;
        };
        $invoices  = $ids($byRef, 'invoice');
        $purchases = $ids($byRef, 'purchase_invoice');
        $banks     = $ids($byRef, 'bank');
        $cash      = $ids($byRef, 'cash');
        $settles   = $ids($byRef, 'settlement');
        $gopay     = $ids($byRef, 'gopay');

        $hits = [];
        /** Označí obě strany hrany — na kterékoli z nich může řádek deníku stát. */
        $mark = static function (?int $id, string $type) use (&$hits): void {
            if ($id !== null && $id > 0) $hits[$type . ':' . $id] = true;
        };

        // 1) N:N párování banky (payment_matches) — vydané i přijaté faktury.
        foreach ($this->pairsQuery(
            'SELECT bank_transaction_id, invoice_id, purchase_invoice_id FROM payment_matches
              WHERE supplier_id = ?',
            [$supplierId],
            [['bank_transaction_id', $banks], ['invoice_id', $invoices], ['purchase_invoice_id', $purchases]]
        ) as $r) {
            $mark((int) $r['bank_transaction_id'], 'bank');
            $mark($r['invoice_id'] !== null ? (int) $r['invoice_id'] : null, 'invoice');
            $mark($r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null, 'purchase_invoice');
        }

        // 2) Evidence plateb vydaných faktur s vazbou na bankovní transakci.
        foreach ($this->pairsQuery(
            'SELECT bank_transaction_id, invoice_id FROM invoice_payments
              WHERE supplier_id = ? AND bank_transaction_id IS NOT NULL',
            [$supplierId],
            [['bank_transaction_id', $banks], ['invoice_id', $invoices]]
        ) as $r) {
            $mark((int) $r['bank_transaction_id'], 'bank');
            $mark((int) $r['invoice_id'], 'invoice');
        }

        // 3) Legacy 1:1 párování — tenant přes JOIN na výpis (bank_transactions nemá supplier_id).
        foreach ($this->pairsQuery(
            'SELECT t.id, t.matched_invoice_id FROM bank_transactions t
               JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.matched_invoice_id IS NOT NULL',
            [$supplierId],
            [['t.id', $banks], ['t.matched_invoice_id', $invoices]]
        ) as $r) {
            $mark((int) $r['id'], 'bank');
            $mark((int) $r['matched_invoice_id'], 'invoice');
        }

        // 4) Pokladní úhrady faktur.
        foreach ($this->pairsQuery(
            'SELECT id, invoice_id, purchase_invoice_id FROM cash_documents
              WHERE supplier_id = ? AND (invoice_id IS NOT NULL OR purchase_invoice_id IS NOT NULL)',
            [$supplierId],
            [['id', $cash], ['invoice_id', $invoices], ['purchase_invoice_id', $purchases]]
        ) as $r) {
            $mark((int) $r['id'], 'cash');
            $mark($r['invoice_id'] !== null ? (int) $r['invoice_id'] : null, 'invoice');
            $mark($r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null, 'purchase_invoice');
        }

        // 5) Úhrady zápočtem (jen potvrzené — zrušené mají protizápis a doklad je zpět nezaplacený).
        foreach ($this->pairsQuery(
            "SELECT id, doc_type, doc_id FROM invoice_settlements
              WHERE supplier_id = ? AND status = 'confirmed'",
            [$supplierId],
            [
                ['id', $settles],
                ["CASE WHEN doc_type = 'invoice' THEN doc_id END", $invoices],
                ["CASE WHEN doc_type = 'purchase_invoice' THEN doc_id END", $purchases],
            ]
        ) as $r) {
            $mark((int) $r['id'], 'settlement');
            $mark((int) $r['doc_id'], (string) $r['doc_type']);
        }

        // 6) GoPay pohyb a faktura nebo dobropis, který tento pohyb hradí.
        foreach ($this->pairsQuery(
            'SELECT id, invoice_id, credit_note_id FROM gopay_movements
              WHERE supplier_id = ? AND (invoice_id IS NOT NULL OR credit_note_id IS NOT NULL)',
            [$supplierId],
            [['id', $gopay], ['invoice_id', $invoices], ['credit_note_id', $invoices]]
        ) as $r) {
            $mark((int) $r['id'], 'gopay');
            $mark($r['invoice_id'] !== null ? (int) $r['invoice_id'] : null, 'invoice');
            $mark($r['credit_note_id'] !== null ? (int) $r['credit_note_id'] : null, 'invoice');
        }

        // 7) Měkká vazba z druhé strany: doklad na stránce, na který ukazuje ruční
        //    zápis. Bez tohohle by odznak u faktury chyběl, ačkoli panel doúčtování
        //    ukáže — a seznam by lhal (odznak a panel musí říkat totéž).
        foreach ($this->pairsQuery(
            'SELECT doc_type, doc_id FROM journal_entry_document_links WHERE supplier_id = ?',
            [$supplierId],
            [
                ["CASE WHEN doc_type = 'invoice' THEN doc_id END", $invoices],
                ["CASE WHEN doc_type = 'purchase_invoice' THEN doc_id END", $purchases],
                ["CASE WHEN doc_type = 'bank' THEN doc_id END", $banks],
                ["CASE WHEN doc_type = 'cash' THEN doc_id END", $cash],
            ]
        ) as $r) {
            $mark((int) $r['doc_id'], (string) $r['doc_type']);
        }

        $out = $linked;
        foreach ($byRef as $key => $entryIds) {
            if (!isset($hits[$key])) continue;
            foreach ($entryIds as $entryId) $out[$entryId] = true;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────── graf vazeb ──

    /**
     * Sousedé zápisu v grafu vazeb — jen reference (typ + id), bez popisných dat.
     *
     * Dvě nezávislé skupiny hran:
     *  a) ODVOZENÉ z evidence úhrad (doklad ↔ platba) — jen pro LINKABLE zdroje,
     *  b) RUČNÍ měkké vazby z `journal_entry_document_links` — obousměrně a bez
     *     ohledu na typ zápisu, protože právě ruční zápis (source_id NULL) je ten,
     *     který si vazbu na doklad jinak nemá kde nést (viz migrace 1514).
     *
     * @param  array<string,mixed> $entry
     * @return list<array{kind:string, id:int, relation:string, allocated:?float}>
     */
    private function neighbourRefs(int $supplierId, array $entry): array
    {
        $refs    = [];
        $entryId = (int) ($entry['id'] ?? 0);

        if ($entryId > 0) {
            foreach ($this->linkedDocumentRefs($supplierId, $entryId) as $r) {
                $this->addRef($refs, $r['kind'], $r['id'], $r['relation'], $r['allocated']);
            }
        }

        $type     = (string) ($entry['source_type'] ?? '');
        $sourceId = $this->sourceId($entry);
        if ($sourceId === null || !in_array($type, self::LINKABLE, true)) {
            return array_values($refs);
        }

        $derived = match ($type) {
            'invoice', 'purchase_invoice' => $this->paymentsOfDocument($supplierId, $type, $sourceId),
            'bank'                        => $this->documentsOfBankTransaction($supplierId, $sourceId),
            'cash'                        => $this->documentsOfCashDocument($supplierId, $sourceId),
            'settlement'                  => $this->documentsOfSettlement($supplierId, $sourceId),
            'gopay'                       => $this->documentsOfGoPayMovement($supplierId, $sourceId),
            default                       => [],
        };
        foreach ($derived as $r) {
            $this->addRef($refs, $r['kind'], $r['id'], $r['relation'], $r['allocated']);
        }

        // Zpětná hrana měkké vazby: zápisy, které si TENHLE doklad navázaly.
        // Bez ní by vazba fungovala jen jedním směrem — z faktury by se účetní
        // k ručnímu doúčtování nedostal a musel by ho hledat ve filtrech.
        foreach ($this->linkingEntryRefs($supplierId, $type, $sourceId) as $r) {
            $this->addRef($refs, $r['kind'], $r['id'], $r['relation'], $r['allocated']);
        }

        return array_values($refs);
    }

    /**
     * Doklady ručně navázané na zápis (měkká vazba, migrace 1514).
     *
     * @return list<array{kind:string, id:int, relation:string, allocated:?float}>
     */
    private function linkedDocumentRefs(int $supplierId, int $entryId): array
    {
        $refs = [];
        foreach ($this->rows(
            'SELECT doc_type, doc_id FROM journal_entry_document_links
              WHERE supplier_id = ? AND entry_id = ? ORDER BY id',
            [$supplierId, $entryId]
        ) as $r) {
            $this->addRef($refs, (string) $r['doc_type'], (int) $r['doc_id'], 'linked_document', null);
        }
        return array_values($refs);
    }

    /**
     * Zápisy, které si daný doklad ručně navázaly (opačný směr měkké vazby).
     * Reference míří na SAMOTNÝ ZÁPIS (kind 'journal_entry'), ne na doklad —
     * protějškem tu totiž není doklad, ale interní zápis bez vlastního dokladu.
     *
     * @return list<array{kind:string, id:int, relation:string, allocated:?float}>
     */
    private function linkingEntryRefs(int $supplierId, string $docType, int $docId): array
    {
        $refs = [];
        foreach ($this->rows(
            'SELECT entry_id FROM journal_entry_document_links
              WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? ORDER BY entry_id',
            [$supplierId, $docType, $docId]
        ) as $r) {
            $this->addRef($refs, 'journal_entry', (int) $r['entry_id'], 'linked_entry', null);
        }
        return array_values($refs);
    }

    /**
     * Ručně navázané doklady jednoho zápisu i s popisnými daty — podklad pro správu
     * vazeb v UI. Tatáž struktura položky jako {@see related()}, aby seznam vazeb
     * a panel „Souvisí" nemohly tentýž doklad popsat každý jinak.
     *
     * @return list<array<string,mixed>>
     */
    public function linkedDocuments(int $supplierId, int $entryId): array
    {
        $refs = $this->linkedDocumentRefs($supplierId, $entryId);
        return $refs === [] ? [] : $this->hydrate($supplierId, array_slice($refs, 0, self::MAX_ITEMS));
    }

    /**
     * Evidenční řádky vazeb (id, poznámka, kdo a kdy) slepené s popisem dokladu.
     *
     * JEDINÝ tvar, ve kterém vazby opouštějí backend — vrací ho detail zápisu
     * i endpointy /links. Dva tvary by znamenaly, že tatáž vazba vypadá po
     * načtení stránky jinak než po uložení: bez popisu se doklad tváří jako
     * smazaný („#193 · doklad neexistuje"), ačkoli existuje.
     *
     * @return list<array<string,mixed>>
     */
    public function documentLinks(int $supplierId, int $entryId): array
    {
        $described = [];
        foreach ($this->linkedDocuments($supplierId, $entryId) as $item) {
            $described[$item['source_type'] . ':' . $item['source_id']] = $item;
        }

        $out = [];
        foreach ($this->documentLinks->listForEntry($entryId, $supplierId) as $row) {
            // Doklad mezitím smazaný (doc_id nemá FK) zůstane bez popisu — vazbu
            // ukážeme dál, ať ji uživatel vidí a může ji zrušit.
            $out[] = $row + ['document' => $described[$row['doc_type'] . ':' . $row['doc_id']] ?? null];
        }
        return $out;
    }

    /**
     * Doklad → jeho úhrady (banka, pokladna, zápočet).
     *
     * @return list<array{kind:string, id:int, relation:string, allocated:?float}>
     */
    private function paymentsOfDocument(int $supplierId, string $docType, int $docId): array
    {
        // Sloupec z uzavřené dvojice, ne z requestu — do SQL jde jen jedna ze dvou konstant.
        $col  = $docType === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        $refs = [];

        foreach ($this->rows(
            "SELECT bank_transaction_id AS id, amount FROM payment_matches
              WHERE supplier_id = ? AND {$col} = ? ORDER BY id",
            [$supplierId, $docId]
        ) as $r) {
            $this->addRef($refs, 'bank', (int) $r['id'], 'payment', (float) $r['amount']);
        }

        if ($docType === 'invoice') {
            foreach ($this->rows(
                'SELECT id, amount FROM gopay_movements
                  WHERE supplier_id = ? AND (invoice_id = ? OR credit_note_id = ?) ORDER BY id',
                [$supplierId, $docId, $docId]
            ) as $r) {
                $this->addRef($refs, 'gopay', (int) $r['id'], 'payment', abs((float) $r['amount']));
            }
            foreach ($this->rows(
                'SELECT bank_transaction_id AS id, amount FROM invoice_payments
                  WHERE supplier_id = ? AND invoice_id = ? AND bank_transaction_id IS NOT NULL
                  ORDER BY id',
                [$supplierId, $docId]
            ) as $r) {
                $this->addRef($refs, 'bank', (int) $r['id'], 'payment', (float) $r['amount']);
            }
            foreach ($this->rows(
                'SELECT t.id FROM bank_transactions t
                   JOIN bank_statements s ON s.id = t.statement_id
                  WHERE s.supplier_id = ? AND t.matched_invoice_id = ? ORDER BY t.id',
                [$supplierId, $docId]
            ) as $r) {
                $this->addRef($refs, 'bank', (int) $r['id'], 'payment', null);
            }
        }

        foreach ($this->rows(
            "SELECT id, total_amount AS amount FROM cash_documents
              WHERE supplier_id = ? AND {$col} = ? ORDER BY id",
            [$supplierId, $docId]
        ) as $r) {
            $this->addRef($refs, 'cash', (int) $r['id'], 'payment', (float) $r['amount']);
        }

        foreach ($this->rows(
            "SELECT id, amount FROM invoice_settlements
              WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? AND status = 'confirmed'
              ORDER BY id",
            [$supplierId, $docType, $docId]
        ) as $r) {
            $this->addRef($refs, 'settlement', (int) $r['id'], 'payment', (float) $r['amount']);
        }

        return array_values($refs);
    }

    /**
     * GoPay pohyb -> faktura nebo dobropis, který hradí.
     *
     * @return list<array{kind:string, id:int, relation:string, allocated:?float}>
     */
    private function documentsOfGoPayMovement(int $supplierId, int $movementId): array
    {
        $refs = [];
        foreach ($this->rows(
            'SELECT invoice_id,credit_note_id,amount FROM gopay_movements
              WHERE id=? AND supplier_id=?',
            [$movementId, $supplierId]
        ) as $r) {
            if ($r['invoice_id'] !== null) {
                $this->addRef($refs, 'invoice', (int) $r['invoice_id'], 'document', abs((float) $r['amount']));
            }
            if ($r['credit_note_id'] !== null) {
                $this->addRef($refs, 'invoice', (int) $r['credit_note_id'], 'document', abs((float) $r['amount']));
            }
        }
        return array_values($refs);
    }

    /**
     * Bankovní pohyb → doklady, které hradí.
     *
     * @return list<array{kind:string, id:int, relation:string, allocated:?float}>
     */
    private function documentsOfBankTransaction(int $supplierId, int $txId): array
    {
        $refs = [];

        foreach ($this->rows(
            'SELECT invoice_id, purchase_invoice_id, amount FROM payment_matches
              WHERE supplier_id = ? AND bank_transaction_id = ? ORDER BY id',
            [$supplierId, $txId]
        ) as $r) {
            if ($r['invoice_id'] !== null) {
                $this->addRef($refs, 'invoice', (int) $r['invoice_id'], 'document', (float) $r['amount']);
            }
            if ($r['purchase_invoice_id'] !== null) {
                $this->addRef($refs, 'purchase_invoice', (int) $r['purchase_invoice_id'], 'document', (float) $r['amount']);
            }
        }

        foreach ($this->rows(
            'SELECT invoice_id, amount FROM invoice_payments
              WHERE supplier_id = ? AND bank_transaction_id = ? ORDER BY id',
            [$supplierId, $txId]
        ) as $r) {
            $this->addRef($refs, 'invoice', (int) $r['invoice_id'], 'document', (float) $r['amount']);
        }

        foreach ($this->rows(
            'SELECT t.matched_invoice_id FROM bank_transactions t
               JOIN bank_statements s ON s.id = t.statement_id
              WHERE t.id = ? AND s.supplier_id = ? AND t.matched_invoice_id IS NOT NULL',
            [$txId, $supplierId]
        ) as $r) {
            $this->addRef($refs, 'invoice', (int) $r['matched_invoice_id'], 'document', null);
        }

        return array_values($refs);
    }

    /**
     * Pokladní doklad → faktura, kterou hradí.
     *
     * @return list<array{kind:string, id:int, relation:string, allocated:?float}>
     */
    private function documentsOfCashDocument(int $supplierId, int $cashDocId): array
    {
        $refs = [];
        foreach ($this->rows(
            'SELECT invoice_id, purchase_invoice_id, total_amount FROM cash_documents
              WHERE id = ? AND supplier_id = ?',
            [$cashDocId, $supplierId]
        ) as $r) {
            if ($r['invoice_id'] !== null) {
                $this->addRef($refs, 'invoice', (int) $r['invoice_id'], 'document', (float) $r['total_amount']);
            }
            if ($r['purchase_invoice_id'] !== null) {
                $this->addRef($refs, 'purchase_invoice', (int) $r['purchase_invoice_id'], 'document', (float) $r['total_amount']);
            }
        }
        return array_values($refs);
    }

    /**
     * Zápočet → vyrovnaný doklad.
     *
     * @return list<array{kind:string, id:int, relation:string, allocated:?float}>
     */
    private function documentsOfSettlement(int $supplierId, int $settlementId): array
    {
        $refs = [];
        foreach ($this->rows(
            "SELECT doc_type, doc_id, amount FROM invoice_settlements
              WHERE id = ? AND supplier_id = ? AND status = 'confirmed'",
            [$settlementId, $supplierId]
        ) as $r) {
            $kind = (string) $r['doc_type'];
            if ($kind === 'invoice' || $kind === 'purchase_invoice') {
                $this->addRef($refs, $kind, (int) $r['doc_id'], 'document', (float) $r['amount']);
            }
        }
        return array_values($refs);
    }

    /**
     * Přidá referenci do setu klíčovaného "typ:id". Deduplikace je nutná: jedna
     * bankovní úhrada vydané faktury sedí zároveň v invoice_payments, payment_matches
     * i v legacy matched_invoice_id — bez ní by se v panelu zobrazila třikrát.
     * První zápis vyhrává, protože nese alokovanou částku z konkrétnějšího zdroje.
     *
     * @param array<string,array{kind:string, id:int, relation:string, allocated:?float}> $refs
     */
    private function addRef(array &$refs, string $kind, int $id, string $relation, ?float $allocated): void
    {
        if ($id <= 0 || $id >= self::SYNTHETIC_ID_FLOOR) return;
        $key = $kind . ':' . $id;
        if (isset($refs[$key])) return;
        $refs[$key] = ['kind' => $kind, 'id' => $id, 'relation' => $relation, 'allocated' => $allocated];
    }

    // ────────────────────────────────────────────────────── popisná data ──

    /**
     * Doplní referencím popisná data dokladu a jejich zaúčtování. Batch po typech —
     * počet dotazů nezávisí na počtu položek.
     *
     * @param  list<array{kind:string, id:int, relation:string, allocated:?float}> $refs
     * @return list<array<string,mixed>>
     */
    private function hydrate(int $supplierId, array $refs): array
    {
        $byKind = [];
        foreach ($refs as $ref) $byKind[$ref['kind']][] = $ref['id'];

        $descriptors = [];
        $entries     = [];
        foreach ($byKind as $kind => $ids) {
            $ids = array_values(array_unique($ids));
            $descriptors[$kind] = $this->describe($supplierId, $kind, $ids);
            $entries[$kind]     = $this->entriesFor($supplierId, $kind, $ids);
        }

        $items = [];
        foreach ($refs as $ref) {
            $desc = $descriptors[$ref['kind']][$ref['id']] ?? null;
            if ($desc === null) {
                // Doklad smazán nebo patří jinému tenantovi — cizí data nikdy nevracíme.
                continue;
            }
            $entry = $entries[$ref['kind']][$ref['id']] ?? null;
            $items[] = [
                'relation'          => $ref['relation'],
                'source_type'       => $ref['kind'],
                'source_id'         => $ref['id'],
                'permission'        => self::PERMISSION[$ref['kind']] ?? 'accounting',
                'title'             => $desc['title'],
                'subtitle'          => $desc['subtitle'],
                'date'              => $desc['date'],
                'amount'            => $desc['amount'],
                // Alokovaná částka se od celkové liší u splátek a souhrnných plateb;
                // bez ní panel ukazuje „5 000" u úhrady, která na doklad poslala 1 200.
                'allocated_amount'  => $ref['allocated'],
                'currency'          => $desc['currency'],
                'route'             => $desc['route'],
                'entry_id'          => $entry['id'] ?? null,
                'entry_date'        => $entry['entry_date'] ?? null,
                'entry_document_no' => $entry['document_no'] ?? null,
                'entry_posted'      => isset($entry['posted_at']) && $entry['posted_at'] !== null,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return [$a['date'] ?? '', $a['source_id']] <=> [$b['date'] ?? '', $b['source_id']];
        });
        return $items;
    }

    /**
     * Popisná data dokladů jednoho typu.
     *
     * @param  list<int> $ids
     * @return array<int,array{title:?string, subtitle:?string, date:?string, amount:?float, currency:string, route:?array<string,mixed>}>
     */
    private function describe(int $supplierId, string $kind, array $ids): array
    {
        if ($ids === []) return [];
        $in = $this->placeholders($ids);
        $out = [];

        if ($kind === 'bank') {
            foreach ($this->rows(
                "SELECT t.id, t.posted_at, t.amount, t.counterparty_name, t.variable_symbol, t.bank_ref,
                        t.statement_id, UPPER(COALESCE(t.currency, s.currency, 'CZK')) AS currency
                   FROM bank_transactions t
                   JOIN bank_statements s ON s.id = t.statement_id
                  WHERE s.supplier_id = ? AND t.id IN ({$in})",
                array_merge([$supplierId], $ids)
            ) as $r) {
                $id = (int) $r['id'];
                $out[$id] = [
                    'title'    => (string) ($r['bank_ref'] ?: $r['variable_symbol'] ?: ('#' . $id)),
                    'subtitle' => $r['counterparty_name'] !== null ? (string) $r['counterparty_name'] : null,
                    'date'     => $r['posted_at'] !== null ? (string) $r['posted_at'] : null,
                    'amount'   => (float) $r['amount'],
                    'currency' => (string) $r['currency'],
                    // Detail transakce žije na stránce výpisu — proto statement_id v params
                    // a id transakce v query (shodně s JournalSourceSummaryService).
                    'route'    => ['name' => 'bank-detail', 'params' => ['id' => (int) $r['statement_id']],
                                   'query' => ['transaction' => $id]],
                ];
            }
            return $out;
        }

        if ($kind === 'cash') {
            foreach ($this->rows(
                "SELECT d.id, d.doc_number, d.issue_date, d.total_amount, d.partner_name, d.description,
                        d.register_id, UPPER(COALESCE(d.currency_code, 'CZK')) AS currency
                   FROM cash_documents d
                  WHERE d.supplier_id = ? AND d.id IN ({$in})",
                array_merge([$supplierId], $ids)
            ) as $r) {
                $id = (int) $r['id'];
                $out[$id] = [
                    'title'    => (string) ($r['doc_number'] ?: ('#' . $id)),
                    'subtitle' => $r['partner_name'] !== null && $r['partner_name'] !== ''
                        ? (string) $r['partner_name'] : (string) $r['description'],
                    'date'     => (string) $r['issue_date'],
                    'amount'   => (float) $r['total_amount'],
                    'currency' => (string) $r['currency'],
                    // Pokladna nemá detail-stránku dokladu, jen seznam filtrovatelný přes `q`.
                    'route'    => $r['doc_number'] !== null ? [
                        'name'  => 'accounting-cash',
                        'query' => array_filter([
                            'register_id' => $r['register_id'] !== null ? (string) $r['register_id'] : null,
                            'q'           => (string) $r['doc_number'],
                        ], static fn ($v): bool => $v !== null),
                    ] : null,
                ];
            }
            return $out;
        }

        if ($kind === 'invoice') {
            foreach ($this->rows(
                "SELECT i.id, i.varsymbol, i.issue_date, i.total_with_vat, i.client_snapshot,
                        c.company_name, c.first_name, c.last_name,
                        UPPER(COALESCE(cur.code, 'CZK')) AS currency
                   FROM invoices i
                   LEFT JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
                   LEFT JOIN currencies cur ON cur.id = i.currency_id
                  WHERE i.supplier_id = ? AND i.id IN ({$in})",
                array_merge([$supplierId], $ids)
            ) as $r) {
                $id = (int) $r['id'];
                $out[$id] = [
                    'title'    => (string) ($r['varsymbol'] ?: ('#' . $id)),
                    'subtitle' => DocumentPartnerName::from($r),
                    'date'     => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
                    'amount'   => (float) $r['total_with_vat'],
                    'currency' => (string) $r['currency'],
                    'route'    => ['name' => 'invoice-detail', 'params' => ['id' => $id]],
                ];
            }
            return $out;
        }

        if ($kind === 'purchase_invoice') {
            foreach ($this->rows(
                "SELECT p.id, p.varsymbol, p.vendor_invoice_number, p.issue_date, p.total_with_vat,
                        p.vendor_snapshot, c.company_name, c.first_name, c.last_name,
                        UPPER(COALESCE(cur.code, 'CZK')) AS currency
                   FROM purchase_invoices p
                   LEFT JOIN clients c ON c.id = p.vendor_id AND c.supplier_id = p.supplier_id
                   LEFT JOIN currencies cur ON cur.id = p.currency_id
                  WHERE p.supplier_id = ? AND p.id IN ({$in})",
                array_merge([$supplierId], $ids)
            ) as $r) {
                $id = (int) $r['id'];
                $out[$id] = [
                    'title'    => (string) ($r['vendor_invoice_number'] ?: $r['varsymbol'] ?: ('#' . $id)),
                    'subtitle' => DocumentPartnerName::from($r, 'vendor_snapshot'),
                    'date'     => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
                    'amount'   => (float) $r['total_with_vat'],
                    'currency' => (string) $r['currency'],
                    'route'    => ['name' => 'purchase-invoice-detail', 'params' => ['id' => $id]],
                ];
            }
            return $out;
        }

        if ($kind === 'settlement') {
            foreach ($this->rows(
                "SELECT s.id, s.settled_on, s.amount, s.note, a.account_code, a.name AS account_name
                   FROM invoice_settlements s
                   JOIN chart_of_accounts a ON a.id = s.account_id
                  WHERE s.supplier_id = ? AND s.id IN ({$in})",
                array_merge([$supplierId], $ids)
            ) as $r) {
                $id = (int) $r['id'];
                $out[$id] = [
                    'title'    => (string) $r['account_code'],
                    'subtitle' => $r['note'] !== null && $r['note'] !== ''
                        ? (string) $r['note'] : (string) $r['account_name'],
                    'date'     => (string) $r['settled_on'],
                    'amount'   => (float) $r['amount'],
                    'currency' => 'CZK',
                    // Zápočet nemá vlastní stránku — proklik vede jen na jeho zaúčtování.
                    'route'    => null,
                ];
            }
            return $out;
        }

        if ($kind === 'gopay') {
            foreach ($this->rows(
                "SELECT m.id,m.performed_on,m.amount,m.order_id,m.payment_session_id,
                        m.movement_type,c.id clearing_pk,c.clearing_id,c.currency
                   FROM gopay_movements m
                   JOIN gopay_clearings c ON c.id=m.clearing_id AND c.supplier_id=m.supplier_id
                  WHERE m.supplier_id=? AND m.id IN ({$in})",
                array_merge([$supplierId], $ids)
            ) as $r) {
                $id = (int) $r['id'];
                $reference = (string) ($r['order_id'] ?: $r['payment_session_id'] ?: ('#' . $id));
                $out[$id] = [
                    'title' => 'GoPay ' . $reference,
                    'subtitle' => 'Vyúčtování ' . (string) $r['clearing_id'],
                    'date' => (string) $r['performed_on'],
                    'amount' => (float) $r['amount'],
                    'currency' => strtoupper((string) $r['currency']),
                    'route' => ['name' => 'gopay', 'query' => ['clearing' => (int) $r['clearing_pk']]],
                ];
            }
            return $out;
        }

        if ($kind === 'journal_entry') {
            // Protějškem měkké vazby je sám ÚČETNÍ ZÁPIS (typicky ruční doúčtování),
            // ne doklad — popisná data proto jdou z deníku a částka je Σ MD.
            foreach ($this->rows(
                "SELECT e.id, e.document_no, e.description, e.entry_date,
                        COALESCE((SELECT SUM(l.amount) FROM journal_entry_lines l
                                   WHERE l.entry_id = e.id AND l.supplier_id = e.supplier_id
                                     AND l.side = 'debit'), 0) AS amount
                   FROM journal_entries e
                  WHERE e.supplier_id = ? AND e.id IN ({$in})",
                array_merge([$supplierId], $ids)
            ) as $r) {
                $id = (int) $r['id'];
                $out[$id] = [
                    'title'    => (string) ($r['document_no'] ?: ('#' . $id)),
                    'subtitle' => $r['description'] !== null ? (string) $r['description'] : null,
                    'date'     => (string) $r['entry_date'],
                    'amount'   => (float) $r['amount'],
                    'currency' => 'CZK',
                    // Vlastní doklad nemá — proklik obstarají tlačítka na zápis.
                    'route'    => null,
                ];
            }
            return $out;
        }

        return $out;
    }

    /**
     * Poslední nestornovaný zápis pro každý doklad daného typu.
     *
     * @param  list<int> $ids
     * @return array<int,array{id:int, entry_date:string, document_no:?string, posted_at:?string}>
     */
    private function entriesFor(int $supplierId, string $sourceType, array $ids): array
    {
        if ($ids === []) return [];
        $in = $this->placeholders($ids);

        // U reference 'journal_entry' JE protějšek sám zápisem — nehledá se přes
        // (source_type, source_id), klíčem je rovnou jeho id.
        if ($sourceType === 'journal_entry') {
            $out = [];
            foreach ($this->rows(
                "SELECT id, entry_date, document_no, posted_at FROM journal_entries
                  WHERE supplier_id = ? AND id IN ({$in})",
                array_merge([$supplierId], $ids)
            ) as $r) {
                $out[(int) $r['id']] = [
                    'id'          => (int) $r['id'],
                    'entry_date'  => (string) $r['entry_date'],
                    'document_no' => $r['document_no'] !== null ? (string) $r['document_no'] : null,
                    'posted_at'   => $r['posted_at'] !== null ? (string) $r['posted_at'] : null,
                ];
            }
            return $out;
        }

        // Doklad může mít víc zápisů (storno + přeúčtování). Panel má ukázat ten
        // aktuální, proto ROW_NUMBER() přes source_id, ne prostý JOIN.
        $rows = $this->rows(
            "SELECT id, source_id, entry_date, document_no, posted_at FROM (
                 SELECT id, source_id, entry_date, document_no, posted_at,
                        ROW_NUMBER() OVER (PARTITION BY source_id ORDER BY id DESC) AS rn
                   FROM journal_entries
                  WHERE supplier_id = ? AND source_type = ? AND source_id IN ({$in})
                    AND reversed_by IS NULL
             ) x WHERE x.rn = 1",
            array_merge([$supplierId, $sourceType], $ids)
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['source_id']] = [
                'id'          => (int) $r['id'],
                'entry_date'  => (string) $r['entry_date'],
                'document_no' => $r['document_no'] !== null ? (string) $r['document_no'] : null,
                'posted_at'   => $r['posted_at'] !== null ? (string) $r['posted_at'] : null,
            ];
        }
        return $out;
    }

    // ───────────────────────────────────────────────────────────── plumbing ──

    /**
     * Dotaz s OR-skupinou přes několik sloupců; prázdné množiny se do SQL vůbec
     * nedostanou (`IN ()` je syntaktická chyba) a je-li prázdná celá skupina,
     * dotaz se neprovede — jinak by WHERE bez omezení vrátilo celou tabulku.
     *
     * @param  list<mixed>                    $params
     * @param  list<array{0:string,1:list<int>}> $groups sloupec => povolená id
     * @return list<array<string,mixed>>
     */
    private function pairsQuery(string $sql, array $params, array $groups): array
    {
        $ors = [];
        foreach ($groups as [$column, $ids]) {
            if ($ids === []) continue;
            $ors[] = "{$column} IN (" . $this->placeholders($ids) . ')';
            $params = array_merge($params, $ids);
        }
        if ($ors === []) return [];
        return $this->rows($sql . ' AND (' . implode(' OR ', $ors) . ')', $params);
    }

    /** @param array<string,mixed> $entry */
    private function sourceId(array $entry): ?int
    {
        $raw = $entry['source_id'] ?? null;
        if ($raw === null) return null;
        $id = (int) $raw;
        return ($id > 0 && $id < self::SYNTHETIC_ID_FLOOR) ? $id : null;
    }

    /** @param list<int> $ids */
    private function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /**
     * @param  list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
