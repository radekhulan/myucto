<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use PDO;

/**
 * Repository pro uzávěrku období (Epic F4) — SQL podklady závěrkových zápisů
 * (R8/R9/R10), CRUD kroků průvodce (accounting_closing_steps) a hard delete
 * závěrkových zápisů při revertu (R12).
 *
 * Okna zůstatků (R9): výsledkové účty za období (BETWEEN starts_on AND ends_on),
 * rozvahové kumulativně k ends_on přes celou historii (starší closing/opening
 * páry se vzájemně nulují) — vždy s vyloučením VLASTNÍHO closing zápisu období
 * (idempotentní re-run). Všechny dotazy jsou tenant-scoped přes supplier_id.
 */
final class ClosingRepository
{
    /**
     * Haléřová tolerance nerozpuštěného dohadu (ČÚS 019). Odhad se od doručené faktury
     * o pár haléřů liší běžně a rozdíl se doúčtuje do nákladu — hlásit ho jako
     * „nerozpuštěný dohad" by kontrolu utopilo v šumu.
     */
    private const ESTIMATE_TOLERANCE = 0.50;

    public function __construct(private readonly Connection $db) {}

    public function provisionSourceId(int $supplierId, int $periodId, int $invoiceId): int
    {
        $pdo = $this->db->pdo();
        $mapped = $pdo->prepare('SELECT id FROM accounting_receivable_provisions WHERE supplier_id = ? AND period_id = ? AND invoice_id = ?');
        $mapped->execute([$supplierId, $periodId, $invoiceId]);
        $mappedId = $mapped->fetchColumn();
        if ($mappedId !== false) {
            return ClosingSourceId::PROVISION_BASE + (int) $mappedId;
        }
        $legacy = $pdo->prepare("SELECT period_id FROM journal_entries WHERE supplier_id = ? AND source_type = 'provision' AND source_id = ? LIMIT 1");
        $legacy->execute([$supplierId, $invoiceId]);
        $legacyPeriod = $legacy->fetchColumn();
        if ($legacyPeriod === false || (int) $legacyPeriod === $periodId) {
            return $invoiceId;
        }
        $pdo->prepare('INSERT INTO accounting_receivable_provisions (supplier_id, period_id, invoice_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id')
            ->execute([$supplierId, $periodId, $invoiceId]);
        $stmt = $pdo->prepare('SELECT id FROM accounting_receivable_provisions WHERE supplier_id = ? AND period_id = ? AND invoice_id = ?');
        $stmt->execute([$supplierId, $periodId, $invoiceId]);
        return ClosingSourceId::PROVISION_BASE + (int) $stmt->fetchColumn();
    }

