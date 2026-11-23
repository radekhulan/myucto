<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Accounting\TakenOverRecord;
use PDO;

/**
 * Zpětné dorovnání stavů výpisů převzatých z jiného programu podle prvního výpisu banky.
 *
 * Převzatý výpis (`import`) nenese stav od banky: převod ho dopočítal z počátečního stavu
 * v deníku, a když zdroj počáteční stav neměl, od nuly. První výpis banky se stavem na účtu
 * ukáže skutečný rozdíl. Všem převzatým výpisům účtu se o něj posunou zůstatky, takže na něj
 * navazují a první z nich ukazuje skutečný počáteční stav. Pohyby ani deník se nemění.
 *
 * Dorovnává jen první potvrzený výpis účtu: další výpis banky se dorovnat nesmí, jeho rozdíl
 * znamená chybějící pohyb a ukazuje ho {@see StatementBalanceService}. Stejně tak výpis
 * s mezerou víc než jeden kalendářní měsíc za posledním převzatým, tam rozdíl mohou tvořit
 * nezaevidované pohyby mezi nimi.
 *
 * Pojistky:
 *  - převzaté výpisy různých účtů zdroje (jiná zkratka účtu v POHODĚ nebo Money S3) se stejným
 *    číslem účtu a měnou se nedorovnávají: jeden řetěz stavů z nich nevznikne (víceměnový účet
 *    banky převedený dřív celý v Kč), posun by poškodil oba (`mixed_sources`);
 *  - posun víc než {@see HOLD_LIMIT} v měně účtu u řetězu s počátečním stavem ze zdroje se bez
 *    potvrzení nepouští (`held`), potvrzení dává `api/bin/rebase-imported-statements.php`.
 *    Řetěz od nulového počátečního stavu (zdroj počáteční stav neznal) se dorovná vždy;
 *  - posun se zaeviduje (`bank_statement_rebases`) a smazání výpisu banky ho vrátí ({@see revert()}).
 */
