<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankTransactionReleaseService;
use PDO;

/**
 * JournalIntegrityService — ČISTĚ ČTECÍ (read-only) diagnostika konzistence
 * doklad ↔ účetní deník (Fáze A/A6 audit 2026-07). Nic neopravuje; pouze
 * detekuje díry, které mohly vzniknout přímými DB zásahy, migrací dat, nebo
 * force-editem zaúčtovaného dokladu bez re-postu.
 *
 * Kontroly (per supplier v podvojném účetnictví):
 *   - orphan_entry         — aktivní zaúčtovaný zápis odkazuje na zdroj, který už
 *                            neexistuje: vydaná/přijatá faktura, pokladní doklad nebo
 *                            bankovní pohyb (bank, vypořádání a uzavření platby kartou).
 *   - unbalanced_entry     — Σ MD ≠ Σ D na řádcích jednoho zápisu (v haléřích).
 *                            PostingService to jinak vynucuje — pojistka proti
 *                            přímým DB zásahům.
 *   - booked_without_entry — doklad má booked_at, ale žádný aktivní (nestornovaný)
 *                            zaúčtovaný zápis pro něj neexistuje.
 *   - entry_without_booked — aktivní zaúčtovaný zápis existuje, ale doklad má
 *                            booked_at NULL (nekonzistence opačným směrem).
 *   - amount_mismatch      — total_with_vat dokladu (přepočtený kurzem na CZK) se
 *                            neshoduje s žádným řádkem zápisu (haléřová tolerance).
 *                            Saldokontní řádek (311/321) nese vždy celkovou částku
 *                            dokladu — když se doklad po zaúčtování změnil, žádný
 *                            řádek se s novým totálem netrefí. Výjimka na OBOU
 *                            větvích: daňový doklad k přijaté platbě (§ 28 ZDPH)
 *                            účtuje jen DPH, očekává se total_vat.
 *   - cancelled_with_entry — doklad je stornovaný, ale jeho zápis je v deníku stále
 *                            aktivní (storno neprošlo přes DocumentJournalSync).
 *
 * „Aktivní" zápis = posted_at IS NOT NULL AND reversed_by IS NULL — stejná
 * definice jako backfill booked_at v migraci 1021 (stornovaný zápis je vyrušen
 * zrcadlem, tedy neaktivní).
 *
 * Výsledek se ukládá do `journal_integrity_findings` (poslední běh per typ) —
 * dashboard čte odsud, ať se plný sweep deníku nepočítá při každém requestu.
 *
 * EP-11: {@see checkTenantIntegrity} přidává tvrdé tenantové/strukturální invarianty
 * (cross-tenant období/zápis, datum mimo období, nekladná částka, FX metadata).
 * Ty se vyhodnocují VŽDY NAŽIVO (aktuální stav, ne poslední naplánovaný běh) a
 * NEUKLÁDAJÍ se do findings tabulky (její ENUM pokrývá jen persistentní {@see TYPES}).
 * Od migrace 1122 je většinu z nich vynucuje přímo DB (složené FK + CHECK).
 */
final class JournalIntegrityService
{
    public const TYPE_ORPHAN_ENTRY = 'orphan_entry';
    public const TYPE_UNBALANCED_ENTRY = 'unbalanced_entry';
    public const TYPE_BOOKED_WITHOUT_ENTRY = 'booked_without_entry';
    public const TYPE_ENTRY_WITHOUT_BOOKED = 'entry_without_booked';
    public const TYPE_AMOUNT_MISMATCH = 'amount_mismatch';
    public const TYPE_CANCELLED_WITH_ENTRY = 'cancelled_with_entry';

    // EP-11: tvrdé tenantové/strukturální invarianty deníku. NEUKLÁDAJÍ se do
    // journal_integrity_findings (ta má ENUM jen pro TYPES výše) — vyhodnocují se
    // VŽDY NAŽIVO přes checkTenantIntegrity() (aktuální výsledek, ne poslední
    // naplánovaný běh cronu). DB je od migrace 1122 vynucuje složeným FK / CHECKem;
    // tyto kontroly slouží k detekci historických porušení z doby před 1122 a jako
    // druhá vrstva za PostingService.
    public const TYPE_CROSS_TENANT_PERIOD = 'cross_tenant_period';
    public const TYPE_CROSS_TENANT_LINE = 'cross_tenant_line';
    public const TYPE_ENTRY_DATE_OUTSIDE_PERIOD = 'entry_date_outside_period';
    public const TYPE_NONPOSITIVE_AMOUNT = 'nonpositive_amount';
    public const TYPE_FX_METADATA = 'fx_metadata_inconsistent';

