<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use PDO;

/**
 * Perzistence předpisů záloh na daň a pojistné (tax_advance_schedules, migrace 1044, E9).
 *
 * Předpis = jedna splátka zálohy (daň §38a / sociální OSVČ / zdravotní OSVČ) s částkou,
 * splatností a očekávaným VS pro párování s bankou. `period_year` = rok, ZA KTERÝ se
 * záloha platí (rok příštího přiznání). Stav planned/paid; „po splatnosti" se odvozuje
 * z due_date při čtení (bez cronu). Tenant izolace přes supplier_id na každém dotazu.
 */
final class TaxAdvanceScheduleRepository
{
    public function __construct(private readonly Connection $db) {}

    private const COLS = 'id, supplier_id, taxpayer_type, advance_kind, period_year, seq_no, amount,
                          due_date, variable_symbol, status, paid_amount, paid_on,
                          matched_transaction_id, match_confidence, paid_source, source_return_id, created_at, updated_at';

    /**
     * Přepíše NAPLÁNOVANÉ předpisy dané skupiny (supplier+type+kind+period_year) novou sadou.
     * Zaplacené (status='paid') předpisy zůstávají nedotčené — regenerace z re-finalizovaného
     * přiznání nesmí zahodit už spárované úhrady. Idempotentní přes UNIQUE klíč (upsert).
     *
     * @param list<array{seq_no:int,amount:float,due_date:string,variable_symbol:?string}> $rows
     */
    public function replacePlanned(int $supplierId, string $type, string $kind, int $periodYear, array $rows, ?int $sourceReturnId): void
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            // Smaž jen dosud nezaplacené předpisy skupiny.
            $del = $pdo->prepare(
                "DELETE FROM tax_advance_schedules
                  WHERE supplier_id = ? AND taxpayer_type = ? AND advance_kind = ?
                    AND period_year = ? AND status = 'planned'"
            );
            $del->execute([$supplierId, $type, $kind, $periodYear]);

            // Splatnosti, které už mají ZAPLACENÝ předpis — pro ně NEvkládej planned
            // duplikát. Generovaný řádek dostane jiné seq_no než zaplacený řádek a
            // ON DUPLICATE KEY (na seq_no) by kolizi nezachytil → vznikla by duplicitní
            // splatnost (jedna paid, jedna planned „Po splatnosti"). Zaplacené předpisy
            // zůstávají beze změny (DELETE výše maže jen planned). Zároveň dedup v rámci dávky.
            $paidStmt = $pdo->prepare(
                "SELECT due_date FROM tax_advance_schedules
                  WHERE supplier_id = ? AND taxpayer_type = ? AND advance_kind = ?
                    AND period_year = ? AND status = 'paid'"
            );
            $paidStmt->execute([$supplierId, $type, $kind, $periodYear]);
            $seenDue = [];
            foreach ($paidStmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
                $seenDue[substr((string) $d, 0, 10)] = true;
            }

