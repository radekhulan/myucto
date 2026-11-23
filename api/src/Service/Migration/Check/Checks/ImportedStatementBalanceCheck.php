<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\AuthoritativeTransactionReconciler;
use MyInvoice\Service\Bank\BankLedgerAccount;
use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\FixableMigrationDataCheck;
use MyInvoice\Service\Migration\Check\FixPreview;
use MyInvoice\Service\Migration\Check\FixResult;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\Pohoda\PohodaForeignBank;
use PDO;

/**
 * Kontrola 3: stavy převzatých výpisů, které nenavazují na deník.
 *
 * Převzatý výpis (`bank_statements.source = 'import'`) nenese stav od banky, převod ho
 * dopočítal od počátečního stavu. Stav pak mohl posunout první výpis banky
 * ({@see \MyInvoice\Service\Bank\ImportedStatementRebase}, evidence `bank_statement_rebases`),
 * nebo převod spojil výpisy dvou účtů zdroje se stejným číslem (víceměnový účet). Kontrola
 * porovná každý řetěz převzatých výpisů účtu v účetním období s počátečním stavem jeho
 * analytiky 221 v deníku ({@see BankLedgerAccount}) a ověří, že stavy výpisů navazují
 * na jejich pohyby.
 *
 * Oprava spočte stavy řetězu znovu od počátečního stavu v deníku (stejně jako oprava účtů
 * v cizí měně u korunového účtu se stejným číslem, {@see PohodaForeignBank::rechain()})
 * a odebere výpisy z evidence posunů, aby je smazání výpisu banky znovu neposunulo. Jen
 * tam, kde je jednoznačná: korunový účet, všechny zápisy pohybů na jedné analytice, výpisy
 * jednoho účtu zdroje, otevírací zápis analytiky v období, stavy neposunul výpis banky
 * (posun doložený výpisem banky je stav u banky a rozdíl proti deníku se posuzuje ručně)
 * a období otevřené a bez zámku. Otisk nálezu nese stavy a obraty všech výpisů řetězu.
 * Uzavřené období chrání i tahle oprava ({@see MigrationCheckLocks::reason()}): stavy
 * výpisů jsou podkladem inventarizace účtu 221 v uzávěrce.
 * Ostatní rozdíly (účty v cizí měně kontroluje {@see ForeignBankInCzkCheck}) jen ohlásí.
 * Pohyby ani deník se nemění.
 */
final class ImportedStatementBalanceCheck extends SourceScopedCheck implements FixableMigrationDataCheck
{
    public const KEY = 'imported_statement_balances';
    public const AUDIT_ACTION = 'bank.imported_statements_rechained';

