<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Repository\PostingRuleRepository;
use PDO;

final class MoneyS3AdvanceAccounts
{
    private const RULES = [
        '324' => [
            'advance.received.collection' => 'credit_account_code',
            'advance.received.settlement' => 'debit_account_code',
            'advance.received.vatdocument' => 'debit_account_code',
        ],
        '314' => [
            'advance.paid.payment' => 'debit_account_code',
            'advance.paid.settlement' => 'credit_account_code',
            'advance.paid.vatdocument' => 'credit_account_code',
        ],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly PostingRuleRepository $rules,
        private readonly MoneyS3ImportRepository $imports,
    ) {}

    /**
     * Předkontace hlavičky (`PredKontac` → `UcPrKont.Zkrat`) se vyhodnotí po dokladech.
     * `Neuctovat` zálohu nevylučuje: předkontace slouží k volbě účtu zálohy a samotný
     * doklad typicky zápis nemá. Při více účtech požadujeme aspoň dvě třetiny dokladů.
     * Deník použijeme jen ve směru bez použitelné předkontace a jen při jediném účtu.
     *
     * @param list<string>|null $onlyDirs názvy převáděných adresářů
     * @return array<int,array{account:?string,candidates:list<string>,source:string}>
     */
    public static function detect(Ms3Backup $backup, ?array $onlyDirs = null): array
    {
        $posting = [];
        foreach ($backup->rowsAcrossYears('UcPrKont', ['Zkrat', 'UcMD', 'UcD'], $onlyDirs) as $row) {
            $posting[$row['__dir']][trim((string) ($row['Zkrat'] ?? ''))] = $row;
        }

        $result = [];
        foreach (['324' => ['VFaktury', 'UcD'], '314' => ['PFaktury', 'UcMD']] as $root => [$table, $side]) {
            $votes = [];
            foreach ($backup->rowsAcrossYears($table, ['Druh', 'PredKontac', 'Storno', 'Dobropis'], $onlyDirs) as $row) {
                $key = trim((string) ($row['PredKontac'] ?? ''));
                if ($key === '' || !InvoiceImporter::isAdvance((string) ($row['Druh'] ?? ''))
                    || !empty($row['Storno']) || !empty($row['Dobropis'])) {
                    continue;
                }
                $raw = trim((string) ($posting[$row['__dir']][$key][$side] ?? ''));
                if (strlen($raw) > 3 && AccountCode::synthetic($raw) === (string) $root) {
                    $code = AccountCode::fromMoney($raw);
                    $votes[$code] = ($votes[$code] ?? 0) + 1;
                }
            }
            if ($votes !== []) {
                arsort($votes, SORT_NUMERIC);
                $account = reset($votes) * 3 >= array_sum($votes) * 2 ? array_key_first($votes) : null;
                $result[$root] = ['account' => $account, 'candidates' => array_keys($votes), 'source' => 'documents'];
            }
        }

        if (count($result) === count(self::RULES)) {
            return $result;
        }
        $candidates = [];
        foreach ($backup->rowsAcrossYears('UcDenik', ['Zdroj', 'UcMD', 'UcD', 'Castka'], $onlyDirs) as $row) {
            if (!in_array(Ms3Journal::sourceType((string) ($row['Zdroj'] ?? '')), ['bank', 'cash'], true)) {
                continue;
            }
            $effect = Ms3Journal::effect($row);
            if ($effect === null) {
                continue;
            }
            foreach (['324' => ['credit', 'debit'], '314' => ['debit', 'credit']] as $root => [$advanceSide, $moneySide]) {
                $raw = $effect[$advanceSide];
                if (!isset($result[$root]) && AccountCode::synthetic($raw) === (string) $root
                    && in_array(AccountCode::synthetic($effect[$moneySide]), ['211', '221'], true)) {
                    $candidates[$root][AccountCode::fromMoney($raw)] = true;
                }
            }
        }
        return $result + self::journalDetection($candidates);
    }

    public function run(?Ms3Backup $backup, int $supplierId, bool $commit = false): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        $ico = $backup === null ? '' : CodebookImporter::ico(($backup->agendaCompany()['ico'] ?? '') ?: ($backup->agendaInfoIni()['ico'] ?? ''));
        if ($supplier === false || $supplier['accounting_mode'] !== 'double_entry'
            || ($ico !== '' && $ico !== CodebookImporter::ico((string) $supplier['ic']))) {
            throw new MoneyS3Exception('advance_accounts_supplier_mismatch', 'Firma musí vést podvojné účetnictví a případná záloha musí patřit téže firmě.');
        }