            $ins = $pdo->prepare(
                'INSERT INTO tax_advance_schedules
                     (supplier_id, taxpayer_type, advance_kind, period_year, seq_no, amount, due_date, variable_symbol, source_return_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     amount = IF(status = \'paid\', amount, VALUES(amount)),
                     due_date = IF(status = \'paid\', due_date, VALUES(due_date)),
                     variable_symbol = IF(status = \'paid\', variable_symbol, VALUES(variable_symbol)),
                     source_return_id = VALUES(source_return_id)'
            );
            foreach ($rows as $r) {
                $due = substr((string) $r['due_date'], 0, 10);
                if (isset($seenDue[$due])) {
                    continue; // splatnost už má zaplacený předpis nebo je v dávce dvakrát
                }
                $seenDue[$due] = true;
                $ins->execute([
                    $supplierId, $type, $kind, $periodYear,
                    (int) $r['seq_no'],
                    round((float) $r['amount'], 2),
                    (string) $r['due_date'],
                    $r['variable_symbol'] !== null && $r['variable_symbol'] !== '' ? (string) $r['variable_symbol'] : null,
                    $sourceReturnId,
                ]);
            }
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Předpisy pro rok (period_year), volitelně filtr na typ. Řazeno dle druhu a splatnosti.
     *
     * @return list<array<string,mixed>>
     */
    public function listForYear(int $supplierId, int $periodYear, ?string $type = null): array
    {
        $sql = 'SELECT ' . self::COLS . '
                  FROM tax_advance_schedules
                 WHERE supplier_id = ? AND period_year = ?';
        $params = [$supplierId, $periodYear];
        if ($type !== null) {
            $sql .= ' AND taxpayer_type = ?';
            $params[] = $type;
        }
        $sql .= " ORDER BY FIELD(advance_kind,'tax','social','health'), due_date, seq_no";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Předpisy NAPŘÍČ ROKY pro typ (globální tabulka předpisu placení záloh ve FE, #46).
     * Řazeno dle splatnosti. @return list<array<string,mixed>>
     */
    public function listAllForType(int $supplierId, string $type): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . '
               FROM tax_advance_schedules
              WHERE supplier_id = ? AND taxpayer_type = ?
              ORDER BY due_date, FIELD(advance_kind,\'tax\',\'social\',\'health\'), seq_no'
        );
        $stmt->execute([$supplierId, $type]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Roky (period_year), pro které existují předpisy daného typu — pro cílené auto-párování
     * před výpisem napříč roky. @return list<int>
     */
    public function distinctPeriodYears(int $supplierId, string $type): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT period_year FROM tax_advance_schedules
              WHERE supplier_id = ? AND taxpayer_type = ? ORDER BY period_year'
        );
        $stmt->execute([$supplierId, $type]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Nadcházející (nezaplacené) předpisy napříč roky pro dashboard widget.
     *
     * @return list<array<string,mixed>>
     */
    public function upcoming(int $supplierId, string $fromDate, int $limit = 12): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT " . self::COLS . "
               FROM tax_advance_schedules
              WHERE supplier_id = ? AND status = 'planned' AND due_date >= ?
              ORDER BY due_date, FIELD(advance_kind,'tax','social','health'), seq_no
              LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute([$supplierId, $fromDate]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Naplánované předpisy dané skupiny k párování (FIFO dle splatnosti).
     *
     * @return list<array<string,mixed>>
     */
    public function plannedForMatching(int $supplierId, string $type, string $kind, int $periodYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT " . self::COLS . "
               FROM tax_advance_schedules
              WHERE supplier_id = ? AND taxpayer_type = ? AND advance_kind = ?
                AND period_year = ? AND status = 'planned'
              ORDER BY due_date, seq_no"
        );
        $stmt->execute([$supplierId, $type, $kind, $periodYear]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Označí předpis za zaplacený a naváže spárovanou bankovní transakci. `supplierId`
     * je defense-in-depth (id samo o sobě už přišlo z dotazu scopovaného na supplera,
     * ale WHERE ho vynucuje i tady, kdyby volající předal cizí id).
     *
     * `$confidence` (audit 2026-07): 'exact' = částka sedí na předpis (vstoupí do
     * automaticky předvyplněného součtu zaplacených záloh), 'uncertain' = částka nesedí
     * (do automatického součtu NEvstupuje, {@see paidTotals()} ji vrací zvlášť).
     */
    public function markPaid(int $supplierId, int $id, float $paidAmount, string $paidOn, int $transactionId, string $confidence = 'exact'): void
    {
        (new BankNoticeMutationGuard($this->db))->mutate($supplierId, $transactionId, fn () => $this->markPaidMutable($supplierId, $id, $paidAmount, $paidOn, $transactionId, $confidence));
    }

    private function markPaidMutable(int $supplierId, int $id, float $paidAmount, string $paidOn, int $transactionId, string $confidence = 'exact'): void
    {
        $confidence = $confidence === 'uncertain' ? 'uncertain' : 'exact';
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_advance_schedules
                SET status = 'paid', paid_amount = ?, paid_on = ?, matched_transaction_id = ?, match_confidence = ?
              WHERE id = ? AND supplier_id = ? AND status = 'planned'"
        );
        $stmt->execute([round($paidAmount, 2), $paidOn, $transactionId, $confidence, $id, $supplierId]);
    }

    /** Zruší spárování (vrátí do planned) — pro ruční opravu chybného párování. */
    public function unmatch(int $supplierId, int $id, int $transactionId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_advance_schedules
                SET status = 'planned', paid_amount = 0, paid_on = NULL, matched_transaction_id = NULL,
                    match_confidence = 'exact', paid_source = 'bank'
              WHERE id = ? AND supplier_id = ? AND matched_transaction_id = ?"
        );
        $stmt->execute([$id, $supplierId, $transactionId]);
    }

    /** Jeden předpis (defense-in-depth scoping na supplera). @return array<string,mixed>|null */
    public function findById(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM tax_advance_schedules WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Ruční úprava předepsané výše zálohy (#43 bod 3) — jen na dosud NEzaplaceném předpisu,
     * aby se nepřepsala už spárovaná úhrada. @return bool true = upraveno
     */
    public function updatePlannedAmount(int $supplierId, int $id, float $amount): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_advance_schedules
                SET amount = ?
              WHERE id = ? AND supplier_id = ? AND status = 'planned'"
        );
        $stmt->execute([round(max(0.0, $amount), 2), $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * RUČNÍ potvrzení úhrady předpisu (#43 bod 3) — bez bankovní transakce (účetní zná
     * úhradu, kterou modul netrackuje). Potvrzeno účetní ⇒ match_confidence='exact'
     * (vstupuje do automatického součtu), paid_source='manual', matched_transaction_id NULL.
     * Jen na 'planned' předpisu. @return bool true = potvrzeno
     */
    public function markPaidManual(int $supplierId, int $id, float $paidAmount, string $paidOn): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_advance_schedules
                SET status = 'paid', paid_amount = ?, paid_on = ?, matched_transaction_id = NULL,
                    match_confidence = 'exact', paid_source = 'manual'
              WHERE id = ? AND supplier_id = ? AND status = 'planned'"
        );
        $stmt->execute([round(max(0.0, $paidAmount), 2), $paidOn, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hromadné ruční potvrzení „vše zaplaceno" pro rok/typ (volitelně druh) — každý
     * dosud 'planned' předpis se potvrdí na svou předepsanou částku k datu splatnosti.
     * @return int počet potvrzených
     */
    public function markAllPlannedPaidManual(int $supplierId, string $type, int $periodYear, ?string $kind = null): int
    {
        $sql = "UPDATE tax_advance_schedules
                   SET status = 'paid', paid_amount = amount, paid_on = due_date,
                       matched_transaction_id = NULL, match_confidence = 'exact', paid_source = 'manual'
                 WHERE supplier_id = ? AND taxpayer_type = ? AND period_year = ? AND status = 'planned'";
        $params = [$supplierId, $type, $periodYear];
        if ($kind !== null) {
            $sql .= ' AND advance_kind = ?';
            $params[] = $kind;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Vrátí ručně potvrzený (nebo jakkoli zaplacený bez bankovní transakce) předpis zpět
     * do 'planned'. Pro bankou spárované použij {@see unmatch()}. @return bool
     */
    public function resetToPlanned(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_advance_schedules
                SET status = 'planned', paid_amount = 0, paid_on = NULL, matched_transaction_id = NULL,
                    match_confidence = 'exact', paid_source = 'bank'
              WHERE id = ? AND supplier_id = ? AND status = 'paid'"
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Bankovní transakce už spárované s nějakým předpisem (aby se nepárovaly dvakrát). */
    public function matchedTransactionIds(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT matched_transaction_id FROM tax_advance_schedules
              WHERE supplier_id = ? AND matched_transaction_id IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Součet zaplacených záloh za rok per druh, rozdělený dle spolehlivosti shody
     * částky (audit 2026-07). Do `exact` jdou JEN jisté shody (bezpečné k automatickému
     * předvyplnění přiznání/přehledu); `uncertain` (nesedící částka — pojistné) se vrací
     * ZVLÁŠŤ, aby ho volající nabídl k ručnímu potvrzení místo tichého započtení.
     *
     * @return array{exact:array{tax:float,social:float,health:float},uncertain:array{tax:float,social:float,health:float}}
     */
    public function paidTotals(int $supplierId, string $type, int $periodYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT advance_kind, match_confidence, COALESCE(SUM(paid_amount), 0) AS total
               FROM tax_advance_schedules
              WHERE supplier_id = ? AND taxpayer_type = ? AND period_year = ? AND status = 'paid'
              GROUP BY advance_kind, match_confidence"
        );
        $stmt->execute([$supplierId, $type, $periodYear]);
        $out = [
            'exact'     => ['tax' => 0.0, 'social' => 0.0, 'health' => 0.0],
            'uncertain' => ['tax' => 0.0, 'social' => 0.0, 'health' => 0.0],
        ];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bucket = ((string) $r['match_confidence']) === 'uncertain' ? 'uncertain' : 'exact';
            $kind = (string) $r['advance_kind'];
            if (!isset($out[$bucket][$kind])) {
                continue;
            }
            $out[$bucket][$kind] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function cast(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'supplier_id' => (int) $r['supplier_id'],
            'taxpayer_type' => (string) $r['taxpayer_type'],
            'advance_kind' => (string) $r['advance_kind'],
            'period_year' => (int) $r['period_year'],
            'seq_no' => (int) $r['seq_no'],
            'amount' => round((float) $r['amount'], 2),
            'due_date' => (string) $r['due_date'],
            'variable_symbol' => $r['variable_symbol'] === null ? null : (string) $r['variable_symbol'],
            'status' => (string) $r['status'],
            'paid_amount' => round((float) $r['paid_amount'], 2),
            'paid_on' => $r['paid_on'] === null ? null : (string) $r['paid_on'],
            'matched_transaction_id' => $r['matched_transaction_id'] === null ? null : (int) $r['matched_transaction_id'],
            'match_confidence' => ((string) ($r['match_confidence'] ?? 'exact')) === 'uncertain' ? 'uncertain' : 'exact',
            'paid_source' => ((string) ($r['paid_source'] ?? 'bank')) === 'manual' ? 'manual' : 'bank',
            'source_return_id' => $r['source_return_id'] === null ? null : (int) $r['source_return_id'],
        ];
    }
}
