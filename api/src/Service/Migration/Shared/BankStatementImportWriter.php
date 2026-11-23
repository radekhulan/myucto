<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\BankApiMonthlyStatements;
use MyInvoice\Service\Bank\StatementTransactionScope;
use PDO;

/**
 * Zápis převzatých bankovních výpisů a pohybů (zdroj výpisu `import`) z kanonického
 * řádku pohybu. Společné převodům z Money S3, POHODY, PREMIER a dalším konektorům.
 *
 * Výpis převzatý z cizího programu není bankou potvrzený originál (GPC, PDF): nese zdroj
 * `import`, syntetický název souboru `{zdroj}-{štítek}.import` a otisk
 * `sha256("{zdroj}|{firma}|{klíč výpisu}")`, pohyb otisk `sha256("{zdroj}|{firma}|{klíč pohybu}")`.
 * Klíče i hodnoty pohybu (zkrácení textů, symboly) staví zdroj; tahle třída je jen zapíše.
 *
 * Jedna instance = jeden běh importu banky (připravené dotazy se znovu používají).
 */
final class BankStatementImportWriter
{
    private ?\PDOStatement $insertStatement = null;
    private ?\PDOStatement $insertTransaction = null;
    private ?\PDOStatement $touchStatement = null;
    private ?\PDOStatement $ledgerOpening = null;

    /** @param string $source prefix názvu souboru a otisků (`money-s3`, `pohoda`, `premier`) */
    public function __construct(
        private readonly Connection $db,
        private readonly string $source,
    ) {}