        if ($commit && !$this->imports->acquireLock($supplierId)) {
            throw new MoneyS3Exception('advance_accounts_locked', 'Pro tuto firmu už běží převod nebo doplnění předkontací Money S3.');
        }
        try {
            $detected = $backup === null ? $this->detectImportedJournal($supplierId) : self::detect($backup);
            return $this->prefill($detected, $supplierId, $commit);
        } finally {
            if ($commit) {
                $this->imports->releaseLock($supplierId);
            }
        }
    }

    public function prefill(array $detected, int $supplierId, bool $commit): array
    {
        $result = self::plan($detected, $this->accounts->codeToIdMap($supplierId),
            $this->rules->effectiveMap($supplierId, 'advance.'), $this->rules->listOverrides($supplierId, 'advance.'));
        if ($commit) {
            $this->rules->upsertOverrides($supplierId, $result['rules'], replace: false);
        }
        return $result;
    }

    public static function plan(array $detected, array $chart, array $effective, array $overrides = []): array
    {
        $result = ['rules' => [], 'current' => [], 'warnings' => []];
        foreach (self::RULES as $root => $keys) {
            $evidence = $detected[$root] ?? null;
            $account = $evidence['account'] ?? null;
            if ($evidence !== null && $account === null) {
                $source = $evidence['source'];
                $accounts = implode(', ', $evidence['candidates']);
                $where = $source === 'documents' ? 'předkontace zálohových faktur' : 'platby v deníku';
                $result['warnings'][] = [
                    'code' => 'advance_accounts_ambiguous_' . $source,
                    'text' => "Předkontace záloh na účtu {$root} nebyly doplněny. Podle zdroje ({$where}) připadají v úvahu účty {$accounts}.",
                    'context' => ['root' => $root, 'accounts' => $accounts],
                ];
            } elseif ($account !== null && empty($chart[$account]['is_active'])) {
                $result['warnings'][] = [
                    'code' => 'advance_accounts_unavailable',
                    'text' => "Předkontace záloh na účtu {$root} nebyly doplněny. Účet {$account} chybí v účtové osnově firmy nebo je neaktivní.",
                    'context' => ['root' => $root, 'account' => $account],
                ];
                $account = null;
            }
            foreach ($keys as $key => $side) {
                $rule = $effective[$key] ?? null;
                array_push($result['current'], ...($overrides[$key] ?? []));
                if ($rule !== null && ($rule['supplier_id'] === null || !isset($overrides[$key]))) {
                    $result['current'][] = $rule;
                }
                if ($account === null || $rule === null || isset($overrides[$key]) || $rule['supplier_id'] !== null
                    || $rule[$side] === $account) {
                    continue;
                }
                $result['rules'][] = [
                    'rule_key' => $key,
                    'debit_account_code' => $side === 'debit_account_code' ? $account : $rule['debit_account_code'],
                    'credit_account_code' => $side === 'credit_account_code' ? $account : $rule['credit_account_code'],
                    'description' => $rule['description'],
                ];
            }
        }
        return $result;
    }

    private static function journalDetection(array $candidates): array
    {
        $result = [];
        foreach ($candidates as $root => $codes) {
            $codes = array_keys($codes);
            sort($codes, SORT_STRING);
            $result[$root] = ['account' => count($codes) === 1 ? $codes[0] : null, 'candidates' => $codes, 'source' => 'journal'];
        }
        return $result;
    }

    private function detectImportedJournal(int $supplierId): array
    {
        // Money převádí jeden zdrojový řádek jako MD a Dal po sobě. U jednoduchých zápisů
        // máme i číslo dvojice. Pouhá přítomnost účtů v téže hlavičce souvztažnost nedokládá.
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT STRAIGHT_JOIN ca.account_code
               FROM chart_of_accounts ca
               JOIN journal_entry_lines a ON a.supplier_id = ca.supplier_id AND a.account_id = ca.id
               JOIN journal_entries e ON e.id = a.entry_id AND e.supplier_id = a.supplier_id
              WHERE ca.supplier_id = ?
                AND (ca.account_code LIKE '324.%' OR ca.account_code LIKE '314.%')
                AND a.side = CASE WHEN ca.account_code LIKE '324.%' THEN 'credit' ELSE 'debit' END
                AND e.source_type IN ('bank', 'cash') AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND a.is_red_storno = 0 AND a.amount > 0
                AND EXISTS (SELECT 1 FROM money_s3_import_map m
                             WHERE m.supplier_id = e.supplier_id AND m.kind = ? AND m.target_id = e.id)
                AND EXISTS (SELECT 1 FROM journal_entry_lines p
                              JOIN chart_of_accounts cash_account ON cash_account.id = p.account_id AND cash_account.supplier_id = p.supplier_id
                             WHERE p.supplier_id = a.supplier_id AND p.entry_id = a.entry_id AND p.side <> a.side
                               AND p.amount = a.amount AND p.is_red_storno = 0
                               AND (p.pair_no = a.pair_no OR (a.pair_no IS NULL AND p.pair_no IS NULL
                                 AND ((a.side = 'credit' AND p.line_no + 1 = a.line_no AND MOD(p.line_no, 2) = 1)
                                   OR (a.side = 'debit' AND a.line_no + 1 = p.line_no AND MOD(a.line_no, 2) = 1))))
                               AND (cash_account.account_code IN ('211', '221')
                                 OR cash_account.account_code LIKE '211.%' OR cash_account.account_code LIKE '221.%'))"
        );
        $stmt->execute([$supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY]);
        $candidates = [];
        while (($code = $stmt->fetchColumn()) !== false) {
            $candidates[substr($code, 0, 3)][$code] = true;
        }
        return self::journalDetection($candidates);
    }
}
