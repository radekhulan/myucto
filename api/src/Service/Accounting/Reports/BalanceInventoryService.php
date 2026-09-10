<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\LedgerReportRepository;
use PDO;

/**
 * Inventarizace rozvahových účtů k rozvahovému dni (§29–30 ZoÚ) — REAL_data_followup_UX.md T2.
 *
 * Na rozdíl od obratové předvahy (TrialBalanceService) bere jen ÚČTY TŘÍD 0–4
 * (account_type asset/liability/equity) — výsledkové účty (5xx/6xx) nejsou předmětem
 * inventarizace majetku a závazků. Vždy k celému období (starts_on → ends_on), protože
 * inventarizace je vázaná na rozvahový den = konec účetního období, ne na libovolný
 * dílčí rozsah jako F2 sestavy.
 *
 * V1 = generovaný soupis účetních zůstatků (KZ MD/D) + `documentation_hint` (typový návrh
 * způsobu doložení dle prefixu účtu) + prázdné sloupce „skutečný stav" a „rozdíl" pro ruční
 * doplnění na vytištěné/exportované sestavě. Žádná perzistence inventurních hodnot —
 * to je follow-up (viz REAL_data_followup_UX.md T2).
 */
final class BalanceInventoryService
{
    public function __construct(
        private readonly Connection $db,
        private readonly LedgerReportRepository $ledger,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $from = (string) $period['starts_on'];
        $to = (string) $period['ends_on'];

        // excludeClosing=true: inventarizace k rozvahovému dni bere zůstatky PŘED uzavřením
        // knih (UZ zápisy převádějící rozvahové účty na 702 by je jinak vynulovaly).
        $raw = $this->ledger->trialBalanceRows($supplierId, $from, $to, $from, false, [], true);

        $rows = [];
        $totals = ['ks_md' => 0, 'ks_d' => 0];
        foreach ($raw as $r) {
            $type = (string) $r['account_type'];
            if (!in_array($type, ['asset', 'liability', 'equity'], true)) {
                continue;
            }
            $psMd = self::cents($r['ps_md']);
            $psD  = self::cents($r['ps_d']);
            $tMd  = self::cents($r['to_md']);
            $tD   = self::cents($r['to_d']);
            $delta = $psMd - $psD + $tMd - $tD;
            $ksMd = $delta > 0 ? $delta : 0;
            $ksD  = $delta > 0 ? 0 : -$delta;

            $rows[] = [
                'account_id'         => (int) $r['id'],
                'account_code'       => (string) $r['account_code'],
                'name'               => (string) $r['name'],
                'account_type'       => $type,
                'normal_side'        => $r['normal_side'] !== null ? (string) $r['normal_side'] : null,
                'ks_md'              => $ksMd / 100,
                'ks_d'               => $ksD / 100,
                'documentation_hint' => $this->documentationHint((string) $r['account_code']),
            ];
            $totals['ks_md'] += $ksMd;
            $totals['ks_d']  += $ksD;
        }

        return [
            'period'      => [
                'id'          => (int) $period['id'],
                'fiscal_year' => (int) $period['fiscal_year'],
                'starts_on'   => $from,
                'ends_on'     => $to,
            ],
            'as_of'       => $to,
            'entity'      => $this->entity($supplierId),
            'draft_count' => $this->ledger->draftCount($supplierId, $from, $to),
            'rows'        => $rows,
            'count'       => count($rows),
            'totals'      => array_map(static fn (int $c): float => $c / 100, $totals),
        ];
    }