    /** @var list<string> Typy ukládané do journal_integrity_findings (ENUM 1034). */
    public const TYPES = [
        self::TYPE_ORPHAN_ENTRY,
        self::TYPE_UNBALANCED_ENTRY,
        self::TYPE_BOOKED_WITHOUT_ENTRY,
        self::TYPE_ENTRY_WITHOUT_BOOKED,
        self::TYPE_AMOUNT_MISMATCH,
        self::TYPE_CANCELLED_WITH_ENTRY,
    ];

    /** @var list<string> Live-only tenantové/strukturální invarianty (EP-11). */
    public const TENANT_TYPES = [
        self::TYPE_CROSS_TENANT_PERIOD,
        self::TYPE_CROSS_TENANT_LINE,
        self::TYPE_ENTRY_DATE_OUTSIDE_PERIOD,
        self::TYPE_NONPOSITIVE_AMOUNT,
        self::TYPE_FX_METADATA,
    ];

    /** Kolik ukázkových nálezů uložit do detail JSON (plný count je vždy přesný). */
    private const DETAIL_LIMIT = 100;

    /** Haléřová tolerance porovnání částek (0,5 haléře). */
    private const CENT_TOLERANCE = 0.005;

    /**
     * Tolerance porovnání celkové částky dokladu se zápisem (1 Kč). Přičítá se
     * k ní zaokrouhlení dokladu — viz {@see amountMismatchFrom}. Řádově vyšší než
     * {@see CENT_TOLERANCE} záměrně: daň lze počítat ze základu i shora (§ 37
     * odst. 1 a 2 ZDPH) a po jednotlivých položkách i ze součtu, takže daň
     * přepočtená z položek se od daně vytištěné na dokladu dodavatele běžně liší
     * o haléře až desetihaléře. To není nekonzistence deníku — kontrola má hledat
     * MATERIÁLNÍ rozdíl (doklad změněný po zaúčtování, přímý zásah do DB).
     */
    private const AMOUNT_TOLERANCE = 1.00;

    public function __construct(private readonly Connection $db) {}

