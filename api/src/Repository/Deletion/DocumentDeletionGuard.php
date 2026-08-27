<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Deletion;

use PDO;

/**
 * Co brání natrvalo smazat doklad z koše.
 *
 * ── Proč to bylo nejhorší z celého nálezu ─────────────────────────────────────
 * Vysypání koše byl JEDEN hromadný `DELETE FROM documents WHERE deleted_at IS NOT
 * NULL` bez jakékoli kontroly. Mzdový modul přidal tři cizí klíče RESTRICT
 * (`payroll_document_dms_links`, `payroll_enforcement_case_documents`,
 * `payroll_enforcement_events`), takže JEDINÝ takový doklad shodil celý příkaz —
 * koš pak nešlo vysypat vůbec nikdy, uživatel neměl jak zjistit který doklad to
 * způsobil, a nemělo to obchvat. Proto se dnes maže cíleně: blokované doklady
 * v koši zůstanou, zbytek zmizí, a odpověď řekne kolik jich zůstalo a proč.
 *
 * ── Tichá kaskáda na artefakty podání ─────────────────────────────────────────
 * `tax_submission_artifacts.document_id` je naopak ON DELETE CASCADE, takže
 * vysypání koše mlčky mazalo důkaz o podání na finanční správu (odeslané XML,
 * podepsaný `.p7s`, potvrzení EPO) — bez chyby, bez stopy, jen se to ztratilo.
 * Řídíme se týmž pravidlem jako mzdový modul u zaměstnance: blokovat smí důkaz
 * vnějšího úkonu. Podání na FS je vnější úkon jako každý jiný, proto je tahle
 * vazba VÝSLOVNĚ blokující, i když ji databáze sama nevynucuje.
 *
 * ── Kaskáda uvnitř `documents` ────────────────────────────────────────────────
 * `documents.parent_document_id` kaskáduje, takže smazání rodiče smete i potomka.
 * Kdyby byl blokovaný potomek a rodič ne, DELETE rodiče by na FK potomka spadl.
 * Proto se blokace propisuje i na předky ({@see blockedTrashDocuments()}).
 */
final class DocumentDeletionGuard extends ForeignKeyDeletionGuard
{
    /** Doklad zůstal v koši kvůli blokovanému potomkovi, ne kvůli vlastní vazbě. */
    private const CASCADE_PARENT_CODE = 'blocked_child_document';

    private const CASCADE_PARENT_MESSAGE = 'Doklad zůstal v koši, protože jeho podřízený soubor '
        . '(podepsaná verze / příloha) je navázaný jinou agendou a smazáním rodiče by zmizel s ním.';

    protected static function blockers(): array
    {
        return [
            'purchase_invoice_submission' => [
                'message' => 'Doklad je originálem předaným klientem ve frontě příchozích dokladů '
                    . '(%d vazeb). Originál je součástí auditní stopy a nelze ho odstranit.',
                'references' => [
                    ['table' => 'purchase_invoice_submissions', 'column' => 'document_id'],
                ],
            ],
            'payroll_document' => [
                'message' => 'Doklad je v mzdové agendě připojený k vydané výplatní pásce, mzdovému '
                    . 'listu nebo ročnímu potvrzení (%d vazeb). Ty doklady jsou neměnné — odpojte '
                    . 'ho nejdřív v Mzdy → Dokumenty.',
                'references' => [
                    ['table' => 'payroll_document_dms_links', 'column' => 'dms_document_id'],
                ],
            ],
            'payroll_enforcement_evidence' => [
                'message' => 'Doklad je důkazem k vedené exekuci nebo insolvenci — usnesení, doručenka '
                    . 'nebo rozhodnutí (%d vazeb). Odpojit ho jde jen v Mzdy → Exekuce.',
                'references' => [
                    ['table' => 'payroll_enforcement_case_documents', 'column' => 'dms_document_id'],
                    ['table' => 'payroll_enforcement_events', 'column' => 'decision_document_id'],
                    ['table' => 'payroll_insolvency_payment_instructions', 'column' => 'decision_document_id'],
                ],
            ],
            'payroll_enforcement_xmlzam_source' => [
                'message' => 'Doklad je ověřenou zdrojovou přílohou požadavku XMLZAM na součinnost '
                    . 'exekutorovi (%d vazeb). Je součástí neměnné důkazní stopy a nelze ho odstranit.',
                'references' => [
                    ['table' => 'payroll_enforcement_xmlzam_requests', 'column' => 'source_document_id'],
                ],
            ],
            'payroll_production_qualification' => [
                'message' => 'Doklad prokazuje připravenost firmy k ostrému provozu mezd '
                    . '(%d vazeb). Je součástí neměnné kvalifikační stopy a nelze ho odstranit.',
                'references' => [
                    [
                        'table' => 'payroll_production_qualification_documents',
                        'column' => 'document_id',
                    ],
                ],
            ],
            'tax_submission_artifact' => [
                'message' => 'Doklad je součástí podání na finanční správu — odeslané XML, podepsaný '
                    . 'soubor nebo potvrzení EPO (%d vazeb). Kdyby zmizel, ztratíte důkaz o tom, '
                    . 'co bylo podáno, proto v koši zůstává. Obnovte ho zpět do složky, ať se '
                    . 'neztratí; smazat půjde teprve tehdy, když zanikne samotné podání.',
                'references' => [
                    ['table' => 'tax_submission_artifacts', 'column' => 'document_id'],
                ],
            ],
            'document_link' => [
                'message' => 'Doklad je propojený s jinou agendou přes vazby Dokumentů (%d vazeb). Odpojte ho nejdřív v detailu příslušné agendy.',
                'references' => [
                    ['table' => 'document_links', 'column' => 'document_id'],
                ],
            ],
            'monthly_report_send' => [
                'message' => 'Doklad je archivovanou kopií odeslaného měsíčního reportu (%d vazeb) a nelze ho odstranit.',
                'references' => [
                    ['table' => 'monthly_report_sends', 'column' => 'document_id'],
                ],
            ],
            'submission_receipt' => [
                'message' => 'Doklad je doručenkou podání v datové schránce (%d vazeb) a nelze ho odstranit.',
                'references' => [
                    ['table' => 'submission_outbox', 'column' => 'receipt_document_id'],
                ],
            ],
        ];
    }

