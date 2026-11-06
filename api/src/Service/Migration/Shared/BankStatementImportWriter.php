<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;

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

    /** Haléře jako částka pro DECIMAL sloupec (`12345` → `123.45`). */
    public static function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