    /**
     * ID dodavatelů v podvojném účetnictví (jen ti mají smysl kontrolovat —
     * daňová evidence deník nevede).
     *
     * @return list<int>
     */
    public function doubleEntrySupplierIds(): array
    {
        $rows = $this->db->pdo()
            ->query("SELECT id FROM supplier WHERE accounting_mode = 'double_entry' ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows ?: []);
    }

    /**
     * Spustí všechny kontroly pro jednoho dodavatele. ČISTĚ ČTECÍ — žádný zápis.
     *
     * @return array<string, array{count:int, sample:list<array<string,mixed>>}>
     *         klíč = typ nálezu; count = celkový počet; sample = prvních N řádků.
     */
    public function check(int $supplierId): array
    {
        return [
            self::TYPE_ORPHAN_ENTRY        => $this->checkOrphanEntries($supplierId),
            self::TYPE_UNBALANCED_ENTRY    => $this->checkUnbalancedEntries($supplierId),
            self::TYPE_BOOKED_WITHOUT_ENTRY => $this->checkBookedWithoutEntry($supplierId),
            self::TYPE_ENTRY_WITHOUT_BOOKED => $this->checkEntryWithoutBooked($supplierId),
            self::TYPE_AMOUNT_MISMATCH     => $this->checkAmountMismatch($supplierId),
            self::TYPE_CANCELLED_WITH_ENTRY => $this->checkCancelledWithEntry($supplierId),
        ];
    }

    /**
     * EP-11 — tvrdé tenantové/strukturální invarianty deníku. ČISTĚ ČTECÍ a VŽDY
     * NAŽIVO (aktuální stav dat, ne poslední naplánovaný běh cronu / findings
     * tabulka). Od migrace 1122 je většinu z nich vynucuje DB (složené FK, CHECK);
     * kontroly detekují historická porušení z doby před 1122 a jsou druhou vrstvou
     * za PostingService:
     *   - cross_tenant_period       — zápis odkazuje na období JINÉHO dodavatele
     *                                 (nebo neexistující) → tenant leak.
     *   - cross_tenant_line         — řádek odkazuje na zápis JINÉHO dodavatele.
     *   - entry_date_outside_period — entry_date leží mimo <starts_on, ends_on>
     *                                 svého (správně vlastněného) období.
     *   - nonpositive_amount        — řádek deníku s amount <= 0 (schéma 1005:
     *                                 amount je vždy kladná, MD/D dáno `side`).
     *   - fx_metadata_inconsistent  — nekonzistentní cizoměnová metadata řádku
     *                                 (§4/12: cizí měna bez kurzu/částky v cizí měně,
     *                                 nebo naopak FX metadata na domácím řádku).
     *
     * @return array<string, array{count:int, sample:list<array<string,mixed>>}>
     */
    public function checkTenantIntegrity(int $supplierId): array
    {
        return [
            self::TYPE_CROSS_TENANT_PERIOD       => $this->checkCrossTenantPeriod($supplierId),
            self::TYPE_CROSS_TENANT_LINE         => $this->checkCrossTenantLine($supplierId),
            self::TYPE_ENTRY_DATE_OUTSIDE_PERIOD => $this->checkEntryDateOutsidePeriod($supplierId),
            self::TYPE_NONPOSITIVE_AMOUNT        => $this->checkNonPositiveAmount($supplierId),
            self::TYPE_FX_METADATA               => $this->checkFxMetadata($supplierId),
        ];
    }

    /**
     * Spustí kontroly a uloží výsledek do `journal_integrity_findings`
     * (INSERT … ON DUPLICATE KEY UPDATE — jeden řádek per typ, přepsaný každým
     * během). Ukládání findingů NENÍ zásah do účetních dat — je to separátní
     * diagnostická tabulka.
     *
     * @return array<string, array{count:int, sample:list<array<string,mixed>>}>
     */
    public function checkAndStore(int $supplierId, ?string $checkedAt = null): array
    {
        $findings = $this->check($supplierId);
        $checkedAt ??= date('Y-m-d H:i:s');

        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO journal_integrity_findings
                 (supplier_id, finding_type, finding_count, detail, checked_at)
             VALUES (:sid, :type, :cnt, :detail, :checked_at)
             ON DUPLICATE KEY UPDATE
                 finding_count = VALUES(finding_count),
                 detail        = VALUES(detail),
                 checked_at    = VALUES(checked_at)"
        );
        foreach (self::TYPES as $type) {
            $f = $findings[$type];
            $stmt->execute([
                'sid'        => $supplierId,
                'type'       => $type,
                'cnt'        => $f['count'],
                'detail'     => $f['sample'] === []
                    ? null
                    : json_encode($f['sample'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'checked_at' => $checkedAt,
            ]);
        }
        return $findings;
    }

    /**
     * Poslední uložený výsledek pro dashboard. Sečte počty a vrátí rozpad per typ
     * (jen typy s count > 0). Nepočítá nic naživo — čte jen tabulku findingů.
     *
     * @return array{total:int, checked_at:?string, breakdown:array<string,int>}
     */
    public function latestSummary(int $supplierId): array
    {
        $rows = $this->latestFindings($supplierId);

        $total = 0;
        $checkedAt = null;
        $breakdown = [];
        foreach ($rows as $row) {
            $cnt = (int) $row['finding_count'];
            $checkedAt = (string) $row['checked_at'];
            $total += $cnt;
            if ($cnt > 0) {
                $breakdown[(string) $row['finding_type']] = $cnt;
            }
        }
        return ['total' => $total, 'checked_at' => $checkedAt, 'breakdown' => $breakdown];
    }

    /**
     * Řádky POSLEDNÍHO běhu včetně `detail` (JSON vzorek nálezů pro proklik).
     * Jediné místo, které findings tabulku čte — dashboard i {@see latestSummary}
     * jdou přes tohle, ať se dvě kopie téhož dotazu nerozejdou.
     *
     * AKTUÁLNÍ (poslední) běh, ne směs napříč běhy: filtruj na MAX(checked_at).
     * journal_integrity_findings drží jeden řádek per (supplier, typ) přepsaný
     * každým během, ale osiřelé řádky typů, které cron už neprodukuje (nebo
     * částečný/zkolabovaný běh), by jinak přimíchaly zastaralé počty z DŘÍVĚJŠÍHO
     * naplánovaného běhu. Bereme jen řádky posledního běhu → koherentní výsledek.
     *
     * @return list<array{finding_type:string, finding_count:int, detail:?string, checked_at:string}>
     */
    public function latestFindings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT finding_type, finding_count, detail, checked_at
               FROM journal_integrity_findings
              WHERE supplier_id = :sid
                AND checked_at = (
                    SELECT MAX(checked_at) FROM journal_integrity_findings WHERE supplier_id = :sid2
                )'
        );
        $stmt->execute(['sid' => $supplierId, 'sid2' => $supplierId]);
        return array_map(static fn (array $r): array => [
            'finding_type'  => (string) $r['finding_type'],
            'finding_count' => (int) $r['finding_count'],
            'detail'        => $r['detail'] !== null ? (string) $r['detail'] : null,
            'checked_at'    => (string) $r['checked_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    // ── jednotlivé kontroly (read-only SQL) ────────────────────────────────────

    /**
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function checkOrphanEntries(int $supplierId): array
    {
        // Bankovní zápisy (i vypořádání a uzavření platby kartou) mají source_id =
        // bank_transactions.id bez cizího klíče — smazaný výpis po sobě nechal zápis,
        // který nic nehlídalo. Výpis nese tenanta; legacy výpis bez supplier_id tenanta
        // neprozradí, takže se bere jako existující (nález musí být jistý).
        $bankTypes = implode(',', array_map(
            static fn (string $t): string => "'{$t}'",
            BankTransactionReleaseService::TRANSACTION_SOURCE_TYPES,
        ));
        $sql =
            "FROM journal_entries je
             LEFT JOIN invoices i          ON je.source_type = 'invoice'          AND i.id  = je.source_id AND i.supplier_id = je.supplier_id
             LEFT JOIN purchase_invoices p ON je.source_type = 'purchase_invoice' AND p.id  = je.source_id AND p.supplier_id = je.supplier_id
             LEFT JOIN cash_documents cd   ON je.source_type = 'cash'             AND cd.id = je.source_id AND cd.supplier_id = je.supplier_id
             LEFT JOIN bank_transactions bt ON je.source_type IN ({$bankTypes})   AND bt.id = je.source_id
             LEFT JOIN bank_statements bs  ON bs.id = bt.statement_id
                                          AND (bs.supplier_id IS NULL OR bs.supplier_id = je.supplier_id)
             WHERE je.supplier_id = :sid
               AND je.source_type IN ('invoice','purchase_invoice','cash',{$bankTypes})
               AND je.source_id IS NOT NULL
               AND je.posted_at IS NOT NULL
               AND je.reversed_by IS NULL
               AND ((je.source_type = 'invoice'          AND i.id IS NULL)
                 OR (je.source_type = 'purchase_invoice' AND p.id IS NULL)
                 OR (je.source_type = 'cash'             AND cd.id IS NULL)
                 OR (je.source_type IN ({$bankTypes})    AND bs.id IS NULL))";
        $select =
            "SELECT je.id AS entry_id, je.source_type, je.source_id, je.document_no, je.entry_date " . $sql
            . " ORDER BY je.id LIMIT " . self::DETAIL_LIMIT;
        return $this->countAndSample($supplierId, "SELECT COUNT(*) " . $sql, $select);
    }

    /**
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function checkUnbalancedEntries(int $supplierId): array
    {
        // LEFT JOIN, ne INNER: zápis BEZ jediného řádku je taky vadný (prázdná hlavička
        // po zkolabovaném JournalEntryRepository::replace() nebo přímém DELETE řádků).
        // S INNER JOINem takový zápis z GROUP BY vypadl a platil za vyvážený — přitom
        // ho nechytí ani booked_without_entry (zápis existuje), ani entry_without_booked.
        // U source_type mimo invoice/purchase_invoice (manual, bank, cash, closing,
        // depreciation) ho nezachytí vůbec nic jiného.
        $base =
            "SELECT je.id AS entry_id, je.source_type, je.source_id, je.document_no,
                    COUNT(l.id) AS line_count,
                    ROUND(COALESCE(SUM(CASE WHEN l.side = 'debit'  THEN l.amount ELSE 0 END), 0), 2) AS debit,
                    ROUND(COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END), 0), 2) AS credit
               FROM journal_entries je
               LEFT JOIN journal_entry_lines l ON l.entry_id = je.id
              WHERE je.supplier_id = :sid
              GROUP BY je.id
             HAVING line_count = 0 OR ABS(debit - credit) > " . self::CENT_TOLERANCE;

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare($base . " ORDER BY entry_id LIMIT " . self::DETAIL_LIMIT);
        $stmt->execute(['sid' => $supplierId]);
        $sample = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM (" . $base . ") x");
        $cntStmt->execute(['sid' => $supplierId]);
        $count = (int) $cntStmt->fetchColumn();

        return ['count' => $count, 'sample' => $this->normalizeRows($sample)];
    }

    /**
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function checkBookedWithoutEntry(int $supplierId): array
    {
        // Doklad musí být vůbec postovatelný, jinak je „booked_at bez zápisu" korektní
        // stav, ne nález. Vyřazují se:
        //   - stornované doklady — storno booked_at nemaže (CancelInvoiceAction mění jen
        //     status/cancelled_at), takže by každý stornovaný doklad firoval navždy;
        //   - typy, které se do deníku z principu neúčtují — proforma/cancellation na
        //     vydané větvi (mimo PostingService::POSTABLE_ISSUED_INVOICE_TYPES) a
        //     zálohová PF na přijaté (PostingService vyhodí advance_payment_only).
        // Predikát je shodný s AutomationFeedService::unbookedInvoices/unbookedPurchases.
        $sql =
            "FROM (
                 SELECT 'invoice' AS source_type, i.id AS source_id, i.varsymbol AS document_no, i.booked_at
                   FROM invoices i
                  WHERE i.supplier_id = :sid AND i.booked_at IS NOT NULL
                    AND i.status <> 'cancelled'
                    AND i.invoice_type IN ('invoice','credit_note','tax_document','penalty')
                 UNION ALL
                 SELECT 'purchase_invoice', p.id, p.varsymbol, p.booked_at
                   FROM purchase_invoices p
                  WHERE p.supplier_id = :sid2 AND p.booked_at IS NOT NULL
                    AND p.status <> 'cancelled'
                    AND p.document_kind <> 'advance'
             ) d
             WHERE NOT EXISTS (
                 SELECT 1 FROM journal_entries je
                  WHERE je.supplier_id = :sid3
                    AND je.source_type = d.source_type
                    AND je.source_id   = d.source_id
                    AND je.posted_at IS NOT NULL
                    AND je.reversed_by IS NULL
             )";
        $params = ['sid' => $supplierId, 'sid2' => $supplierId, 'sid3' => $supplierId];
        $select = "SELECT d.source_type, d.source_id, d.document_no, d.booked_at " . $sql
            . " ORDER BY d.source_type, d.source_id LIMIT " . self::DETAIL_LIMIT;
        return $this->countAndSample($supplierId, "SELECT COUNT(*) " . $sql, $select, $params);
    }

    /**
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function checkEntryWithoutBooked(int $supplierId): array
    {
        $sql =
            "FROM journal_entries je
             JOIN (
                 SELECT 'invoice' AS source_type, i.id AS source_id FROM invoices i
                  WHERE i.supplier_id = :sid AND i.booked_at IS NULL
                 UNION ALL
                 SELECT 'purchase_invoice', p.id FROM purchase_invoices p
                  WHERE p.supplier_id = :sid2 AND p.booked_at IS NULL
             ) d ON d.source_type = je.source_type AND d.source_id = je.source_id
             WHERE je.supplier_id = :sid3
               AND je.posted_at IS NOT NULL
               AND je.reversed_by IS NULL";
        $params = ['sid' => $supplierId, 'sid2' => $supplierId, 'sid3' => $supplierId];
        $select = "SELECT je.id AS entry_id, je.source_type, je.source_id, je.document_no " . $sql
            . " ORDER BY je.id LIMIT " . self::DETAIL_LIMIT;
        return $this->countAndSample($supplierId, "SELECT COUNT(*) " . $sql, $select, $params);
    }

    /**
     * Stornovaný doklad, který má v deníku stále aktivní zápis.
     *
     * `orphan_entry` řeší jen fyzickou neexistenci dokladu, ne jeho stav — storno
     * mimo DocumentJournalSync::onCancel() (import, přímý status transition, backfill)
     * tak nechá v deníku náklad/výnos a saldokonto dokladu, který už v DPH evidenci
     * není. Kontrola je zrcadlem `booked_without_entry` z opačné strany.
     *
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function checkCancelledWithEntry(int $supplierId): array
    {
        $sql =
            "FROM journal_entries je
             JOIN (
                 SELECT 'invoice' AS source_type, i.id AS source_id, i.varsymbol AS document_no,
                        i.cancelled_at
                   FROM invoices i
                  WHERE i.supplier_id = :sid AND i.status = 'cancelled'
                 UNION ALL
                 SELECT 'purchase_invoice', p.id, p.varsymbol, p.cancelled_at
                   FROM purchase_invoices p
                  WHERE p.supplier_id = :sid2 AND p.status = 'cancelled'
             ) d ON d.source_type = je.source_type AND d.source_id = je.source_id
             WHERE je.supplier_id = :sid3
               AND je.posted_at IS NOT NULL
               AND je.reversed_by IS NULL";
        $params = ['sid' => $supplierId, 'sid2' => $supplierId, 'sid3' => $supplierId];
        $select = "SELECT je.id AS entry_id, je.source_type, je.source_id, d.document_no,
                          d.cancelled_at, je.entry_date " . $sql
            . " ORDER BY je.id LIMIT " . self::DETAIL_LIMIT;
        return $this->countAndSample($supplierId, "SELECT COUNT(*) " . $sql, $select, $params);
    }

    /**
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function checkAmountMismatch(int $supplierId): array
    {
        $sql    = 'FROM ' . $this->amountMismatchFrom();
        $params = $this->amountMismatchParams($supplierId);
        $select = "SELECT je.id AS entry_id, je.source_type, je.source_id, je.document_no,
                          ROUND(doc.expected, 2) AS expected " . $sql
            . " ORDER BY je.id LIMIT " . self::DETAIL_LIMIT;
        return $this->countAndSample($supplierId, "SELECT COUNT(*) " . $sql, $select, $params);
    }

    /**
     * ID zápisů, které aktuálně (NAŽIVO, ne z findings tabulky) padají na
     * {@see TYPE_AMOUNT_MISMATCH}. Slouží filtru deníku, aby si uživatel mohl
     * nálezy prohlédnout — dashboard umí prokliknout jen jeden zápis.
     *
     * @return list<int>
     */
    public function amountMismatchEntryIds(int $supplierId, int $limit = 1000): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT je.id FROM ' . $this->amountMismatchFrom()
            . ' ORDER BY je.entry_date DESC, je.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute($this->amountMismatchParams($supplierId));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array<string,int> */
    private function amountMismatchParams(int $supplierId): array
    {
        return ['sid' => $supplierId, 'sid2' => $supplierId, 'sid3' => $supplierId];
    }

    /**
     * Sdílené FROM+WHERE pro {@see checkAmountMismatch} a {@see amountMismatchEntryIds}
     * (jeden zdroj pravdy — jinak by se filtr deníku rozešel s počtem na dashboardu).
     *
     * Očekávaná celková částka dokladu v CZK = total_with_vat × kurz (CZK/NULL měna
     * → 1). Doklad se po zaúčtování změnil (nebo přímý DB zásah), když se tahle
     * částka v zápisu NIKDE neobjeví. „Nikde" se vyhodnocuje ve třech tvarech,
     * protože zaúčtování saldokonta má víc legitimních podob:
     *   1. jeden řádek = celková částka (dnešní PostingService: 311/321 nese total),
     *   2. součet řádků TÉHOŽ účtu na TÉŽE straně (starší i ručně pořízené zápisy
     *      rozpadají saldokonto na základ + DPH — dohromady dají celkovou částku),
     *   3. saldo TÉHOŽ účtu (MD − D) — zápis nese protizápis na tomtéž účtu
     *      (haléřové vyrovnání, storno řádek, sleva zaúčtovaná opačnou stranou).
     * Tvar 1 je podmnožinou tvaru 2 (jeden řádek = jednoprvková skupina), ale
     * kontroluje se zvlášť: skupina se dvěma řádky, z nichž jeden sedí přesně,
     * by jinak nově propadla jako nález.
     *
     * Tolerance je haléř plus zaokrouhlení dokladu: rozpad po sazbách dá jiné
     * haléře než hlavička a zaokrouhlovací rozdíl se v zápisu nese vlastním
     * řádkem (nebo je rovnou v saldokontní částce). Kontrola má hledat MATERIÁLNÍ
     * rozdíl, ne haléře.
     *
     * Doklad plně krytý zálohou (advance_paid_amount ≥ celková částka) se
     * přeskakuje — saldokonto u něj vůbec nevzniká, závazek/pohledávka se
     * uzavírá proti zálohovému účtu (314/324), takže celková částka dokladu
     * v zápisu být nemá.
     *
     * Daňový doklad k přijaté platbě (§ 28 ZDPH) je výjimka na OBOU větvích —
     * účtuje se jen o DPH (vydaný 324/343, přijatý DDKP 343/314), základ už
     * sedí na zálohovém účtu z úhrady. Očekávaná částka je proto total_vat.
     * Vydaná větev: invoice_type='tax_document'; přijatá: document_kind='tax_document'
     * (PostingService::buildPurchaseAdvanceVatDocumentLines). Symetrii hlídá
     * Architecture\DocumentBranchParityGuardsTest.
     */
    private function amountMismatchFrom(): string
    {
        $docExpr =
            "SELECT 'invoice' AS source_type, i.id AS source_id,
                    (CASE WHEN i.invoice_type = 'tax_document' THEN i.total_vat ELSE i.total_with_vat END)
                    * (CASE WHEN ci.code = 'CZK' OR ci.code IS NULL THEN 1 ELSE COALESCE(i.exchange_rate, 1) END) AS expected,
                    " . self::AMOUNT_TOLERANCE . " + ABS(COALESCE(i.rounding, 0))
                    * (CASE WHEN ci.code = 'CZK' OR ci.code IS NULL THEN 1 ELSE COALESCE(i.exchange_rate, 1) END) AS tol,
                    ABS(COALESCE(i.total_with_vat, 0)) - ABS(COALESCE(i.advance_paid_amount, 0)) AS unsettled
               FROM invoices i
               LEFT JOIN currencies ci ON ci.id = i.currency_id
              WHERE i.supplier_id = :sid
             UNION ALL
             SELECT 'purchase_invoice', p.id,
                    (CASE WHEN p.document_kind = 'tax_document' THEN p.total_vat ELSE p.total_with_vat END)
                    * (CASE WHEN cp.code = 'CZK' OR cp.code IS NULL THEN 1 ELSE COALESCE(p.exchange_rate, 1) END),
                    " . self::AMOUNT_TOLERANCE . " + ABS(COALESCE(p.rounding, 0))
                    * (CASE WHEN cp.code = 'CZK' OR cp.code IS NULL THEN 1 ELSE COALESCE(p.exchange_rate, 1) END),
                    ABS(COALESCE(p.total_with_vat, 0)) - ABS(COALESCE(p.advance_paid_amount, 0))
               FROM purchase_invoices p
               LEFT JOIN currencies cp ON cp.id = p.currency_id
              WHERE p.supplier_id = :sid2";

        // DECIMAL aritmetika je přesná, ROUND na haléře jen srovná měřítko po
        // násobení kurzem (jinak by 0,67 vs 0,67 selhalo na 15. desetinném místě).
        $matches = static fn(string $amount): string =>
            "ABS(ROUND(ABS($amount), 2) - ROUND(ABS(doc.expected), 2)) <= ROUND(doc.tol, 2)";

        return
            "journal_entries je
             JOIN ( " . $docExpr . " ) doc
               ON doc.source_type = je.source_type AND doc.source_id = je.source_id
             WHERE je.supplier_id = :sid3
               AND je.source_type IN ('invoice','purchase_invoice')
               AND je.posted_at IS NOT NULL
               AND je.reversed_by IS NULL
               AND doc.unsettled > doc.tol
               AND NOT EXISTS (
                   SELECT 1 FROM journal_entry_lines l
                    WHERE l.entry_id = je.id
                      AND " . $matches('l.amount') . "
               )
               AND NOT EXISTS (
                   SELECT 1 FROM journal_entry_lines l
                    WHERE l.entry_id = je.id
                    GROUP BY l.account_id, l.side
                   HAVING " . $matches('SUM(l.amount)') . "
               )
               AND NOT EXISTS (
                   SELECT 1 FROM journal_entry_lines l
                    WHERE l.entry_id = je.id
                    GROUP BY l.account_id
                   HAVING " . $matches("SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END)") . "
               )";
    }

    // ── EP-11: tenantové/strukturální invarianty (read-only SQL) ───────────────

    /**
     * Zápis odkazuje na období, které NEPATŘÍ témuž dodavateli (nebo neexistuje).
     *
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function checkCrossTenantPeriod(int $supplierId): array
    {
        $sql =
            "FROM journal_entries je
             LEFT JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
             WHERE je.supplier_id = :sid
               AND p.id IS NULL";
        $select = "SELECT je.id AS entry_id, je.period_id, je.entry_date, je.source_type, je.source_id " . $sql
            . " ORDER BY je.id LIMIT " . self::DETAIL_LIMIT;
        return $this->countAndSample($supplierId, "SELECT COUNT(*) " . $sql, $select);
    }

    /**
     * Řádek odkazuje na zápis, který NEPATŘÍ témuž dodavateli (nebo neexistuje).
     *
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function checkCrossTenantLine(int $supplierId): array
    {
        $sql =
            "FROM journal_entry_lines l
             LEFT JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
             WHERE l.supplier_id = :sid
               AND je.id IS NULL";
        $select = "SELECT l.id AS line_id, l.entry_id, l.account_id, l.side, l.amount " . $sql
            . " ORDER BY l.id LIMIT " . self::DETAIL_LIMIT;
        return $this->countAndSample($supplierId, "SELECT COUNT(*) " . $sql, $select);
    }

    /**
     * entry_date leží mimo <starts_on, ends_on> svého (správně vlastněného) období.
     * Cross-tenant období řeší {@see checkCrossTenantPeriod} — tady JOIN vyžaduje
     * shodu supplier_id, aby se datum porovnávalo jen proti VLASTNÍMU období.
     *
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function checkEntryDateOutsidePeriod(int $supplierId): array
    {
        $sql =
            "FROM journal_entries je
             JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
             WHERE je.supplier_id = :sid
               AND (je.entry_date < p.starts_on OR je.entry_date > p.ends_on)";
        $select = "SELECT je.id AS entry_id, je.entry_date, je.period_id, p.starts_on, p.ends_on " . $sql
            . " ORDER BY je.id LIMIT " . self::DETAIL_LIMIT;
        return $this->countAndSample($supplierId, "SELECT COUNT(*) " . $sql, $select);
    }

    /**
     * Řádek deníku s nekladnou částkou (amount <= 0). Schéma 1005: amount je vždy
     * kladná, směr dán sloupcem `side`.
     *
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function checkNonPositiveAmount(int $supplierId): array
    {
        $sql =
            "FROM journal_entry_lines l
             WHERE l.supplier_id = :sid
               AND l.amount <= 0";
        $select = "SELECT l.id AS line_id, l.entry_id, l.account_id, l.side, l.amount " . $sql
            . " ORDER BY l.id LIMIT " . self::DETAIL_LIMIT;
        return $this->countAndSample($supplierId, "SELECT COUNT(*) " . $sql, $select);
    }

    /**
     * Nekonzistentní cizoměnová metadata řádku (§4/12 ZoÚ). Sloupce z 1008:
     * currency_code (NULL = domácí/CZK), fx_rate, amount_foreign.
     *   - cizí řádek (currency_code ≠ NULL/CZK) MUSÍ nést kladný fx_rate i
     *     kladnou amount_foreign — jinak nelze přecenit k rozvahovému dni.
     *   - domácí řádek (currency_code NULL) NESMÍ nést FX metadata (amount_foreign,
     *     ani netriviální fx_rate ≠ 1) — osiřelá metadata bez měnové identity.
     *
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function checkFxMetadata(int $supplierId): array
    {
        // Měnová identita se normalizuje přes COALESCE(...,'CZK'): řádek s EXPLICITNÍM
        // currency_code='CZK' dřív nespadl ani do jedné větve (první ho vylučovala,
        // druhá vyžadovala NULL), takže mohl nést libovolná FX metadata neviditelně.
        // PostingService::withForeign 'CZK' nikdy nezapisuje — vzniká jen z ručních
        // a importních cest, kde validace chybí.
        //
        // amount_foreign = 0 je LEGITIMNÍ u přecenění k rozvahovému dni: FxRevaluationService
        // zapisuje FX stopu (currency_code + fx_rate) s amount_foreign = 0.0 záměrně —
        // přeceněním se cizoměnová částka nemění (R20), stopa jen drží měnovou identitu
        // účtu pro další období. Nálezem je proto jen záporná částka, nebo nula mimo
        // přeceňovací zápis.
        $sql =
            "FROM journal_entry_lines l
             JOIN journal_entries je ON je.id = l.entry_id
             WHERE l.supplier_id = :sid
               AND (
                   (COALESCE(l.currency_code, 'CZK') <> 'CZK'
                       AND (l.fx_rate IS NULL OR l.fx_rate <= 0
                            OR l.amount_foreign IS NULL
                            OR l.amount_foreign < 0
                            OR (l.amount_foreign = 0 AND je.source_type <> 'fx_revaluation')))
                OR (COALESCE(l.currency_code, 'CZK') = 'CZK'
                       AND (l.amount_foreign IS NOT NULL
                            OR (l.fx_rate IS NOT NULL AND l.fx_rate <> 1)))
               )";
        $select = "SELECT l.id AS line_id, l.entry_id, je.source_type, l.currency_code, l.fx_rate, l.amount_foreign, l.amount " . $sql
            . " ORDER BY l.id LIMIT " . self::DETAIL_LIMIT;
        return $this->countAndSample($supplierId, "SELECT COUNT(*) " . $sql, $select);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * @param array<string,int>|null $params
     * @return array{count:int, sample:list<array<string,mixed>>}
     */
    private function countAndSample(int $supplierId, string $countSql, string $selectSql, ?array $params = null): array
    {
        $params ??= ['sid' => $supplierId];
        $pdo = $this->db->pdo();

        $cntStmt = $pdo->prepare($countSql);
        $cntStmt->execute($params);
        $count = (int) $cntStmt->fetchColumn();

        $sample = [];
        if ($count > 0) {
            $stmt = $pdo->prepare($selectSql);
            $stmt->execute($params);
            $sample = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return ['count' => $count, 'sample' => $this->normalizeRows($sample)];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $norm = [];
            foreach ($row as $k => $v) {
                if (in_array($k, ['entry_id', 'source_id', 'line_id', 'period_id', 'account_id'], true) && $v !== null) {
                    $norm[$k] = (int) $v;
                } elseif (in_array($k, ['debit', 'credit', 'expected', 'amount', 'fx_rate', 'amount_foreign'], true) && $v !== null) {
                    $norm[$k] = (float) $v;
                } else {
                    $norm[$k] = $v;
                }
            }
            $out[] = $norm;
        }
        return $out;
    }
}