final class ImportedStatementRebase
{
    /** Největší posun (v haléřích měny účtu), který se pustí bez potvrzení. */
    public const HOLD_LIMIT = 100000;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param bool $confirmed posun potvrdil uživatel (bez omezení {@see HOLD_LIMIT})
     * @return array{status:'rebased'|'held'|'mixed_sources', shift:float, statements:int, opening:float, sources?:list<string>}|null null = není co dorovnávat
     */
    public function rebase(int $supplierId, int $statementId, bool $confirmed = false): ?array
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $result = $this->apply($pdo, $supplierId, $statementId, $confirmed);
            if ($ownTransaction) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Vrátí posun převzatých výpisů, který způsobil výpis banky `$anchorStatementId` (volá se
     * před jeho smazáním, uvnitř transakce mazání). Výpis, který mezitím přestal být převzatý,
     * se nemění.
     *
     * @return int počet výpisů, kterým se posun vrátil
     */
    public function revert(int $supplierId, int $anchorStatementId): int
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->prepare('SELECT id, shift, statement_ids FROM bank_statement_rebases WHERE supplier_id = ? AND anchor_statement_id = ? ORDER BY id DESC FOR UPDATE');
        $rows->execute([$supplierId, $anchorStatementId]);
        $update = $pdo->prepare("UPDATE bank_statements SET prev_balance = prev_balance - ?, curr_balance = curr_balance - ?
            WHERE id = ? AND supplier_id = ? AND source = 'import' AND prev_balance IS NOT NULL AND curr_balance IS NOT NULL");
        $delete = $pdo->prepare('DELETE FROM bank_statement_rebases WHERE id = ? AND supplier_id = ?');
        $reverted = 0;
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids = json_decode((string) $row['statement_ids'], true);
            foreach (is_array($ids) ? $ids : [] as $id) {
                $update->execute([$row['shift'], $row['shift'], (int) $id, $supplierId]);
                $reverted += $update->rowCount();
            }
            $delete->execute([(int) $row['id'], $supplierId]);
        }
        return $reverted;
    }

    /** @return array{status:'rebased'|'held'|'mixed_sources', shift:float, statements:int, opening:float, sources?:list<string>}|null */
    private function apply(PDO $pdo, int $supplierId, int $statementId, bool $confirmed): ?array
    {
        $query = $pdo->prepare("SELECT bs.id, bs.source, bs.account_number, bs.bank_code, bs.currency, bs.statement_date,
                bs.prev_balance, bs.curr_balance, (bs.file_content IS NOT NULL) AS has_file
              FROM bank_statements bs
             WHERE " . BankStatementOwnershipResolver::sql() . ' ORDER BY bs.statement_date, bs.id FOR UPDATE');
        $query->execute(BankStatementOwnershipResolver::params($supplierId));
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        $selected = null;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $statementId) $selected = $row;
        }
        if ($selected === null || $selected['curr_balance'] === null || !self::isBankConfirmed($selected)) {
            return null;
        }
        $account = AuthoritativeTransactionReconciler::account((string) $selected['account_number'], (string) $selected['bank_code']);
        if ($account === null) {
            return null;
        }

        $sameAccount = [];
        $imported = [];
        $date = substr((string) $selected['statement_date'], 0, 10);
        $last = null;
        foreach ($rows as $row) {
            if ($row['currency'] !== $selected['currency'] || !BankStatementSource::isStatement((string) $row['source'])
                || AuthoritativeTransactionReconciler::account((string) $row['account_number'], (string) $row['bank_code']) !== $account) {
                continue;
            }
            $sameAccount[] = (int) $row['id'];
            if ((int) $row['id'] !== $statementId && self::isBankConfirmed($row)) {
                return null;
            }
            if ($row['source'] === 'import' && $row['curr_balance'] !== null) {
                $imported[] = $row;
                if (substr((string) $row['statement_date'], 0, 10) <= $date) $last = $row;
            }
        }
        if ($last === null || !self::adjacent(substr((string) $last['statement_date'], 0, 10), $date)) {
            return null;
        }

        $lastDate = substr((string) $last['statement_date'], 0, 10);
        $moves = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM bank_transactions
             WHERE statement_id IN (' . implode(',', $sameAccount) . ") AND source = 'statement' AND posted_at > ? AND posted_at <= ?");
        $moves->execute([$lastDate, $date]);
        $expected = StatementBalanceService::cents($last['curr_balance']) + StatementBalanceService::cents(number_format((float) $moves->fetchColumn(), 2, '.', ''));
        $shift = StatementBalanceService::cents($selected['curr_balance']) - $expected;
        if ($shift === 0) {
            return null;
        }

        $result = [
            'status' => 'rebased',
            'shift' => (float) ($shift / 100),
            'statements' => count($imported),
            'opening' => (float) ((StatementBalanceService::cents($imported[0]['prev_balance'] ?? '0') + $shift) / 100),
        ];
        $sources = array_values(array_unique(array_filter((new TakenOverRecord($this->db))
            ->bankStatementSourceAccounts($supplierId, array_map(static fn (array $r): int => (int) $r['id'], $imported)))));
        if (count($sources) > 1) {
            return ['status' => 'mixed_sources', 'sources' => $sources] + $result;
        }
        if (!$confirmed && abs($shift) > self::HOLD_LIMIT && StatementBalanceService::cents($imported[0]['prev_balance'] ?? '0') !== 0) {
            return ['status' => 'held'] + $result;
        }

        $amount = number_format($shift / 100, 2, '.', '');
        $update = $pdo->prepare('UPDATE bank_statements SET prev_balance = prev_balance + ?, curr_balance = curr_balance + ? WHERE id = ? AND source = \'import\'');
        foreach ($imported as $row) {
            $update->execute([$amount, $amount, (int) $row['id']]);
        }
        $pdo->prepare('INSERT INTO bank_statement_rebases (supplier_id, anchor_statement_id, shift, statement_ids) VALUES (?, ?, ?, ?)')
            ->execute([$supplierId, $statementId, $amount, json_encode(array_map(static fn (array $r): int => (int) $r['id'], $imported))]);
        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function isBankConfirmed(array $row): bool
    {
        $source = (string) $row['source'];
        if ($source === 'import') {
            return false;
        }
        return BankStatementSource::isBalanceAnchor($source)
            || ($source === 'bank_api' && !empty($row['has_file']) && $row['prev_balance'] !== null && $row['curr_balance'] !== null);
    }

    private static function adjacent(string $from, string $to): bool
    {
        $months = ((int) substr($to, 0, 4) * 12 + (int) substr($to, 5, 2)) - ((int) substr($from, 0, 4) * 12 + (int) substr($from, 5, 2));
        return $months <= 1;
    }
}
