<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use PDO;

final class StatementBalanceService
{
    public function __construct(private readonly Connection $db) {}

    public function summaries(int $supplierId, array $statementIds): array
    {
        if ($statementIds === []) return [];
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $byId = [];
            $groups = [];
            $statements = $this->loadStatements($supplierId);
            $adviceMemberships = $this->adviceMemberships($statements);
            foreach ($statements as $row) {
                $account = AuthoritativeTransactionReconciler::account((string) $row['account_number'], (string) $row['bank_code']);
                if ($account === null || !BankStatementSource::isStatement((string) $row['source'])) continue;
                $key = json_encode([$account, $row['currency']], JSON_THROW_ON_ERROR);
                $byId[(int) $row['id']] = ['row' => $row, 'key' => $key];
                $groups[$key][] = $row;
            }
            $transactions = [];
            $results = [];
            $periods = [];
            foreach ($statementIds as $id) {
                $selected = $byId[$id] ?? null;
                if ($selected === null) continue;
                $key = $selected['key'];
                $to = substr((string) $selected['row']['statement_date'], 0, 10);
                $from = substr($to, 0, 7) . '-01';
                $periods[$key] = [
                    'from' => min($periods[$key]['from'] ?? $from, $from),
                    'to' => max($periods[$key]['to'] ?? $to, $to),
                ];
            }
            foreach ($statementIds as $id) {
                $selected = $byId[$id] ?? null;
                if ($selected === null) {
                    $results[$id] = ['status' => 'unavailable'];
                    continue;
                }
                $key = $selected['key'];
                if (!isset($transactions[$key])) {
                    $ids = array_column($groups[$key], 'id');
                    $query = $pdo->prepare("SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount, bt.currency
                        FROM bank_transactions bt WHERE bt.statement_id IN (" . implode(',', array_map('intval', $ids)) . ")
                        AND bt.source = 'statement' AND bt.posted_at > ? AND bt.posted_at <= ? ORDER BY bt.posted_at, bt.id");
                    $query->execute([self::transactionLowerBound($groups[$key], $periods[$key]['from']), $periods[$key]['to']]);
                    $transactions[$key] = $query->fetchAll(PDO::FETCH_ASSOC);
                }
                try {
                    $calculation = $this->calculateSnapshot(
                        $selected['row'],
                        $groups[$key],
                        $transactions[$key],
                        $adviceMemberships,
                    );
                    $calculation['transaction_count'] = count($calculation['transactions']);
                    unset($calculation['transactions']);
                    $results[$id] = $calculation;
                } catch (\InvalidArgumentException) {
                    $results[$id] = ['status' => 'unavailable'];
                }
            }
            if ($ownTransaction) $pdo->commit();
            return $results;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function summary(int $supplierId, int $statementId): array
    {
        try {
            $calculation = $this->snapshot($supplierId, $statementId);
            $calculation['transaction_count'] = count($calculation['transactions']);
            unset($calculation['transactions']);
            return $calculation;
        } catch (\InvalidArgumentException) {
            return ['status' => 'unavailable'];
        }
    }

    public function snapshot(int $supplierId, int $statementId): array
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $result = $this->readSnapshot($supplierId, $statementId);
            if ($ownTransaction) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function loadStatements(int $supplierId): array
    {
        $query = $this->db->pdo()->prepare("SELECT bs.id, bs.source, bs.account_number, bs.bank_code, bs.currency,
            bs.statement_date, bs.prev_balance, bs.credit_total, bs.debit_total, bs.curr_balance,
            (bs.pdf_content IS NOT NULL) AS has_pdf, (bs.file_content IS NOT NULL) AS has_file,
            CASE WHEN bs.source = 'bank_api' AND bs.file_name LIKE 'csob-advice-%.json' AND JSON_VALID(bs.file_content)
                 THEN JSON_UNQUOTE(JSON_EXTRACT(bs.file_content, '$.advice.reference')) END AS advice_reference,
            CASE WHEN bs.source = 'bank_api' AND bs.file_name LIKE 'csob-advice-%.json' AND JSON_VALID(bs.file_content)
                 THEN JSON_UNQUOTE(JSON_EXTRACT(bs.file_content, '$.advice.booked_on')) END AS advice_booked_on,
            CASE WHEN bs.source = 'bank_api' AND bs.file_name LIKE 'csob-advice-%.json' AND JSON_VALID(bs.file_content)
                 THEN JSON_UNQUOTE(JSON_EXTRACT(bs.file_content, '$.advice.balance')) END AS advice_balance,
            ((bs.source = 'bank_api' AND bs.file_name LIKE 'csob-advice-%.json') OR EXISTS (
                SELECT 1 FROM bank_api_evidence_months advice_link
                JOIN bank_statements advice ON advice.id = advice_link.evidence_statement_id
                WHERE advice_link.monthly_statement_id = bs.id
                  AND advice.source = 'bank_api'
                  AND advice.file_name LIKE 'csob-advice-%.json'
            )) AS has_csob_advice
            FROM bank_statements bs WHERE " . BankStatementOwnershipResolver::sql() . ' ORDER BY bs.statement_date, bs.id');
        $query->execute(BankStatementOwnershipResolver::params($supplierId));
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function adviceMemberships(array $statements): array
    {
        $adviceByStatement = [];
        foreach ($statements as $row) {
            $advice = self::advice($row);
            if ($advice !== null) $adviceByStatement[(int) $row['id']] = $advice;
        }
        if ($adviceByStatement === []) return [];

        $ids = implode(',', array_keys($adviceByStatement));
        $query = $this->db->pdo()->query(
            "SELECT bt.statement_id AS evidence_statement_id, bt.id AS transaction_id
               FROM bank_transactions bt
              WHERE bt.statement_id IN ($ids)
              UNION ALL
             SELECT bti.statement_id AS evidence_statement_id, bti.bank_transaction_id AS transaction_id
               FROM bank_transaction_imports bti
               JOIN bank_statements evidence ON evidence.id = bti.statement_id
               JOIN bank_transactions bt ON bt.id = bti.bank_transaction_id
               JOIN bank_statements original ON original.id = bt.statement_id
              WHERE bti.statement_id IN ($ids)
                AND evidence.supplier_id = original.supplier_id"
        );
        $memberships = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $advice = $adviceByStatement[(int) $row['evidence_statement_id']];
            $transactionId = (int) $row['transaction_id'];
            if (!isset($memberships[$transactionId]) || self::compareAdvice($advice, $memberships[$transactionId]) < 0) {
                $memberships[$transactionId] = $advice;
            }
        }
        return $memberships;
    }

    private static function advice(array $row): ?array
    {
        $reference = trim((string) ($row['advice_reference'] ?? ''));
        $bookedOn = trim((string) ($row['advice_booked_on'] ?? ''));
        if (preg_match('/^(\d{8})(\d{6})$/D', $reference, $match) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $bookedOn) !== 1) {
            return null;
        }
        $referenceDate = \DateTimeImmutable::createFromFormat('!Ymd', $match[1]);
        $bookedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $bookedOn);
        if ($referenceDate === false || $referenceDate->format('Ymd') !== $match[1]
            || $bookedDate === false || $bookedDate->format('Y-m-d') !== $bookedOn) {
            return null;
        }
        $sequence = ltrim($match[2], '0');
        $balance = $row['advice_balance'] ?? null;
        try {
            $balance = $balance === null || $balance === '' || $balance === 'null'
                ? null
                : self::cents($balance);
        } catch (\InvalidArgumentException) {
            $balance = null;
        }
        return [
            'reference' => $reference,
            'reference_date' => $referenceDate->format('Y-m-d'),
            'booked_on' => $bookedOn,
            'sequence' => $sequence === '' ? '0' : $sequence,
            'balance' => $balance,
        ];
    }

    private static function compareAdvice(array $left, array $right): int
    {
        $date = $left['reference_date'] <=> $right['reference_date'];
        if ($date !== 0) return $date;
        $leftSequence = (string) $left['sequence'];
        $rightSequence = (string) $right['sequence'];
        return strlen($leftSequence) <=> strlen($rightSequence)
            ?: ($leftSequence <=> $rightSequence);
    }

    private static function transactionLowerBound(array $statements, string $from): string
    {
        $anchorDate = null;
        foreach ($statements as $row) {
            $date = substr((string) $row['statement_date'], 0, 10);
            if ($date >= $from) break;
            if (self::isAnchorRow($row) && $row['curr_balance'] !== null) $anchorDate = $date;
        }
        return $anchorDate ?? '1000-01-01';
    }

    /**
     * Je tenhle řádek doložený stav účtu, kterým se smí kotvit zůstatek?
     *
     * Vedle zdrojů, které jsou kotvou vždycky ({@see BankStatementSource::isBalanceAnchor()}),
     * sem patří i STAŽENÝ výpis z bankovního API, který nese OBA zůstatky. Strojový feed
     * obvykle vrací jen přírůstek pohybů bez zůstatků — proto `bank_api` mezi zdroji-kotvami
     * není. Když ale konektor předá skutečný výpis banky se zůstatky (KB Plus, ČSOB), je to
     * stejně doložený stav jako ručně nahrané GPC. Bez toho nemá nově napojený účet čím
     * kotvit: zůstatky se sice v řádku ukládají, ale nepoužily se, takže měsíc zůstal
     * `missing_anchor` a export GPC navždy zablokovaný.
     *
     * Měsíční projekce zůstatky ani soubor sama nemá. Doloží je až přiložené PDF výpisu,
     * jehož zůstatky přečetl parser: nese je jen tehdy, viz
     * {@see \MyInvoice\Action\Bank\BankStatementAction::attachParsedPdf()}.
     *
     * @param array<string,mixed> $row
     */
    private static function isAnchorRow(array $row): bool
    {
        if (BankStatementSource::isBalanceAnchor((string) $row['source'])) return true;
        return (string) $row['source'] === 'bank_api'
            && (!empty($row['has_file']) || !empty($row['has_pdf']))
            && $row['prev_balance'] !== null
            && $row['curr_balance'] !== null;
    }

    /**
     * Leží v měsíci pohyb PŘED tímhle výpisem, který výpis sám nepokrývá?
     *
     * Počáteční zůstatek výpisu platí k začátku JEHO období, ne nutně k prvnímu dni
     * měsíce. Vlastní pohyby výpisu jsou v pořádku — leží uvnitř jeho období. Cizí pohyb
     * před ním ale znamená, že výpis začíná až uvnitř měsíce, takže jeho počáteční
     * zůstatek ty dřívější pohyby UŽ OBSAHUJE. Otevřít jím měsíc znamená započítat je
     * dvakrát: přesně tak vznikl počáteční zůstatek 10 000 Kč u nově napojeného účtu,
     * který reálně začínal na nule. Radši zůstaneme bez kotvy než s tiše posunutým
     * počátečním stavem.
     *
     * @param list<array<string,mixed>> $transactions
     */
    private static function hasForeignMovementBefore(array $transactions, string $from, string $date, int $statementId): bool
    {
        foreach ($transactions as $row) {
            $posted = substr((string) $row['posted_at'], 0, 10);
            if ($posted < $from || $posted >= $date) continue;
            if ((int) ($row['statement_id'] ?? 0) !== $statementId) return true;
        }
        return false;
    }

    private function readSnapshot(int $supplierId, int $statementId): array
    {
        $statements = $this->loadStatements($supplierId);
        $selected = null;
        foreach ($statements as $row) if ((int) $row['id'] === $statementId) $selected = $row;
        if ($selected === null) throw new \InvalidArgumentException('statement_not_found');
        $key = AuthoritativeTransactionReconciler::account((string) $selected['account_number'], (string) $selected['bank_code']);
        if ($key === null || !BankStatementSource::isStatement((string) $selected['source'])) {
            throw new \InvalidArgumentException('gpc_account_unsupported');
        }
        $statements = array_values(array_filter($statements, static fn (array $row): bool =>
            $row['currency'] === $selected['currency'] && BankStatementSource::isStatement((string) $row['source'])
            && AuthoritativeTransactionReconciler::account((string) $row['account_number'], (string) $row['bank_code']) === $key));
        return $this->calculateSnapshot($selected, $statements, null, $this->adviceMemberships($statements));
    }

    private function calculateSnapshot(
        array $selected,
        array $statements,
        ?array $preloadedTransactions = null,
        array $adviceMemberships = [],
    ): array
    {
        $key = AuthoritativeTransactionReconciler::account((string) $selected['account_number'], (string) $selected['bank_code']);
        $to = substr((string) $selected['statement_date'], 0, 10);
        $from = substr($to, 0, 7) . '-01';
        $anchorDate = null;
        $anchor = null;
        $confirmed = null;
        $conflict = false;
        $checkpoints = [];
        $unverifiedPdf = false;
        $hasKnownBalance = false;
        // Existoval účet v evidenci UŽ PŘED tímhle měsícem? Když ne, jeho historie tímhle
        // měsícem začíná a měsíc se otevírá na nule — a to i tehdy, když nějaký zůstatek
        // známe zevnitř měsíce. Přesně tak vypadá první výpis po napojení účtu: banka
        // pošle výpis za JEDEN den uprostřed měsíce, jeho počáteční zůstatek je stav
        // uprostřed měsíce (ne na začátku), a bez tohohle rozlišení zůstal měsíc bez kotvy
        // a export GPC zablokovaný navždy.
        $historyBefore = false;
        $latestBalanceDate = null;
        foreach ($statements as $row) {
            $date = substr($row['statement_date'], 0, 10);
            if ($date > $to) continue;
            if ($row['curr_balance'] !== null || $row['prev_balance'] !== null) $hasKnownBalance = true;
            if ($date < $from) $historyBefore = true;
            // PDF bez přečtených zůstatků nejde s pohyby porovnat. Přečtené je kontrolní bod níže.
            if ($row['source'] === 'bank_api' && $row['has_pdf'] && $row['curr_balance'] === null && $date >= $from) $unverifiedPdf = true;
            if (!self::isAnchorRow($row) || $row['curr_balance'] === null) continue;
            $latestBalanceDate = $date;
            $balance = self::cents($row['curr_balance']);
            // Obraty měsíční projekce počítáme z pohybů, nejsou údajem banky. Rozdíl proti
            // zůstatkům z PDF je chybějící pohyb a má se ukázat jako rozdíl, ne jako konflikt.
            $ownTotals = $row['source'] !== 'bank_api' || !empty($row['has_file']);
            if ($ownTotals && $row['prev_balance'] !== null && $row['credit_total'] !== null && $row['debit_total'] !== null
                && self::cents($row['prev_balance']) + self::cents($row['credit_total']) - self::cents($row['debit_total']) !== $balance) {
                throw new \InvalidArgumentException('balance_conflict');
            }
            if ($date >= $from) {
                if (isset($checkpoints[$date]) && $checkpoints[$date] !== $balance) throw new \InvalidArgumentException('balance_conflict');
                $checkpoints[$date] = $balance;
            }
            if ($date < $from) {
                if ($date === $anchorDate && $anchor !== $balance) $conflict = true;
                if ($date !== $anchorDate) $conflict = false;
                $anchorDate = $date;
                $anchor = $balance;
            }
            if ($date === $to) {
                if ($confirmed !== null && $confirmed !== $balance) throw new \InvalidArgumentException('balance_conflict');
                $confirmed = $balance;
            }
        }
        if ($conflict) throw new \InvalidArgumentException('balance_conflict');
        $after = self::transactionLowerBound($statements, $from);
        if ($preloadedTransactions === null) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $statements);
            $tx = $this->db->pdo()->prepare("SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount, bt.currency, bt.bank_ref,
            bt.variable_symbol, bt.constant_symbol, bt.specific_symbol, bt.counterparty_account,
            bt.counterparty_bank, bt.counterparty_name, bt.description
            FROM bank_transactions bt WHERE bt.statement_id IN (" . implode(',', $ids) . ")
            AND bt.source = 'statement' AND bt.posted_at > ? AND bt.posted_at <= ? ORDER BY bt.posted_at, bt.id");
            $tx->execute([$after, $to]);
            $preloadedTransactions = $tx->fetchAll(PDO::FETCH_ASSOC);
        }
        // Bez stavu z dřívějška: historie účtu začíná prvním výpisem se stavem. Leží-li v tomto
        // měsíci, počáteční stav nese on sám (typicky první výpis převzatý z jiného programu).
        $firstOpening = null;
        if ($anchor === null) {
            foreach ($statements as $row) {
                if (!self::isAnchorRow($row) || $row['prev_balance'] === null) continue;
                $date = substr((string) $row['statement_date'], 0, 10);
                if ($date >= $from && $date <= $to
                    && !self::hasForeignMovementBefore($preloadedTransactions, $from, $date, (int) $row['id'])) {
                    $firstOpening = self::cents($row['prev_balance']);
                }
                break;
            }
        }
        $opening = $anchor ?? $firstOpening ?? (
            $selected['source'] === 'bank_api' && (!$hasKnownBalance || !$historyBefore)
                && empty($selected['has_csob_advice']) ? 0 : null
        );
        $adviceCheckpoint = null;
        if ($confirmed === null && !empty($selected['has_csob_advice'])) {
            foreach ($statements as $row) {
                $advice = self::advice($row);
                if ($advice === null || $advice['balance'] === null || $advice['booked_on'] > $to) continue;
                if ($latestBalanceDate !== null && $advice['booked_on'] <= $latestBalanceDate) continue;
                $comparison = $adviceCheckpoint === null ? 1 : self::compareAdvice($advice, $adviceCheckpoint);
                if ($comparison === 0 && $advice['balance'] !== $adviceCheckpoint['balance']) {
                    throw new \InvalidArgumentException('balance_conflict');
                }
                if ($comparison > 0) {
                    $adviceCheckpoint = $advice;
                }
            }
        }
        $credit = 0;
        $debit = 0;
        $transactions = [];
        foreach ($preloadedTransactions as $row) {
            if ($row['posted_at'] <= $after) continue;
            if ($row['posted_at'] > $to) break;
            if ($row['currency'] !== null && $row['currency'] !== '' && $row['currency'] !== $selected['currency']) {
                throw new \InvalidArgumentException('gpc_currency_mismatch');
            }
            $amount = self::cents($row['amount']);
            if ($row['posted_at'] < $from) {
                if ($opening !== null) $opening += $amount;
                continue;
            }
            if ($amount >= 0) $credit += $amount; else $debit -= $amount;
            $transactions[] = $row;
        }
        $unexplained = null;
        $closing = $opening === null ? null : $opening + $credit - $debit;
        if ($closing === null && $confirmed !== null && !empty($selected['has_csob_advice'])) {
            $closing = $confirmed;
        } elseif ($adviceCheckpoint !== null) {
            $adviceClosing = $adviceCheckpoint['balance'];
            foreach ($preloadedTransactions as $row) {
                if ($row['posted_at'] < $adviceCheckpoint['booked_on']) continue;
                if ($row['posted_at'] > $to) break;
                if ($row['posted_at'] > $adviceCheckpoint['booked_on']) {
                    $adviceClosing += self::cents($row['amount']);
                    continue;
                }
                $membership = $adviceMemberships[(int) $row['id']] ?? null;
                if ($membership === null) {
                    $adviceClosing = null;
                    break;
                }
                if (self::compareAdvice($membership, $adviceCheckpoint) > 0) {
                    $adviceClosing += self::cents($row['amount']);
                }
            }
            // Banka v avízu hlásí svůj vlastní zůstatek. Liší-li se od zůstatku
            // dopočteného z evidovaných pohybů, něco v evidenci chybí (nebo přebývá) —
            // typicky pohyb, který se nenačetl. Dřív se rozdíl jen tiše spolkl
            // zahozením počátečního stavu a výpis tvářil, že je vše v pořádku.
            if ($closing !== null && $adviceClosing !== null && $closing !== $adviceClosing) {
                $unexplained = $adviceClosing - $closing;
            }
            if ($closing !== $adviceClosing) $opening = null;
            $closing = $adviceClosing;
        }
        $difference = $closing === null || $confirmed === null ? null : $confirmed - $closing;
        $checkpointMismatch = false;
        if ($opening !== null) {
            foreach ($checkpoints as $date => $balance) {
                $calculated = $opening;
                foreach ($transactions as $row) {
                    if ($row['posted_at'] <= $date) $calculated += self::cents($row['amount']);
                }
                if ($calculated !== $balance) {
                    $checkpointMismatch = true;
                    $difference = $balance - $calculated;
                    break;
                }
            }
        }
        $bankStatementId = null;
        if ($transactions !== []) {
            $transactionIds = array_map(static fn (array $row): int => (int) $row['id'], $transactions);
            foreach ($statements as $row) {
                if ($row['source'] !== 'gpc' || !$row['has_file'] || $row['curr_balance'] === null
                    || $row['statement_date'] < $to || substr($row['statement_date'], 0, 7) !== substr($to, 0, 7)) continue;
                $covered = $this->db->pdo()->query('SELECT COUNT(*) FROM bank_transactions bt WHERE bt.id IN ('
                    . implode(',', $transactionIds) . ') AND ' . StatementTransactionScope::sql((int) $row['id']))->fetchColumn();
                if ((int) $covered === count($transactionIds)) $bankStatementId = (int) $row['id'];
            }
        }
        if ($unverifiedPdf && $bankStatementId === null) throw new \InvalidArgumentException('balance_pdf_unverified');
        return ['account_number' => substr($key, 5), 'bank_code' => substr($key, 0, 4), 'currency' => $selected['currency'],
            'from' => $from, 'to' => $to, 'anchor_date' => $anchorDate,
            'opening' => $opening === null ? null : $opening / 100, 'closing' => $closing === null ? null : $closing / 100,
            'credit' => $credit / 100, 'debit' => $debit / 100,
            'confirmed_closing' => $confirmed === null ? null : $confirmed / 100,
            'bank_statement_id' => $bankStatementId,
            'difference' => $difference === null ? null : $difference / 100,
            'unexplained_difference' => $unexplained === null ? null : $unexplained / 100,
            'status' => $closing === null ? 'missing_anchor' : ($checkpointMismatch ? 'mismatch' : ($confirmed === null ? 'calculated' : 'confirmed')),
            'transactions' => $transactions];
    }

    public static function cents(mixed $value): int
    {
        if (!is_numeric($value) || !preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/D', (string) $value, $m)) {
            throw new \InvalidArgumentException('gpc_amount_invalid');
        }
        return ($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0'));
    }
}
