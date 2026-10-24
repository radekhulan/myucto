<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Bank\AuthoritativeTransactionReconciler;
use MyInvoice\Service\Bank\BankStatementSource;
use MyInvoice\Service\Bank\StatementBalanceService;
use MyInvoice\Service\Migration\Shared\BankAccountRegistrar;
use MyInvoice\Service\Migration\Shared\BankStatementImportWriter;
use MyInvoice\Service\Migration\Shared\ImportedAccountCode;
use PDO;

/** Doložený počáteční stav banky je kotva výpisu, nikoli peněžní obrat. */
final class StereoNxBankOpeningWriter
{
    public function __construct(private readonly Connection $db, private readonly StereoNxImportMap $map) {}

    /**
     * Otisk zdroje i účetního důkazu sdílený s případnou výslovně schválenou opravou.
     * @param array<string,mixed> $opening @param array<array<string,mixed>> $entries
     */
    public static function fingerprint(array $opening, array $entries): string
    {
        $proof = [];
        foreach ($entries as $entry) {
            $proof[$entry['source_key']] = ['hash' => $entry['source_hash'],
                'debit' => self::account($entry, 'debit'), 'credit' => self::account($entry, 'credit')];
        }
        ksort($proof, SORT_STRING);
        return StereoNxImportMap::fingerprint([
            'movement' => StereoNxImporter::sourceHash($opening['movement']),
            'statement_key' => $opening['statement_key'], 'opening_cents' => $opening['opening_cents'],
            'account_code' => ImportedAccountCode::normalize($opening['account_code']), 'date' => $opening['date'],
            'balance_date' => $opening['balance_date'], 'entries' => $proof,
        ]);
    }

