<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro accounting_periods — účetní období per firma (Epic F1).
 * Stav uzávěrky (open → closing → closed) rozhoduje o neměnnosti zápisů (§35 ZoÚ).
 */
final class AccountingPeriodRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at,
                    row_version, closed_by, approved_at, approved_by,
                    reviewed_at, reviewed_by, approval_body, approval_decision_ref, approval_document_hash,
                    created_reason
               FROM accounting_periods
              WHERE supplier_id = ?
              ORDER BY fiscal_year DESC'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at,
                    row_version, closed_by, approved_at, approved_by,
                    reviewed_at, reviewed_by, approval_body, approval_decision_ref, approval_document_hash,
                    created_reason
               FROM accounting_periods
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function findByYear(int $supplierId, int $fiscalYear): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at,
                    row_version, closed_by, approved_at, approved_by,
                    reviewed_at, reviewed_by, approval_body, approval_decision_ref, approval_document_hash,
                    created_reason
               FROM accounting_periods
              WHERE supplier_id = ? AND fiscal_year = ?'
        );
        $stmt->execute([$supplierId, $fiscalYear]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Období obsahující dané datum (pro zařazení zápisu do správného období).
     */
    public function findForDate(int $supplierId, string $date): ?array
    {
        // ORDER BY kvůli determinismu, pokud by se období náhodou překrývala
        // (schéma překryv nebrání); nejnovější začátek vyhrává.
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at,
                    row_version, closed_by, approved_at, approved_by,
                    reviewed_at, reviewed_by, approval_body, approval_decision_ref, approval_document_hash,
                    created_reason
               FROM accounting_periods
              WHERE supplier_id = ? AND ? BETWEEN starts_on AND ends_on
              ORDER BY starts_on DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * @param string|null $createdReason proč období vzniklo (migrace 1733):
     *        `posting`/`import`/`maintenance` u automatického založení
     *        ({@see \MyInvoice\Service\Accounting\AccountingPeriodProvisioner}),
     *        NULL u ručního (API, průvodce, uzávěrkový krok `open_next`).
     */
    public function create(int $supplierId, int $fiscalYear, string $startsOn, string $endsOn, ?string $createdReason = null): int
    {
        $pdo = $this->db->pdo();
        try {
            // §DM: nové období zdědí účetní politiku časového rozlišení drobného majetku
            // z firemního defaultu (seed). Dál je politika PER OBDOBÍ a uzávěrka ji přepisuje
            // jen na tomto období — viz {@see getAccrualPolicy}/{@see setAccrualPolicy}.
            $pdo->prepare(
                'INSERT INTO accounting_periods
                    (supplier_id, fiscal_year, starts_on, ends_on,
                     small_asset_accrual_mode, small_asset_accrual_pct, created_reason)
                 SELECT ?, ?, ?, ?,
                        COALESCE(s.small_asset_accrual_mode, ?),
                        CASE WHEN s.small_asset_accrual_mode = ? THEN s.small_asset_accrual_pct ELSE NULL END,
                        ?
                   FROM (SELECT ? AS sid) x
                   LEFT JOIN accounting_supplier_settings s ON s.supplier_id = x.sid'
            )->execute([$supplierId, $fiscalYear, $startsOn, $endsOn, 'none', 'flat_pct', $createdReason, $supplierId]);
        } catch (\PDOException $e) {
            // Souběžné založení téhož roku (UNIQUE supplier×fiscal_year) → vrátit existující.
            if (($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }
            $existing = $this->findByYear($supplierId, $fiscalYear);
            if ($existing === null) {
                throw $e;
            }
            return (int) $existing['id'];
        }
        return (int) $pdo->lastInsertId();
    }

    /**
     * Řádek období pod zámkem SELECT ... FOR UPDATE — serializace souběžné uzávěrky
     * (R4). Volat VÝHRADNĚ uvnitř otevřené transakce.
     */
    public function findForUpdate(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM accounting_periods WHERE id = ? AND supplier_id = ? FOR UPDATE'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Období obsahující datum POD řádkovým zámkem SELECT ... FOR UPDATE (EP-3):
     * účtování (PostingService::postDocument/reverse) a souběžná uzávěrka
     * (ClosingService::start/closeBooks… drží týž řádek přes {@see findForUpdate})
     * se serializují na TÉMŽE řádku období — po commitu uzávěrky vidí účtování nový
     * stav a odmítne zápis do uzavíraného období. Volat VÝHRADNĚ uvnitř otevřené
     * transakce. Řádek se zamyká v pořadí období → řádky deníku (shodné s uzávěrkou),
     * takže nevzniká cyklus.
     */
    public function findForDateForUpdate(int $supplierId, string $date): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at,
                    row_version, closed_by, approved_at, approved_by,
                    reviewed_at, reviewed_by, approval_body, approval_decision_ref, approval_document_hash,
                    created_reason
               FROM accounting_periods
              WHERE supplier_id = ? AND ? BETWEEN starts_on AND ends_on
              ORDER BY starts_on DESC, id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Compare-and-swap změna stavu období (R4): UPDATE projde jen při shodě
     * row_version (→ +1).
     *
     * Rozlišuje VRATNOU interní kontrolu od NEVRATNÉHO zákonného schválení (EP-5,
     * §17 odst. 7 ZoÚ):
     *   - 'closed'   → plní closed_at/closed_by, čistí VRATNÉ reviewed_*,
     *   - 'reviewed' → plní VRATNÉ reviewed_at/reviewed_by (interní kontrola),
     *   - 'approved' → plní NEVRATNÉ approved_at/approved_by + metadata schválení
     *                  (orgán, odkaz na rozhodnutí, hash dokumentu) z $approval,
     *   - 'open'/'closing' → čistí jen closed_* a reviewed_*.
     *
     * approved_at/approved_by ani approval_* se ZDE NIKDY nemažou — schválená
     * závěrka je definitivní; přechod ven z 'approved' odmítá už Action vrstva
     * (opravy patří do období zjištění, §35 ZoÚ). Nulování bylo záměrně odstraněno.
     *
     * @param 'open'|'closing'|'closed'|'reviewed'|'approved' $status
     * @param array{body?:?string, decision_ref?:?string, document_hash?:?string} $approval
     *        Metadata zákonného schválení (jen pro $status === 'approved').
     * @return bool false = version_conflict (nebo neexistující řádek)
     */
    public function setStatusCas(int $id, int $supplierId, string $status, int $expectedVersion, ?int $userId, array $approval = []): bool
    {
        $now = date('Y-m-d H:i:s');
        $sets = ['status = ?', 'row_version = row_version + 1'];
        $params = [$status];
        if ($status === 'closed') {
            // Uzavření knih / návrat z vratné interní kontroly. approved_* se NEmaže.
            $sets[] = 'closed_at = ?';
            $sets[] = 'closed_by = ?';
            $sets[] = 'reviewed_at = NULL';
            $sets[] = 'reviewed_by = NULL';
            $params[] = $now;
            $params[] = $userId;
        } elseif ($status === 'reviewed') {
            // Vratná interní kontrola — pracovní stav, lze zrušit přechodem zpět na 'closed'.
            $sets[] = 'reviewed_at = ?';
            $sets[] = 'reviewed_by = ?';
            $params[] = $now;
            $params[] = $userId;
        } elseif ($status === 'approved') {
            // Nevratné zákonné schválení (§17/7) — zapisuje se JEDNOU a už se nemaže.
            $sets[] = 'approved_at = ?';
            $sets[] = 'approved_by = ?';
            $sets[] = 'approval_body = ?';
            $sets[] = 'approval_decision_ref = ?';
            $sets[] = 'approval_document_hash = ?';
            $sets[] = 'reviewed_at = NULL';
            $sets[] = 'reviewed_by = NULL';
            $params[] = $now;
            $params[] = $userId;
            $params[] = ($approval['body'] ?? null) !== '' ? ($approval['body'] ?? null) : null;
            $params[] = ($approval['decision_ref'] ?? null) !== '' ? ($approval['decision_ref'] ?? null) : null;
            $params[] = ($approval['document_hash'] ?? null) !== '' ? ($approval['document_hash'] ?? null) : null;
        } else {
            // 'open' (reopen z 'closed') / 'closing'. approved_* se NIKDY nemaže —
            // z 'approved' se sem stejně nelze dostat (přechod odmítá Action).
            $sets[] = 'closed_at = NULL';
            $sets[] = 'closed_by = NULL';
            $sets[] = 'reviewed_at = NULL';
            $sets[] = 'reviewed_by = NULL';
        }
        $params[] = $id;
        $params[] = $supplierId;
        $params[] = $expectedVersion;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE accounting_periods SET ' . implode(', ', $sets)
            . ' WHERE id = ? AND supplier_id = ? AND row_version = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    /**
     * První období překrývající interval <startsOn, endsOn> (validace R5 při
     * create/update: NOT (ends_on < starts OR starts_on > ends)).
     */
    public function overlapping(int $supplierId, string $startsOn, string $endsOn, ?int $excludeId = null): ?array
    {
        $sql = 'SELECT id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at,
                       row_version, closed_by, approved_at, approved_by,
                       reviewed_at, reviewed_by, approval_body, approval_decision_ref, approval_document_hash,
                       created_reason
                  FROM accounting_periods
                 WHERE supplier_id = ? AND NOT (ends_on < ? OR starts_on > ?)';
        $params = [$supplierId, $startsOn, $endsOn];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Bezprostředně navazující období: starts_on = afterEndsOn + 1 den (R5).
     */
    public function nextPeriod(int $supplierId, string $afterEndsOn): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at,
                    row_version, closed_by, approved_at, approved_by,
                    reviewed_at, reviewed_by, approval_body, approval_decision_ref, approval_document_hash,
                    created_reason
               FROM accounting_periods
              WHERE supplier_id = ? AND starts_on = DATE_ADD(?, INTERVAL 1 DAY)
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $afterEndsOn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Zajistí existenci období pro dané datum. Vrátí existující (bez ohledu na stav
     * — otevřenost řeší PostingService), jinak založí kalendářní rok data jako open.
     * Pozn.: automaticky se zakládá jen kalendářní rok; hospodářský rok si firma
     * vytváří explicitně přes create() s vlastními hranicemi (setup / Action).
     *
     * @return array<string,mixed> období obsahující $date
     */
    public function ensureOpenPeriodFor(int $supplierId, string $date): array
    {
        $existing = $this->findForDate($supplierId, $date);
        if ($existing !== null) {
            return $existing;
        }
        $year = (int) substr($date, 0, 4);
        $this->create($supplierId, $year, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year));
        // create() může narazit na UNIQUE (supplier, year) jen když už rok existuje
        // s jinými hranicemi — pak findForDate výše vrátil null a tady dočteme znovu.
        $period = $this->findForDate($supplierId, $date);
        if ($period === null) {
            throw new \RuntimeException('Nepodařilo se zajistit účetní období pro ' . $date . '.');
        }
        return $period;
    }

    /**
     * Hranice bezprostředně navazujícího období (R5): starts_on = endsOn + 1 den,
     * délka jeden rok, label = kalendářní rok začátku. SSOT pro obě cesty, které
     * další období zakládají — uzávěrkový krok `open_next`
     * ({@see \MyInvoice\Service\Accounting\Closing\ClosingService::ensureNextPeriod})
     * i automatické otevření chybějícího období
     * ({@see \MyInvoice\Service\Accounting\AccountingPeriodProvisioner}).
     * Tvar období (kalendář vs. hospodářský rok §21a ZDP) se tím dědí z předchozího
     * období, takže hospodářskému roku nikdy nevznikne kalendářní pokračování.
     *
     * @return array{fiscal_year:int, starts_on:string, ends_on:string}
     */
    public static function nextPeriodBounds(string $endsOn): array
    {
        $start = (new \DateTimeImmutable($endsOn))->modify('+1 day');
        $end = $start->add(new \DateInterval('P1Y'))->sub(new \DateInterval('P1D'));
        return [
            'fiscal_year' => (int) $start->format('Y'),
            'starts_on'   => $start->format('Y-m-d'),
            'ends_on'     => $end->format('Y-m-d'),
        ];
    }

    /**
     * §DM účetní politika časového rozlišení drobného majetku (381) PER OBDOBÍ.
     * Zdroj pravdy pro náhled i zaúčtování; seed z firemního defaultu proběhl při
     * {@see create}. Vrací default 'none' pro neexistující období (obranná hodnota).
     *
     * @return array{small_asset_accrual_mode: string, small_asset_accrual_pct: ?float}
     */
    public function getAccrualPolicy(int $supplierId, int $periodId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT small_asset_accrual_mode, small_asset_accrual_pct, small_asset_flat_pct_materiality_limit
               FROM accounting_periods
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['small_asset_accrual_mode' => 'none', 'small_asset_accrual_pct' => null, 'small_asset_flat_pct_materiality_limit' => null];
        }
        return [
            'small_asset_accrual_mode' => (string) ($row['small_asset_accrual_mode'] ?? 'none'),
            'small_asset_accrual_pct'  => $row['small_asset_accrual_pct'] === null ? null : (float) $row['small_asset_accrual_pct'],
            'small_asset_flat_pct_materiality_limit' => $row['small_asset_flat_pct_materiality_limit'] === null ? null : (float) $row['small_asset_flat_pct_materiality_limit'],
        ];
    }

    /**
     * Uloží §DM účetní politiku na KONKRÉTNÍ období (ne na firmu) — uzávěrka jednoho
     * období tak nikdy nezmění politiku jiného období. pct se u režimů mimo flat_pct
     * ukládá jako NULL. Nemění row_version (politika není verzovaný stav uzávěrky —
     * ta se bumpuje samostatně v ClosingService).
     *
     * @param 'none'|'pro_rata'|'flat_pct' $mode
     */
    public function setAccrualPolicy(int $supplierId, int $periodId, string $mode, ?float $pct, ?float $materialityLimit = null): void
    {
        $storedPct = $mode === 'flat_pct' ? $pct : null;
        // Limit významnosti drží jen paušál; jiný režim ho vynuluje (bezpředmětný).
        $storedLimit = $mode === 'flat_pct' ? $materialityLimit : null;
        $this->db->pdo()->prepare(
            'UPDATE accounting_periods
                SET small_asset_accrual_mode = ?, small_asset_accrual_pct = ?,
                    small_asset_flat_pct_materiality_limit = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$mode, $storedPct, $storedLimit, $supplierId, $periodId]);
    }

    /**
     * @param 'open'|'closing'|'closed' $status
     */
    public function setStatus(int $id, int $supplierId, string $status): bool
    {
        $closedAt = $status === 'closed' ? date('Y-m-d H:i:s') : null;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE accounting_periods SET status = ?, closed_at = ? WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$status, $closedAt, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['fiscal_year'] = (int) $r['fiscal_year'];
        if (array_key_exists('row_version', $r)) {
            $r['row_version'] = (int) $r['row_version'];
        }
        if (array_key_exists('closed_by', $r)) {
            $r['closed_by'] = $r['closed_by'] === null ? null : (int) $r['closed_by'];
        }
        if (array_key_exists('approved_by', $r)) {
            $r['approved_by'] = $r['approved_by'] === null ? null : (int) $r['approved_by'];
        }
        if (array_key_exists('reviewed_by', $r)) {
            $r['reviewed_by'] = $r['reviewed_by'] === null ? null : (int) $r['reviewed_by'];
        }
        return $r;
    }
}
