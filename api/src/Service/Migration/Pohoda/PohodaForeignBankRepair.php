<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Migration\Shared\BankAccountRegistrar;
use PDO;

/**
 * Oprava bankovních účtů v cizí měně, které převod z POHODY dřív založil v Kč: výpisy,
 * pohyby i párování v korunách, deník analytiky účtu bez měny ({@see PohodaForeignBank}).
 *
 * Exporty POHODY už nejsou, měna účtu se proto pozná z dat: pohyby účtu v cizí měně POHODA
 * přepočítala denním kurzem ČNB, takže korunová částka pohybu je přepočtem částky v měně
 * na haléře (`ROUND(ROUND(Kč / kurz, 2) * kurz, 2) = Kč`). U korunového účtu takhle vychází
 * jen asi každý dvacátý pohyb (krok kurzu 0,24 Kč na cent eura). Doklad o více položkách
 * POHODA přepočítá po položkách, Kč pak sedí jen do několika haléřů — počítá se zvlášť.
 * Kandidátní měny dává zkratka a název účtu (FIOE, EUR, RBEU …), řádek měn firmy se stejným
 * číslem účtu a vždy EUR. Účet se převede, když:
 *  - ≥ 95 % nenulových pohybů sedí přesně nebo do haléřů položek a aspoň polovina přesně,
 *    s náznakem měny v názvu nebo v měnách firmy;
 *  - bez náznaku jen při aspoň deseti pohybech a ≥ 90 % přesné shody.
 * Cokoli jiného (nižší shoda, dvě měny, pohyby mimo převod, uzavřené období, výpisy v různých
 * měnách) se nemění a vypíše s důvodem.
 *
 * Převod účtu: pohyby dostanou částku `ROUND(Kč / kurz ČNB k datu pohybu, 2)` (kurzový rozdíl
 * v Kč bez protějšku v měně nulu), výpisy měnu a stavy navazující od počátečního stavu
 * v měně (Kč otevíracího zápisu analytiky / kurz ČNB posledního dne před obdobím; tím ruší
 * i posun stavů z dorovnání podle výpisu banky). Párování, deník analytiky (včetně otevíracího
 * zápisu) a evidence účtu se upraví jako při převodu. Korunový účet se stejným číslem
 * (víceměnový účet banky), jehož převzaté výpisy dorovnání posunulo spolu s výpisy účtu
 * v měně, dostane stavy znovu od počátečního stavu v deníku.
 *
 * Pojistky: každý účet v jedné transakci, korunové obraty analytiky v deníku se nesmí změnit
 * (kontrola po zápisu), řetěz stavů musí navazovat, převedený účet už výběru neodpovídá
 * (opakovaný běh nic nemění), každá oprava má záznam v auditní stopě. Bez `$commit` jen vypíše.
 */
final class PohodaForeignBankRepair
{
    public const AUDIT_ACTION = 'bank.pohoda_foreign_account_repaired';