    /**
     * EP-6: {@see build} + slití uloženého skutečného stavu (counted), rozdílu a stavu
     * vyřešení per účet + hlavička inventarizace (odpovědná osoba, datum, protokol, stav
     * dokončení). `book_balance` je znaménkový účetní zůstatek (ks_md − ks_d) = báze,
     * proti které se počítá inventurní rozdíl.
     *
     * @return array<string,mixed>
     */
    public function buildWithSaved(int $supplierId, int $periodId): array
    {
        $report = $this->build($supplierId, $periodId);
        $period = $this->periods->findById($supplierId, $periodId);
        // Uzavřené/schválené období: uzavření knih už rozvahové zůstatky ověřilo
        // (invariant 702 = 0 v closeBooks). Roky uzavřené ještě před EP-6 nemají
        // uloženou inventuru — bez back-fillu by pak inventarizace hlásila každý
        // účet jako „nevyřešený" a v read-only období by to nešlo opravit. Proto
        // u uzavřeného období, kde účetní nic neuložila, odvodíme skutečný stav
        // z účetního (counted = book, resolved). Otevřené období se nebackfilluje.
        $periodClosed = $period !== null && AccountingPeriodStatus::isClosed((string) $period['status']);

        $header = $this->loadHeader($supplierId, $periodId);
        $saved = $this->loadItems($supplierId, $periodId);

        $rows = [];
        $unresolved = 0;
        $backFilledAny = false;
        foreach ($report['rows'] as $row) {
            $accountId = (int) $row['account_id'];
            $book = round((float) $row['ks_md'] - (float) $row['ks_d'], 2);
            $s = $saved[$accountId] ?? null;
            $counted = $s !== null && $s['counted_balance'] !== null ? round((float) $s['counted_balance'], 2) : null;
            $resolution = $s !== null ? (string) $s['resolution'] : 'open';
            $backFilled = false;
            if ($periodClosed && $counted === null && $resolution !== 'resolved') {
                $counted = $book;
                $resolution = 'resolved';
                $backFilled = true;
                $backFilledAny = true;
            }
            $difference = $counted !== null ? round($counted - $book, 2) : null;
            $resolved = self::itemResolved($book, $counted, $resolution);
            if (!$resolved) {
                $unresolved++;
            }
            $rows[] = $row + [
                'book_balance'    => $book,
                'counted_balance' => $counted,
                'difference'      => $difference,
                'resolution'      => $resolution,
                'item_note'       => $s !== null ? ($s['note'] !== null ? (string) $s['note'] : null) : null,
                'resolved'        => $resolved,
                'back_filled'     => $backFilled,
            ];
        }

        $report['rows'] = $rows;
        // Bez uložené hlavičky u uzavřeného období prezentujeme inventuru jako
        // dokončenou (back-fill), aby stránka nehlásila uzavřený rok jako rozpracovaný.
        $report['inventory'] = $header ?? [
            'status'             => $periodClosed ? 'completed' : 'in_progress',
            'responsible_person' => null,
            'inventory_date'     => null,
            'protocol_ref'       => null,
            'note'               => null,
            'item_count'         => $periodClosed ? count($rows) : 0,
            'unresolved_count'   => 0,
            'completed_at'       => null,
        ];
        $report['inventory']['unresolved_count'] = $unresolved;
        $report['inventory']['completed'] = ((string) ($report['inventory']['status'] ?? '') === 'completed')
            || ($periodClosed && $unresolved === 0);
        $report['inventory']['can_close'] = $report['inventory']['completed'] && $unresolved === 0;
        $report['inventory']['back_filled'] = $backFilledAny;

        return $report;
    }