    /**
     * Nový výpis bez pohybů (`transaction_count` = 0).
     *
     * @param string $key klíč výpisu v mapě převodu (do otisku)
     * @param string $label štítek výpisu: název souboru a číslo výpisu (zkráceno na 20 znaků)
     * @param string $accountNumber číslo účtu, případně kód účtu ve zdroji (zkráceno na 40 znaků)
     * @param string $bankCode kód banky; prázdný = NULL
     */
    public function createStatement(int $supplierId, string $key, string $label, string $accountNumber, string $bankCode, string $currency, string $statementDate, ?int $userId): int
    {
        $this->insertStatement ??= $this->db->pdo()->prepare(
            'INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, account_number, bank_code,
                 currency, statement_number, statement_date, transaction_count, imported_by)
             VALUES (?, "import", ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $this->insertStatement->execute([
            $supplierId,
            sprintf('%s-%s.import', $this->source, preg_replace('/[^A-Za-z0-9_-]/', '_', $label)),
            hash('sha256', $this->source . '|' . $supplierId . '|' . $key),
            mb_substr($accountNumber, 0, 40),
            $bankCode !== '' ? mb_substr($bankCode, 0, 4) : null,
            $currency,
            mb_substr($label, 0, 20),
            $statementDate,
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Pohyb výpisu (zdroj pohybu `statement`). Chybějící nepovinné údaje = NULL.
     *
     * @param string $key klíč pohybu v mapě převodu (do otisku)
     * @param array{source_ref:string,posted_at:string,amount:string,currency:string,variable_symbol?:?string,
     *     constant_symbol?:?string,specific_symbol?:?string,counterparty_account?:?string,counterparty_bank?:?string,
     *     counterparty_name?:?string,description?:?string,bank_ref:?string} $tx
     */
    public function insertTransaction(int $supplierId, int $statementId, string $key, array $tx): int
    {
        $this->insertTransaction ??= $this->db->pdo()->prepare(
            'INSERT INTO bank_transactions
                (source, source_ref, statement_id, posted_at, amount, currency, variable_symbol, constant_symbol,
                 specific_symbol, counterparty_account, counterparty_bank, counterparty_name, description, bank_ref,
                 import_fingerprint)
             VALUES ("statement", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $this->insertTransaction->execute([
            $tx['source_ref'],
            $statementId,
            $tx['posted_at'],
            $tx['amount'],
            $tx['currency'],
            $tx['variable_symbol'] ?? null,
            $tx['constant_symbol'] ?? null,
            $tx['specific_symbol'] ?? null,
            $tx['counterparty_account'] ?? null,
            $tx['counterparty_bank'] ?? null,
            $tx['counterparty_name'] ?? null,
            $tx['description'] ?? null,
            $tx['bank_ref'],
            hash('sha256', $this->source . '|' . $supplierId . '|' . $key),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Výpis dostal nové pohyby: počet a datum výpisu (nejpozdější). */
    public function touchStatement(int $supplierId, int $statementId, int $added, string $lastDate): void
    {
        $this->touchStatement ??= $this->db->pdo()->prepare(
            'UPDATE bank_statements SET transaction_count = transaction_count + ?, statement_date = GREATEST(statement_date, ?)
              WHERE id = ? AND supplier_id = ?'
        );
        $this->touchStatement->execute([$added, $lastDate, $statementId, $supplierId]);
    }

    /**
     * Stavy výpisu (částky jako řetězce s dvěma desetinnými místy).
     *
     * @param int|null $transactionCount přepsat i počet pohybů výpisu (PREMIER ho počítá celý znovu)
     */
    public function setBalances(int $supplierId, int $statementId, string $prev, string $curr, string $credit, string $debit, ?int $transactionCount = null): void
    {
        if ($transactionCount !== null) {
            $this->db->pdo()->prepare(
                'UPDATE bank_statements SET transaction_count = ?, prev_balance = ?, curr_balance = ?, credit_total = ?, debit_total = ? WHERE id = ? AND supplier_id = ?'
            )->execute([$transactionCount, $prev, $curr, $credit, $debit, $statementId, $supplierId]);
            return;
        }
        $this->db->pdo()->prepare(
            'UPDATE bank_statements SET prev_balance = ?, curr_balance = ?, credit_total = ?, debit_total = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$prev, $curr, $credit, $debit, $statementId, $supplierId]);
    }

    /** Stavy výpisu počítané v haléřích. */
    public function setBalancesInCents(int $supplierId, int $statementId, int $prev, int $curr, int $credit, int $debit): void
    {
        $this->setBalances($supplierId, $statementId, self::amount($prev), self::amount($curr), self::amount($credit), self::amount($debit));
    }

    /** Doložená kotva bez pohybů; volající ověřil prázdný výpis a drží transakci převodu. */
    public function setOpeningBalanceInCents(int $supplierId, int $statementId, int $opening, string $balanceDate): void
    {
        $this->setBalancesInCents($supplierId, $statementId, $opening, $opening, 0, 0);
        $this->db->pdo()->prepare(
            'UPDATE bank_statements SET statement_date=?, transaction_count=0, matched_count=0 WHERE supplier_id=? AND id=?'
        )->execute([$balanceDate, $supplierId, $statementId]);
    }

    /**
     * Stav účtu banky na začátku období podle otevíracího zápisu deníku (MD − D na účtu).
     * Kotva zůstatků výpisů převzatých ze zdroje, který deník vede.
     */
    public function ledgerOpening(int $supplierId, int $periodId, int $accountId): float
    {
        $this->ledgerOpening ??= $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND l.account_id = ? AND e.source_type = 'opening'"
        );
        $this->ledgerOpening->execute([$supplierId, $periodId, $accountId]);
        return (float) $this->ledgerOpening->fetchColumn();
    }

    /**
     * Pohyby účtu, které do MyÚčta přišly jinou cestou než převodem (stažené přes API banky,
     * nahraný GPC či PDF výpis), v rozsahu dat, ve kterém pohyby účtu nese zdrojový program,
     * porovnané s pohyby zdroje (`$source`: datum, částka v měně účtu, variabilní symbol).
     *
     * - `duplicates`: pohyb má ve zdroji protějšek (datum a částka, variabilní symbol, když
     *   ho nesou oba) a uživatel s ním v MyÚčtu nepracoval - převod ho nahradí pohybem
     *   zdroje ({@see deleteMovements()}),
     * - `used`: pohyb s protějškem, se kterým se pracovalo ({@see BankTransactionLinks}) -
     *   převod by ho zdvojil, volající převod zastaví s výpisem,
     * - `unmatched`: pohyb bez protějšku ve zdroji - převod ho nemaže, jen vyjmenuje.
     *
     * Účet se pozná podle čísla účtu (i s předčíslím, bez vodicích nul) nebo IBANu, kódu
     * banky a měny výpisu. Bez kódu banky a IBANu účet určí jen číslo; patří-li číslu výpisy
     * různých bank, je výsledek `ambiguous` a volající nic nemaže.
     *
     * @param list<array{0:string,1:string,2:?string}> $source pohyby zdroje: datum, částka, VS
     * @return array{duplicates:list<array<string,mixed>>,used:list<array<string,mixed>>,unmatched:list<array<string,mixed>>,ambiguous:bool}
     */
    public function foreignMovements(int $supplierId, string $accountNumber, string $bankCode, string $iban, string $currency, string $from, string $until, array $source): array
    {
        $out = ['duplicates' => [], 'used' => [], 'unmatched' => [], 'ambiguous' => false];
        $pdo = $this->db->pdo();
        $statements = $pdo->prepare(
            "SELECT id, account_number, bank_code, currency FROM bank_statements
              WHERE supplier_id = ? AND source <> 'import'"
        );
        $statements->execute([$supplierId]);
        $bank = AccountNumberNormalizer::canonicalBankCode($bankCode, $iban);
        $ids = [];
        $banks = [];
        foreach ($statements->fetchAll(PDO::FETCH_ASSOC) as $st) {
            $stBank = AccountNumberNormalizer::canonicalBankCode((string) ($st['bank_code'] ?? ''));
            if (($st['currency'] ?? null) !== null && (string) $st['currency'] !== $currency) {
                continue;
            }
            if ($bank !== null && $stBank !== null && $bank !== $stBank) {
                continue;
            }
            if (AccountNumberNormalizer::matchesAny((string) $st['account_number'], $accountNumber !== '' ? $accountNumber : null, $iban !== '' ? $iban : null)) {
                $ids[] = (int) $st['id'];
                $banks[$stBank ?? ''] = true;
            }
        }
        if ($ids === []) {
            return $out;
        }
        if ($bank === null && count(array_filter(array_keys($banks), static fn (string $b): bool => $b !== '')) > 1) {
            $out['ambiguous'] = true;
            return $out;
        }
        $pool = [];
        foreach ($source as [$date, $amount, $vs]) {
            $pool[substr($date, 0, 10) . '|' . number_format((float) $amount, 2, '.', '')][] = self::symbol($vs);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $pdo->prepare(
            "SELECT t.id, t.posted_at, t.amount, t.variable_symbol, t.counterparty_name, t.match_status,
                    " . BankTransactionLinks::linkedSql('t') . " AS used
               FROM bank_transactions t
              WHERE t.statement_id IN ({$in}) AND t.posted_at >= ? AND t.posted_at <= ?
              ORDER BY t.posted_at, t.id"
        );
        $rows->execute([$supplierId, $supplierId, $supplierId, ...$ids, $from, $until]);
        $candidates = $rows->fetchAll(PDO::FETCH_ASSOC);
        // Dva průchody: nejdřív protějšek se shodným variabilním symbolem pro všechny pohyby,
        // teprve pak záložní protějšek, u kterého symbol jedna strana nemá. Jinak by pohyb bez
        // symbolu „spotřeboval" pohyb zdroje, který patří pohybu se symbolem.
        $hits = [];
        foreach ([true, false] as $exact) {
            foreach ($candidates as $i => $r) {
                if (isset($hits[$i])) {
                    continue;
                }
                $key = substr((string) $r['posted_at'], 0, 10) . '|' . number_format((float) $r['amount'], 2, '.', '');
                $vs = self::symbol($r['variable_symbol']);
                foreach ($pool[$key] ?? [] as $j => $sourceVs) {
                    if ($exact ? ($vs !== '' && $sourceVs === $vs) : ($sourceVs === $vs || $sourceVs === '' || $vs === '')) {
                        $hits[$i] = true;
                        unset($pool[$key][$j]);
                        break;
                    }
                }
            }
        }
        foreach ($candidates as $i => $r) {
            $row = [
                'id' => (int) $r['id'], 'posted_at' => substr((string) $r['posted_at'], 0, 10), 'amount' => (string) $r['amount'],
                'variable_symbol' => $r['variable_symbol'], 'counterparty_name' => $r['counterparty_name'], 'status' => (string) $r['match_status'],
            ];
            if (!isset($hits[$i])) {
                $out['unmatched'][] = $row;
            } elseif ((int) $r['used'] === 1) {
                $out['used'][] = $row;
            } else {
                $out['duplicates'][] = $row;
            }
        }
        return $out;
    }

    /**
     * Smaže pohyby jiného zdroje, které nahradí pohyby převodu ({@see foreignMovements()}
     * `duplicates`), i se záznamy jejich načtení, a přepočte počty a obraty dotčených výpisů
     * (u měsíčních výpisů API celý souhrn měsíce). Pohyby se zamknou (`FOR UPDATE`) a smažou
     * jen ty, které ani teď nenesou vazbu ({@see BankTransactionLinks}); vazba vzniklá mezi
     * kontrolou a mazáním pohyb ponechá. Volající drží transakci převodu.
     *
     * @param list<int> $ids
     * @return list<int> smazané pohyby
     */
    public function deleteMovements(int $supplierId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $deleted = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $owned = $pdo->prepare(
                "SELECT t.id, t.statement_id FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                  WHERE s.supplier_id = ? AND t.id IN ({$in}) AND s.source <> 'import'
                    AND NOT " . BankTransactionLinks::linkedSql('t') . '
                  FOR UPDATE'
            );
            $owned->execute([$supplierId, ...$chunk, $supplierId, $supplierId, $supplierId]);
            $statementIds = [];
            $txIds = [];
            foreach ($owned->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $txIds[] = (int) $r['id'];
                $statementIds[(int) $r['statement_id']] = true;
            }
            if ($txIds === []) {
                continue;
            }
            $txIn = implode(',', array_fill(0, count($txIds), '?'));
            $imports = $pdo->prepare("SELECT DISTINCT statement_id FROM bank_transaction_imports WHERE bank_transaction_id IN ({$txIn})");
            $imports->execute($txIds);
            foreach ($imports->fetchAll(PDO::FETCH_COLUMN) as $statementId) {
                $statementIds[(int) $statementId] = true;
            }
            $pdo->prepare("DELETE FROM bank_transaction_imports WHERE bank_transaction_id IN ({$txIn})")->execute($txIds);
            $pdo->prepare("DELETE t FROM bank_transactions t WHERE t.id IN ({$txIn}) AND NOT " . BankTransactionLinks::linkedSql('t'))
                ->execute([...$txIds, $supplierId, $supplierId, $supplierId]);
            $left = $pdo->prepare("SELECT id FROM bank_transactions WHERE id IN ({$txIn})");
            $left->execute($txIds);
            $kept = array_fill_keys(array_map('intval', $left->fetchAll(PDO::FETCH_COLUMN)), true);
            foreach ($txIds as $id) {
                if (!isset($kept[$id])) {
                    $deleted[] = $id;
                }
            }

            $months = new BankApiMonthlyStatements($pdo);
            $isMonth = $pdo->prepare('SELECT 1 FROM bank_api_months WHERE statement_id = ? AND supplier_id = ?');
            $monthIds = [];
            foreach (array_keys($statementIds) as $statementId) {
                $isMonth->execute([$statementId, $supplierId]);
                if ($isMonth->fetchColumn() !== false) {
                    $monthIds[] = $statementId;
                    continue;
                }
                $pdo->prepare(
                    'UPDATE bank_statements SET transaction_count = ' . StatementTransactionScope::countSql($statementId) . ',
                            matched_count = ' . StatementTransactionScope::countSql($statementId, 'bt', "bt.match_status IN ('auto_exact', 'auto_partial', 'manual')") . '
                      WHERE id = ? AND supplier_id = ?'
                )->execute([$statementId, $supplierId]);
            }
            $months->refreshMonths($monthIds, $supplierId);
        }
        return $deleted;
    }

    /** Variabilní symbol pro porovnání: jen číslice bez vodicích nul. */
    private static function symbol(mixed $vs): string
    {
        return ltrim((string) preg_replace('/\D+/', '', (string) $vs), '0');
    }

    /** Haléře jako částka pro DECIMAL sloupec (`12345` → `123.45`). */
    public static function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