    public static function parentTables(): array
    {
        return ['documents'];
    }

    /**
     * Tabulky, které registr blokuje ZÁMĚRNĚ, i když je databáze nevynucuje
     * (kaskádují). Strukturální test díky tomu pozná rozdíl mezi „chybí v registru"
     * a „je v registru schválně".
     *
     * @return list<string>
     */
    public static function deliberateCascadeBlockers(): array
    {
        return [
            'tax_submission_artifacts',
            'document_links',
        ];
    }

    /**
     * Které z nabídnutých dokladů se natrvalo smazat nesmí — vč. předků, na které
     * by se blokace propsala kaskádou `parent_document_id`.
     *
     * @param list<int> $candidateIds doklady v koši, které volající chce smazat
     * @return array<int,DeletionConflict> id dokladu => důvod, proč zůstává
     */
    public function blockedTrashDocuments(int $supplierId, array $candidateIds): array
    {
        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        if ($candidateIds === []) {
            return [];
        }

        $blocked = [];
        foreach ($this->countBlockersForIds($supplierId, $candidateIds) as $id => $counts) {
            $blocked[$id] = new DeletionConflict('has_dependencies', self::describe($counts), $counts);
        }

        foreach ($this->ancestorsOf($supplierId, array_keys($blocked), $candidateIds) as $ancestorId) {
            $blocked[$ancestorId] = new DeletionConflict(
                'has_dependencies',
                self::CASCADE_PARENT_MESSAGE,
                [self::CASCADE_PARENT_CODE => 1],
            );
        }

        return $blocked;
    }

    /**
     * Předci blokovaných dokladů uvnitř mazané dávky. Chodí se nahoru po
     * `parent_document_id`, dokud se najde nový předek — hloubka je v praxi 1
     * (podepsaná verze), cyklus databáze nepovolí, a `count($candidateIds)` je
     * tvrdá horní mez.
     *
     * @param list<int> $blockedIds
     * @param list<int> $candidateIds
     * @return list<int>
     */
    private function ancestorsOf(int $supplierId, array $blockedIds, array $candidateIds): array
    {
        $candidates = array_flip($candidateIds);
        $found = [];
        $frontier = $blockedIds;
        $guard = count($candidateIds) + 1;

        while ($frontier !== [] && $guard-- > 0) {
            $next = [];
            foreach (array_chunk($frontier, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $this->db->pdo()->prepare(
                    'SELECT DISTINCT parent_document_id FROM documents'
                    . " WHERE supplier_id = ? AND id IN ({$placeholders})"
                    . ' AND parent_document_id IS NOT NULL'
                );
                $stmt->execute(array_merge([$supplierId], $chunk));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $parentId) {
                    $parentId = (int) $parentId;
                    if (isset($candidates[$parentId]) && !isset($found[$parentId])) {
                        $found[$parentId] = true;
                        $next[] = $parentId;
                    }
                }
            }
            $frontier = $next;
        }

        return array_map('intval', array_keys($found));
    }

    /** Doklad, který neprošel ani přes registr — vazba vznikla za běhu vysypávání. */
    public static function raceMessage(): string
    {
        return 'Doklad zůstal v koši — mezitím na něj vznikla vazba z jiné agendy. '
            . 'Otevřete koš znovu, uvidíte aktuální stav.';
    }
}