    /** Měny, které převod pozná ve zkratce nebo názvu účtu. */
    private const CODES = ['EUR', 'USD', 'GBP', 'CHF', 'PLN', 'HUF', 'JPY', 'SEK', 'NOK', 'DKK', 'CAD', 'AUD', 'CNY'];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaForeignBank $foreign,
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly CnbExchangeRateClient $cnb,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $activity,
    ) {}

    /** Firmy s výpisy převzatými z POHODY. @return list<int> */
    public function pohodaSuppliers(): array
    {
        return array_map('intval', $this->db->pdo()->query(
            "SELECT DISTINCT supplier_id FROM pohoda_import_map WHERE kind = 'bank_statement' ORDER BY supplier_id"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $supplierIds
     * @return list<array<string,mixed>> řádek za účet zdroje (zkratku účtu v POHODĚ)
     */
    public function run(array $supplierIds, bool $commit, bool $fetchRates = true, ?int $userId = null): array
    {
        $out = [];
        foreach ($supplierIds as $supplierId) {
            foreach ($this->supplier($supplierId, $commit, $fetchRates, $userId) as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function supplier(int $supplierId, bool $commit, bool $fetchRates, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT m.pohoda_key, bs.id, bs.account_number, COALESCE(bs.bank_code, '') AS bank_code, COALESCE(bs.currency, 'CZK') AS currency,
                    bs.statement_date, bs.prev_balance, bs.curr_balance
               FROM pohoda_import_map m
               JOIN bank_statements bs ON bs.id = m.target_id AND bs.supplier_id = m.supplier_id AND bs.source = 'import'
              WHERE m.supplier_id = ? AND m.kind = 'bank_statement'
              ORDER BY bs.statement_date, bs.id"
        );
        $stmt->execute([$supplierId]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parts = explode('|', (string) $row['pohoda_key']);
            if (count($parts) < 4 || $parts[0] !== 'stmt') {
                continue;
            }
            $code = implode('|', array_slice($parts, 1, -2));
            $row['year'] = (int) $parts[count($parts) - 2];
            $groups[$code][] = $row;
        }
        $plans = [];
        foreach ($groups as $code => $statements) {
            // Měny, ve kterých už jsou výpisy jiného účtu zdroje se stejným číslem (víceměnový
            // účet banky): jeho řádek měn a evidence nejsou náznakem měny tohoto účtu.
            $siblings = [];
            foreach ($groups as $otherCode => $other) {
                if ($otherCode !== $code && BankAccountRegistrar::accountKey((string) $other[0]['account_number'], (string) $other[0]['bank_code'])
                    === BankAccountRegistrar::accountKey((string) $statements[0]['account_number'], (string) $statements[0]['bank_code'])) {
                    $siblings += array_fill_keys(array_column($other, 'currency'), true);
                }
            }
            $plans[$code] = $this->plan($supplierId, (string) $code, $statements, $fetchRates, array_keys(array_diff_key($siblings, ['CZK' => true])));
        }
        // Korunový účet se stejným číslem jako převáděný účet v měně: posun z dorovnání zpět.
        foreach ($plans as $code => $plan) {
            if ($plan['status'] !== 'czk') {
                continue;
            }
            foreach ($plans as $other) {
                if (in_array($other['status'], ['convert', 'already_foreign'], true)
                    && BankAccountRegistrar::accountKey($other['account_number'], $other['bank_code']) === BankAccountRegistrar::accountKey($plan['account_number'], $plan['bank_code'])) {
                    $plans[$code] = $this->rechainPlan($supplierId, $plan);
                    break;
                }
            }
        }
        foreach ($plans as $code => $plan) {
            if ($plan['status'] === 'convert') {
                $plan['shared_number'] = false;
                foreach ($plans as $otherCode => $other) {
                    $plan['shared_number'] = $plan['shared_number'] || ($otherCode !== $code && $other['currency'] === 'CZK'
                        && BankAccountRegistrar::accountKey($other['account_number'], $other['bank_code']) === BankAccountRegistrar::accountKey($plan['account_number'], $plan['bank_code']));
                }
                $plan['registration'] = (new BankAccountRegistrar($this->db, $this->bankAccounts))
                    ->adoptForeignAccount($supplierId, $this->companyAccount($supplierId, $plan), $plan['shared_number'], false);
            }
            if ($commit && in_array($plan['status'], ['convert', 'rechain'], true)) {
                $plan = $this->apply($supplierId, $plan, $userId);
            }
            $plans[$code] = $plan;
        }
        return array_values(array_map(static function (array $plan): array {
            unset($plan['tx'], $plan['chains_detail']);
            return $plan;
        }, $plans));
    }

    /**
     * @param list<array<string,mixed>> $statements výpisy jednoho účtu zdroje
     * @return array<string,mixed>
     */
    /** @param list<string> $siblingCurrencies měny výpisů jiných účtů zdroje se stejným číslem */
    private function plan(int $supplierId, string $code, array $statements, bool $fetchRates, array $siblingCurrencies = []): array
    {
        $pdo = $this->db->pdo();
        $plan = [
            'supplier_id' => $supplierId, 'code' => $code, 'status' => 'czk', 'reason' => null,
            'account_number' => (string) $statements[0]['account_number'], 'bank_code' => (string) $statements[0]['bank_code'],
            'currency' => 'CZK', 'statements' => count($statements), 'transactions' => 0, 'tested' => 0,
            'exact' => 0, 'near' => 0, 'fx_differences' => 0, 'account_id' => null, 'account_code' => null, 'chains' => [],
            'registration' => [], 'statement_ids' => array_map(static fn (array $s): int => (int) $s['id'], $statements),
        ];
        $currencies = array_values(array_unique(array_column($statements, 'currency')));
        $numbers = array_unique(array_map(static fn (array $s): string => BankAccountRegistrar::accountKey((string) $s['account_number'], (string) $s['bank_code']), $statements));
        if (count($numbers) > 1) {
            return ['status' => 'ambiguous', 'reason' => 'výpisy účtu mají různá čísla účtu'] + $plan;
        }
        if (count($currencies) > 1) {
            return ['status' => 'ambiguous', 'reason' => 'výpisy účtu jsou v různých měnách (' . implode(', ', $currencies) . ')'] + $plan;
        }
        if ($currencies[0] !== 'CZK') {
            return ['status' => 'already_foreign', 'currency' => $currencies[0], 'reason' => 'výpisy už jsou v ' . $currencies[0]] + $plan;
        }
        $in = implode(',', $plan['statement_ids']);
        $tx = [];
        $foreignRows = 0;
        foreach ($pdo->query(
            "SELECT bt.id, bt.statement_id, bt.amount, bt.posted_at, COALESCE(bt.currency, 'CZK') AS currency, bt.description,
                    EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = {$supplierId} AND m.kind = 'bank_transaction' AND m.target_id = bt.id) AS mapped
               FROM bank_transactions bt WHERE bt.statement_id IN ({$in}) ORDER BY bt.posted_at, bt.id"
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tx[(int) $row['id']] = ['czk' => (float) $row['amount'], 'date' => (string) $row['posted_at'], 'description' => (string) $row['description']];
            if ((int) $row['mapped'] !== 1 || $row['currency'] !== 'CZK') {
                $foreignRows++;
            }
        }
        $plan['transactions'] = count($tx);
        if ($tx === []) {
            return ['status' => 'czk', 'reason' => 'účet nemá pohyby'] + $plan;
        }
        [$accountId, $accountCode, $accountShare] = $this->ledgerAccount($supplierId, array_keys($tx));
        $plan['account_id'] = $accountId;
        $plan['account_code'] = $accountCode;
        $fxDifferences = $this->fxDifferences($supplierId, $accountId, $tx);
        $plan['fx_differences'] = count($fxDifferences);

        $hints = $this->hints($supplierId, $code, $plan['account_number'], $plan['bank_code']);
        foreach ($siblingCurrencies as $currency) {
            if (isset($hints[$currency]) && !str_starts_with($hints[$currency], 'název')) {
                unset($hints[$currency]);
            }
        }
        $candidates = array_values(array_unique([...array_keys($hints), 'EUR']));
        $dates = array_values(array_unique(array_column($tx, 'date')));
        $best = null;
        foreach ($candidates as $currency) {
            $rates = $this->rates($currency, $dates, $fetchRates);
            $stats = ['currency' => $currency, 'tested' => 0, 'exact' => 0, 'near' => 0, 'missing_rates' => 0, 'rates' => $rates];
            foreach ($tx as $id => $t) {
                if (abs($t['czk']) < 0.005 || isset($fxDifferences[$id])) {
                    continue;
                }
                $rate = $rates[$t['date']] ?? null;
                if ($rate === null) {
                    $stats['missing_rates']++;
                    continue;
                }
                $stats['tested']++;
                $czk = PohodaForeignBank::cents($t['czk']);
                $foreign = (int) round($czk / $rate);
                $back = (int) round($foreign * $rate);
                if ($back === $czk) {
                    $stats['exact']++;
                } elseif (abs($back - $czk) <= 5) {
                    $stats['near']++;
                }
            }
            if ($best === null || $stats['exact'] > $best['exact']) {
                $best = $stats;
            }
        }
        $plan['currency'] = $best['currency'];
        $plan['tested'] = $best['tested'];
        $plan['exact'] = $best['exact'];
        $plan['near'] = $best['near'];
        $plan['hint'] = $hints[$best['currency']] ?? null;
        $n = max(1, $best['tested']);
        $exactShare = $best['exact'] / $n;
        $matchShare = ($best['exact'] + $best['near']) / $n;
        $plan['exact_pct'] = round(100 * $exactShare, 1);
        $plan['match_pct'] = round(100 * $matchShare, 1);
        // Dva tři pohyby kurzu odpovídají i náhodou (zhruba každý dvacátý korunový pohyb).
        $looksForeign = $best['tested'] > 0 && $exactShare >= 0.5 && ($hints !== [] || $best['tested'] >= 3);
        if (!$looksForeign) {
            return ['currency' => 'CZK', 'reason' => $plan['hint'] !== null ? 'název naznačuje ' . $best['currency'] . ', ale pohyby kurzu ČNB neodpovídají' : null] + $plan;
        }
        $convertible = $plan['hint'] !== null
            ? $matchShare >= 0.95
            : $best['tested'] >= 10 && $exactShare >= 0.9 && $matchShare >= 0.95;
        $reason = null;
        if ($best['missing_rates'] > 0) {
            $reason = sprintf('chybí kurz ČNB k %d pohybům (spusťte backfill-cnb-rates.php)', $best['missing_rates']);
        } elseif (!$convertible) {
            $reason = sprintf('kurzu ČNB odpovídá jen %s %% pohybů (přesně %s %%)%s', number_format($plan['match_pct'], 1, ',', ''),
                number_format($plan['exact_pct'], 1, ',', ''), $plan['hint'] === null ? ', bez náznaku měny v názvu účtu' : '');
        } elseif ($foreignRows > 0) {
            $reason = sprintf('výpisy obsahují %d pohybů, které nezaložil převod nebo nejsou v Kč', $foreignRows);
        } elseif ($accountId === null && $this->hasJournal($supplierId)) {
            $reason = 'pohyby nemají v deníku jednoznačnou analytiku 221';
        } elseif ($accountShare < 0.9 && $accountId !== null) {
            $reason = sprintf('jen %s %% zápisů pohybů jde na %s', number_format(100 * $accountShare, 0), $accountCode);
        } elseif ($accountId !== null && ($shared = $this->sharedAccountEntries($supplierId, $accountId, array_keys($tx))) > max(2, 0.1 * count($tx))) {
            // Společná analytika více účtů: počáteční stav účtu v deníku není a měna by se
            // na analytice míchala s korunovými řádky ostatních účtů. Jednotlivý převod mezi
            // vlastními účty zaúčtovaný z korunového účtu přímo na analytiku účtu v měně
            // společnou analytikou není, jeho řádek jen zůstane v Kč.
            $reason = sprintf('analytika %s je společná s jiným bankovním účtem (%d zápisů jiných pohybů), počáteční stav v měně nelze určit', $accountCode, $shared);
        }
        $locked = $this->lockReason($supplierId, $statements, $tx);
        if ($reason === null && $locked !== null) {
            $reason = $locked;
        }
        $rates = $best['rates'];
        $plan['tx'] = [];
        foreach ($tx as $id => $t) {
            $rate = $rates[$t['date']] ?? null;
            $zero = abs($t['czk']) < 0.005 || isset($fxDifferences[$id]);
            $plan['tx'][$id] = ['czk' => $t['czk'], 'foreign' => $zero || $rate === null ? 0.0 : round($t['czk'] / $rate, 2), 'rate' => (float) $rate];
        }
        $plan['chains'] = $this->chains($supplierId, $plan, $statements, $fetchRates);
        foreach ($plan['chains'] as $chain) {
            if ($reason === null && $chain['opening_error'] !== null) {
                $reason = $chain['opening_error'];
            }
        }
        $plan['matches'] = (int) $pdo->query("SELECT COUNT(*) FROM payment_matches WHERE supplier_id = {$supplierId} AND bank_transaction_id IN (" . implode(',', array_keys($tx)) . ')')->fetchColumn();
        $plan['suggestions'] = (int) $pdo->query("SELECT COUNT(*) FROM bank_posting_suggestions WHERE supplier_id = {$supplierId} AND status IN ('pending','needs_input','blocked','rejected') AND bank_transaction_id IN (" . implode(',', array_keys($tx)) . ')')->fetchColumn();
        $plan['other_item_allocations'] = (int) $pdo->query('SELECT COUNT(*) FROM other_item_allocations WHERE bank_transaction_id IN (' . implode(',', array_keys($tx)) . ')')->fetchColumn();
        if ($reason === null && $plan['other_item_allocations'] > 0) {
            $reason = sprintf('%d úhrad ostatních položek z pohybů účtu (přepočet převod neumí, opravte ručně)', $plan['other_item_allocations']);
        }
        $plan['journal_lines'] = $accountId !== null ? $this->accountLines($supplierId, $accountId) : null;
        $plan['other_entries'] = $accountId !== null ? $this->sharedAccountEntries($supplierId, $accountId, array_keys($tx)) : 0;
        return ['status' => $reason === null ? 'convert' : 'ambiguous', 'reason' => $reason] + $plan;
    }

    /**
     * Řetězy stavů: výpisy roku agendy (klíč mapy) v pořadí data, počáteční stav v měně
     * z otevíracího zápisu analytiky v období roku.
     *
     * @param array<string,mixed> $plan
     * @param list<array<string,mixed>> $statements
     * @return list<array<string,mixed>>
     */
    private function chains(int $supplierId, array $plan, array $statements, bool $fetchRates): array
    {
        $byYear = [];
        foreach ($statements as $s) {
            $byYear[(int) $s['year']][] = $s;
        }
        ksort($byYear);
        $totals = $this->statementTotals($plan['statement_ids'], $plan['tx']);
        $out = [];
        foreach ($byYear as $year => $rows) {
            $chain = ['year' => $year, 'statement_ids' => array_map(static fn (array $s): int => (int) $s['id'], $rows),
                'period_id' => null, 'opening_czk' => null, 'opening_foreign' => null, 'opening_rate' => null,
                'opening_exact' => null, 'closing_foreign' => null, 'shift_czk' => null, 'opening_error' => null];
            $period = $this->period($supplierId, $year);
            $first = $rows[0];
            if ($plan['account_id'] !== null && $period !== null) {
                $chain['period_id'] = (int) $period['id'];
                $chain['opening_czk'] = $this->ledgerOpening($supplierId, (int) $period['id'], (int) $plan['account_id']);
                $day = (new \DateTimeImmutable((string) $period['starts_on']))->modify('-1 day')->format('Y-m-d');
            } elseif ($first['prev_balance'] !== null) {
                $chain['opening_czk'] = PohodaForeignBank::cents((float) $first['prev_balance']);
                $day = (new \DateTimeImmutable((string) ($period['starts_on'] ?? $first['statement_date'])))->modify('-1 day')->format('Y-m-d');
            }
            if ($chain['opening_czk'] !== null) {
                if ($first['prev_balance'] !== null) {
                    $chain['shift_czk'] = PohodaForeignBank::cents((float) $first['prev_balance']) - $chain['opening_czk'];
                }
                if ($chain['opening_czk'] === 0) {
                    $chain['opening_foreign'] = 0;
                } else {
                    $rate = $this->rates($plan['currency'], [$day], $fetchRates)[$day] ?? null;
                    if ($rate === null) {
                        $chain['opening_error'] = "chybí kurz ČNB k {$day} pro počáteční stav";
                    } else {
                        $chain['opening_rate'] = $rate;
                        $chain['opening_foreign'] = (int) round($chain['opening_czk'] / $rate);
                        $chain['opening_exact'] = (int) round($chain['opening_foreign'] * $rate) === $chain['opening_czk'];
                    }
                }
            }
            if ($chain['opening_foreign'] !== null) {
                $running = $chain['opening_foreign'];
                foreach ($chain['statement_ids'] as $id) {
                    $running += ($totals[$id][0] ?? 0) - ($totals[$id][1] ?? 0);
                }
                $chain['closing_foreign'] = $running;
            }
            $out[] = $chain;
        }
        return $out;
    }

    /**
     * Korunový účet se stejným číslem jako účet v měně: převzaté výpisy, jejichž stav
     * neodpovídá počátečnímu stavu analytiky v deníku (posun z dorovnání, které spojilo
     * výpisy obou účtů), dostanou stavy znovu od deníku.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function rechainPlan(int $supplierId, array $plan): array
    {
        $pdo = $this->db->pdo();
        $in = implode(',', $plan['statement_ids']);
        $ids = array_map('intval', $pdo->query("SELECT id FROM bank_transactions WHERE statement_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN));
        [$accountId, $accountCode] = $ids !== [] ? $this->ledgerAccount($supplierId, $ids) : [null, null, 0.0];
        if ($accountId === null) {
            return $plan;
        }
        $rows = $pdo->query("SELECT id, prev_balance, statement_date FROM bank_statements WHERE id IN ({$in}) ORDER BY statement_date, id")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($pdo->query("SELECT target_id, pohoda_key FROM pohoda_import_map WHERE supplier_id = {$supplierId} AND kind = 'bank_statement' AND target_id IN ({$in})")->fetchAll(PDO::FETCH_NUM) as [$id, $key]) {
            $parts = explode('|', (string) $key);
            $map[(int) $id] = (int) $parts[count($parts) - 2];
        }
        $chains = [];
        foreach ($rows as $r) {
            $chains[$map[(int) $r['id']] ?? 0][] = $r;
        }
        $plan['account_id'] = $accountId;
        $plan['account_code'] = $accountCode;
        $plan['chains'] = [];
        foreach ($chains as $year => $list) {
            $period = $this->period($supplierId, (int) $year);
            if ($period === null || $list[0]['prev_balance'] === null) {
                continue;
            }
            $opening = $this->ledgerOpening($supplierId, (int) $period['id'], $accountId);
            $shift = PohodaForeignBank::cents((float) $list[0]['prev_balance']) - $opening;
            if ($shift !== 0) {
                $plan['chains'][] = ['year' => (int) $year, 'statement_ids' => array_map(static fn (array $s): int => (int) $s['id'], $list),
                    'opening_czk' => $opening, 'shift_czk' => $shift];
            }
        }
        if ($plan['chains'] === []) {
            return $plan;
        }
        $locked = $this->lockReason($supplierId, $rows, []);
        return ['status' => $locked === null ? 'rechain' : 'ambiguous', 'reason' => $locked ?? 'stavy posunuté dorovnáním podle výpisu banky, znovu od deníku'] + $plan;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function apply(int $supplierId, array $plan, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        // Vnořené volání (testy, převod uvnitř cizí transakce) jede přes savepoint.
        $own = !$pdo->inTransaction();
        $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT pohoda_foreign_bank_repair');
        try {
            $before = $plan['account_id'] !== null ? $this->accountLines($supplierId, (int) $plan['account_id']) : null;
            $result = ['transactions' => 0, 'matches' => 0, 'journal_lines' => 0, 'opening_lines' => 0];
            if ($plan['status'] === 'rechain') {
                foreach ($plan['chains'] as $chain) {
                    $this->foreign->rechain($supplierId, $chain['statement_ids'], $chain['opening_czk']);
                }
            } else {
                $converted = $this->foreign->convertStatements($supplierId, $plan['currency'], $plan['statement_ids'], $plan['tx'], null);
                $result['transactions'] = $converted['transactions'];
                $result['matches'] = $converted['matches'];
                foreach ($plan['chains'] as $chain) {
                    $this->foreign->rechain($supplierId, $chain['statement_ids'], $chain['opening_foreign']);
                    if ($plan['account_id'] !== null && $chain['period_id'] !== null && $chain['opening_foreign'] !== null && $chain['opening_rate'] !== null
                        && $this->foreign->fillOpening($supplierId, (int) $plan['account_id'], (int) $chain['period_id'], $plan['currency'], $chain['opening_foreign'] / 100, (float) $chain['opening_rate'])) {
                        $result['opening_lines']++;
                    }
                }
                if ($plan['account_id'] !== null) {
                    $result['journal_lines'] = $this->foreign->fillJournal($supplierId, (int) $plan['account_id'], $plan['currency'],
                        array_filter($plan['tx'], static fn (array $t): bool => $t['rate'] > 0))['lines'];
                }
                $pdo->prepare("DELETE FROM bank_posting_suggestions WHERE supplier_id = ? AND status IN ('pending','needs_input','blocked','rejected')
                    AND bank_transaction_id IN (" . implode(',', array_map('intval', array_keys($plan['tx']))) . ')')->execute([$supplierId]);
                $plan['registration'] = (new BankAccountRegistrar($this->db, $this->bankAccounts))
                    ->adoptForeignAccount($supplierId, $this->companyAccount($supplierId, $plan), (bool) $plan['shared_number'], true);
            }
            if ($before !== null) {
                $after = $this->accountLines($supplierId, (int) $plan['account_id']);
                if ($after['debit'] !== $before['debit'] || $after['credit'] !== $before['credit'] || $after['lines'] !== $before['lines']) {
                    throw new \RuntimeException('Kontrola selhala: korunové obraty analytiky se změnily.');
                }
                $result['foreign_lines_after'] = $after['foreign_lines'];
            }
            $this->assertChains($supplierId, $plan['chains']);
            $this->activity->log(self::AUDIT_ACTION, $userId, 'bank_statement', $plan['statement_ids'][0], [
                'source' => 'pohoda_foreign_bank_repair',
                'mode' => $plan['status'],
                'account' => $plan['code'],
                'account_number' => $plan['account_number'] . '/' . $plan['bank_code'],
                'currency' => $plan['currency'],
                'statements' => $plan['statement_ids'],
                'chains' => array_map(static fn (array $c): array => array_intersect_key($c, array_flip(['year', 'opening_czk', 'opening_foreign', 'opening_rate', 'closing_foreign', 'shift_czk'])), $plan['chains']),
                'match_pct' => $plan['match_pct'] ?? null,
                'registration' => $plan['registration'],
            ] + $result, null, null, $supplierId);
            $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT pohoda_foreign_bank_repair');
            return ['status' => $plan['status'] === 'rechain' ? 'rechained' : 'converted', 'result' => $result] + $plan;
        } catch (\Throwable $e) {
            TransactionFailure::rollbackIfAborted($pdo, $e);
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$own && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_foreign_bank_repair');
            }
            return ['status' => 'failed', 'reason' => $e->getMessage()] + $plan;
        }
    }

    /** @param list<array<string,mixed>> $chains */
    private function assertChains(int $supplierId, array $chains): void
    {
        foreach ($chains as $chain) {
            $in = implode(',', array_map('intval', $chain['statement_ids']));
            $rows = $this->db->pdo()->query("SELECT id, prev_balance, curr_balance, credit_total, debit_total FROM bank_statements
                WHERE supplier_id = {$supplierId} AND id IN ({$in}) ORDER BY FIELD(id, {$in})")->fetchAll(PDO::FETCH_ASSOC);
            $previous = null;
            foreach ($rows as $r) {
                if ($r['prev_balance'] === null) {
                    continue;
                }
                $prev = PohodaForeignBank::cents((float) $r['prev_balance']);
                if ($previous !== null && $prev !== $previous
                    || $prev + PohodaForeignBank::cents((float) $r['credit_total']) - PohodaForeignBank::cents((float) $r['debit_total']) !== PohodaForeignBank::cents((float) $r['curr_balance'])) {
                    throw new \RuntimeException('Kontrola selhala: stavy výpisů nenavazují (výpis #' . $r['id'] . ').');
                }
                $previous = PohodaForeignBank::cents((float) $r['curr_balance']);
            }
        }
    }

    /**
     * Analytika 221, na kterou jdou zápisy pohybů účtu: nejčastější účet řádků 221 v zápisech
     * navázaných na pohyby, s podílem zápisů, které na ni jdou.
     *
     * @param list<int> $txIds
     * @return array{0:?int,1:?string,2:float}
     */
    private function ledgerAccount(int $supplierId, array $txIds): array
    {
        $counts = [];
        $entries = [];
        foreach (array_chunk($txIds, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT l.account_id, a.account_code, k.entry_id
                   FROM journal_entry_document_links k
                   JOIN journal_entry_lines l ON l.entry_id = k.entry_id AND l.supplier_id = k.supplier_id
                   JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                  WHERE k.supplier_id = ? AND k.doc_type = 'bank' AND k.doc_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                    AND a.account_code LIKE '221%'
                  GROUP BY l.account_id, a.account_code, k.entry_id"
            );
            $stmt->execute(array_merge([$supplierId], $chunk));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $counts[(int) $r['account_id']] = ['code' => (string) $r['account_code'], 'n' => ($counts[(int) $r['account_id']]['n'] ?? 0) + 1];
                $entries[(int) $r['entry_id']] = true;
            }
        }
        if ($counts === []) {
            return [null, null, 0.0];
        }
        uasort($counts, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);
        $id = (int) array_key_first($counts);
        return [$id, $counts[$id]['code'], $counts[$id]['n'] / max(1, count($entries))];
    }

    /**
     * Zápisy na analytice účtu, které patří pohybům jiného účtu (žádný z pohybů `$txIds`).
     *
     * @param list<int> $txIds
     */
    private function sharedAccountEntries(int $supplierId, int $accountId, array $txIds): int
    {
        $ids = implode(',', array_map('intval', $txIds));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(DISTINCT l.entry_id)
               FROM journal_entry_lines l
               JOIN journal_entry_document_links k ON k.entry_id = l.entry_id AND k.supplier_id = l.supplier_id AND k.doc_type = 'bank'
              WHERE l.supplier_id = ? AND l.account_id = ?
                AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links o
                                 WHERE o.supplier_id = l.supplier_id AND o.entry_id = l.entry_id AND o.doc_type = 'bank' AND o.doc_id IN ({$ids}))"
        );
        $stmt->execute([$supplierId, $accountId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Pohyby kurzového rozdílu: zápis na analytice účtu jen proti kurzovým ztrátám a ziskům
     * (563/663), nebo text uzávěrky kurzových rozdílů. V měně účtu nic nepohnou.
     *
     * @param array<int,array{czk:float,date:string,description:string}> $tx
     * @return array<int,true>
     */
    private function fxDifferences(int $supplierId, ?int $accountId, array $tx): array
    {
        $out = [];
        foreach ($tx as $id => $t) {
            if (preg_match('/kurzov\w*\s+rozd/iu', $t['description']) === 1) {
                $out[$id] = true;
            }
        }
        if ($accountId === null) {
            return $out;
        }
        foreach (array_chunk(array_keys($tx), 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT k.doc_id, SUM(a.account_code NOT LIKE '563%' AND a.account_code NOT LIKE '663%') AS other, COUNT(*) AS n
                   FROM journal_entry_document_links k
                   JOIN journal_entry_lines l ON l.entry_id = k.entry_id AND l.supplier_id = k.supplier_id AND l.account_id <> ?
                   JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                  WHERE k.supplier_id = ? AND k.doc_type = 'bank' AND k.doc_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
                  GROUP BY k.doc_id'
            );
            $stmt->execute(array_merge([$accountId, $supplierId], $chunk));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((int) $r['n'] > 0 && (int) $r['other'] === 0) {
                    $out[(int) $r['doc_id']] = true;
                }
            }
        }
        return $out;
    }

    /**
     * Náznaky měny účtu: zkratka účtu v POHODĚ a název účtu v evidenci (`FIOE`, `RBEU`, `EUR`,
     * `KB E` …) a řádek měn firmy v cizí měně se stejným číslem účtu.
     *
     * @return array<string,string> měna => odkud náznak je
     */
    private function hints(int $supplierId, string $code, string $number, string $bank): array
    {
        $out = [];
        $labels = [$code];
        $stmt = $this->db->pdo()->prepare('SELECT label, account_number, iban, currency FROM supplier_bank_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (AccountNumberNormalizer::matchesAny($number, (string) $r['account_number'], (string) ($r['iban'] ?? '') ?: null)) {
                $labels[] = (string) $r['label'];
                if (!in_array($r['currency'], [null, 'CZK'], true)) {
                    $out[(string) $r['currency']] ??= 'evidence účtu v ' . $r['currency'];
                }
            }
        }
        foreach ($labels as $label) {
            $hint = self::nameHint($label);
            if ($hint !== null) {
                $out[$hint] ??= 'název „' . $label . '“';
            }
        }
        $stmt = $this->db->pdo()->prepare('SELECT code, account_number, iban, bank_code FROM currencies WHERE supplier_id = ? AND code <> "CZK"');
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((string) ($r['account_number'] ?? '') . (string) ($r['iban'] ?? '') !== ''
                && AccountNumberNormalizer::matchesAny($number, (string) ($r['account_number'] ?? ''), (string) ($r['iban'] ?? '') ?: null)) {
                $out[(string) $r['code']] ??= 'měnový účet ' . $r['code'];
            }
        }
        return $out;
    }

    /** Měna ve zkratce nebo názvu účtu: kód měny, `EU` (EUČB, RBEU, UCEU), nebo `E` na konci (FIOE, KB E). */
    public static function nameHint(string $label): ?string
    {
        $text = mb_strtoupper(trim($label));
        foreach (self::CODES as $code) {
            if (preg_match('/(^|[^A-Z])' . $code . '|' . $code . '($|[^A-Z])/u', $text) === 1 || str_contains($text, $code)) {
                return $code;
            }
        }
        if (str_contains($text, 'EU')) {
            return 'EUR';
        }
        return preg_match('/(^|[A-Z]|\s)E\d?$/u', $text) === 1 ? 'EUR' : null;
    }

    /**
     * Kurzy ČNB měny k datům: z uložených kurzů (víkend a svátek = poslední vyhlášený kurz
     * nejvýš týden před), chybějící pracovní den z ČNB přes {@see CnbExchangeRateClient}
     * (uloží se pro všechny), bez `$fetch` zůstane bez kurzu.
     *
     * @param list<string> $dates
     * @return array<string,?float>
     */
    private function rates(string $currency, array $dates, bool $fetch): array
    {
        if ($dates === []) {
            return [];
        }
        sort($dates);
        $load = function () use ($currency, $dates): array {
            $stmt = $this->db->pdo()->prepare('SELECT rate_date, rate FROM exchange_rates WHERE currency_code = ? AND rate_date >= ? AND rate_date <= ? ORDER BY rate_date');
            $stmt->execute([$currency, (new \DateTimeImmutable($dates[0]))->modify('-10 days')->format('Y-m-d'), end($dates)]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$d, $r]) {
                $out[(string) $d] = (float) $r;
            }
            return $out;
        };
        $known = $load();
        $resolve = static function (array $known, string $date): ?float {
            if (isset($known[$date])) {
                return $known[$date];
            }
            $day = new \DateTimeImmutable($date);
            $previous = null;
            for ($i = 1; $i <= 5 && $previous === null; $i++) {
                $previous = $known[$day->modify("-{$i} days")->format('Y-m-d')] ?? null;
            }
            if ((int) $day->format('N') >= 6) {
                // Víkend: kurz posledního pracovního dne (pátek, ve svátek dřív).
                return $previous;
            }
            // Pracovní den bez kurzu je svátek jen tehdy, když uložené kurzy kolem něj souvislé
            // jsou (kurz má i nejbližší další pracovní den); jinak je to díra v uložených kurzech.
            for ($i = 1; $i <= 4; $i++) {
                $next = $day->modify("+{$i} days");
                if ((int) $next->format('N') < 6) {
                    return isset($known[$next->format('Y-m-d')]) ? $previous : null;
                }
            }
            return null;
        };
        $out = [];
        foreach ($dates as $date) {
            $rate = $resolve($known, $date);
            if ($rate === null && $fetch) {
                $hit = $this->cnb->getRate($currency, new \DateTimeImmutable($date));
                if ($hit !== null && $hit['source'] !== 'last_known' && $hit['rate_date'] <= $date
                    && (new \DateTimeImmutable($hit['rate_date'])) >= (new \DateTimeImmutable($date))->modify('-7 days')) {
                    $rate = (float) $hit['rate'];
                    $known[$date] = $rate;
                }
            }
            $out[$date] = $rate;
        }
        return $out;
    }

    /** @return array{id:int,starts_on:string}|null */
    private function period(int $supplierId, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, starts_on FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ? LIMIT 1');
        $stmt->execute([$supplierId, $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'starts_on' => (string) $row['starts_on']];
    }

    private function ledgerOpening(int $supplierId, int $periodId, int $accountId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND l.account_id = ? AND e.source_type = 'opening'"
        );
        $stmt->execute([$supplierId, $periodId, $accountId]);
        return PohodaForeignBank::cents((float) $stmt->fetchColumn());
    }

    /** Korunové obraty analytiky a počet řádků s měnou (kontrola před a po opravě). @return array{lines:int,debit:int,credit:int,foreign_lines:int} */
    private function accountLines(int $supplierId, int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*), COALESCE(SUM(CASE WHEN side = 'debit' THEN signed_amount END), 0), COALESCE(SUM(CASE WHEN side = 'credit' THEN signed_amount END), 0),
                    SUM(currency_code IS NOT NULL)
               FROM journal_entry_lines WHERE supplier_id = ? AND account_id = ?"
        );
        $stmt->execute([$supplierId, $accountId]);
        [$n, $debit, $credit, $foreign] = $stmt->fetch(PDO::FETCH_NUM);
        return ['lines' => (int) $n, 'debit' => PohodaForeignBank::cents((float) $debit), 'credit' => PohodaForeignBank::cents((float) $credit), 'foreign_lines' => (int) $foreign];
    }

    private function hasJournal(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare("SELECT accounting_mode FROM supplier WHERE id = ?");
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== 'tax_evidence';
    }

    /**
     * Uzavřené, uzavírané nebo zamčené období v rozsahu výpisů a pohybů.
     *
     * @param list<array<string,mixed>> $statements
     * @param array<int,array{date:string}> $tx
     */
    private function lockReason(int $supplierId, array $statements, array $tx): ?string
    {
        $dates = array_merge(array_map(static fn (array $s): string => substr((string) $s['statement_date'], 0, 10), $statements), array_column($tx, 'date'));
        sort($dates);
        $stmt = $this->db->pdo()->prepare("SELECT fiscal_year, status FROM accounting_periods WHERE supplier_id = ? AND status <> 'open' AND starts_on <= ? AND ends_on >= ? ORDER BY starts_on LIMIT 1");
        $stmt->execute([$supplierId, end($dates), $dates[0]]);
        $closed = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($closed !== false) {
            return sprintf('období %s je %s', $closed['fiscal_year'], $closed['status']);
        }
        foreach ([$dates[0], end($dates)] as $date) {
            if ($this->locks->forDate($supplierId, $date)->dateLocked) {
                return 'zámek k datu (' . $date . ')';
            }
        }
        return null;
    }

    /**
     * @param list<int> $statementIds
     * @param array<int,array{foreign:float}> $tx
     * @return array<int,array{0:int,1:int}> výpis => [příjmy, výdaje] v haléřích měny
     */
    private function statementTotals(array $statementIds, array $tx): array
    {
        $out = [];
        $stmt = $this->db->pdo()->query('SELECT id, statement_id FROM bank_transactions WHERE statement_id IN (' . implode(',', $statementIds) . ')');
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $statementId]) {
            $cents = PohodaForeignBank::cents($tx[(int) $id]['foreign'] ?? 0.0);
            $out[(int) $statementId] ??= [0, 0];
            $out[(int) $statementId][$cents >= 0 ? 0 : 1] += abs($cents);
        }
        return $out;
    }

    /** @param array<string,mixed> $plan @return array{number:string,bank:string,iban:string,currency:string,label:string} */
    private function companyAccount(int $supplierId, array $plan): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT label, iban FROM supplier_bank_accounts WHERE supplier_id = ? AND account_canonical = ? AND bank_code_norm = ?');
        $stmt->execute([$supplierId, AccountNumberNormalizer::canonical($plan['account_number']), AccountNumberNormalizer::canonicalBankCode($plan['bank_code'] ?: null) ?? '']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['number' => $plan['account_number'], 'bank' => $plan['bank_code'], 'iban' => str_replace(' ', '', (string) ($row['iban'] ?? '')),
            'currency' => $plan['currency'], 'label' => (string) ($row['label'] ?? $plan['code'])];
    }
}