    public function provisionOpeningState(int $supplierId, string $startsOn, string $endsOn): array
    {
        $origin = \MyInvoice\Service\Tax\Return\JournalTaxOrigin::provisionsBeforeCte($supplierId);
        $stmt = $this->db->pdo()->prepare(
            "WITH RECURSIVE {$origin}
             SELECT COALESCE(pr.invoice_id, tax_origin.source_id) AS invoice_id,
                    SUM(CASE WHEN a.account_code LIKE '558%' OR parent.account_code LIKE '558%' THEN IF(l.side = 'debit', l.amount, -l.amount) ELSE 0 END) AS legal_amount,
                    SUM(CASE WHEN a.account_code LIKE '559%' OR parent.account_code LIKE '559%' THEN IF(l.side = 'debit', l.amount, -l.amount) ELSE 0 END) AS acct_amount
               FROM journal_entries e
               JOIN tax_journal_origins tax_origin ON tax_origin.id = e.id
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = e.supplier_id
               LEFT JOIN chart_of_accounts parent ON parent.id = a.parent_id AND parent.supplier_id = a.supplier_id
               LEFT JOIN accounting_receivable_provisions pr
                      ON pr.id = CASE WHEN tax_origin.source_id >= ? THEN tax_origin.source_id - ? ELSE NULL END
                     AND pr.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                AND tax_origin.source_type = 'provision' AND tax_origin.source_id IS NOT NULL
              GROUP BY COALESCE(pr.invoice_id, tax_origin.source_id)"
        );
        $stmt->execute([$startsOn, ClosingSourceId::PROVISION_BASE, ClosingSourceId::PROVISION_BASE, $supplierId, $endsOn]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['invoice_id']] = [
                'invoice_id' => (int) $row['invoice_id'],
                'legal_amount' => round((float) $row['legal_amount'], 2),
                'acct_amount' => round((float) $row['acct_amount'], 2),
            ];
        }
        $sections = $this->db->pdo()->prepare(
            "SELECT s.payload FROM accounting_closing_steps s
               JOIN accounting_periods p ON p.id = s.period_id AND p.supplier_id = s.supplier_id
              WHERE s.supplier_id = ? AND s.step_key = 'provisions' AND p.ends_on < ? ORDER BY p.ends_on"
        );
        $sections->execute([$supplierId, $startsOn]);
        foreach ($sections->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            foreach ((json_decode((string) $raw, true)['entries'] ?? []) as $entry) {
                $id = (int) ($entry['invoice_id'] ?? 0);
                if (isset($result[$id])) {
                    $result[$id]['legal_section'] = $entry['legal_section'] ?? null;
                }
            }
        }
        return $result;
    }

    /**
     * Zůstatky výsledkových účtů za období (R9), per účet vč. analytik,
     * jen nenulové. `bal` je signed netto (MD kladně).
     *
     * Vylučuje se JEN close_books zápis samotný (source_id = period_id) —
     * idempotence re-runu. Zápisy kroku „Zásoby" (source_type 'closing' se
     * slotovaným source_id, SKLAD §3.4) se do zůstatků POČÍTAJÍ — jejich efekt
     * (112/132 v rozvaze, snížení 501/504) musí projít do closing zápisu.
     * `<=>` = NULL-safe rovnost (storno má source_id NULL → nesmí vypadnout).
     *
     * @return list<array{account_id:int, account_code:string, name:string, bal:float}>
     */
    public function plBalances(int $supplierId, int $periodId, string $startsOn, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.account_id, a.account_code, a.name,
                    SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS bal
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND a.account_type IN ('revenue','expense')
                AND NOT (e.source_type = 'closing' AND e.period_id = ? AND e.source_id <=> ?)
              GROUP BY l.account_id, a.account_code, a.name
             HAVING bal <> 0
              ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, $periodId, $periodId]);
        return array_map(self::castBalance(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Kumulativní zůstatky rozvahových účtů k ends_on (R9), per účet vč.
     * analytik, jen nenulové, bez vlastního close_books zápisu období
     * (source_id = period_id; slotované stock zápisy §3.4 se počítají —
     * viz plBalances).
     *
     * @return list<array{account_id:int, account_code:string, name:string, bal:float}>
     */
    public function bsBalances(int $supplierId, int $periodId, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.account_id, a.account_code, a.name,
                    SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS bal
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date <= ?
                AND a.account_type IN ('asset','liability','equity')
                AND NOT (e.source_type = 'closing' AND e.period_id = ? AND e.source_id <=> ?)
              GROUP BY l.account_id, a.account_code, a.name
             HAVING bal <> 0
              ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId, $endsOn, $periodId, $periodId]);
        return array_map(self::castBalance(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Σ netto výsledkových účtů PŘED starts_on — precheck R9: nenulová stará
     * neuzavřená historie by zfalšovala VH prvního closingu (error).
     */
    public function plBalanceBefore(int $supplierId, string $startsOn): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date < ?
                AND a.account_type IN ('revenue','expense')"
        );
        $stmt->execute([$supplierId, $startsOn]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * ČÚS 019 — dohadné položky (388 aktivní / 389 pasivní) PŘENESENÉ z minulého období,
     * které se v tomhle období nerozpustily.
     *
     * Dohad je odhad nákladu/výnosu, u kterého k rozvahovému dni chybí doklad. Jakmile
     * doklad v N+1 dorazí, dohad se musí rozpustit (389: MD 389 / D 321) — jinak knihy
     * nesou náklad DVAKRÁT: jednou v dohadu z minulého roku a podruhé v doručené faktuře.
     * Rozpuštění je dnes čistě ruční úkon a nic ho nehlídalo; `estimates_balances` je
     * `info` s `ok => true`, takže zůstatek projde tiše.
     *
     * Počítá se z POČÁTEČNÍHO zůstatku, ne koncového: dohad zaúčtovaný na konci TOHOTO
     * období má na účtu zůstat, ten z minulého ne. Rozpuštěním je pohyb na opačné straně,
     * než na které dohad sedí (389 pasivní → MD, 388 aktivní → D).
     *
     * Počáteční zůstatek se bere K PRVNÍMU DNI období, ne ke dni předchozímu: otevírací
     * zápis má `entry_date = starts_on` (OpeningBalanceService), takže měření „den před"
     * by u firmy, která zůstatky přenáší otevíracím zápisem, vracelo nulu a kontrola by
     * nikdy nesepnula. Rozpuštění se proto počítá až OD DALŠÍHO DNE — jinak by se pohyb
     * z prvního dne odečetl dvakrát (jednou v zůstatku, jednou v rozpuštění).
     *
     * Tolerance {@see self::ESTIMATE_TOLERANCE} kryje haléřové rozdíly mezi odhadem
     * a skutečnou fakturou; ty se doúčtovávají do nákladu, ne že by dohad zbyl.
     *
     * @return list<array{account_code:string, opening:float, released:float, unreleased:float}>
     */
    public function unreleasedEstimates(int $supplierId, string $rangeFrom, string $rangeTo): array
    {
        $dayAfter = (new \DateTimeImmutable($rangeFrom))->modify('+1 day')->format('Y-m-d');

        $out = [];
        foreach (['388' => 'debit', '389' => 'credit'] as $code => $side) {
            $opening = $this->accountBalance($supplierId, (string) $code, $rangeFrom);
            // 389 má kreditní (záporný) zůstatek, 388 debetní — obojí na absolutní hodnotu.
            $carried = $side === 'credit' ? -$opening : $opening;
            if ($carried <= self::ESTIMATE_TOLERANCE) {
                continue; // z minulého období nic nepřešlo (nebo účet stojí na opačné straně)
            }

            $released = $this->accountMovement(
                $supplierId,
                (string) $code,
                $dayAfter,
                $rangeTo,
                $side === 'credit' ? 'debit' : 'credit',
            );
            $unreleased = round($carried - $released, 2);
            if ($unreleased <= self::ESTIMATE_TOLERANCE) {
                continue;
            }

            $out[] = [
                'account_code' => (string) $code,
                'opening'      => round($carried, 2),
                'released'     => round($released, 2),
                'unreleased'   => $unreleased,
            ];
        }

        return $out;
    }

    /** Součet pohybů účtu (dle prefixu kódu) na dané straně v období. Vždy kladný. */
    private function accountMovement(int $supplierId, string $accountCode, string $from, string $to, string $side): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(l.amount), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
               LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND l.side = ?
                AND (a.account_code LIKE CONCAT(?, '%')
                     OR COALESCE(p.account_code, a.account_code) LIKE CONCAT(?, '%'))"
        );
        $stmt->execute([$supplierId, $from, $to, $side, $accountCode, $accountCode]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Netto zůstatek účtu dle KÓDU k datu — prefix match na analytiky (vzor F2
     * netTurnoverForCodes: chytí analytiku dle prefixu kódu i dle kódu syntetiky
     * po roll-upu). Pro precheck (261, 431, 395, 041, 042) a Kč zůstatky
     * bank/pokladen ve FX kroku. Signed (MD kladně).
     */
    public function accountBalance(int $supplierId, string $accountCode, string $asOf, ?string $excludeSourceType = null, ?int $excludeSourceId = null): float
    {
        // excludeSource*: idempotence FX slotu 2 — zůstatek banky/pokladny se
        // počítá BEZ vlastního přeceňovacího zápisu, jinak by re-run kroku viděl
        // diff 0 a zákonné přecenění smazal (§24/6 ZoÚ).
        $exclude = $excludeSourceType !== null
            ? 'AND NOT (e.source_type = ? AND e.source_id = ?)'
            : '';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
               LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date <= ?
                {$exclude}
                AND (a.account_code LIKE CONCAT(?, '%')
                     OR COALESCE(p.account_code, a.account_code) LIKE CONCAT(?, '%'))"
        );
        $params = [$supplierId, $asOf];
        if ($excludeSourceType !== null) {
            $params[] = $excludeSourceType;
            $params[] = $excludeSourceId;
        }
        $params[] = $accountCode;
        $params[] = $accountCode;
        $stmt->execute($params);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Účty se zůstatkem na neobvyklé straně dle normal_side k datu (audit 2026-07,
     * D8 — inventarizační kontrola: pohledávka v kreditu, závazek v debetu apod.
     * signalizuje přeplatek/chybu). Saldní účty (normal_side IS NULL, např. 343)
     * se nekontrolují — nemají jednoznačnou „obvyklou" stranu. Vylučuje se vlastní
     * closing zápis období (idempotence, vzor bsBalances).
     *
     * @return list<array{account_id:int, account_code:string, name:string, normal_side:string, bal:float}>
     */
    public function accountsOnUnusualSide(int $supplierId, int $periodId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.id AS account_id, a.account_code, a.name, a.normal_side,
                    SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS bal
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date <= ?
                AND a.normal_side IS NOT NULL
                AND NOT (e.source_type = 'closing' AND e.period_id = ? AND e.source_id <=> ?)
              GROUP BY a.id, a.account_code, a.name, a.normal_side
             HAVING (a.normal_side = 'debit'  AND bal < -0.005)
                 OR (a.normal_side = 'credit' AND bal >  0.005)
              ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId, $asOf, $periodId, $periodId]);
        return array_map(static function (array $r): array {
            $r['account_id'] = (int) $r['account_id'];
            $r['normal_side'] = (string) $r['normal_side'];
            $r['bal'] = round((float) $r['bal'], 2);
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * K1: zůstatky ZÚČTOVACÍCH (průběžných) účtů k rozvahovému dni nad příznakem
     * chart_of_accounts.is_clearing — generická náhrada dnešních ad-hoc kontrol
     * jednotlivých kódů (261/04x/111/131/395/314/324). Roll-up analytik na syntetiku
     * (COALESCE parent). BEZ filtru na reversed_by — originál i storno se v SUM vyruší
     * (vzor accountBalance/bsBalances; `reversed_by IS NULL` by účet posunul o storno).
     * Jen nenulové (tolerance 0,5 Kč — účty jsou saldokontní/haléřové zbytky nehlásíme).
     *
     * @return list<array{account_id:int, account_code:string, name:string, bal:float}>
     */
    public function clearingAccountsWithBalance(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(p.id, a.id) AS account_id,
                    COALESCE(p.account_code, a.account_code) AS account_code,
                    COALESCE(p.name, a.name) AS name,
                    SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS bal
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
               LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date <= ?
                AND (a.is_clearing = 1 OR COALESCE(p.is_clearing, 0) = 1)
              GROUP BY COALESCE(p.id, a.id), COALESCE(p.account_code, a.account_code), COALESCE(p.name, a.name)
             HAVING ABS(bal) > 0.005
              ORDER BY account_code"
        );
        $stmt->execute([$supplierId, $asOf]);
        return array_map(self::castBalance(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * K6: řádky deníku s NEÚPLNOU cizoměnovou stopou k rozvahovému dni — XOR mezi
     * currency_code (cizí měna) a amount_foreign. FxRevaluationService potřebuje OBĚ
     * hodnoty pro nerealizované přecenění (§24/6 ZoÚ); půl-vyplněný řádek na devizovém
     * účtu (typicky 221/211/261) přecenění tiše zkreslí. Surfacing — nic nepřepisuje.
     *
     * Jen ŽIVÉ řádky (e.reversed_by IS NULL — nejde o zůstatek, ale o hledání
     * rozbitých live řádků; stornované originály nemá smysl hlásit), posted, do asOf.
     * Seskupeno per účet s počtem řádků a ukázkou entry_id (proklik do deníku).
     *
     * @return list<array{account_id:int, account_code:string, name:string, line_count:int, entry_ids:list<int>}>
     */
    public function foreignCurrencyFootprintMissing(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.id AS account_id, a.account_code, a.name,
                    COUNT(*) AS line_count,
                    SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT e.id ORDER BY e.id), ',', 20) AS entry_ids
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND e.entry_date <= ?
                AND (
                     (l.currency_code IS NOT NULL AND l.currency_code <> 'CZK'
                        AND (l.amount_foreign IS NULL OR l.amount_foreign = 0))
                  OR (l.amount_foreign IS NOT NULL AND l.amount_foreign <> 0
                        AND (l.currency_code IS NULL OR l.currency_code = 'CZK'))
                )
              GROUP BY a.id, a.account_code, a.name
              ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId, $asOf]);
        return array_map(static function (array $r): array {
            $ids = $r['entry_ids'] === null || $r['entry_ids'] === ''
                ? []
                : array_map('intval', explode(',', (string) $r['entry_ids']));
            return [
                'account_id'   => (int) $r['account_id'],
                'account_code' => (string) $r['account_code'],
                'name'         => (string) $r['name'],
                'line_count'   => (int) $r['line_count'],
                'entry_ids'    => $ids,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Cizoměnové saldokontní řádky deníku otevřených dokladů k rozvahovému dni
     * (R10a, open-item): fix kurzu dokladu z 1008 withForeign je přesný podklad
     * pro nerealizovaný rozdíl. Storno páry: originál má reversed_by IS NOT NULL
     * → vyloučen; storno samo má source_id NULL → JOIN ho nechytí.
     *
     * `doc_date` a `partner_name` přecenění nepotřebuje — nese je kvůli kontrole
     * `fx_open_items`, která z týchž řádků staví seznam do detailu. Bez nich by popup
     * ukázal jen čísla dokladů bez data a protistrany.
     *
     * @return list<array{doc_type:'invoice'|'purchase_invoice', doc_id:int, varsymbol:?string,
     *                    doc_date:?string, partner_name:?string,
     *                    account_id:int, account_code:string, currency_code:string, fx_rate:float,
     *                    amount_foreign:float, total_with_vat:float, paid_at:?string, status:string}>
     */
    public function openFxItems(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 'invoice' AS doc_type, i.id AS doc_id, i.varsymbol,
                    i.issue_date AS doc_date, cl.company_name AS partner_name,
                    l.account_id, ca.account_code, l.currency_code, l.fx_rate, l.amount_foreign,
                    i.total_with_vat, i.paid_at, i.status
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id AND e.source_type = 'invoice'
               JOIN chart_of_accounts ca ON ca.id = l.account_id
               JOIN invoices i           ON i.id = e.source_id AND i.supplier_id = l.supplier_id
               LEFT JOIN clients cl      ON cl.id = i.client_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND l.currency_code IS NOT NULL AND l.currency_code <> 'CZK'
                AND e.entry_date <= ?
                AND i.status NOT IN ('draft','cancelled')
                AND (i.paid_at IS NULL OR i.paid_at > ?)
             UNION ALL
             SELECT 'purchase_invoice' AS doc_type, pi.id AS doc_id, pi.varsymbol,
                    pi.issue_date AS doc_date, vn.company_name AS partner_name,
                    l.account_id, ca.account_code, l.currency_code, l.fx_rate, l.amount_foreign,
                    pi.total_with_vat, pi.paid_at, pi.status
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id AND e.source_type = 'purchase_invoice'
               JOIN chart_of_accounts ca ON ca.id = l.account_id
               JOIN purchase_invoices pi ON pi.id = e.source_id AND pi.supplier_id = l.supplier_id
               LEFT JOIN clients vn      ON vn.id = pi.vendor_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND l.currency_code IS NOT NULL AND l.currency_code <> 'CZK'
                AND e.entry_date <= ?
                AND pi.status NOT IN ('draft','cancelled')
                AND (pi.paid_at IS NULL OR pi.paid_at > ?)
              ORDER BY doc_type, doc_id"
        );
        $stmt->execute([$supplierId, $asOf, $asOf, $supplierId, $asOf, $asOf]);
        return array_map(static function (array $r): array {
            $r['doc_id'] = (int) $r['doc_id'];
            $r['account_id'] = (int) $r['account_id'];
            $r['fx_rate'] = (float) $r['fx_rate'];
            $r['amount_foreign'] = (float) $r['amount_foreign'];
            $r['total_with_vat'] = (float) $r['total_with_vat'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array{account_id:int,account_code:string,currency_code:string,amount:float}>
     */
    public function fxCarryingAdjustments(int $supplierId, string $asOf, int $excludeSourceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.account_id, a.account_code, l.currency_code,
                    SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS amount
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.currency_code IS NOT NULL AND l.currency_code <> 'CZK'
                AND e.source_type = 'fx_revaluation' AND e.posted_at IS NOT NULL
                AND e.entry_date <= ? AND e.source_id <> ?
              GROUP BY l.account_id, a.account_code, l.currency_code
             HAVING ABS(amount) >= 0.005"
        );
        $stmt->execute([$supplierId, $asOf, $excludeSourceId]);
        return array_map(static fn (array $row): array => [
            'account_id' => (int) $row['account_id'],
            'account_code' => (string) $row['account_code'],
            'currency_code' => (string) $row['currency_code'],
            'amount' => round((float) $row['amount'], 2),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Poměr úhrad dokladu PŘED rozvahovým dnem: Σ payment_matches.amount
     * (transakce s posted_at <= asOf) / total. Vrací SUROVÝ poměr (>= 0, bez
     * horního clampu) — hodnota > 1 signalizuje nejistotu jednotky (platba
     * v jiné měně než doklad, adversariál §8/6) a FxRevaluationService na ni
     * reaguje konzervativním fallbackem ratio=0 + warning
     * `fx_partial_payment_uncertain` (R10).
     *
     * @param 'invoice'|'purchase_invoice' $docType
     */
    public function paidRatioBefore(int $supplierId, string $docType, int $docId, string $asOf, float $total): float
    {
        if ($total <= 0.0) {
            return 0.0;
        }
        $fkColumn = $docType === 'purchase_invoice' ? 'purchase_invoice_id' : 'invoice_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(pm.amount), 0)
               FROM payment_matches pm
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id AND bt.posted_at <= ?
              WHERE pm.supplier_id = ? AND pm.{$fkColumn} = ?"
        );
        $stmt->execute([$asOf, $supplierId, $docId]);
        $paid = (float) $stmt->fetchColumn();
        return max(0.0, $paid / $total);
    }

    /**
     * Návrh devizových zůstatků pro FX krok banka/pokladna (R10b): per devizový
     * BANKOVNÍ/POKLADNÍ účet (211 valutová pokladna, 221 banka, 261 peníze na
     * cestě) zůstatek z DENÍKU k
     * rozvahovému dni $asOf — na rozdíl od starší verze (jeden účet per měnu
     * z posledního bank_statements.curr_balance) tak nabídne VŠECHNY cizoměnové
     * analytiky (i termínované vklady bez bankovního výpisu) a Kč zůstatek přesně
     * k D, ne k datu posledního výpisu.
     *
     * Agregace přes CELÝ deník (žádný filtr na reversed_by): storno nese vlastní
     * řádky se stejnou částkou na opačné straně (PostingService::reverse), takže
     * se v SUM per (účet, měna) samo vynuluje — stejný vzor jako accountBalance/
     * bsBalances/plBalances. Filtr na source_id (jak to dělá dokladové openFxItems)
     * by tu byl nesprávný: nejde o join přes konkrétní doklad, ale o čistý
     * zůstatek účtu, a storno záznam sám nese source_id NULL.
     *
     * Nabízí JEN účty 211/221 vedené ČISTĚ v jedné cizí měně — tj. kde se Kč hodnota
     * cizoměnových řádků (`czk_balance`) rovná CELÉMU Kč zůstatku účtu (HAVING
     * porovnává s accountBalance přes korelovaný poddotaz, tolerance 0,5 Kč).
     * Tím se vyloučí účty, kde by FxRevaluationService (diff = foreign × kurz −
     * accountBalance) spočetl nesmysl:
     *  - „plochý" 221 se smíchanými EUR+CZK pohyby (celkový Kč zůstatek ≫ cizoměnová část),
     *  - 261 „peníze na cestě" (cizí měna přijde a hned se převede na CZK → celkový
     *    Kč zůstatek účtu je ~0, ale cizoměnové řádky nesou nenulovou částku).
     * Takové účty (bez samostatné analytiky měny) se přeceňují ručně, ne v poloautomatu.
     *
     * @return list<array{account_code:string, currency_code:string, label:string,
     *                    foreign_balance:float, czk_balance:float}>
     */
    public function bankProposals(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, a.name AS label, l.currency_code,
                    SUM(CASE WHEN l.side = 'debit' THEN l.amount_foreign ELSE -l.amount_foreign END) AS foreign_balance,
                    SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS czk_balance
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date <= ?
                AND l.currency_code IS NOT NULL AND l.currency_code <> 'CZK'
                AND (a.account_code LIKE '211%' OR a.account_code LIKE '221%')
              GROUP BY a.account_code, a.name, l.currency_code
             HAVING (ABS(foreign_balance) >= 0.005 OR ABS(czk_balance) >= 0.005)
                AND ABS(czk_balance - (
                    SELECT COALESCE(SUM(CASE WHEN lt.side = 'debit' THEN lt.amount ELSE -lt.amount END), 0)
                      FROM journal_entry_lines lt
                      JOIN journal_entries et ON et.id = lt.entry_id
                     WHERE lt.supplier_id = ? AND lt.account_id = MAX(l.account_id)
                       AND et.posted_at IS NOT NULL AND et.entry_date <= ?)) < 0.5
              ORDER BY a.account_code, l.currency_code"
        );
        $stmt->execute([$supplierId, $asOf, $supplierId, $asOf]);
        return array_map(static function (array $r): array {
            $r['foreign_balance'] = round((float) $r['foreign_balance'], 2);
            $r['czk_balance'] = round((float) $r['czk_balance'], 2);
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── kroky průvodce (accounting_closing_steps) ─────────────────────────────

    /**
     * Kroky průvodce daného období v závazném pořadí wizardu; payload
     * dekódovaný z JSON.
     *
     * @return list<array<string,mixed>>
     */
    public function steps(int $periodId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, supplier_id, period_id, step_key, status, payload, note, done_at, done_by,
                    created_at, updated_at
               FROM accounting_closing_steps
              WHERE period_id = ?
              ORDER BY FIELD(step_key, 'precheck','depreciation','fx_revaluation','estimates',
                             'deferrals','close_books','open_next')"
        );
        $stmt->execute([$periodId]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['supplier_id'] = (int) $r['supplier_id'];
            $r['period_id'] = (int) $r['period_id'];
            $r['done_by'] = $r['done_by'] === null ? null : (int) $r['done_by'];
            $r['payload'] = $r['payload'] === null ? null : json_decode((string) $r['payload'], true);
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Založí/aktualizuje krok průvodce (INSERT ... ON DUPLICATE KEY UPDATE na
     * uq_acs_period_step). done_at/done_by se plní jen u done/skipped.
     *
     * Payload se ukládá VŽDY jako JSON objekt, nikdy jako pole. Prázdné PHP pole by
     * `json_encode` uložil jako `[]` a frontend pak na něm čte klíč (`payload.entries`)
     * — jenže `[].entries` v JavaScriptu není `undefined`, ale zděděná metoda
     * `Array.prototype.entries`, takže `?? []` nezabere a následné `.map()` shodí
     * celou stránku uzávěrky do bílé obrazovky. JSON_FORCE_OBJECT tuhle třídu chyb
     * odřízne u zdroje.
     *
     * @param 'pending'|'done'|'skipped' $status
     */
    public function upsertStep(
        int $supplierId,
        int $periodId,
        string $key,
        string $status,
        ?array $payload,
        ?string $note,
        ?int $userId,
    ): void {
        $isDone = $status === 'done' || $status === 'skipped';
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_closing_steps
                (supplier_id, period_id, step_key, status, payload, note, done_at, done_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status), payload = VALUES(payload), note = VALUES(note),
                done_at = VALUES(done_at), done_by = VALUES(done_by)'
        )->execute([
            $supplierId,
            $periodId,
            $key,
            $status,
            $payload === null ? null : json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | ($payload === [] ? JSON_FORCE_OBJECT : 0),
            ),
            $note,
            $isDone ? date('Y-m-d H:i:s') : null,
            $isDone ? $userId : null,
        ]);
    }

    /**
     * Vrátí krok do stavu pending a smaže payload (revert kroku, R12).
     */
    public function resetStep(int $supplierId, int $periodId, string $key): void
    {
        $this->db->pdo()->prepare(
            'UPDATE accounting_closing_steps
                SET status = ?, payload = NULL, note = NULL, done_at = NULL, done_by = NULL
              WHERE supplier_id = ? AND period_id = ? AND step_key = ?'
        )->execute(['pending', $supplierId, $periodId, $key]);
    }

    // ── delete závěrkových zápisů (R12) + guardy (R3) ─────────────────────────

    /**
     * HARD DELETE závěrkového zápisu (R12): SELECT zápisu + řádků (audit dump)
     * → DELETE hlavičky (řádky kaskádují přes fk_jel_entry). GUARD: jiné
     * source_type než closing/opening/fx_revaluation se NIKDY nemaže — pojistka
     * jak v PHP, tak ve WHERE. Vrací dump {entry, lines} nebo null (neexistoval).
     *
     * @return array{entry: array<string,mixed>, lines: list<array<string,mixed>>}|null
     */
    public function deleteClosingEntry(int $supplierId, string $sourceType, int $sourceId, ?int $periodId = null): ?array
    {
        // Tvrdě mazat lze jen zápisy generované uzávěrkovým průvodcem / asistenty (revert
        // kroku = čistý úklid s auditním dumpem, ne §35 storno externího dokladu):
        // closing/opening/fx_revaluation (bilanční, vč. slotů zásob způsobu B), provision
        // (OP k pohledávkám, D9), income_tax (splatná daň, D11), profit_distribution
        // (rozdělení VH, D10). Ostatní source_type (invoice/purchase/bank/cash/manual…) NIKDY.
        $allowed = ['closing', 'opening', 'fx_revaluation', 'provision', 'income_tax', 'profit_distribution', 'small_asset_accrual', 'prepaid_expense_accrual'];
        if (!in_array($sourceType, $allowed, true)) {
            return null;
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version
               FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($entry === false) {
            return null;
        }
        if ($periodId !== null && (int) $entry['period_id'] !== $periodId) {
            return null;
        }
        return $this->hardDeleteWithDump(
            $supplierId,
            $entry,
            $sourceType === 'closing' && $sourceId === (int) $entry['period_id'],
        );
    }

    /**
     * HARD DELETE převzatého otevíracího zápisu podle id — výslovná náhrada převzatých
     * počátečních stavů vypočtenými v kroku open_next. Převzatý zápis nemusí mít
     * uzávěrkový klíč (source_id bývá NULL), proto nejde přes {@see deleteClosingEntry}.
     * Guard: jen source_type 'opening' a jen v otevřeném/uzavíraném období.
     *
     * @return array{entry: array<string,mixed>, lines: list<array<string,mixed>>}|null
     */
    public function deleteOpeningEntry(int $supplierId, int $entryId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version
               FROM journal_entries
              WHERE supplier_id = ? AND id = ? AND source_type = 'opening'
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        return $entry === false ? null : $this->hardDeleteWithDump($supplierId, $entry, false);
    }

    /**
     * @param array<string,mixed> $entry
     * @return array{entry: array<string,mixed>, lines: list<array<string,mixed>>}
     */
    private function hardDeleteWithDump(int $supplierId, array $entry, bool $allowClosedPeriod): array
    {
        $pdo = $this->db->pdo();
        $status = $pdo->prepare('SELECT status FROM accounting_periods WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $status->execute([$supplierId, $entry['period_id']]);
        $periodStatus = $status->fetchColumn();
        if (!in_array($periodStatus, ['open', 'closing'], true)
            && !($allowClosedPeriod && $periodStatus === 'closed')) {
            throw new \MyInvoice\Service\Accounting\Closing\ClosingException('period_not_open', 'Zápis uzavřeného období nelze smazat.', 409);
        }
        $entryId = (int) $entry['id'];

        $linesStmt = $pdo->prepare(
            'SELECT id, entry_id, supplier_id, account_id, side, amount, currency_code, fx_rate,
                    amount_foreign, cost_center, line_no
               FROM journal_entry_lines
              WHERE entry_id = ? AND supplier_id = ?
              ORDER BY line_no ASC, id ASC'
        );
        $linesStmt->execute([$entryId, $supplierId]);
        $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

        $pdo->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?')
            ->execute([$entryId, $supplierId]);

        return ['entry' => $entry, 'lines' => $lines];
    }

    /**
     * Existují zaúčtované závěrkové zápisy období — close_books, slotované
     * zápisy kroku „Zásoby" (SKLAD §3.4), OP, splatnou daň NEBO libovolný FX
     * slot (R3 reopen / abort guard)? Stock release v N+1 (slot 7) se nekontroluje — patří
     * otevření následujícího období a maže se s revertem open_next.
     *
     * DŮLEŽITÉ: §DM / §DČR rozpouštěcí zápisy (small_asset_accrual /
     * prepaid_expense_accrual), které do N+1 zrcadlí open_next období N, mají
     * source_id ve vysokém RELEASE pásmu (≥ 2e12 / 3e12) a NESMÍ se počítat jako
     * „vlastní uzávěrka N+1" — jinak by období, které cokoli odloží do dalšího
     * roku, už nešlo znovuotevřít (revert open_next by guard vždy zablokoval).
     * Vlastní odklad období má source_id = period_id (< RELEASE_BASE). Symetricky
     * se stock/fx_reversal mirrorem výše.
     */
    public function hasClosingEntries(int $supplierId, int $periodId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT EXISTS (
                SELECT 1 FROM journal_entries
                 WHERE supplier_id = ? AND posted_at IS NOT NULL
                   AND ((source_type = 'closing' AND source_id IN (?, ?, ?, ?))
                        OR (source_type = 'fx_revaluation' AND source_id IN (?, ?, ?))
                        OR (source_type IN ('provision', 'income_tax') AND period_id = ?)
                        OR (source_type = 'small_asset_accrual' AND period_id = ? AND source_id < ?)
                        OR (source_type = 'prepaid_expense_accrual' AND period_id = ? AND source_id < ?))
             )"
        );
        $fxSlots = ClosingSourceId::allFxSlots($periodId);
        $stockSlots = ClosingSourceId::stockClosingSlots($periodId);
        $stmt->execute([
            $supplierId,
            $periodId, $stockSlots[0], $stockSlots[1], $stockSlots[2],
            $fxSlots[0], $fxSlots[1], $fxSlots[2],
            $periodId,
            $periodId, ClosingSourceId::SMALL_ASSET_RELEASE_BASE,
            $periodId, ClosingSourceId::PREPAID_EXPENSE_RELEASE_BASE,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Existuje zaúčtovaný opening zápis následujícího období pod klíčem průvodce (R3 guard)?
     *
     * Záměrně podle klíče ('opening', next_period_id): ptá se, jestli v N+1 leží zápis,
     * který na uzávěrce N stojí. `$excludeEntryIds` = převzaté zápisy, které krok
     * open_next jen převzal ({@see takenOverOpeningEntryIds}) — na uzávěrce N nestojí,
     * i když nesou stejný klíč (ruční otevírací rozvaha při zahájení účetnictví).
     *
     * @param list<int> $excludeEntryIds
     */
    public function hasOpeningEntries(int $supplierId, int $nextPeriodId, array $excludeEntryIds = []): bool
    {
        $exclude = '';
        $params = [$supplierId, $nextPeriodId];
        if ($excludeEntryIds !== []) {
            $exclude = ' AND id NOT IN (' . implode(',', array_fill(0, count($excludeEntryIds), '?')) . ')';
            array_push($params, ...array_map('intval', $excludeEntryIds));
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT EXISTS (
                SELECT 1 FROM journal_entries
                 WHERE supplier_id = ? AND source_type = 'opening' AND source_id = ?
                   AND posted_at IS NOT NULL{$exclude}
             )"
        );
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Živé otevírací zápisy období: zaúčtované, source_type 'opening', BEZ OHLEDU na
     * source_id. Převzatý zápis (převod z jiného systému) klíč nemá, ruční otevírací
     * rozvaha ho má shodný s průvodcem — kdo se ptá „má rok počáteční stavy?", musí se
     * ptát takhle, jinak zápis přehlédne a založí druhý. Stornovaný zápis a jeho storno
     * (storno nese tentýž source_type) se vzájemně ruší, proto se vynechávají oba.
     *
     * @return list<array{id:int, document_no:?string, entry_date:string, description:?string, source_id:?int}>
     */
    public function openingEntriesInPeriod(int $supplierId, int $periodId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id, e.document_no, e.entry_date, e.description, e.source_id
               FROM journal_entries e
              WHERE e.supplier_id = ? AND ' . self::LIVE_OPENING_SQL . '
              ORDER BY e.id'
        );
        $stmt->execute([$supplierId, $periodId, $supplierId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'document_no' => $r['document_no'] === null ? null : (string) $r['document_no'],
            'entry_date' => (string) $r['entry_date'],
            'description' => $r['description'] === null ? null : (string) $r['description'],
            'source_id' => $r['source_id'] === null ? null : (int) $r['source_id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Zůstatky živých otevíracích zápisů období per účet (signed netto, MD kladně) —
     * druhá strana porovnání převzatých počátečních stavů s vypočtenými.
     *
     * @return array<string,float>
     */
    public function openingBalancesInPeriod(int $supplierId, int $periodId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS bal
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts a   ON a.id = l.account_id
              WHERE e.supplier_id = ? AND " . self::LIVE_OPENING_SQL . '
              GROUP BY a.account_code'
        );
        $stmt->execute([$supplierId, $periodId, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['account_code']] = (float) $r['bal'];
        }
        return $out;
    }

    /**
     * Id otevíracích zápisů N+1, které krok open_next období N převzal (payload kroku,
     * i po revertu kroku — převzatý zápis revert nemaže a nesmí pak blokovat guardy).
     *
     * @return list<int>
     */
    public function takenOverOpeningEntryIds(int $supplierId, int $periodId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM accounting_closing_steps
              WHERE supplier_id = ? AND period_id = ? AND step_key = 'open_next'"
        );
        $stmt->execute([$supplierId, $periodId]);
        $payload = json_decode((string) ($stmt->fetchColumn() ?: ''), true);
        if (!is_array($payload) || ($payload['opening_source'] ?? null) !== 'taken_over') {
            return [];
        }
        return array_values(array_map('intval', (array) ($payload['taken_over_entry_ids'] ?? [])));
    }

    /**
     * Filtr živých otevíracích zápisů za `e.supplier_id = ? AND`, který stojí v každém
     * statementu doslova (tenant guard); parametry (supplier_id, period_id, supplier_id).
     */
    private const LIVE_OPENING_SQL = "e.period_id = ? AND e.source_type = 'opening'
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND NOT EXISTS (SELECT 1 FROM journal_entries o WHERE o.supplier_id = ? AND o.reversed_by = e.id)";

    // ── prechecky (§3.4) ──────────────────────────────────────────────────────

    /**
     * Id draft zápisů (posted_at IS NULL) s entry_date v období.
     *
     * @return list<int>
     */
    public function draftsInPeriod(int $supplierId, string $startsOn, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM journal_entries
              WHERE supplier_id = ? AND posted_at IS NULL AND entry_date BETWEEN ? AND ?
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);
        return array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Vydané faktury s účetním datem (effective_tax_date, 1009) v období bez
     * ŽIVÉHO journal zápisu — precheck warning unposted_invoices.
     *
     * Za zaúčtovaný se považuje jen AKTIVNÍ zápis (posted_at IS NOT NULL
     * AND reversed_by IS NULL). Draft (posted_at NULL) ani reverzovaný zápis
     * warning nepotlačí — doklad pak správně figuruje jako nezaúčtovaný.
     *
     * Vyloučeny typy, které nemají VLASTNÍ účetní předpis a hlásily by se
     * jako trvalé false-positives: proforma (účtuje se až vyúčtovací doklad)
     * a cancellation (interní storno, žádný samostatný zápis).
     *
     * @return list<array{id:int, varsymbol:?string}>
     */
    public function unpostedInvoices(int $supplierId, string $startsOn, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, i.issue_date AS doc_date
               FROM invoices i
              WHERE i.supplier_id = ?
                AND i.status NOT IN ('draft','cancelled')
                AND i.invoice_type NOT IN ('proforma','cancellation')
                AND i.effective_tax_date BETWEEN ? AND ?
                AND NOT EXISTS (
                    SELECT 1 FROM journal_entries e
                     WHERE e.supplier_id = i.supplier_id
                       AND e.source_type = 'invoice' AND e.source_id = i.id
                       AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                )
              ORDER BY i.id"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);
        return array_map(static fn (array $r): array => [
            'id'        => (int) $r['id'],
            'varsymbol' => $r['varsymbol'],
            // Datum jde až do detailu kontroly — bez něj má sloupec Datum prázdno.
            'doc_date'  => isset($r['doc_date']) ? (string) $r['doc_date'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Přijaté faktury s účetním datem (effective_cost_date, 1010) v období bez
     * ŽIVÉHO journal zápisu — precheck warning unposted_purchases.
     *
     * Za zaúčtovaný se považuje jen AKTIVNÍ zápis (posted_at IS NOT NULL
     * AND reversed_by IS NULL). Draft (posted_at NULL) ani reverzovaný zápis
     * warning nepotlačí — doklad pak správně figuruje jako nezaúčtovaný.
     *
     * Vyloučeny zálohové doklady (document_kind='advance'): záloha nemá vlastní
     * účetní předpis, dokud nedojde k vyúčtování — jinak trvalý false-positive.
     *
     * @return list<array{id:int, varsymbol:?string}>
     */
    public function unpostedPurchases(int $supplierId, string $startsOn, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.issue_date AS doc_date
               FROM purchase_invoices pi
              WHERE pi.supplier_id = ?
                AND pi.status NOT IN ('draft','cancelled')
                AND pi.document_kind <> 'advance'
                AND pi.effective_cost_date BETWEEN ? AND ?
                AND NOT EXISTS (
                    SELECT 1 FROM journal_entries e
                     WHERE e.supplier_id = pi.supplier_id
                       AND e.source_type = 'purchase_invoice' AND e.source_id = pi.id
                       AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                )
              ORDER BY pi.id"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);
        return array_map(static fn (array $r): array => [
            'id'        => (int) $r['id'],
            'varsymbol' => $r['varsymbol'],
            // Datum jde až do detailu kontroly — bez něj má sloupec Datum prázdno.
            'doc_date'  => isset($r['doc_date']) ? (string) $r['doc_date'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * K3: zaplacené VYDANÉ faktury, jejichž saldo na 311 v DENÍKU není vynulované
     * („doklad říká zaplaceno, ale deník o úhradě neví"). Per doklad:
     *
     *   booked  = Σ(MD − D) řádků 311* z vlastního zápisu faktury (source_type
     *             'invoice', posted, storno platné k asOf dle entry_date protizápisu),
     *   settled = Σ(D − MD) řádků 311* ze zaúčtovaných ÚHRADOVÝCH zápisů dohledatelných
     *             k dokladu: bankovní (invoice_payments.bank_transaction_id → zápis
     *             source_type='bank'; víc dokladů na jedné tx se rozpočítá poměrem
     *             ip.amount) a pokladní (cash_documents.invoice_id / invoice_payment_id
     *             → zápis source_type='cash'),
     *   saldo   = booked − settled.
     *
     * Úhrada bez vazby na deník (mark_paid / ruční platba bez dokladu) settled
     * nezvýší → doklad se objeví v nálezu. Zápočty (source_type='offset') a ruční
     * zápisy na 311 vazbu na doklad nenesou — projeví se jako otevřené saldo,
     * což je pro inventarizační kontrolu žádoucí (člověk ověří). Tolerance
     * |saldo| > 0,50 Kč (haléřová/kurzová zaokrouhlení nehlásíme).
     *
     * Placeholdery jsou v každém CTE zvlášť (žádné `params` CTE): MariaDB 11.8
     * s prepared-statement placeholdery uvnitř CTE odkazovaného přes LEFT JOIN
     * vrací tiše NULL místo joinnutých hodnot (ověřeno reprodukcí při vývoji).
     *
     * Výkon: MariaDB materializuje CTE zvlášť za KAŽDÝ odkaz (ověřeno na EXPLAIN —
     * neplatí tu „spočítá se jednou"). Proto se `bank_credit` čte jen z jediného
     * místa: mapa transakce → doklad se staví napřed v `bank_target` (dvě disjunktní
     * větve) a teprve ta se joinuje na `bank_credit`. Nový odkaz na `bank_credit`
     * nebo `booked` = další plný průchod deníkem.
     *
     * @return list<array{id:int, doc_no:string, partner_name:string, booked:float, settled:float, saldo:float}>
     */
    public function paidInvoicesOpenSaldo(int $supplierId, string $asOf): array
    {
        $sql =
            "WITH booked AS (
                SELECT e.source_id AS invoice_id,
                       SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS booked
                  FROM journal_entries e
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE e.supplier_id = ? AND e.source_type = 'invoice'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '311%' OR COALESCE(pa.account_code, '') LIKE '311%')
                 GROUP BY e.source_id
            ), bank_credit AS (
                SELECT e.source_id AS bank_transaction_id,
                       SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END) AS net_credit
                  FROM journal_entries e
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE e.supplier_id = ? AND e.source_type = 'bank'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '311%' OR COALESCE(pa.account_code, '') LIKE '311%')
                 GROUP BY e.source_id
            ), alloc AS (
                SELECT ip.bank_transaction_id, SUM(ip.amount) AS total_alloc
                  FROM invoice_payments ip
                 WHERE ip.supplier_id = ? AND ip.bank_transaction_id IS NOT NULL
                 GROUP BY ip.bank_transaction_id
            ), bank_target AS (
                -- Mapa bankovní transakce → cílový doklad a podíl úhrady (num/den).
                -- Postavená PŘED sáhnutím na `bank_credit`, aby se deník pro 311
                -- procházel jen jednou: MariaDB materializuje CTE zvlášť za KAŽDÝ
                -- odkaz, takže dřívější dvojice settled_bank + settled_bank_matched
                -- znamenala dva plné průchody týchž řádků.
                --
                -- Větev 1 — úhrada s vazbou invoice_payments; rozpad poměrem
                -- ip.amount / total_alloc, když na jedné transakci visí víc dokladů.
                -- Úhrada proformy se musí započítat FINÁLNÍ faktuře, ne proformě:
                -- proforma sama nemá předpis na 311 (nezakládá pohledávku), takže by
                -- ji `JOIN booked` zahodilo a konečná faktura by svítila jako
                -- neuhrazená, přestože je zaplacená předem.
                SELECT ip.bank_transaction_id,
                       COALESCE(ch.id, ip.invoice_id) AS invoice_id,
                       ip.amount AS num, a.total_alloc AS den
                  FROM invoice_payments ip
                  JOIN alloc a       ON a.bank_transaction_id = ip.bank_transaction_id
                  LEFT JOIN invoices pf ON pf.id = ip.invoice_id
                                       AND pf.supplier_id = ip.supplier_id
                                       AND pf.invoice_type = 'proforma'
                  LEFT JOIN invoices ch ON ch.parent_invoice_id = pf.id
                                       AND ch.supplier_id = ip.supplier_id
                                       AND ch.invoice_type <> 'proforma'
                                       AND ch.cancelled_at IS NULL
                 WHERE ip.supplier_id = ?
                UNION ALL
                -- Větev 2 — úhrady spárované jen přes bank_transactions.matched_invoice_id
                -- (bez invoice_payments vazby) — legacy import / ruční match cizoměnové
                -- platby (např. CZK faktura placená z EUR účtu). Bez tohohle by reálně
                -- vypořádaná faktura (311 v deníku nulové) svítila jako otevřená. Bereme
                -- jen banky nepokryté v `alloc`, ať se úhrada nezapočítá dvakrát — obě
                -- větve jsou tím pádem DISJUNKTNÍ a smí se sečíst jedním SUM.
                -- num/den NULL = bere se celá částka, žádný rozpad.
                SELECT bt.id, bt.matched_invoice_id, NULL, NULL
                  FROM bank_transactions bt
                 WHERE bt.matched_invoice_id IS NOT NULL
                   AND bt.id NOT IN (SELECT bank_transaction_id FROM alloc)
            ), settled_bank AS (
                -- Pořadí operací v rozpadu (net_credit * num / den) je schválně shodné
                -- s původním zápisem — DECIMAL dělení zaokrouhluje, takže přeskupení
                -- na net_credit * (num / den) by hnulo posledním desetinným místem.
                SELECT t.invoice_id,
                       SUM(CASE WHEN t.den IS NULL THEN bc.net_credit
                                ELSE bc.net_credit * t.num / NULLIF(t.den, 0) END) AS settled
                  FROM bank_target t
                  JOIN bank_credit bc ON bc.bank_transaction_id = t.bank_transaction_id
                 GROUP BY t.invoice_id
            ), settled_cash AS (
                SELECT COALESCE(cd.invoice_id, ip.invoice_id) AS invoice_id,
                       SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END) AS settled
                  FROM cash_documents cd
                  LEFT JOIN invoice_payments ip ON ip.id = cd.invoice_payment_id
                  JOIN journal_entries e ON e.supplier_id = cd.supplier_id
                   AND e.source_type = 'cash' AND e.source_id = cd.id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE cd.supplier_id = ?
                   AND (cd.invoice_id IS NOT NULL OR ip.invoice_id IS NOT NULL)
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '311%' OR COALESCE(pa.account_code, '') LIKE '311%')
                 GROUP BY COALESCE(cd.invoice_id, ip.invoice_id)
            ), settled_offset AS (
                -- Zápočet proti účtu (invoice_settlements, migrace 1126) — úhrada bez peněz,
                -- na výnosové straně zvolený účet MD / 311 D pod source_type 'settlement'.
                -- Bez něj by kontrola započtenou fakturu hlásila jako otevřené saldo v plné
                -- výši, přestože 311 je vyrovnané.
                SELECT stl.doc_id AS invoice_id,
                       SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END) AS settled
                  FROM invoice_settlements stl
                  JOIN journal_entries e ON e.supplier_id = stl.supplier_id
                   AND e.source_type = 'settlement' AND e.source_id = stl.id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE stl.supplier_id = ? AND stl.doc_type = 'invoice'
                   AND stl.status = 'confirmed'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '311%' OR COALESCE(pa.account_code, '') LIKE '311%')
                 GROUP BY stl.doc_id
            ), doc AS (
                -- Doklad + jeho peněžní vyrovnání; dobropis patří do skupiny svého RODIČE.
                -- Zdůvodnění skupiny viz {@see paidPurchasesOpenSaldo} — na výnosové straně
                -- platí zrcadlově (dobropis snižuje pohledávku na 311 i bez pohybu peněz).
                SELECT i.id,
                       CASE WHEN i.invoice_type = 'credit_note' AND i.parent_invoice_id IS NOT NULL
                            THEN i.parent_invoice_id ELSE i.id END AS group_id,
                       b.booked,
                       COALESCE(sb.settled, 0) + COALESCE(sc.settled, 0)
                         + COALESCE(so.settled, 0) AS settled
                  FROM booked b
                  JOIN invoices i ON i.id = b.invoice_id AND i.supplier_id = ?
                  LEFT JOIN settled_bank sb ON sb.invoice_id = i.id
                  LEFT JOIN settled_cash sc ON sc.invoice_id = i.id
                  LEFT JOIN settled_offset so ON so.invoice_id = i.id
            ), grp AS (
                SELECT group_id, SUM(booked) AS booked, SUM(settled) AS settled
                  FROM doc
                 GROUP BY group_id
            )
            SELECT i.id,
                   COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS doc_no,
                   i.issue_date AS doc_date,
                   cl.company_name AS partner_name,
                   g.booked,
                   g.settled,
                   g.booked - g.settled AS saldo,
                   CASE WHEN ABS(g.settled) < 0.005 THEN 'marked_paid_unposted' END AS note
              FROM grp g
              JOIN invoices i ON i.id = g.group_id AND i.supplier_id = ?
              JOIN clients cl ON cl.id = i.client_id
             WHERE i.status = 'paid'
               AND (i.paid_at IS NULL OR i.paid_at <= ?)
            -- Tolerance 1 Kč — zdůvodnění viz paidPurchasesOpenSaldo.
            HAVING ABS(saldo) > 1.0
             ORDER BY ABS(saldo) DESC, i.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $supplierId, $asOf, $asOf,          // booked
            $supplierId, $asOf, $asOf,          // bank_credit
            $supplierId,                        // alloc
            $supplierId,                        // bank_target
            $supplierId, $asOf, $asOf,          // settled_cash
            $supplierId, $asOf, $asOf,          // settled_offset
            $supplierId,                        // doc
            $supplierId, $asOf,                 // final SELECT
        ]);
        return array_map(static fn (array $r): array => self::castPaidSaldoRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * K3 zrcadlově pro PŘIJATÉ faktury na 321: booked = Σ(D − MD) z předpisu
     * (source_type 'purchase_invoice'), settled = Σ(MD − D) z úhrad — bankovní
     * přes payment_matches.purchase_invoice_id (poměr pm.amount při více
     * dokladech na jedné tx), pokladní přes cash_documents.purchase_invoice_id.
     * Detaily sémantiky viz {@see paidInvoicesOpenSaldo}.
     *
     * Saldo se počítá za **skupinu doklad + navázané dobropisy**, ne za jednotlivý doklad.
     * Opravný doklad nese na 321 opačné znaménko, takže dvojice faktura + dobropis účet
     * vynuluje, i když peníze nikdy netekly (vrácené zboží prostě sníží závazek) — a když
     * dodavatel dobropis proplatí, sedí to taky, protože do skupiny vstupují i obě peněžní
     * vyrovnání. Po dokladech to nešlo spočítat ani jedním směrem: bez dobropisů kontrola
     * hlásila obě strany dvojice v plné výši, s naivním přičtením dobropisu k rodiči zase
     * proplacený dobropis vyrobil záporné saldo. Vazbu nese `parent_purchase_invoice_id`
     * (migrace 1096); skupina se hlásí pod rodičovským dokladem.
     *
     * `note` označí doklad, který je 'paid', ale nemá ŽÁDNOU zaznamenanou úhradu (banka
     * ani pokladna) — typicky ruční „označit jako uhrazené" po zápočtu. To není totéž
     * jako „úhrada nesedí" a účetní to potřebuje v seznamu rozlišit.
     *
     * @return list<array{id:int, doc_no:string, partner_name:string, booked:float, settled:float, saldo:float, note:?string}>
     */
    public function paidPurchasesOpenSaldo(int $supplierId, string $asOf): array
    {
        $sql =
            "WITH booked AS (
                SELECT e.source_id AS purchase_invoice_id,
                       SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END) AS booked
                  FROM journal_entries e
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE e.supplier_id = ? AND e.source_type = 'purchase_invoice'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                 GROUP BY e.source_id
            ), bank_debit AS (
                SELECT e.source_id AS bank_transaction_id,
                       SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS net_debit
                  FROM journal_entries e
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE e.supplier_id = ? AND e.source_type = 'bank'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                 GROUP BY e.source_id
            ), alloc AS (
                SELECT pm.bank_transaction_id, SUM(pm.amount) AS total_alloc
                  FROM payment_matches pm
                 WHERE pm.supplier_id = ? AND pm.purchase_invoice_id IS NOT NULL
                 GROUP BY pm.bank_transaction_id
            ), settled_bank AS (
                SELECT pm.purchase_invoice_id,
                       SUM(bd.net_debit * pm.amount / NULLIF(a.total_alloc, 0)) AS settled
                  FROM payment_matches pm
                  JOIN alloc a      ON a.bank_transaction_id = pm.bank_transaction_id
                  JOIN bank_debit bd ON bd.bank_transaction_id = pm.bank_transaction_id
                 WHERE pm.supplier_id = ? AND pm.purchase_invoice_id IS NOT NULL
                 GROUP BY pm.purchase_invoice_id
            ), settled_cash AS (
                SELECT cd.purchase_invoice_id,
                       SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS settled
                  FROM cash_documents cd
                  JOIN journal_entries e ON e.supplier_id = cd.supplier_id
                   AND e.source_type = 'cash' AND e.source_id = cd.id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE cd.supplier_id = ? AND cd.purchase_invoice_id IS NOT NULL
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                 GROUP BY cd.purchase_invoice_id
            ), settled_offset AS (
                -- Zápočet proti účtu (invoice_settlements, migrace 1126): úhrada bez peněz,
                -- zápis 321 MD / zvolený účet D pod source_type 'settlement'. Třetí kanál
                -- vedle banky a pokladny — bez něj by kontrola započtenou fakturu hlásila
                -- jako otevřené saldo v plné výši, přestože 321 je vyrovnané.
                SELECT stl.doc_id AS purchase_invoice_id,
                       SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS settled
                  FROM invoice_settlements stl
                  JOIN journal_entries e ON e.supplier_id = stl.supplier_id
                   AND e.source_type = 'settlement' AND e.source_id = stl.id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE stl.supplier_id = ? AND stl.doc_type = 'purchase_invoice'
                   AND stl.status = 'confirmed'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                 GROUP BY stl.doc_id
            ), agreement_debit AS (
                -- Vzájemný zápočet FV↔PF (offset_agreements): JEDEN zápis 321 MD / 311 D
                -- za celou dohodu, ne za položku. Rozpouští se proto poměrem stejně jako
                -- bankovní pohyb rozdělený mezi víc faktur — bez toho by dohoda pokrývající
                -- dvě přijaté faktury přiznala každé z nich celý debet.
                SELECT e.source_id AS agreement_id,
                       SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS net_debit
                  FROM journal_entries e
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE e.supplier_id = ? AND e.source_type = 'offset'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                 GROUP BY e.source_id
            ), agreement_alloc AS (
                SELECT oi.agreement_id, SUM(oi.amount) AS total_alloc
                  FROM offset_agreement_items oi
                 WHERE oi.supplier_id = ? AND oi.doc_type = 'purchase_invoice'
                 GROUP BY oi.agreement_id
            ), settled_agreement AS (
                SELECT oi.doc_id AS purchase_invoice_id,
                       SUM(ad.net_debit * oi.amount / NULLIF(aa.total_alloc, 0)) AS settled
                  FROM offset_agreement_items oi
                  JOIN agreement_alloc aa ON aa.agreement_id = oi.agreement_id
                  JOIN agreement_debit ad ON ad.agreement_id = oi.agreement_id
                 WHERE oi.supplier_id = ? AND oi.doc_type = 'purchase_invoice'
                 GROUP BY oi.doc_id
            ), settled_advance AS (
                -- ZÁLOHA UHRAZENÁ PŘÍMO NA SALDOKONTNÍ ÚČET (bez 314). Zálohová faktura je
                -- samostatný doklad a výpis se páruje NA NI (variabilní symbol nese ona),
                -- kdežto na 321 visí předpis až konečné faktury. Bez tohohle kanálu se
                -- úhrada a závazek nikdy nepotkají a plně předplacená faktura se hlásí jako
                -- otevřené saldo v plné výši. Zrcadlo SaldoRepository::advanceOnAccountCte.
                --
                -- Podmínka „záloha nemá vlastní předpis na 321\" brání dvojímu započtení:
                -- když účetní zálohu na saldokonto předepsala, vyrovná si ji sama ve své
                -- vlastní skupině a konečná faktura si na ni nesmí sáhnout podruhé.
                SELECT pi.id AS purchase_invoice_id,
                       COALESCE(sb.settled, 0) + COALESCE(sc.settled, 0) AS settled
                  FROM purchase_invoices pi
                  JOIN purchase_invoices adv
                    ON adv.id = pi.advance_purchase_invoice_id AND adv.supplier_id = pi.supplier_id
                  LEFT JOIN settled_bank sb ON sb.purchase_invoice_id = adv.id
                  LEFT JOIN settled_cash sc ON sc.purchase_invoice_id = adv.id
                 WHERE pi.supplier_id = ?
                   -- Tentýž guard jako `NOT EXISTS (SELECT 1 FROM booked …)`, ale bez
                   -- odkazu na CTE: MariaDB materializuje CTE zvlášť za každý odkaz, a
                   -- `booked` je plný průchod deníkem. Korelovaně na jedno source_id to
                   -- je bodový dotaz přes idx_je_supplier_source. Podmínky musí zůstat
                   -- SHODNÉ s `booked` — jinak se guard rozejde s předpisem, na který
                   -- se ptá (GROUP BY tam vrací řádek právě tehdy, když sem něco padne).
                   AND NOT EXISTS (
                       SELECT 1
                         FROM journal_entries ea
                         JOIN journal_entry_lines la ON la.entry_id = ea.id AND la.supplier_id = ea.supplier_id
                         JOIN chart_of_accounts caa ON caa.id = la.account_id
                         LEFT JOIN chart_of_accounts paa ON paa.id = caa.parent_id
                         LEFT JOIN journal_entries reva ON reva.id = ea.reversed_by
                        WHERE ea.supplier_id = ? AND ea.source_type = 'purchase_invoice'
                          AND ea.source_id = adv.id
                          AND ea.posted_at IS NOT NULL AND ea.entry_date <= ?
                          AND (ea.reversed_by IS NULL OR reva.entry_date > ?)
                          AND (caa.account_code LIKE '321%' OR COALESCE(paa.account_code, '') LIKE '321%')
                   )
            ), doc AS (
                -- Doklad = předpis + jeho vlastní peněžní vyrovnání. Dobropis se přiřadí
                -- ke skupině svého RODIČE: opravný doklad nese na 321 opačné znaménko,
                -- takže dvojice účet vynuluje, i když peníze nikdy netekly (vrácené zboží
                -- prostě sníží závazek). Hodnotit každou stranu zvlášť znamenalo hlásit
                -- obě jako otevřené saldo v plné výši, přestože 321 je po nich nula.
                SELECT pi.id,
                       CASE WHEN pi.document_kind = 'credit_note' AND pi.parent_purchase_invoice_id IS NOT NULL
                            THEN pi.parent_purchase_invoice_id ELSE pi.id END AS group_id,
                       b.booked,
                       COALESCE(sb.settled, 0) + COALESCE(sc.settled, 0) + COALESCE(so.settled, 0)
                       + COALESCE(sg.settled, 0) + COALESCE(sa.settled, 0) AS settled
                  FROM booked b
                  JOIN purchase_invoices pi ON pi.id = b.purchase_invoice_id AND pi.supplier_id = ?
                  LEFT JOIN settled_bank sb ON sb.purchase_invoice_id = pi.id
                  LEFT JOIN settled_cash sc ON sc.purchase_invoice_id = pi.id
                  LEFT JOIN settled_offset so ON so.purchase_invoice_id = pi.id
                  LEFT JOIN settled_agreement sg ON sg.purchase_invoice_id = pi.id
                  LEFT JOIN settled_advance sa ON sa.purchase_invoice_id = pi.id
            ), grp AS (
                SELECT group_id, SUM(booked) AS booked, SUM(settled) AS settled
                  FROM doc
                 GROUP BY group_id
            )
            SELECT pi.id,
                   COALESCE(NULLIF(pi.vendor_invoice_number, ''), NULLIF(pi.varsymbol, ''), CONCAT('#', pi.id)) AS doc_no,
                   pi.issue_date AS doc_date,
                   cl.company_name AS partner_name,
                   g.booked,
                   g.settled,
                   g.booked - g.settled AS saldo,
                   -- Doklad tvrdí, že je zaplacený, ale žádná úhrada (banka ani pokladna) k němu
                   -- zaznamenaná není — typicky ruční označení po zápočtu. Jiná diagnóza než
                   -- rozdíl v částce, a účetní to v seznamu potřebuje rozlišit.
                   CASE WHEN ABS(g.settled) < 0.005 THEN 'marked_paid_unposted' END AS note
              FROM grp g
              JOIN purchase_invoices pi ON pi.id = g.group_id AND pi.supplier_id = ?
              JOIN clients cl ON cl.id = pi.vendor_id
             WHERE pi.status = 'paid'
               AND (pi.paid_at IS NULL OR pi.paid_at <= ?)
            -- Tolerance 1 Kč: haléřové rozdíly vznikají zaokrouhlením úhrady (banka pošle
            -- 1 637 Kč proti faktuře 1 637,52) a chybějící úhrada to být nemůže. Dřív 0,50 Kč
            -- propouštělo i takové řádky a účetní je musela odbavovat jednu po druhé.
            HAVING ABS(saldo) > 1.0
             ORDER BY ABS(saldo) DESC, pi.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $supplierId, $asOf, $asOf,          // booked
            $supplierId, $asOf, $asOf,          // bank_debit
            $supplierId,                        // alloc
            $supplierId,                        // settled_bank
            $supplierId, $asOf, $asOf,          // settled_cash
            $supplierId, $asOf, $asOf,          // settled_offset
            $supplierId, $asOf, $asOf,          // agreement_debit
            $supplierId,                        // agreement_alloc
            $supplierId,                        // settled_agreement
            $supplierId, $supplierId, $asOf, $asOf, // settled_advance (+ guard nad deníkem)
            $supplierId,                        // doc
            $supplierId, $asOf,                 // final SELECT
        ]);
        return array_map(static fn (array $r): array => self::castPaidSaldoRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * K3 (proformy): zálohové PROFORMY označené 'paid', ale na 324 (přijatá záloha) NENÍ
     * v deníku žádné inkaso — přijatá záloha nebyla zaúčtována. Zrcadlo
     * {@see paidInvoicesOpenSaldo} pro zálohové doklady, které nemají účetní PŘEDPIS na
     * 311 (proforma není daňový doklad, money leg jde 221/211/261 MD → 324 D — viz
     * PostingService::buildFromAdvancePayment). „324 bez inkasa" = proforma tvrdí
     * zaplaceno, ale deník o přijaté záloze neví.
     *
     * Vazba inkasa na proformu: invoice_payments.invoice_id (banka) / cash_documents.invoice_id
     * (pokladna). Konzervativně jen EXISTENČNÍ kontrola (žádný 324 kredit navázaný na
     * doklad) — bez dopočtu částky, ať nevznikají falešné poplachy z částečných záloh.
     *
     * @return list<array{id:int, doc_no:string, partner_name:string, booked:float, settled:float, saldo:float}>
     */
    /**
     * Účet, na který firma účtuje inkaso přijaté zálohy. Standardně 324, ale firma
     * si ho může přesměrovat kontací `advance.received.collection` — některé účetní
     * 324 nevedou vůbec a platbu zálohy dávají rovnou na pohledávku 311. Kontrola
     * „zaplacená proforma bez závazku ze zálohy“ pak musí hledat tenhle účet, jinak
     * by u takové firmy hlásila jako chybu úplně každou zaplacenou proformu.
     */
    private function advanceReceivedAccount(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT credit_account_code FROM posting_rules
              WHERE rule_key = 'advance.received.collection' AND is_active = 1
                AND (supplier_id = ? OR supplier_id IS NULL)
                AND credit_account_code IS NOT NULL
              ORDER BY supplier_id IS NULL, priority
              LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $code = $stmt->fetchColumn();

        return is_string($code) && $code !== '' ? $code : '324';
    }

    public function paidProformasWithoutAdvance(int $supplierId, string $asOf): array
    {
        $sql =
            "SELECT i.id,
                    COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS doc_no,
                    i.issue_date AS doc_date,
                    cl.company_name AS partner_name,
                    ROUND(i.total_with_vat, 2) AS booked,
                    0 AS settled,
                    ROUND(i.total_with_vat, 2) AS saldo
               FROM invoices i
               JOIN clients cl ON cl.id = i.client_id
              WHERE i.supplier_id = ? AND i.invoice_type = 'proforma' AND i.status = 'paid'
                AND (i.paid_at IS NULL OR i.paid_at <= ?)
                AND NOT EXISTS (
                    SELECT 1 FROM invoice_payments ip
                      JOIN journal_entries e ON e.supplier_id = i.supplier_id
                        AND e.source_type = 'bank' AND e.source_id = ip.bank_transaction_id
                        AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                      JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                      JOIN chart_of_accounts ca ON ca.id = l.account_id
                      LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                     WHERE ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id AND l.side = 'credit'
                       AND (ca.account_code LIKE ? OR COALESCE(pa.account_code, '') LIKE ?)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM cash_documents cd
                      JOIN journal_entries e ON e.supplier_id = cd.supplier_id
                        AND e.source_type = 'cash' AND e.source_id = cd.id
                        AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                      JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                      JOIN chart_of_accounts ca ON ca.id = l.account_id
                      LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                     WHERE cd.supplier_id = i.supplier_id AND cd.invoice_id = i.id AND l.side = 'credit'
                       AND (ca.account_code LIKE ? OR COALESCE(pa.account_code, '') LIKE ?)
                )
              ORDER BY i.id";
        $adv = $this->advanceReceivedAccount($supplierId) . '%';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $asOf, $asOf, $adv, $adv, $asOf, $adv, $adv]);
        return array_map(static fn (array $r): array => self::castPaidSaldoRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Zaplacená POSKYTNUTÁ záloha (`document_kind='advance'`), ke které v deníku není
     * žádná úhrada na 314 — zrcadlo {@see paidProformasWithoutAdvance} pro přijatou stranu.
     *
     * Zálohová faktura od dodavatele není daňový doklad a nemá účetní PŘEDPIS na 321;
     * do deníku vstupuje až peněžní nohou 314 MD / 221–211 D
     * (PostingService::buildFromAdvancePayment, resp. CashDocumentService). „Záloha
     * zaplacená, ale 314 prázdné" tedy znamená, že doklad tvrdí úhradu, o které deník neví —
     * chybí pohledávka za dodavatelem a uzávěrka by ten rozdíl zabetonovala.
     *
     * Vazba úhrady na zálohu: payment_matches.purchase_invoice_id (banka) /
     * cash_documents.purchase_invoice_id (pokladna). Konzervativně jen EXISTENČNÍ kontrola
     * (žádný debet navázaný na doklad), bez dopočtu částky — částečné zálohy by jinak
     * dělaly falešné poplachy.
     *
     * Uznává se debet na 314 NEBO na saldokontní 321. Účtovat zálohu rovnou na 321 je
     * legitimní varianta — na 321 pak visí předpis konečné faktury a úhrada zálohy ho
     * vyruší, takže deník o penězích ví a saldo sedí (zrcadlo
     * {@see SaldoRepository::advanceOnAccountCte}). Trvat jen na 314 znamenalo hlásit
     * takovou zálohu jako „deník o úhradě neví", přestože o ní ví — jen jinou nohou.
     *
     * @return list<array{id:int, doc_no:string, partner_name:string, booked:float, settled:float, saldo:float}>
     */
    public function paidAdvancesWithoutBookedPayment(int $supplierId, string $asOf): array
    {
        $sql =
            "SELECT p.id,
                    COALESCE(NULLIF(p.vendor_invoice_number, ''), NULLIF(p.varsymbol, ''), CONCAT('#', p.id)) AS doc_no,
                    p.issue_date AS doc_date,
                    cl.company_name AS partner_name,
                    ROUND(p.total_with_vat, 2) AS booked,
                    0 AS settled,
                    ROUND(p.total_with_vat, 2) AS saldo
               FROM purchase_invoices p
               JOIN clients cl ON cl.id = p.vendor_id
              WHERE p.supplier_id = ? AND p.document_kind = 'advance' AND p.status = 'paid'
                AND (p.paid_at IS NULL OR p.paid_at <= ?)
                AND NOT EXISTS (
                    SELECT 1 FROM payment_matches pm
                      JOIN journal_entries e ON e.supplier_id = p.supplier_id
                        AND e.source_type = 'bank' AND e.source_id = pm.bank_transaction_id
                        AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                      JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                      JOIN chart_of_accounts ca ON ca.id = l.account_id
                      LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                     WHERE pm.supplier_id = p.supplier_id AND pm.purchase_invoice_id = p.id AND l.side = 'debit'
                       AND (ca.account_code LIKE '314%' OR COALESCE(pa.account_code, '') LIKE '314%'
                            OR ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                )
                AND NOT EXISTS (
                    SELECT 1 FROM cash_documents cd
                      JOIN journal_entries e ON e.supplier_id = cd.supplier_id
                        AND e.source_type = 'cash' AND e.source_id = cd.id
                        AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                      JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                      JOIN chart_of_accounts ca ON ca.id = l.account_id
                      LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                     WHERE cd.supplier_id = p.supplier_id AND cd.purchase_invoice_id = p.id AND l.side = 'debit'
                       AND (ca.account_code LIKE '314%' OR COALESCE(pa.account_code, '') LIKE '314%'
                            OR ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                )
              ORDER BY p.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $asOf, $asOf, $asOf]);
        return array_map(static fn (array $r): array => self::castPaidSaldoRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * § 11 odst. 1 písm. b) ZoÚ — účetní doklad musí obsahovat OBSAH účetního případu.
     * Zaúčtované zápisy, které v uzavíraném období popis nemají: částky i účty sedí, ale
     * z deníku nejde poznat, čeho se případ týkal, takže auditní stopa (§ 33a) doloží
     * jen KDY a KOLIK, ne CO.
     *
     * Nové zápisy tímhle projít nemůžou — od 2359ae10 si {@see PostingService} popis
     * dopočítá ze zdrojového dokladu, když ho volající nedodá. Kontrola cílí na HISTORII
     * zaúčtovanou dřív a na zápisy vzniklé mimo aplikaci (import, přímý zásah do DB).
     * Proto se popis nevynucuje při zaúčtování — blokující kontrola by nic nového
     * nezachytila a jen by odmítala existující data.
     *
     * Nález míří na ZÁPIS (`doc_type = journal_entry`), ne na zdrojový doklad: opravuje se
     * popis zápisu v deníku, a část zápisů (uzávěrkové, kurzové, ruční) žádný doklad, na
     * který by šlo odkázat, nemá. Původ zůstává v `note`, aby bylo poznat, odkud zápis je.
     *
     * @return list<array{entry_id:int, doc_type:string, doc_id:int, doc_no:string, partner_name:string, note:string, entry_date:string, booked:float}>
     */
    public function entriesWithoutDescription(int $supplierId, string $rangeFrom, string $rangeTo): array
    {
        $sql =
            "SELECT e.id AS entry_id, e.entry_date, e.source_type, e.source_id,
                    COALESCE(NULLIF(e.document_no, ''), CONCAT('#', e.id)) AS doc_no,
                    COALESCE(ci.company_name, cv.company_name, '') AS partner_name,
                    ROUND(COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE 0 END), 0), 2) AS booked
               FROM journal_entries e
               LEFT JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               LEFT JOIN invoices i
                      ON e.source_type = 'invoice' AND i.id = e.source_id AND i.supplier_id = e.supplier_id
               LEFT JOIN clients ci ON ci.id = i.client_id
               LEFT JOIN purchase_invoices p
                      ON e.source_type = 'purchase_invoice' AND p.id = e.source_id AND p.supplier_id = e.supplier_id
               LEFT JOIN clients cv ON cv.id = p.vendor_id
              WHERE e.supplier_id = ?
                AND e.posted_at IS NOT NULL
                AND e.reversed_by IS NULL
                AND e.entry_date BETWEEN ? AND ?
                AND (e.description IS NULL OR TRIM(e.description) = '')
              GROUP BY e.id, e.entry_date, e.source_type, e.source_id, doc_no, partner_name
              ORDER BY e.entry_date, e.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $rangeFrom, $rangeTo]);

        return array_map(static fn (array $r): array => [
            'entry_id'     => (int) $r['entry_id'],
            'doc_type'     => 'journal_entry',
            'doc_id'       => (int) $r['entry_id'],
            'doc_no'       => (string) $r['doc_no'],
            'partner_name' => (string) ($r['partner_name'] ?? ''),
            'note'         => (string) $r['source_type'],
            'entry_date'   => (string) $r['entry_date'],
            'booked'       => (float) $r['booked'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Stornovaný doklad, jehož zápis je v deníku pořád AKTIVNÍ (posted, nestornovaný)
     * a spadá do uzavíraného období. Účetnictví se řídí deníkem, takže takový doklad
     * nese náklad/výnos a saldokonto, přestože evidence tvrdí, že neexistuje — knihy
     * a doklady si přímo protiřečí a uzávěrka by ten rozpor zabetonovala.
     *
     * Vzniká stornem, které neprošlo přes DocumentJournalSync::onCancel (import,
     * přímý zásah do DB, migrace dat) — aplikační cesta protizápis vždy vytvoří,
     * a u zavřeného období ho PostingService::reverse posune do otevřeného data.
     *
     * Řeší se buď vrácením dokladu ze storna (když je zápis správný), nebo stornem
     * zápisu protizápisem v otevřeném období (když je správné storno).
     *
     * Zrcadlí JournalIntegrityService::TYPE_CANCELLED_WITH_ENTRY, ale omezeně na
     * uzavírané období — noční job kontroluje celý deník bez ohledu na období.
     *
     * @return list<array{id:int, doc_no:string, partner_name:string, source_type:string, entry_id:int, booked:float}>
     */
    public function cancelledDocumentsWithActiveEntry(int $supplierId, string $rangeFrom, string $rangeTo): array
    {
        $sql =
            "SELECT d.id, d.doc_no, d.partner_name, d.source_type, e.id AS entry_id,
                    ROUND(COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE 0 END), 0), 2) AS booked
               FROM (
                   SELECT 'invoice' AS source_type, i.id, i.supplier_id,
                          COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS doc_no,
                          cl.company_name AS partner_name
                     FROM invoices i
                     LEFT JOIN clients cl ON cl.id = i.client_id
                    WHERE i.supplier_id = ? AND i.status = 'cancelled'
                   UNION ALL
                   SELECT 'purchase_invoice', p.id, p.supplier_id,
                          COALESCE(NULLIF(p.vendor_invoice_number, ''), CONCAT('#', p.id)),
                          cv.company_name
                     FROM purchase_invoices p
                     LEFT JOIN clients cv ON cv.id = p.vendor_id
                    WHERE p.supplier_id = ? AND p.status = 'cancelled'
               ) d
               JOIN journal_entries e
                 ON e.supplier_id = d.supplier_id AND e.source_type = d.source_type AND e.source_id = d.id
               LEFT JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
              WHERE e.posted_at IS NOT NULL
                AND e.reversed_by IS NULL
                AND e.entry_date BETWEEN ? AND ?
              GROUP BY d.id, d.doc_no, d.partner_name, d.source_type, e.id
              ORDER BY e.entry_date, e.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $supplierId, $rangeFrom, $rangeTo]);
        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'doc_no'       => (string) $r['doc_no'],
            'partner_name' => (string) ($r['partner_name'] ?? ''),
            'source_type'  => (string) $r['source_type'],
            'entry_id'     => (int) $r['entry_id'],
            'booked'       => (float) $r['booked'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * K3 (opačný směr): doklady se stavem 'sent' (issued, NEoznačené jako paid), na jejichž
     * saldokontním účtu (FV 311 / FP 321) přesto V DENÍKU existuje inkaso — „úhrada v deníku,
     * doklad pořád issued". Signalizuje neaktualizovaný stav dokladu (platba zaúčtovaná,
     * status nedotažen). Konzervativně EXISTENČNÍ kontrola + předpis dokladu jako `booked`
     * (potvrzuje, že doklad JE zaúčtovaný); přesná výše inkasa se tu nedopočítává.
     *
     * @return list<array{id:int, doc_no:string, partner_name:string, booked:float}>
     */
    public function settledButUnpaidInvoices(int $supplierId, string $asOf): array
    {
        $sql =
            "SELECT i.id,
                    COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS doc_no,
                    i.issue_date AS doc_date,
                    cl.company_name AS partner_name,
                    bk.booked
               FROM invoices i
               JOIN clients cl ON cl.id = i.client_id
               JOIN (
                    SELECT e.source_id AS invoice_id,
                           SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS booked
                      FROM journal_entries e
                      JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                      JOIN chart_of_accounts ca ON ca.id = l.account_id
                      LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                     WHERE e.supplier_id = ? AND e.source_type = 'invoice'
                       AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                       AND (ca.account_code LIKE '311%' OR COALESCE(pa.account_code, '') LIKE '311%')
                     GROUP BY e.source_id
               ) bk ON bk.invoice_id = i.id
              WHERE i.supplier_id = ? AND i.status = 'sent'
                AND (
                    EXISTS (
                        SELECT 1 FROM invoice_payments ip
                          JOIN journal_entries e ON e.supplier_id = i.supplier_id
                            AND e.source_type = 'bank' AND e.source_id = ip.bank_transaction_id
                            AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                          JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                          JOIN chart_of_accounts ca ON ca.id = l.account_id
                          LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                         WHERE ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id AND l.side = 'credit'
                           AND (ca.account_code LIKE '311%' OR COALESCE(pa.account_code, '') LIKE '311%')
                    )
                    OR EXISTS (
                        SELECT 1 FROM cash_documents cd
                          JOIN journal_entries e ON e.supplier_id = cd.supplier_id
                            AND e.source_type = 'cash' AND e.source_id = cd.id
                            AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                          JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                          JOIN chart_of_accounts ca ON ca.id = l.account_id
                          LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                         WHERE cd.supplier_id = i.supplier_id AND cd.invoice_id = i.id AND l.side = 'credit'
                           AND (ca.account_code LIKE '311%' OR COALESCE(pa.account_code, '') LIKE '311%')
                    )
                )
              ORDER BY i.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $asOf, $supplierId, $asOf, $asOf]);
        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'doc_no'       => (string) $r['doc_no'],
            'partner_name' => (string) $r['partner_name'],
            'booked'       => round((float) $r['booked'], 2),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * K3 (opačný směr, přijaté): purchase_invoices 'sent' s inkasem na 321 v deníku
     * (banka přes payment_matches, pokladna přes cash_documents). Viz {@see settledButUnpaidInvoices}.
     *
     * @return list<array{id:int, doc_no:string, partner_name:string, booked:float}>
     */
    public function settledButUnpaidPurchases(int $supplierId, string $asOf): array
    {
        $sql =
            "SELECT pi.id,
                    COALESCE(NULLIF(pi.vendor_invoice_number, ''), NULLIF(pi.varsymbol, ''), CONCAT('#', pi.id)) AS doc_no,
                    cl.company_name AS partner_name,
                    bk.booked
               FROM purchase_invoices pi
               JOIN clients cl ON cl.id = pi.vendor_id
               JOIN (
                    SELECT e.source_id AS purchase_invoice_id,
                           SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END) AS booked
                      FROM journal_entries e
                      JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                      JOIN chart_of_accounts ca ON ca.id = l.account_id
                      LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                     WHERE e.supplier_id = ? AND e.source_type = 'purchase_invoice'
                       AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                       AND (ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                     GROUP BY e.source_id
               ) bk ON bk.purchase_invoice_id = pi.id
              WHERE pi.supplier_id = ? AND pi.status = 'sent'
                AND (
                    EXISTS (
                        SELECT 1 FROM payment_matches pm
                          JOIN journal_entries e ON e.supplier_id = pi.supplier_id
                            AND e.source_type = 'bank' AND e.source_id = pm.bank_transaction_id
                            AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                          JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                          JOIN chart_of_accounts ca ON ca.id = l.account_id
                          LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                         WHERE pm.supplier_id = pi.supplier_id AND pm.purchase_invoice_id = pi.id AND l.side = 'debit'
                           AND (ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                    )
                    OR EXISTS (
                        SELECT 1 FROM cash_documents cd
                          JOIN journal_entries e ON e.supplier_id = cd.supplier_id
                            AND e.source_type = 'cash' AND e.source_id = cd.id
                            AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                          JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                          JOIN chart_of_accounts ca ON ca.id = l.account_id
                          LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                         WHERE cd.supplier_id = pi.supplier_id AND cd.purchase_invoice_id = pi.id AND l.side = 'debit'
                           AND (ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                    )
                )
              ORDER BY pi.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $asOf, $supplierId, $asOf, $asOf]);
        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'doc_no'       => (string) $r['doc_no'],
            'partner_name' => (string) $r['partner_name'],
            'booked'       => round((float) $r['booked'], 2),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Realizovaný kurzový rozdíl NEZAÚČTOVANÝ na 563/663 (audit 2026-07 — VF 2405007).
     * Cizoměnové PLNĚ zaplacené doklady (FV 311 / FP 321), jejichž úhrada vypořádala
     * pohledávku/závazek JINÝM kurzem než doklad, takže na saldokontním účtu zůstal
     * reziduální rozdíl, který NEBYL přeúčtován na 563 (kurzová ztráta) / 663 (kurzový
     * zisk). Reziduum = booked (311/321 z předpisu) − settled (311/321 z úhrad) shodně
     * s {@see paidInvoicesOpenSaldo} — ale JEN pro řádky v cizí měně (currency_code <>
     * 'CZK').
     *
     * Odlišení od NEREALIZOVANÝCH rozdílů k rozvahovému dni ({@see openFxItems} / krok
     * fx_revaluation): sem patří jen doklady se statusem 'paid' k asOf — realizovaný,
     * uzavřený případ, ne otevřená položka.
     *
     * Ne-dvojité hlášení (guard): doklad, jehož úhradový (bankovní/pokladní) zápis už
     * NESE řádek 563/663 „pro tuto úhradu" (auto-B6 v {@see BankPostingService} nebo
     * ruční korekce v úhradovém dokladu), se vynechá — kurzový výsledek je zaúčtovaný.
     * Ruční korekce SAMOSTATNÝM zápisem (manual 311/663) se k dokladu přes deník
     * nedohledá (řádky deníku nemají partnera) → takový doklad tu zůstane, stejně jako
     * u {@see paidInvoicesOpenSaldo}; to je záměrně konzervativní (účetní ověří).
     *
     * Tolerance |residual| > 0,50 Kč. Placeholdery po CTE zvlášť (viz poznámka u
     * {@see paidInvoicesOpenSaldo}).
     *
     * @return list<array{id:int, doc_type:'invoice'|'purchase_invoice', doc_no:string,
     *                    partner:string, residual:float, currency:string}>
     */
    public function realizedFxUnbooked(int $supplierId, string $asOf): array
    {
        $rows = array_merge(
            $this->realizedFxIssuedUnbooked($supplierId, $asOf),
            $this->realizedFxReceivedUnbooked($supplierId, $asOf),
        );
        usort($rows, static fn (array $a, array $b): int => abs($b['residual']) <=> abs($a['residual']) ?: $a['id'] <=> $b['id']);
        return $rows;
    }

    /**
     * FV na 311 — realizovaný kurzový rozdíl nezaúčtovaný. Struktura shodná s
     * {@see paidInvoicesOpenSaldo}, navíc cizoměnový filtr + guard 563/663.
     *
     * @return list<array{id:int, doc_type:'invoice', doc_no:string, partner:string, residual:float, currency:string}>
     */
    private function realizedFxIssuedUnbooked(int $supplierId, string $asOf): array
    {
        $sql =
            "WITH booked AS (
                SELECT e.source_id AS invoice_id,
                       SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS booked,
                       MAX(l.currency_code) AS currency
                  FROM journal_entries e
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE e.supplier_id = ? AND e.source_type = 'invoice'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '311%' OR COALESCE(pa.account_code, '') LIKE '311%')
                   AND l.currency_code IS NOT NULL AND l.currency_code <> 'CZK'
                 GROUP BY e.source_id
            ), bank_credit AS (
                SELECT e.source_id AS bank_transaction_id,
                       SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END) AS net_credit
                  FROM journal_entries e
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE e.supplier_id = ? AND e.source_type = 'bank'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '311%' OR COALESCE(pa.account_code, '') LIKE '311%')
                 GROUP BY e.source_id
            ), alloc AS (
                SELECT ip.bank_transaction_id, SUM(ip.amount) AS total_alloc
                  FROM invoice_payments ip
                 WHERE ip.supplier_id = ? AND ip.bank_transaction_id IS NOT NULL
                 GROUP BY ip.bank_transaction_id
            ), settled_bank AS (
                SELECT ip.invoice_id,
                       SUM(bc.net_credit * ip.amount / NULLIF(a.total_alloc, 0)) AS settled
                  FROM invoice_payments ip
                  JOIN alloc a       ON a.bank_transaction_id = ip.bank_transaction_id
                  JOIN bank_credit bc ON bc.bank_transaction_id = ip.bank_transaction_id
                 WHERE ip.supplier_id = ?
                 GROUP BY ip.invoice_id
            ), settled_cash AS (
                SELECT COALESCE(cd.invoice_id, ip.invoice_id) AS invoice_id,
                       SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END) AS settled
                  FROM cash_documents cd
                  LEFT JOIN invoice_payments ip ON ip.id = cd.invoice_payment_id
                  JOIN journal_entries e ON e.supplier_id = cd.supplier_id
                   AND e.source_type = 'cash' AND e.source_id = cd.id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE cd.supplier_id = ?
                   AND (cd.invoice_id IS NOT NULL OR ip.invoice_id IS NOT NULL)
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '311%' OR COALESCE(pa.account_code, '') LIKE '311%')
                 GROUP BY COALESCE(cd.invoice_id, ip.invoice_id)
            ), fx_booked AS (
                SELECT ip.invoice_id
                  FROM invoice_payments ip
                  JOIN journal_entries e ON e.supplier_id = ?
                   AND e.source_type = 'bank' AND e.source_id = ip.bank_transaction_id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                 WHERE ip.supplier_id = ? AND ip.bank_transaction_id IS NOT NULL
                   AND (ca.account_code LIKE '563%' OR ca.account_code LIKE '663%'
                        OR COALESCE(pa.account_code, '') LIKE '563%' OR COALESCE(pa.account_code, '') LIKE '663%')
                 UNION
                SELECT COALESCE(cd.invoice_id, ip.invoice_id) AS invoice_id
                  FROM cash_documents cd
                  LEFT JOIN invoice_payments ip ON ip.id = cd.invoice_payment_id
                  JOIN journal_entries e ON e.supplier_id = cd.supplier_id
                   AND e.source_type = 'cash' AND e.source_id = cd.id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                 WHERE cd.supplier_id = ? AND (cd.invoice_id IS NOT NULL OR ip.invoice_id IS NOT NULL)
                   AND (ca.account_code LIKE '563%' OR ca.account_code LIKE '663%'
                        OR COALESCE(pa.account_code, '') LIKE '563%' OR COALESCE(pa.account_code, '') LIKE '663%')
            )
            SELECT i.id,
                   COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS doc_no,
                   i.issue_date AS doc_date,
                   cl.company_name AS partner,
                   b.currency,
                   b.booked - (COALESCE(sb.settled, 0) + COALESCE(sc.settled, 0)) AS residual
              FROM booked b
              JOIN invoices i ON i.id = b.invoice_id AND i.supplier_id = ?
              JOIN clients cl ON cl.id = i.client_id
              LEFT JOIN settled_bank sb ON sb.invoice_id = i.id
              LEFT JOIN settled_cash sc ON sc.invoice_id = i.id
              LEFT JOIN fx_booked fb ON fb.invoice_id = i.id
             WHERE i.status = 'paid'
               AND (i.paid_at IS NULL OR i.paid_at <= ?)
               AND fb.invoice_id IS NULL
               -- Zbytek na saldokontu je kurzovým rozdílem JEN tehdy, když úhrada
               -- vůbec zaúčtovaná je. Bez téhle podmínky se sem dostal doklad
               -- s NEZAÚČTOVANOU platbou, u kterého zbytek == celá faktura:
               -- doklad hlásil kurzový rozdíl ve výši celé
               -- své hodnoty. Ten případ hlásí kontrola otevřeného salda, a to
               -- správně; tady by svedl k zaúčtování celé faktury do 563/663.
               AND (COALESCE(sb.settled, 0) + COALESCE(sc.settled, 0)) <> 0
            HAVING ABS(residual) > 0.5
             ORDER BY ABS(residual) DESC, i.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $supplierId, $asOf, $asOf,          // booked
            $supplierId, $asOf, $asOf,          // bank_credit
            $supplierId,                        // alloc
            $supplierId,                        // settled_bank
            $supplierId, $asOf, $asOf,          // settled_cash
            $supplierId, $supplierId,           // fx_booked bank
            $supplierId,                        // fx_booked cash
            $supplierId, $asOf,                 // final SELECT
        ]);
        return array_map(static fn (array $r): array => self::castFxRow($r, 'invoice'), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * FP na 321 — zrcadlově k {@see realizedFxIssuedUnbooked} (payment_matches, 321,
     * vendor). Struktura shodná s {@see paidPurchasesOpenSaldo} + cizoměnový filtr a guard.
     *
     * @return list<array{id:int, doc_type:'purchase_invoice', doc_no:string, partner:string, residual:float, currency:string}>
     */
    private function realizedFxReceivedUnbooked(int $supplierId, string $asOf): array
    {
        $sql =
            "WITH booked AS (
                SELECT e.source_id AS purchase_invoice_id,
                       SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END) AS booked,
                       MAX(l.currency_code) AS currency
                  FROM journal_entries e
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE e.supplier_id = ? AND e.source_type = 'purchase_invoice'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                   AND l.currency_code IS NOT NULL AND l.currency_code <> 'CZK'
                 GROUP BY e.source_id
            ), bank_debit AS (
                SELECT e.source_id AS bank_transaction_id,
                       SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS net_debit
                  FROM journal_entries e
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE e.supplier_id = ? AND e.source_type = 'bank'
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                 GROUP BY e.source_id
            ), alloc AS (
                SELECT pm.bank_transaction_id, SUM(pm.amount) AS total_alloc
                  FROM payment_matches pm
                 WHERE pm.supplier_id = ? AND pm.purchase_invoice_id IS NOT NULL
                 GROUP BY pm.bank_transaction_id
            ), settled_bank AS (
                SELECT pm.purchase_invoice_id,
                       SUM(bd.net_debit * pm.amount / NULLIF(a.total_alloc, 0)) AS settled
                  FROM payment_matches pm
                  JOIN alloc a      ON a.bank_transaction_id = pm.bank_transaction_id
                  JOIN bank_debit bd ON bd.bank_transaction_id = pm.bank_transaction_id
                 WHERE pm.supplier_id = ? AND pm.purchase_invoice_id IS NOT NULL
                 GROUP BY pm.purchase_invoice_id
            ), settled_cash AS (
                SELECT cd.purchase_invoice_id,
                       SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS settled
                  FROM cash_documents cd
                  JOIN journal_entries e ON e.supplier_id = cd.supplier_id
                   AND e.source_type = 'cash' AND e.source_id = cd.id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE cd.supplier_id = ? AND cd.purchase_invoice_id IS NOT NULL
                   AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                   AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                   AND (ca.account_code LIKE '321%' OR COALESCE(pa.account_code, '') LIKE '321%')
                 GROUP BY cd.purchase_invoice_id
            ), fx_booked AS (
                SELECT pm.purchase_invoice_id
                  FROM payment_matches pm
                  JOIN journal_entries e ON e.supplier_id = ?
                   AND e.source_type = 'bank' AND e.source_id = pm.bank_transaction_id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                 WHERE pm.supplier_id = ? AND pm.purchase_invoice_id IS NOT NULL
                   AND (ca.account_code LIKE '563%' OR ca.account_code LIKE '663%'
                        OR COALESCE(pa.account_code, '') LIKE '563%' OR COALESCE(pa.account_code, '') LIKE '663%')
                 UNION
                SELECT cd.purchase_invoice_id
                  FROM cash_documents cd
                  JOIN journal_entries e ON e.supplier_id = cd.supplier_id
                   AND e.source_type = 'cash' AND e.source_id = cd.id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                 WHERE cd.supplier_id = ? AND cd.purchase_invoice_id IS NOT NULL
                   AND (ca.account_code LIKE '563%' OR ca.account_code LIKE '663%'
                        OR COALESCE(pa.account_code, '') LIKE '563%' OR COALESCE(pa.account_code, '') LIKE '663%')
            )
            SELECT pi.id,
                   COALESCE(NULLIF(pi.vendor_invoice_number, ''), NULLIF(pi.varsymbol, ''), CONCAT('#', pi.id)) AS doc_no,
                   pi.issue_date AS doc_date,
                   cl.company_name AS partner,
                   b.currency,
                   b.booked - (COALESCE(sb.settled, 0) + COALESCE(sc.settled, 0)) AS residual
              FROM booked b
              JOIN purchase_invoices pi ON pi.id = b.purchase_invoice_id AND pi.supplier_id = ?
              JOIN clients cl ON cl.id = pi.vendor_id
              LEFT JOIN settled_bank sb ON sb.purchase_invoice_id = pi.id
              LEFT JOIN settled_cash sc ON sc.purchase_invoice_id = pi.id
              LEFT JOIN fx_booked fb ON fb.purchase_invoice_id = pi.id
             WHERE pi.status = 'paid'
               AND (pi.paid_at IS NULL OR pi.paid_at <= ?)
               AND fb.purchase_invoice_id IS NULL
               -- Zbytek na saldokontu je kurzovým rozdílem JEN tehdy, když úhrada
               -- vůbec zaúčtovaná je. Bez téhle podmínky se sem dostal doklad
               -- s NEZAÚČTOVANOU platbou, u kterého zbytek == celá faktura:
               -- doklad hlásil kurzový rozdíl ve výši celé
               -- své hodnoty. Ten případ hlásí kontrola otevřeného salda, a to
               -- správně; tady by svedl k zaúčtování celé faktury do 563/663.
               AND (COALESCE(sb.settled, 0) + COALESCE(sc.settled, 0)) <> 0
            HAVING ABS(residual) > 0.5
             ORDER BY ABS(residual) DESC, pi.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $supplierId, $asOf, $asOf,          // booked
            $supplierId, $asOf, $asOf,          // bank_debit
            $supplierId,                        // alloc
            $supplierId,                        // settled_bank
            $supplierId, $asOf, $asOf,          // settled_cash
            $supplierId, $supplierId,           // fx_booked bank
            $supplierId,                        // fx_booked cash
            $supplierId, $asOf,                 // final SELECT
        ]);
        return array_map(static fn (array $r): array => self::castFxRow($r, 'purchase_invoice'), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string,mixed> $r
     * @param 'invoice'|'purchase_invoice' $docType
     * @return array{id:int, doc_type:'invoice'|'purchase_invoice', doc_no:string, partner:string, residual:float, currency:string}
     */
    private static function castFxRow(array $r, string $docType): array
    {
        return [
            'id'       => (int) $r['id'],
            'doc_type' => $docType,
            'doc_no'   => (string) $r['doc_no'],
            'doc_date' => isset($r['doc_date']) ? (string) $r['doc_date'] : null,
            'partner'  => (string) $r['partner'],
            'residual' => round((float) $r['residual'], 2),
            // Zbytek na saldokontu je v KORUNÁCH — je to rozdíl dvou korunových
            // přepočtů. Vydávat ho v měně dokladu znamenalo psát u částky 4 365,00
            // jednotku EUR, přestože jde o koruny.
            'currency' => 'CZK',
            'doc_currency' => (string) $r['currency'],
        ];
    }

    /**
     * @param array<string,mixed> $r
     * @return array{id:int, doc_no:string, partner_name:string, booked:float, settled:float, saldo:float}
     */
    private static function castPaidSaldoRow(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'doc_no'       => (string) $r['doc_no'],
            // Bez data nejde nález zařadit v čase a účetní musí každý řádek dohledávat
            // ručně — sloupec Datum v detailu kontroly zůstával prázdný.
            'doc_date'     => isset($r['doc_date']) ? (string) $r['doc_date'] : null,
            'partner_name' => (string) $r['partner_name'],
            'booked'       => round((float) $r['booked'], 2),
            'settled'      => round((float) $r['settled'], 2),
            'saldo'        => round((float) $r['saldo'], 2),
            // Kód nálezu, ne hotová věta: klient ho překládá (`checks.issue.*`), stejně
            // jako u kontroly spárovaných plateb. Diagnóza „doklad označen jako uhrazený,
            // ale žádná úhrada k němu není" se liší od „úhrada je, jen nesedí částka".
            'issues'       => isset($r['note']) && $r['note'] !== null ? [(string) $r['note']] : null,
        ];
    }

    /**
     * @param array<string,mixed> $r
     * @return array{account_id:int, account_code:string, name:string, bal:float}
     */
    private static function castBalance(array $r): array
    {
        return [
            'account_id'   => (int) $r['account_id'],
            'account_code' => (string) $r['account_code'],
            'name'         => (string) $r['name'],
            'bal'          => round((float) $r['bal'], 2),
        ];
    }
}
