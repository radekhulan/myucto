<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
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
 */
final class ImportedStatementRebase
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{shift:float, statements:int, opening:float}|null null = není co dorovnávat */
    public function rebase(int $supplierId, int $statementId): ?array
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $result = $this->apply($pdo, $supplierId, $statementId);
            if ($ownTransaction) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{shift:float, statements:int, opening:float}|null */
    private function apply(PDO $pdo, int $supplierId, int $statementId): ?array
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

        $amount = number_format($shift / 100, 2, '.', '');
        $update = $pdo->prepare('UPDATE bank_statements SET prev_balance = prev_balance + ?, curr_balance = curr_balance + ? WHERE id = ? AND source = \'import\'');
        foreach ($imported as $row) {
            $update->execute([$amount, $amount, (int) $row['id']]);
        }
        return [
            'shift' => (float) ($shift / 100),
            'statements' => count($imported),
            'opening' => (float) ((StatementBalanceService::cents($imported[0]['prev_balance'] ?? '0') + $shift) / 100),
        ];
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
