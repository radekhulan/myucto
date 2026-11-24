<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingPeriodProvisioner;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\PostingException;
use PDO;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Repository pro accounting_periods — účetní období per firma (Epic F1).
 * Stav uzávěrky (open → closing → closed) rozhoduje o neměnnosti zápisů (§35 ZoÚ).
 */
final class AccountingPeriodRepository
{
    private const POSTING_YEARS_AHEAD = 1;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

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

    public function findByYear(int $supplierId, int $fiscalYear, bool $currentRead = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at,
                    row_version, closed_by, approved_at, approved_by,
                    reviewed_at, reviewed_by, approval_body, approval_decision_ref, approval_document_hash,
                    created_reason
               FROM accounting_periods
              WHERE supplier_id = ? AND fiscal_year = ?' . ($currentRead ? ' FOR UPDATE' : '')
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
     * Posune začátek období (CAS přes row_version). Volající drží zámky období
     * a ručí za pravidla prvního období, viz AccountingPeriodAction::updateStart.
     *
     * @return bool false = version_conflict nebo období už není otevřené
     */
    public function updateStartsOnCas(int $id, int $supplierId, string $startsOn, int $expectedVersion): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE accounting_periods
                SET starts_on = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND row_version = ?
                AND status = 'open' AND approved_at IS NULL"
        );
        $stmt->execute([$startsOn, $id, $supplierId, $expectedVersion]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Zápisy deníku období s datem před daným dnem (včetně konceptů a otevíracích).
     *
     * @return array{count:int, first_date:?string}
     */
    public function journalEntriesBefore(int $supplierId, int $periodId, string $date): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS cnt, MIN(entry_date) AS first_date
               FROM journal_entries
              WHERE supplier_id = ? AND period_id = ? AND entry_date < ?'
        );
        $stmt->execute([$supplierId, $periodId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'first_date' => isset($row['first_date']) ? (string) $row['first_date'] : null,
        ];
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
     * Existuje účetní období končící dřív, než tohle začíná?
     *
     * Rozlišuje dva tvary období, které z dvojice dat vypadají stejně: první (a proto
     * zkrácený) rok nově vzniklého poplatníka, a přechodné období při návratu
     * z hospodářského roku na kalendářní. To druhé chce jiný typ přiznání
     * (§ 21a, § 38ma ZDP), takže se o něm musí vědět.
     */
    public function existsBefore(int $supplierId, string $startsOn): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM accounting_periods WHERE supplier_id = ? AND ends_on < ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $startsOn]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Kalendář účetního roku firmy — JEDINÉ místo, které rozhoduje, zda firma vede
     * kalendářní, nebo hospodářský rok (§ 3 odst. 2 ZoÚ).
     *
     * Má-li firma období tvaru hospodářského roku, rozhodují ta (vzniklá data jsou
     * autoritativní). Jinak nastavení firmy `fiscal_year_start_month` — zadává se předem,
     * aby první období (převod z jiného programu, aktivace účetnictví, automatické
     * otevření) vzniklo hned ve správném tvaru. Bez nastavení kalendářní rok.
     */
    public function fiscalCalendar(int $supplierId): FiscalCalendar
    {
        return $this->fiscalCalendarSnapshot($supplierId)['calendar'];
    }