    /**
     * EP-6: uloží inventarizaci (hlavičku + per účet skutečný stav / stav vyřešení).
     * Skutečný stav se ukládá jen pro účty vrácené {@see build} (rozvahové účty tříd
     * 0–4); `book_balance` se dopočítá znovu ze živého deníku (zdroj pravdy), rozdíl a
     * počty se přepočítají serverově. Volá se z {@see \MyInvoice\Service\Accounting\Closing\ClosingService::saveInventory}
     * uvnitř otevřené transakce (lock/CAS/audit), proto zde žádná vlastní transakce.
     *
     * @param array{responsible_person?:?string, inventory_date?:?string, protocol_ref?:?string, note?:?string, complete?:bool} $header
     * @param array<int, array{counted_balance?:float|int|string|null, resolution?:string, note?:?string}> $itemsByAccount
     * @return array{status:string, unresolved_count:int, item_count:int, completed:bool, ok:bool}
     */
    public function saveInventory(int $supplierId, int $periodId, array $header, array $itemsByAccount, ?int $userId): array
    {
        $report = $this->build($supplierId, $periodId);
        $pdo = $this->db->pdo();

        // Hlavička — upsert (uq supplier_id, period_id).
        $stmt = $pdo->prepare('SELECT id FROM accounting_balance_inventory WHERE supplier_id = ? AND period_id = ?');
        $stmt->execute([$supplierId, $periodId]);
        $inventoryId = $stmt->fetchColumn();

        $responsible = self::nullTrim($header['responsible_person'] ?? null);
        $inventoryDate = self::nullDate($header['inventory_date'] ?? null);
        $protocolRef = self::nullTrim($header['protocol_ref'] ?? null);
        $note = self::nullTrim($header['note'] ?? null, 1000);

        if ($inventoryId === false) {
            $ins = $pdo->prepare(
                'INSERT INTO accounting_balance_inventory
                    (supplier_id, period_id, responsible_person, inventory_date, protocol_ref, note, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$supplierId, $periodId, $responsible, $inventoryDate, $protocolRef, $note, $userId]);
            $inventoryId = (int) $pdo->lastInsertId();
        } else {
            $inventoryId = (int) $inventoryId;
            $upd = $pdo->prepare(
                'UPDATE accounting_balance_inventory
                    SET responsible_person = ?, inventory_date = ?, protocol_ref = ?, note = ?, updated_by = ?
                  WHERE id = ? AND supplier_id = ?'
            );
            $upd->execute([$responsible, $inventoryDate, $protocolRef, $note, $userId, $inventoryId, $supplierId]);
        }

        // Per účet — přepiš položky z aktuálních rozvahových účtů + poslaného skutečného stavu.
        $pdo->prepare('DELETE FROM accounting_balance_inventory_items WHERE inventory_id = ? AND supplier_id = ?')
            ->execute([$inventoryId, $supplierId]);

        $insItem = $pdo->prepare(
            'INSERT INTO accounting_balance_inventory_items
                (supplier_id, inventory_id, account_id, account_code, book_balance, counted_balance, difference, resolution, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $itemCount = 0;
        $unresolved = 0;
        foreach ($report['rows'] as $row) {
            $accountId = (int) $row['account_id'];
            $book = round((float) $row['ks_md'] - (float) $row['ks_d'], 2);
            $in = $itemsByAccount[$accountId] ?? [];
            $countedRaw = $in['counted_balance'] ?? null;
            $counted = ($countedRaw === null || $countedRaw === '') ? null : round((float) $countedRaw, 2);
            $difference = $counted !== null ? round($counted - $book, 2) : 0.0;
            $resolution = (($in['resolution'] ?? '') === 'resolved') ? 'resolved' : 'open';
            $itemNote = self::nullTrim($in['note'] ?? null);

            $insItem->execute([
                $supplierId, $inventoryId, $accountId, (string) $row['account_code'],
                $book, $counted, $difference, $resolution, $itemNote,
            ]);
            $itemCount++;
            if (!self::itemResolved($book, $counted, $resolution)) {
                $unresolved++;
            }
        }

        // Dokončení je povolené jen bez nevyřešených rozdílů.
        $complete = ((bool) ($header['complete'] ?? false)) && $unresolved === 0;
        $status = $complete ? 'completed' : 'in_progress';
        $pdo->prepare(
            'UPDATE accounting_balance_inventory
                SET status = ?, item_count = ?, unresolved_count = ?,
                    completed_at = ' . ($complete ? 'COALESCE(completed_at, NOW())' : 'NULL') . '
              WHERE id = ? AND supplier_id = ?'
        )->execute([$status, $itemCount, $unresolved, $inventoryId, $supplierId]);

        return [
            'status'           => $status,
            'unresolved_count' => $unresolved,
            'item_count'       => $itemCount,
            'completed'        => $complete,
            'ok'               => $complete && $unresolved === 0,
        ];
    }

    /**
     * EP-6: stav inventarizace pro gating uzavření knih. `ok` = inventarizace je
     * `completed` a nemá žádný nevyřešený rozdíl (blokuje/odblokuje close_books).
     * Nevyřešené rozdíly se počítají proti AKTUÁLNÍM rozvahovým účtům, ať se stav
     * inventarizace zneplatní, pokud se po uložení účetnictví ještě změní.
     *
     * @param list<array<string,mixed>>|null $mergedRows výstup {@see buildWithSaved} (kvůli reuse); null = dopočítat
     * @return array{exists:bool, completed:bool, unresolved_count:int, ok:bool}
     */
    public function inventoryStatus(int $supplierId, int $periodId, ?array $mergedRows = null): array
    {
        $header = $this->loadHeader($supplierId, $periodId);
        if ($header === null) {
            return ['exists' => false, 'completed' => false, 'unresolved_count' => 0, 'ok' => false];
        }

        if ($mergedRows === null) {
            $report = $this->build($supplierId, $periodId);
            $saved = $this->loadItems($supplierId, $periodId);
            $mergedRows = [];
            foreach ($report['rows'] as $row) {
                $accountId = (int) $row['account_id'];
                $book = round((float) $row['ks_md'] - (float) $row['ks_d'], 2);
                $s = $saved[$accountId] ?? null;
                $counted = $s !== null && $s['counted_balance'] !== null ? round((float) $s['counted_balance'], 2) : null;
                $resolution = $s !== null ? (string) $s['resolution'] : 'open';
                $mergedRows[] = ['book_balance' => $book, 'counted_balance' => $counted, 'resolution' => $resolution];
            }
        }

        $unresolved = 0;
        foreach ($mergedRows as $r) {
            $book = round((float) ($r['book_balance'] ?? 0), 2);
            $counted = $r['counted_balance'] ?? null;
            $counted = $counted === null ? null : round((float) $counted, 2);
            if (!self::itemResolved($book, $counted, (string) ($r['resolution'] ?? 'open'))) {
                $unresolved++;
            }
        }

        $completed = (string) $header['status'] === 'completed';
        return [
            'exists'           => true,
            'completed'        => $completed,
            'unresolved_count' => $unresolved,
            'ok'               => $completed && $unresolved === 0,
        ];
    }

    /**
     * Účet je „vyřešený" pro účely uzavření knih, když ho účetní explicitně potvrdila
     * (resolution='resolved') NEBO je napočítaný a účetní stav sedí přesně (diff = 0).
     * Nenapočítaný účet bez potvrzení = nevyřešený (blokuje uzavření).
     */
    private static function itemResolved(float $book, ?float $counted, string $resolution): bool
    {
        if ($resolution === 'resolved') {
            return true;
        }
        return $counted !== null && abs(round($counted - $book, 2)) < 0.005;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadHeader(int $supplierId, int $periodId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, responsible_person, inventory_date, protocol_ref, note,
                    item_count, unresolved_count, completed_at
               FROM accounting_balance_inventory WHERE supplier_id = ? AND period_id = ?'
        );
        $stmt->execute([$supplierId, $periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['item_count'] = (int) $row['item_count'];
        $row['unresolved_count'] = (int) $row['unresolved_count'];
        return $row;
    }

    /**
     * @return array<int, array{counted_balance:?string, resolution:string, note:?string}>
     */
    private function loadItems(int $supplierId, int $periodId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.account_id, i.counted_balance, i.resolution, i.note
               FROM accounting_balance_inventory_items i
               JOIN accounting_balance_inventory h ON h.id = i.inventory_id
              WHERE h.supplier_id = ? AND h.period_id = ?'
        );
        $stmt->execute([$supplierId, $periodId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['account_id']] = [
                'counted_balance' => $r['counted_balance'],
                'resolution'      => (string) $r['resolution'],
                'note'            => $r['note'],
            ];
        }
        return $out;
    }

    private static function nullTrim(mixed $v, int $max = 190): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $max);
    }

    private static function nullDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return ($d !== false && $d->format('Y-m-d') === $s) ? $s : null;
    }