    /**
     * Vlastníkem transakce a rollbacku je převod. Nejprve se ověří celá dávka,
     * teprve potom se zapíší kotvy; původní deník se nikdy nemění.
     * @param array<string,mixed> $context @param list<array<string,mixed>> $bankOpenings
     * @param array<string,mixed> $journalPlan @return int počet nových kotev
     */
    public function write(array $context, array $bankOpenings, array $journalPlan): int
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new StereoNxException('transaction_required', 'Zápis počátečních stavů banky vyžaduje transakci převodu.');
        if ($bankOpenings === []) return 0;
        $supplier = (int) $context['supplier_id'];
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
        foreach ($bankOpenings as &$opening) $opening['account_code'] = ImportedAccountCode::normalize((string) ($opening['account_code'] ?? ''));
        unset($opening);
        $keys = []; $statementKeys = []; $entryKeys = []; $statementIds = []; $accountIds = [];
        foreach ($bankOpenings as $opening) {
            $key = (string) ($opening['movement']['source_key'] ?? '');
            $statementKey = (string) ($opening['statement_key'] ?? '');
            $statement = $context['bank_statement_records'][$statementKey] ?? null;
            $id = $context['ids']['bank_statement'][$statementKey] ?? null;
            $accountId = $context['ids']['bank_account'][$statement['account_key'] ?? ''] ?? null;
            if ($key === '' || isset($keys[$key]) || $statement === null || $id === null || $accountId === null
                || isset($statementIds[$id]) || ($opening['movement']['statement_key'] ?? null) !== $statementKey
                || ($opening['entry_keys'] ?? []) === [] || !is_int($opening['opening_cents'] ?? null)
                || $opening['opening_cents'] === 0
                || ($opening['movement']['currency'] ?? null) !== 'CZK'
                || ($opening['movement']['date'] ?? null) !== ($opening['date'] ?? null)
                || !preg_match('/^221(?:\.[0-9A-Za-z]+)?$/D', (string) ($opening['account_code'] ?? ''))) self::unverified();
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($opening['date'] ?? ''));
            if ($date === false || $date->format('Y-m-d') !== $opening['date']
                || $date->modify('-1 day')->format('Y-m-d') !== ($opening['balance_date'] ?? null)
                || StereoNxRecapAmounts::moneyCents($opening['movement']['amount'] ?? null) !== $opening['opening_cents']) self::unverified();
            $keys[$key] = true; $statementKeys[$statementKey] = true; $statementIds[(int) $id] = true; $accountIds[(int) $accountId] = true;
            foreach ($opening['entry_keys'] as $entryKey) {
                if (isset($entryKeys[$entryKey])) self::unverified();
                $entryKeys[$entryKey] = true;
            }
        }
        $legacy = $this->maps($context, 'bank', array_keys($keys));
        if ($legacy !== []) {
            throw new StereoNxException('bank_opening_repair_required', 'Počáteční stav banky už byl převzat jako pohyb. Je nutná samostatná ověřená oprava původního převodu.');
        }
        $previous = $this->maps($context, 'bank_opening', array_keys($keys));
        $statementMaps = $this->maps($context, 'bank_statement', array_keys($statementKeys));
        $statements = $this->byId('bank_statements', $supplier, array_keys($statementIds));
        $accounts = $this->byId('supplier_bank_accounts', $supplier, array_keys($accountIds));
        $this->assertNoOtherAnchors($supplier, $context, $bankOpenings, $statements);
        // Přímé i sekundární členství by udělalo z počátečního stavu běžný výpis.
        foreach (array_chunk(array_keys($statementIds), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            foreach (['bank_transactions' => 'statement_id', 'bank_transaction_imports' => 'statement_id'] as $table => $column) {
                $q = $pdo->prepare("SELECT x.{$column} FROM bank_statements bs JOIN {$table} x ON x.{$column}=bs.id
                    WHERE bs.supplier_id=? AND bs.id IN ({$marks}) FOR UPDATE");
                $q->execute([$supplier, ...$chunk]);
                if ($q->fetchColumn() !== false) self::unverified();
            }
        }
        $journalProofs = (new StereoNxOpeningJournalProof($this->db))
            ->validate($context, $bankOpenings, $journalPlan, 'bank_opening_unverified');
        $writes = [];
        foreach ($bankOpenings as $opening) {
            $key = $opening['movement']['source_key']; $statementKey = $opening['statement_key'];
            $id = (int) $context['ids']['bank_statement'][$statementKey];
            $sourceStatement = $context['bank_statement_records'][$statementKey];
            $statement = $statements[$id] ?? null;
            $account = $accounts[(int) $context['ids']['bank_account'][$sourceStatement['account_key']]] ?? null;
            $mapped = $statementMaps[$statementKey] ?? null;
            if ($statement === null || $account === null || $mapped === null || (int) $mapped['target_id'] !== $id
                || !hash_equals($mapped['source_hash'], StereoNxImporter::sourceHash($sourceStatement))
                || $statement['source'] !== 'import' || $statement['currency'] !== 'CZK' || $account['currency'] !== 'CZK'
                || $statement['credit_total'] === null || $statement['debit_total'] === null
                || StatementBalanceService::cents($statement['credit_total']) !== 0
                || StatementBalanceService::cents($statement['debit_total']) !== 0
                || (int) $statement['transaction_count'] !== 0 || (int) $statement['matched_count'] !== 0
                || BankAccountRegistrar::accountKey($statement['account_number'], (string) $statement['bank_code'])
                    !== BankAccountRegistrar::accountKey($account['account_number'], (string) $account['bank_code'])
                || ((string) ($account['analytic_suffix'] ?? '') !== '' && '221.' . $account['analytic_suffix'] !== $opening['account_code'])) self::unverified();
            $proof = $journalProofs[$key]['entries'];
            $total = $opening['opening_cents'];
            $hash = self::fingerprint($opening, $proof);
            if (isset($previous[$key])) {
                if ((int) $previous[$key]['target_id'] !== $id || !hash_equals($previous[$key]['source_hash'], $hash)
                    || $statement['statement_date'] !== $opening['balance_date']
                    || $statement['prev_balance'] === null || $statement['curr_balance'] === null
                    || StatementBalanceService::cents($statement['prev_balance']) !== $total
                    || StatementBalanceService::cents($statement['curr_balance']) !== $total) self::unverified();
                continue;
            }
            if ($statement['statement_date'] !== $sourceStatement['date'] || $statement['prev_balance'] !== null || $statement['curr_balance'] !== null) self::unverified();
            $writes[] = [$id, $key, $hash, $total, $opening['balance_date']];
        }
        $writer = new BankStatementImportWriter($this->db, 'stereo-nx');
        foreach ($writes as [$id, $key, $hash, $amount, $date]) {
            $writer->setOpeningBalanceInCents($supplier, $id, $amount, $date);
            $this->map->put($supplier, $context['ico'], $context['company_index'], 'bank_opening', $key, $hash, $id);
        }
        return count($writes);
    }

    /**
     * Nativní zůstatky sdílejí kotvu podle fyzického účtu, měny a konce dne.
     * Číslo může mít národní, GPC nebo IBAN zápis; stejnou identitu používá
     * StatementBalanceService. Datum nejprve omezíme indexem, nikoli funkcí nad účtem.
     */
    private function assertNoOtherAnchors(int $supplier, array $context, array $openings, array $statements): void
    {
        $dates = []; $expected = [];
        foreach ($openings as $opening) {
            $id = (int) $context['ids']['bank_statement'][$opening['statement_key']];
            $row = $statements[$id] ?? null;
            if ($row === null) self::unverified();
            $account = AuthoritativeTransactionReconciler::account($row['account_number'], (string) $row['bank_code']);
            if ($account === null) self::unverified();
            $key = $opening['balance_date'] . '|' . $row['currency'] . '|' . $account;
            if (isset($expected[$key]) && $expected[$key] !== $id) self::anchorConflict();
            $expected[$key] = $id;
            $dates[$opening['balance_date']] = true;
        }
        $dates = array_keys($dates);
        sort($dates, SORT_STRING);
        foreach (array_chunk($dates, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $query = $this->db->pdo()->prepare("SELECT id,source,account_number,bank_code,currency,statement_date,
                    prev_balance,(file_content IS NOT NULL) AS has_file
                FROM bank_statements FORCE INDEX (idx_bs_supplier)
                WHERE supplier_id=? AND statement_date IN ({$marks}) AND curr_balance IS NOT NULL
                  AND source IN " . BankStatementSource::sqlList() . ' ORDER BY statement_date,id FOR UPDATE');
            $query->execute([$supplier, ...$chunk]);
            while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
                // Stejná výjimka jako isAnchorRow(): bank_api musí doložit skutečný soubor i oba stavy.
                if (!BankStatementSource::isBalanceAnchor($row['source'])
                    && !($row['source'] === 'bank_api' && (bool) $row['has_file'] && $row['prev_balance'] !== null)) continue;
                $account = AuthoritativeTransactionReconciler::account($row['account_number'], (string) $row['bank_code']);
                if ($account === null) continue;
                $key = $row['statement_date'] . '|' . $row['currency'] . '|' . $account;
                if (isset($expected[$key]) && $expected[$key] !== (int) $row['id']) self::anchorConflict();
            }
        }
    }

    private static function anchorConflict(): never
    {
        throw new StereoNxException('bank_opening_conflict', 'Pro stejný bankovní účet, měnu a den už existuje jiný výpis se zůstatkem. Počáteční stav vyžaduje ruční kontrolu.');
    }

    /** @return array<string,array<string,mixed>> */
    private function maps(array $context, string $kind, array $keys): array
    {
        $out = [];
        sort($keys, SORT_STRING);
        foreach (array_chunk($keys, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db->pdo()->prepare("SELECT source_key,source_hash,target_id FROM stereo_nx_import_map
                WHERE supplier_id=? AND source_ico=? AND source_company_index=? AND kind=? AND source_key IN ({$marks}) ORDER BY source_key FOR UPDATE");
            $q->execute([$context['supplier_id'], $context['ico'], $context['company_index'], $kind, ...$chunk]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[$row['source_key']] = $row;
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function byId(string $table, int $supplier, array $ids): array
    {
        $out = [];
        sort($ids, SORT_NUMERIC);
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db->pdo()->prepare("SELECT * FROM {$table} WHERE supplier_id=? AND id IN ({$marks}) ORDER BY id FOR UPDATE");
            $q->execute([$supplier, ...$chunk]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    private static function account(array $entry, string $side): string
    {
        return StereoNxOpeningJournalProof::account($entry, $side);
    }

    private static function unverified(): never
    {
        throw new StereoNxException('bank_opening_unverified', 'Počáteční stav banky nemá nezměněný výpis a doloženou živou počáteční kontaci.');
    }
}