    /**
     * Kalendář a jeho podklad pro náhled i atomické ověření před zápisem.
     * Zamykací varianta nevrátí zastaralý snapshot. Při innodb_snapshot_isolation=ON
     * může předat konflikt 1020 vlastníkovi transakce, který zopakuje celou operaci.
     *
     * @return array{calendar: FiscalCalendar, periods: list<array<string,mixed>>, supplier_start_month: int|null, fingerprint: string}
     */
    public function fiscalCalendarSnapshot(int $supplierId, bool $forUpdate = false): array
    {
        $pdo = $this->db->pdo();
        if ($forUpdate && !$pdo->inTransaction()) {
            throw new \LogicException('Zamknutí účetního kalendáře vyžaduje transakci.');
        }
        // Stejné pořadí zámků jako DimensionPeriodGuard a změna fiskálního roku firmy.
        $stmt = $pdo->prepare(
            'SELECT id, fiscal_year, starts_on, ends_on, status FROM accounting_periods
              WHERE supplier_id = ? ORDER BY starts_on, id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId]);
        $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $periodBounds = array_map(static fn (array $p): array => [(int) $p['fiscal_year'], (string) $p['starts_on'], (string) $p['ends_on']], $periods);
        ['calendar' => $calendar, 'supplier_start_month' => $startMonth] = $this->resolveCalendar($supplierId, $periods, $forUpdate);
        // Nepoužité nastavení firmy nemění účinný kalendář ani jeho otisk a není třeba je zamykat.
        $fingerprint = hash('sha256', json_encode([
            'calendar_start_month' => $calendar->startMonth(),
            'periods' => $periodBounds,
        ], JSON_THROW_ON_ERROR));
        return ['calendar' => $calendar, 'periods' => $periods, 'supplier_start_month' => $startMonth, 'fingerprint' => $fingerprint];
    }

    /**
     * Kalendář, který by firma měla s daným výčtem období — stejné pravidlo jako
     * {@see fiscalCalendarSnapshot()}, jen nad navrhovanými hranicemi před zápisem.
     *
     * @param list<array<string,mixed>> $periods řádky s fiscal_year, starts_on, ends_on
     */
    public function fiscalCalendarFor(int $supplierId, array $periods, bool $forUpdate = false): FiscalCalendar
    {
        return $this->resolveCalendar($supplierId, $periods, $forUpdate)['calendar'];
    }

    /**
     * @param list<array<string,mixed>> $periods
     * @return array{calendar: FiscalCalendar, supplier_start_month: int|null}
     */
    private function resolveCalendar(int $supplierId, array $periods, bool $forUpdate): array
    {
        // Historická změna hospodářského roku: rozhoduje nejnovější období, stejně jako listForTenant().
        usort($periods, static fn (array $a, array $b): int => (int) $b['fiscal_year'] <=> (int) $a['fiscal_year']);
        $calendar = FiscalCalendar::forPeriods($periods);
        $startMonth = null;
        if ($calendar->isCalendar()) {
            $startMonth = $this->fiscalYearStartMonth($supplierId, $forUpdate);
            $calendar = new FiscalCalendar($startMonth);
        }
        return ['calendar' => $calendar, 'supplier_start_month' => $startMonth];
    }

    /** Nastavení firmy „účetní rok začíná měsícem" (1 = kalendářní rok; migrace 2003). */
    public function fiscalYearStartMonth(int $supplierId, bool $forUpdate = false): int
    {
        if ($forUpdate && !$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Zamknutí nastavení účetního roku vyžaduje transakci.');
        }
        if (!$this->db->hasColumn('supplier', 'fiscal_year_start_month')) {
            return 1;
        }
        // Zámek chrání fiscal_year_start_month před souběžným uložením nastavení i u firmy bez období.
        $stmt = $this->db->pdo()->prepare('SELECT fiscal_year_start_month FROM supplier WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId]);
        $month = (int) $stmt->fetchColumn();
        return $month >= 1 && $month <= 12 ? $month : 1;
    }

    /**
     * Zajistí existenci období pro dané datum. Vrátí existující (bez ohledu na stav
     * — otevřenost řeší PostingService), jinak založí období podle kalendáře účetního
     * roku firmy ({@see fiscalCalendar()}) jako open.
     *
     * @param bool $forPosting běžné zaúčtování, mimo vědomé založení při aktivaci a seedování
     * @return array<string,mixed> období obsahující $date
     */
    public function ensureOpenPeriodFor(int $supplierId, string $date, bool $forPosting = true): array
    {
        $currentRead = $forPosting && $this->db->pdo()->inTransaction();
        $existing = $currentRead
            ? $this->findForDateForUpdate($supplierId, $date)
            : $this->findForDate($supplierId, $date);
        if ($existing !== null) {
            return $existing;
        }
        if ($forPosting) {
            $this->assertCanAutomaticallyOpenForPosting($supplierId, $date);
        }
        $calendar = $this->fiscalCalendar($supplierId);
        $year = $calendar->fiscalYearOfDate($date);
        $this->create(
            $supplierId,
            $year,
            $calendar->periodStart($year),
            $calendar->periodEnd($year),
            $forPosting ? AccountingPeriodProvisioner::REASON_POSTING : null,
        );
        // create() může narazit na UNIQUE (supplier, year) jen když už rok existuje
        // s jinými hranicemi — pak findForDate výše vrátil null a tady dočteme znovu.
        $period = $this->findForDate($supplierId, $date);
        if ($period === null) {
            throw new \RuntimeException('Nepodařilo se zajistit účetní období pro ' . $date . '.');
        }
        return $period;
    }

    /**
     * Kontrola před automatickým založením chybějícího období při účtování.
     *
     * Automaticky se zakládá první období firmy, období po konci řady, mezera uvnitř
     * řady (zapomenutý rok mezi převzatou historií a současností) a jeden účetní rok
     * bezprostředně před prvním obdobím (pozdní doklad nebo storno za loňský rok).
     * Chybějící období starší než rok před začátkem nebo vzdálenější než příští
     * účetní rok založí účetní ručně.
     */
    public function assertCanAutomaticallyOpenForPosting(int $supplierId, string $date): void
    {
        $calendar = $this->fiscalCalendar($supplierId);
        $year = $calendar->fiscalYearOfDate($date);
        $today = $this->clock->now()->format('Y-m-d');
        if ($year > $calendar->fiscalYearOfDate($today) + self::POSTING_YEARS_AHEAD) {
            throw new PostingException(
                'period_after_horizon',
                'Datum je za příštím účetním rokem firmy. Zkontrolujte, zda nejde o překlep v roce. '
                . 'Pokud jde opravdu o budoucí rok, založte období ručně v Nástroje → Uzávěrka.',
            );
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT MIN(starts_on) AS first_start FROM accounting_periods WHERE supplier_id = ?'
            . ($pdo->inTransaction() ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId]);
        $firstStart = $stmt->fetchColumn();
        if ($firstStart === null || $firstStart === false || $date >= (string) $firstStart) {
            return;
        }
        if ($year >= $calendar->fiscalYearOfDate((string) $firstStart) - 1) {
            return;
        }
        throw new PostingException(
            'period_before_start',
            'Datum je před začátkem účetnictví firmy v MyÚčtu. Zkontrolujte, zda nejde o překlep v roce. '
            . 'Pokud jde opravdu o starší rok, založte období ručně v Nástroje → Uzávěrka.',
        );
    }

    /**
     * Otevřená období bez zápisu v deníku, která celá předcházejí známému začátku
     * účetnictví. U starších instalací bez data aktivace lze použít období ze setupu.
     * Bez těchto údajů se začátek z obsahu deníku neodhaduje.
     *
     * @return list<array<string,mixed>>
     */
    public function emptyBeforeAccountingStart(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ap.id, ap.fiscal_year, ap.starts_on, ap.ends_on
               FROM accounting_periods ap
               JOIN supplier s ON s.id = ap.supplier_id
              WHERE ap.supplier_id = ? AND ap.status = 'open' AND ap.approved_at IS NULL
                AND (ap.created_reason IS NULL OR ap.created_reason = 'posting')
                AND ap.ends_on < COALESCE(s.accounting_starts_on, (
                    SELECT MIN(setup.starts_on) FROM accounting_periods setup
                     WHERE setup.supplier_id = ap.supplier_id AND setup.created_reason = 'setup'
                ))
                AND NOT EXISTS (
                    SELECT 1 FROM journal_entries je WHERE je.supplier_id = ap.supplier_id AND je.period_id = ap.id
                )
              ORDER BY ap.starts_on, ap.id"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{id:int,fiscal_year:int,starts_on:string,ends_on:string,entry_count:int}> */
    public function postingPeriodsAfterHorizon(int $supplierId): array
    {
        $firstYearAfterHorizon = (int) $this->clock->now()->format('Y') + self::POSTING_YEARS_AHEAD + 1;
        $stmt = $this->db->pdo()->prepare(
            "SELECT ap.id, ap.fiscal_year, ap.starts_on, ap.ends_on, COUNT(je.id) AS entry_count
               FROM accounting_periods ap
               LEFT JOIN journal_entries je ON je.supplier_id = ap.supplier_id AND je.period_id = ap.id
              WHERE ap.supplier_id = ? AND ap.created_reason = 'posting' AND ap.starts_on >= ?
              GROUP BY ap.id, ap.fiscal_year, ap.starts_on, ap.ends_on
              ORDER BY ap.starts_on, ap.id"
        );
        $stmt->execute([$supplierId, sprintf('%04d-01-01', $firstYearAfterHorizon)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