    /**
     * Typový návrh způsobu doložení dle prefixu účtu (rozvahový den, §29–30 ZoÚ) —
     * jen výchozí předvyplnění, účetní ho na tištěné/exportované sestavě může přepsat.
     */
    private function documentationHint(string $accountCode): string
    {
        $exact = [
            '211' => 'Pokladní kniha — protokol o fyzické inventuře pokladní hotovosti',
            '213' => 'Fyzická inventura cenin',
            '221' => 'Bankovní výpis k rozvahovému dni',
            '261' => 'Doklad o převodu (peníze na cestě)',
            '311' => 'Saldokonto pohledávek — odsouhlasení s odběratelem',
            '314' => 'Saldokonto poskytnutých záloh',
            '315' => 'Saldokonto ostatních pohledávek',
            '321' => 'Saldokonto závazků — odsouhlasení s dodavatelem',
            '324' => 'Saldokonto přijatých záloh',
        ];
        if (isset($exact[$accountCode])) {
            return $exact[$accountCode];
        }
        // Analytika dědí návrh po své syntetice: bankovní účty (221100, 221200 …),
        // valutové pokladny (211xxx) i saldokonta se doloží stejně jako jejich
        // syntetický účet. Bez toho by každý analytický účet spadl na obecné
        // „Analytická evidence" — a od zavedení analytik banky by tak byl BEZ hintu
        // „Bankovní výpis k rozvahovému dni" úplně každý bankovní účet.
        $synthetic = substr($accountCode, 0, 3);
        if (isset($exact[$synthetic])) {
            return $exact[$synthetic];
        }

        $prefix2 = substr($accountCode, 0, 2);
        $prefix1 = substr($accountCode, 0, 1);

        return match (true) {
            $prefix1 === '0' => 'Inventární karta majetku / odpisový plán',
            $prefix1 === '1' => 'Skladová evidence — inventurní soupis zásob',
            $prefix2 === '23' || $prefix2 === '24' => 'Úvěrová smlouva / výpis úvěrového účtu',
            $prefix2 === '25' => 'Evidence cenných papírů',
            $prefix2 === '31' || $prefix2 === '32' => 'Saldokonto / odsouhlasení s protistranou',
            $prefix2 === '33' => 'Zúčtovací a výplatní listina mezd',
            $prefix2 === '34' => 'Přiznání / rozhodnutí správce daně, rekapitulace',
            $prefix2 === '35' || $prefix2 === '36' || $prefix2 === '37' => 'Smlouva / zúčtovací listina',
            $prefix2 === '38' => 'Rozpis časového rozlišení / výpočet dohadné položky',
            $prefix2 === '39' => 'Výpočet opravné položky / vnitřní zúčtovací doklad',
            $prefix1 === '4' => 'Zápis orgánu společnosti / rozpis zůstatku',
            default => 'Analytická evidence / rozpis zůstatku',
        };
    }

    /**
     * @return array{name:string, ico:?string, address:string, prepared_at:string}
     */
    private function entity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, street, city, zip, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $addressParts = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim((string) ($row['zip'] ?? '') . ' ' . (string) ($row['city'] ?? '')),
        ], static fn (string $p): bool => $p !== '');

        return [
            'name'        => (string) ($row['company_name'] ?? ''),
            'ico'         => $row['ic'] !== null && $row['ic'] !== '' ? (string) $row['ic'] : null,
            'address'     => implode(', ', $addressParts),
            'prepared_at' => (new \DateTimeImmutable())->format('d.m.Y H:i'),
        ];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