    public function __construct(
        MigrationSources $sources,
        private readonly Connection $db,
        private readonly BankLedgerAccount $ledger,
        private readonly PohodaForeignBank $chain,
        private readonly MigrationCheckLocks $locks,
        private readonly ActivityLogger $activity,
    ) {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Stavy převzatých výpisů proti deníku';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#1265-stavy-prevzatych-vypisu-proti-deniku';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA, MigrationSources::MONEY_S3, MigrationSources::ABRA, MigrationSources::PREMIER,
            MigrationSources::STEREO_NX, MigrationSources::HELIOS];
    }

    public function options(): array
    {
        return [];
    }

    public function detect(int $supplierId): CheckResult
    {
        $items = [];
        $foreign = 0;
        foreach ($this->chains($supplierId, false) as $chain) {
            if ($chain === null) {
                $foreign++;
                continue;
            }
            if ($chain['item'] !== null) {
                $items[] = $chain['item'];
            }
        }
        return new CheckResult($items, $foreign > 0
            ? [sprintf('Převzaté výpisy %d účtů v cizí měně se tu neporovnávají, měnu účtů kontroluje kontrola bankovních účtů v cizí měně.', $foreign)]
            : []);
    }

    /** @param array<string,bool> $options */
    public function preview(int $supplierId, array $options = []): FixPreview
    {
        return FixPreview::fromResult($this->detect($supplierId));
    }

    /** @param array<string,bool> $options */
    public function fix(int $supplierId, ?int $userId, array $options = [], ?string $fingerprint = null): FixResult
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT migration_check_statements');
        try {
            $fixed = [];
            $skipped = [];
            // Plán nad zamčenými výpisy, ověřený proti potvrzenému náhledu, se zapíše celý beze změny.
            $chains = array_values(array_filter($this->chains($supplierId, true), static fn (?array $c): bool => $c !== null && $c['item'] !== null));
            FixPreview::fromResult(new CheckResult(array_column($chains, 'item')))->assertConfirmed($fingerprint);
            foreach ($chains as $chain) {
                if ($chain === null || $chain['item'] === null) {
                    continue;
                }
                $item = $chain['item'];
                if ($item->state !== CheckItem::FIXABLE) {
                    $skipped[] = $item;
                    continue;
                }
                $this->chain->rechain($supplierId, $chain['statement_ids'], $chain['opening']);
                $released = $this->releaseRebases($supplierId, $chain['statement_ids']);
                $first = $pdo->prepare("SELECT prev_balance FROM bank_statements WHERE id = ? AND supplier_id = ? AND source = 'import'");
                $first->execute([$chain['statement_ids'][0], $supplierId]);
                if (PohodaForeignBank::cents((float) $first->fetchColumn()) !== $chain['opening']) {
                    throw new \RuntimeException("Řetěz výpisů {$item->label} po opravě nenavazuje na deník, nic se nezapsalo.");
                }
                $this->activity->log(self::AUDIT_ACTION, $userId, 'bank_statement', $chain['statement_ids'][0], [
                    'source' => 'migration_data_check',
                    'check' => self::KEY,
                    'account' => $item->label,
                    'ledger_account' => $item->details['ledger_account'] ?? null,
                    'statements' => $chain['statement_ids'],
                    'opening' => PohodaForeignBank::amount($chain['opening']),
                    'shift' => $item->amountCzk,
                    'rebases_released' => $released,
                ], null, null, $supplierId);
                $fixed[] = $item;
            }
            $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT migration_check_statements');
            return new FixResult($fixed, $skipped);
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$own) {
                $pdo->exec('ROLLBACK TO SAVEPOINT migration_check_statements');
            }
            throw $e;
        }
    }

    /**
     * Řetězy převzatých výpisů po účtech a obdobích s nálezem; `null` za korunou nevedený účet.
     *
     * @return list<array{item:?CheckItem,statement_ids:list<int>,opening:?int}|null>
     */
    private function chains(int $supplierId, bool $forUpdate): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT bs.id, bs.account_number, COALESCE(bs.bank_code, '') AS bank_code, COALESCE(bs.currency, 'CZK') AS currency,
                    bs.statement_date, bs.prev_balance, bs.curr_balance
               FROM bank_statements bs
              WHERE bs.supplier_id = ? AND bs.source = 'import'
              ORDER BY bs.statement_date, bs.id" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = (AuthoritativeTransactionReconciler::account((string) $r['account_number'], (string) $r['bank_code']) ?? ('#' . $r['account_number'])) . '|' . $r['currency'];
            $groups[$key][] = $r;
        }
        if ($groups === []) {
            return [];
        }
        $periods = $pdo->prepare('SELECT id, fiscal_year, starts_on, ends_on, status FROM accounting_periods WHERE supplier_id = ? ORDER BY starts_on');
        $periods->execute([$supplierId]);
        $periods = $periods->fetchAll(PDO::FETCH_ASSOC);
        $sources = (new TakenOverRecord($this->db))->bankStatementSourceAccounts($supplierId,
            array_merge(...array_map(static fn (array $g): array => array_map(static fn (array $s): int => (int) $s['id'], $g), array_values($groups))));

        $rebases = $this->rebases($supplierId);
        $out = [];
        foreach ($groups as $statements) {
            if ($statements[0]['currency'] !== 'CZK') {
                $out[] = null;
                continue;
            }
            $ids = array_map(static fn (array $s): int => (int) $s['id'], $statements);
            [$totals, $txIds] = $this->movements($ids);
            [$accountId, $accountCode, $share] = $txIds === [] ? [null, null, 0.0] : $this->ledger->forTransactions($supplierId, $txIds);
            if ($accountId === null) {
                continue;
            }
            foreach ($periods as $period) {
                $chain = array_values(array_filter($statements, static fn (array $s): bool => substr((string) $s['statement_date'], 0, 10) >= (string) $period['starts_on']
                    && substr((string) $s['statement_date'], 0, 10) <= (string) $period['ends_on']));
                if ($chain === [] || $chain[0]['prev_balance'] === null) {
                    continue;
                }
                $out[] = $this->evaluate($supplierId, $chain, $period, $totals, (int) $accountId, (string) $accountCode, $share, $sources, $rebases);
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $chain
     * @param array<string,mixed> $period
     * @param array<int,array{0:int,1:int}> $totals
     * @param array<int,?string> $sources
     * @param list<array{id:int,shift:string,ids:list<int>,anchor_source:?string,anchor_date:?string}> $rebases
     * @return array{item:?CheckItem,statement_ids:list<int>,opening:?int}
     */
    private function evaluate(int $supplierId, array $chain, array $period, array $totals, int $accountId, string $accountCode, float $share, array $sources, array $rebases): array
    {
        $ids = array_map(static fn (array $s): int => (int) $s['id'], $chain);
        $opening = $this->ledger->openingCents($supplierId, (int) $period['id'], $accountId);
        $first = PohodaForeignBank::cents((float) $chain[0]['prev_balance']);
        $running = $first;
        $broken = null;
        foreach ($chain as $s) {
            [$credit, $debit] = $totals[(int) $s['id']] ?? [0, 0];
            if ($s['prev_balance'] === null || $s['curr_balance'] === null || PohodaForeignBank::cents((float) $s['prev_balance']) !== $running
                || PohodaForeignBank::cents((float) $s['curr_balance']) !== $running + $credit - $debit) {
                $broken ??= $s;
            }
            $running += $credit - $debit;
        }
        $shift = $opening === null ? null : $first - $opening;
        if (($shift === null || $shift === 0) && $broken === null) {
            return ['item' => null, 'statement_ids' => $ids, 'opening' => $opening];
        }
        $label = sprintf('%s/%s, rok %d', $chain[0]['account_number'], $chain[0]['bank_code'], (int) $period['fiscal_year']);
        // Otisk vstupů plánu: stavy a obraty každého výpisu řetězu a počáteční stav v deníku.
        // Přibude-li pohyb nebo změní-li se stav mezi náhledem a opravou, otisk náhledu nesedí.
        $inputs = [$accountId, $opening];
        foreach ($chain as $s) {
            $inputs[] = [(int) $s['id'], (string) $s['prev_balance'], (string) $s['curr_balance'], $totals[(int) $s['id']] ?? [0, 0]];
        }
        $details = ['ledger_account' => $accountCode, 'fiscal_year' => (int) $period['fiscal_year'], 'statements' => count($ids),
            'opening_ledger' => $opening === null ? null : PohodaForeignBank::amount($opening), 'opening_statement' => PohodaForeignBank::amount($first),
            'inputs' => substr(hash('sha256', (string) json_encode($inputs)), 0, 16)];
        $chainSources = array_values(array_unique(array_filter(array_map(static fn (int $id): ?string => $sources[$id] ?? null, $ids))));
        $rebased = 0;
        $confirmed = null;
        foreach ($rebases as $r) {
            if (array_intersect($ids, $r['ids']) !== []) {
                $rebased += PohodaForeignBank::cents((float) $r['shift']);
                if ($r['anchor_source'] !== null && $r['anchor_source'] !== 'import') {
                    $confirmed ??= $r;
                }
            }
        }
        $lastDate = substr((string) end($chain)['statement_date'], 0, 10);
        $firstDate = substr((string) $chain[0]['statement_date'], 0, 10);

        $reason = null;
        if ($opening === null) {
            $reason = sprintf('Deník nemá počáteční stav analytiky %s v roce %d, stav převzatých výpisů nejde ověřit.', $accountCode, (int) $period['fiscal_year']);
        } elseif (count($chainSources) > 1) {
            $reason = sprintf('Převzaté výpisy patří k různým účtům zdroje (%s). Nejdřív opravte měnu účtů.', implode(', ', $chainSources));
        } elseif ($share < 0.999) {
            $reason = sprintf('Zápisy pohybů účtu nejdou všechny na analytiku %s, počáteční stav nejde určit jednoznačně.', $accountCode);
        } elseif ($confirmed !== null) {
            // Posun doložený výpisem banky je stav účtu u banky; rozdíl proti deníku je rozdíl
            // banky a deníku (chybějící pohyb, počáteční stav), automaticky se nevrací.
            $reason = sprintf('Stavy převzatých výpisů dorovnal podle banky výpis z %s (posun %s Kč). Rozdíl proti deníku je rozdíl banky a deníku, posuďte ho ručně.',
                (string) $confirmed['anchor_date'], number_format(PohodaForeignBank::cents((float) $confirmed['shift']) / 100, 2, ',', ' '));
        }
        $lock = null;
        if ($reason === null) {
            $lock = (string) $period['status'] !== 'open'
                ? sprintf('Účetní období %d není otevřené.', (int) $period['fiscal_year'])
                : ($this->locks->reason($supplierId, $firstDate) ?? $this->locks->reason($supplierId, $lastDate));
        }
        $state = $lock !== null ? CheckItem::LOCKED : ($reason !== null ? CheckItem::REVIEW : CheckItem::FIXABLE);
        if ($state === CheckItem::FIXABLE) {
            $reason = $shift !== 0
                ? sprintf('Počáteční stav převzatých výpisů %s Kč se liší od deníku (%s Kč)%s. Stavy se spočtou znovu od deníku.',
                    number_format($first / 100, 2, ',', ' '), number_format(((int) $opening) / 100, 2, ',', ' '),
                    $rebased !== 0 ? sprintf(', posun podle výpisu banky %s Kč', number_format($rebased / 100, 2, ',', ' ')) : '')
                : sprintf('Stavy výpisu z %s nenavazují na pohyby. Stavy se spočtou znovu od deníku.', substr((string) $broken['statement_date'], 0, 10));
        }
        return [
            'item' => new CheckItem('bank_statement', $ids[0], $label, $firstDate, $shift !== null ? $shift / 100 : null, $state, $lock ?? (string) $reason,
                null, 'CZK', $details),
            'statement_ids' => $ids,
            'opening' => $opening,
        ];
    }

    /**
     * Příjmy a výdaje výpisů v haléřích a jejich pohyby.
     *
     * @param list<int> $ids
     * @return array{0:array<int,array{0:int,1:int}>,1:list<int>}
     */
    private function movements(array $ids): array
    {
        $totals = [];
        $txIds = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            foreach ($this->db->pdo()->query("SELECT id, statement_id, amount, source FROM bank_transactions WHERE statement_id IN ({$in})")->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $txIds[] = (int) $t['id'];
                if ($t['source'] !== 'statement') {
                    continue;
                }
                $cents = PohodaForeignBank::cents((float) $t['amount']);
                $totals[(int) $t['statement_id']] ??= [0, 0];
                if ($cents > 0) {
                    $totals[(int) $t['statement_id']][0] += $cents;
                } else {
                    $totals[(int) $t['statement_id']][1] -= $cents;
                }
            }
        }
        return [$totals, $txIds];
    }

    /**
     * Evidované posuny stavů podle výpisu banky s výpisem, který je způsobil (zdroj a datum;
     * `null`, když už neexistuje).
     *
     * @return list<array{id:int,shift:string,ids:list<int>,anchor_source:?string,anchor_date:?string}>
     */
    private function rebases(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT r.id, r.shift, r.statement_ids, a.source AS anchor_source, a.statement_date AS anchor_date
              FROM bank_statement_rebases r
              LEFT JOIN bank_statements a ON a.id = r.anchor_statement_id AND a.supplier_id = r.supplier_id
             WHERE r.supplier_id = ? ORDER BY r.id');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ids = json_decode((string) $r['statement_ids'], true);
            $out[] = ['id' => (int) $r['id'], 'shift' => (string) $r['shift'], 'ids' => array_map('intval', is_array($ids) ? $ids : []),
                'anchor_source' => $r['anchor_source'] !== null ? (string) $r['anchor_source'] : null,
                'anchor_date' => $r['anchor_date'] !== null ? substr((string) $r['anchor_date'], 0, 10) : null];
        }
        return $out;
    }

    /**
     * Výpisy přepočtené od deníku z evidence posunů vypadnou: smazání výpisu banky, který
     * posun způsobil, by je jinak posunulo zpět mimo deník.
     *
     * @param list<int> $ids
     */
    private function releaseRebases(int $supplierId, array $ids): int
    {
        $pdo = $this->db->pdo();
        $update = $pdo->prepare('UPDATE bank_statement_rebases SET statement_ids = ? WHERE id = ? AND supplier_id = ?');
        $delete = $pdo->prepare('DELETE FROM bank_statement_rebases WHERE id = ? AND supplier_id = ?');
        $released = 0;
        foreach ($this->rebases($supplierId) as $r) {
            $left = array_values(array_diff($r['ids'], $ids));
            if (count($left) === count($r['ids'])) {
                continue;
            }
            $released += count($r['ids']) - count($left);
            $left === [] ? $delete->execute([$r['id'], $supplierId]) : $update->execute([json_encode($left), $r['id'], $supplierId]);
        }
        return $released;
    }
}
