<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalLinePairingRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\AccountBalanceSign;
use MyInvoice\Service\Accounting\JournalLineAmount;

/**
 * Otevřené položky a párování (okruhy) na libovolném účtu — saldokonto řádků deníku.
 *
 * Okruh spojuje řádky jednoho účtu (analytiky), které se vyrovnávají: převod přes
 * 261, záloha a vyúčtování na 395, půjčka a splátky. Vyrovnaný okruh je uzavřený,
 * nevyrovnaný nechává rozdíl otevřený. Σ otevřených částek = zůstatek účtu k datu;
 * sestava to kontroluje proti zůstatku spočtenému cestou opisu účtu.
 *
 * Párování je metadata: nemění deník, a proto nehlídá uzavřené ani zamčené období.
 */
final class OpenItemsService
{
    public const MAX_SUGGESTIONS = 500;
    public const DEFAULT_SUGGESTION_DAYS = 14;
    public const MAX_NOTE_LENGTH = 255;

    /** Výchozí účty soupisu; přidávají se k nim účty označené v osnově jako zúčtovací. */
    public const LISTING_DEFAULT_CODES = ['261', '335', '395'];
    public const LISTING_MAX_ACCOUNTS = 50;

    public function __construct(
        private readonly Connection $db,
        private readonly JournalLinePairingRepository $pairings,
        private readonly LedgerReportRepository $ledger,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalLineContext $context,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $accountId, string $asOf, bool $onlyOpen, int $page, int $perPage): array
    {
        $account = $this->account($supplierId, $accountId, $asOf);
        [$periodStart, $anchor] = $this->window($supplierId, $asOf);

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $totals = $this->pairings->openItemTotals($supplierId, $accountId, $asOf, $periodStart, $anchor);
        $rows = $this->pairings->openItemLines($supplierId, $accountId, $asOf, $periodStart, $anchor, $onlyOpen, $perPage, ($page - 1) * $perPage);

        $normalSide = $account['normal_side'] !== null ? (string) $account['normal_side'] : null;
        $type = $account['type'];
        $codes = $this->accountCodes($supplierId, array_map(static fn (array $r): int => $r['account_id'], $rows));
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'line_id'         => $r['line_id'],
                'line_no'         => $r['line_no'],
                'entry_id'        => $r['entry_id'],
                'entry_date'      => (string) $r['entry_date'],
                'document_no'     => $r['document_no'],
                'description'     => $r['description'],
                'source_type'     => (string) $r['source_type'],
                'source_id'       => $r['source_id'],
                'side'            => (string) $r['side'],
                'amount'          => JournalLineAmount::signed($r),
                'is_red_storno'   => (bool) $r['is_red_storno'],
                'open_amount'     => $r['open_amount'],
                'open_balance'    => $r['running_open'],
                'balance'         => $r['running_balance'],
                'balance_signed'      => AccountBalanceSign::signed($r['running_balance'], $type),
                'balance_unusual'     => AccountBalanceSign::isUnusual($r['running_balance'], $normalSide),
                'open_balance_signed' => AccountBalanceSign::signed($r['running_open'], $type),
                'account_id'      => $r['account_id'],
                'account_code'    => $codes[$r['account_id']]['code'] ?? '',
                'account_name'    => $codes[$r['account_id']]['name'] ?? '',
                'is_reversed'     => $r['reversed_by'] !== null,
                'source_statement_id'        => $r['source_statement_id'],
                'source_doc_number'          => $r['source_doc_number'],
                'source_register_id'         => $r['source_register_id'],
                'source_asset_id'            => $r['source_asset_id'],
                'source_settlement_doc_type' => $r['source_settlement_doc_type'],
                'source_settlement_doc_id'   => $r['source_settlement_doc_id'],
                'currency_code'   => $r['currency_code'],
                'amount_foreign'  => $r['amount_foreign'],
            ];
        }
        $items = $this->context->enrich($supplierId, $items);

        $ledgerBalance = $this->ledgerBalance($supplierId, $accountId, $periodStart, $asOf);

        return [
            'account'        => $account,
            'as_of'          => $asOf,
            'only_open'      => $onlyOpen,
            'items'          => $items,
            'total'          => $onlyOpen ? $totals['open_count'] : $totals['total'],
            'page'           => $page,
            'per_page'       => $perPage,
            'line_count'     => $totals['total'],
            'open_count'     => $totals['open_count'],
            'open_md'        => $totals['open_md'],
            'open_d'         => $totals['open_d'],
            'open_total'     => $totals['open_total'],
            'open_total_signed' => AccountBalanceSign::signed($totals['open_total'], $type),
            'balance'        => $ledgerBalance,
            'balance_signed'  => AccountBalanceSign::signed($ledgerBalance, $type),
            'balance_unusual' => AccountBalanceSign::isUnusual($ledgerBalance, $normalSide),
            'difference'     => round($totals['open_total'] - $ledgerBalance, 2),
        ];
    }

    /**
     * Soupis otevřených položek více účtů k datu pro inventarizaci (261, 335, 395 a další).
     * Bez `codes` vezme výchozí účty a zúčtovací účty z osnovy; účet bez jediného pohybu
     * do soupisu nepatří. Jeden účet = jeden dotaz nad jeho okruhy, počet účtů je omezený
     * konstantou LISTING_MAX_ACCOUNTS a každý účet jde po svém indexu.
     *
     * @param list<string> $codes
     * @return array<string,mixed>
     */
    public function listing(int $supplierId, string $asOf, array $codes, int $maxRowsPerAccount): array
    {
        $accounts = $this->listingAccounts($supplierId, $codes);
        $out = [];
        $grand = ['open_count' => 0, 'open_md' => 0, 'open_d' => 0, 'unmatched' => 0];
        foreach ($accounts as $id) {
            $report = $this->build($supplierId, $id, $asOf, true, 1, $maxRowsPerAccount);
            if ($report['line_count'] === 0) {
                continue;
            }
            unset($report['page'], $report['per_page'], $report['only_open'], $report['as_of']);
            $report['matches'] = (int) round($report['difference'] * 100) === 0;
            $report['truncated'] = $report['total'] > count($report['items']);
            $out[] = $report;
            $grand['open_count'] += $report['open_count'];
            $grand['open_md'] += self::cents($report['open_md']);
            $grand['open_d'] += self::cents($report['open_d']);
            $grand['unmatched'] += $report['matches'] ? 0 : 1;
        }
        return [
            'as_of'    => $asOf,
            'entity'   => $this->listingEntity($supplierId),
            'accounts' => $out,
            'totals'   => [
                'open_count' => $grand['open_count'],
                'open_md'    => $grand['open_md'] / 100,
                'open_d'     => $grand['open_d'] / 100,
                'unmatched'  => $grand['unmatched'],
            ],
        ];
    }

    /**
     * @param list<string> $codes
     * @return list<int> ID účtů; analytika pod vybranou syntetikou se nevrací zvlášť,
     *                   protože ji syntetika zahrnuje
     */
    private function listingAccounts(int $supplierId, array $codes): array
    {
        $byDefault = $codes === [];
        if ($byDefault) {
            $codes = self::LISTING_DEFAULT_CODES;
        }
        $in = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.id, a.parent_id
               FROM chart_of_accounts a
              WHERE a.supplier_id = ?
                AND (a.account_code IN ({$in})" . ($byDefault ? " OR (a.is_clearing = 1 AND a.parent_id IS NULL)" : '') . ")
              ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId, ...$codes]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $own = array_values(array_filter($rows, static fn (array $r): bool => $r['parent_id'] === null || !in_array((int) $r['parent_id'], $ids, true)));
        return array_slice(array_map(static fn (array $r): int => (int) $r['id'], $own), 0, self::LISTING_MAX_ACCOUNTS);
    }

    /** @return array{name:string, ico:?string, address:string, prepared_at:string} */
    private function listingEntity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, street, city, zip, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $parts = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim((string) ($row['zip'] ?? '') . ' ' . (string) ($row['city'] ?? '')),
        ], static fn (string $p): bool => $p !== '');
        return [
            'name'        => (string) ($row['company_name'] ?? ''),
            'ico'         => ($row['ic'] ?? '') !== '' ? (string) $row['ic'] : null,
            'address'     => implode(', ', $parts),
            'prepared_at' => (new \DateTimeImmutable())->format('d.m.Y H:i'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function pairing(int $supplierId, int $pairingId): array
    {
        $pairing = $this->pairings->find($supplierId, $pairingId);
        if ($pairing === null) {
            throw new ReportException('not_found', 'Okruh nenalezen.', 404);
        }
        $items = array_map(static function (array $item): array {
            if ($item['line_id'] !== null) {
                $item['amount'] = JournalLineAmount::signed($item);
            }
            return $item;
        }, $this->pairings->items($supplierId, $pairingId));
        $md = 0.0;
        $d = 0.0;
        foreach ($items as $it) {
            if ($it['line_id'] === null || !$it['posted']) continue;
            if ($it['side'] === 'debit') $md += (float) $it['amount'];
            else $d += (float) $it['amount'];
        }
        return $pairing + [
            'items'     => $items,
            'total_md'  => round($md, 2),
            'total_d'   => round($d, 2),
            'remainder' => round($md - $d, 2),
            'balanced'  => round($md - $d, 2) === 0.0,
        ];
    }

    /**
     * @param list<int> $lineIds
     * @param array{user_id:?int, ip:?string, user_agent:?string} $meta
     * @return array<string,mixed> detail založeného okruhu
     */
    public function create(int $supplierId, int $accountId, array $lineIds, ?string $note, array $meta, string $origin = 'manual'): array
    {
        $this->account($supplierId, $accountId);
        $note = $this->normalizeNote($note);
        if (count(array_unique($lineIds)) < 2) {
            throw new ReportException('validation_failed', 'Okruh musí mít alespoň dva řádky.', 422);
        }
        return $this->transactional(function () use ($supplierId, $accountId, $lineIds, $note, $meta, $origin): array {
            $this->pruneDegenerate($supplierId, null, $meta);
            $refs = $this->validatedLines($supplierId, $accountId, $lineIds, null);
            $lineAccountId = $refs[0]['account_id'];
            $pairingId = $this->pairings->create($supplierId, $lineAccountId, $refs, $note, $origin, $meta['user_id'] ?? null);
            $this->log('accounting.pairing_created', $supplierId, $pairingId, [
                'account_id' => $lineAccountId,
                'origin'     => $origin,
                'lines'      => array_map(static fn (array $r): array => ['entry_id' => $r['entry_id'], 'line_no' => $r['line_no']], $refs),
            ], $meta);
            return $this->pairing($supplierId, $pairingId);
        });
    }

    /**
     * @param list<int> $lineIds
     * @param array{user_id:?int, ip:?string, user_agent:?string} $meta
     * @return array<string,mixed>
     */
    public function addLines(int $supplierId, int $pairingId, array $lineIds, array $meta): array
    {
        if ($lineIds === []) {
            throw new ReportException('validation_failed', 'Vyber alespoň jeden řádek.', 422);
        }
        return $this->transactional(function () use ($supplierId, $pairingId, $lineIds, $meta): array {
            $pairing = $this->pairings->find($supplierId, $pairingId);
            if ($pairing === null) {
                throw new ReportException('not_found', 'Okruh nenalezen.', 404);
            }
            $this->pruneDegenerate($supplierId, $pairingId, $meta);
            $refs = $this->validatedLines($supplierId, $pairing['account_id'], $lineIds, $pairing['account_id']);
            $this->pairings->addItems($supplierId, $pairingId, $refs);
            $this->log('accounting.pairing_lines_added', $supplierId, $pairingId, [
                'lines' => array_map(static fn (array $r): array => ['entry_id' => $r['entry_id'], 'line_no' => $r['line_no']], $refs),
            ], $meta);
            return $this->pairing($supplierId, $pairingId);
        });
    }

    /**
     * Odebere řádek z okruhu. Okruh, ve kterém nezůstane nic, zanikne.
     *
     * @param array{user_id:?int, ip:?string, user_agent:?string} $meta
     * @return array<string,mixed>|null detail okruhu, NULL když zanikl
     */
    public function removeLine(int $supplierId, int $pairingId, int $entryId, int $lineNo, array $meta): ?array
    {
        return $this->transactional(function () use ($supplierId, $pairingId, $entryId, $lineNo, $meta): ?array {
            if ($this->pairings->find($supplierId, $pairingId) === null) {
                throw new ReportException('not_found', 'Okruh nenalezen.', 404);
            }
            if (!$this->pairings->removeItem($supplierId, $pairingId, $entryId, $lineNo)) {
                throw new ReportException('not_found', 'Řádek v okruhu není.', 404);
            }
            $dissolved = $this->pairings->dissolveDegenerate($supplierId, [$pairingId]);
            $this->log('accounting.pairing_line_removed', $supplierId, $pairingId, [
                'entry_id' => $entryId, 'line_no' => $lineNo,
                'dissolved' => $dissolved,
            ], $meta);
            return $this->pairings->find($supplierId, $pairingId) === null ? null : $this->pairing($supplierId, $pairingId);
        });
    }

    /**
     * @param list<int> $pairingIds
     * @param array{user_id:?int, ip:?string, user_agent:?string} $meta
     * @return int počet zrušených okruhů
     */
    public function delete(int $supplierId, array $pairingIds, array $meta): int
    {
        $pairingIds = array_values(array_unique(array_filter(array_map('intval', $pairingIds), static fn (int $id): bool => $id > 0)));
        if ($pairingIds === []) {
            throw new ReportException('validation_failed', 'Vyber alespoň jeden okruh.', 422);
        }
        return $this->transactional(function () use ($supplierId, $pairingIds, $meta): int {
            $deleted = 0;
            foreach ($pairingIds as $id) {
                $items = $this->pairings->items($supplierId, $id);
                if (!$this->pairings->delete($supplierId, $id)) {
                    continue;
                }
                $deleted++;
                $this->log('accounting.pairing_deleted', $supplierId, $id, [
                    'lines' => array_map(static fn (array $r): array => ['entry_id' => $r['entry_id'], 'line_no' => $r['line_no']], $items),
                ], $meta);
            }
            if ($deleted === 0) {
                throw new ReportException('not_found', 'Okruh nenalezen.', 404);
            }
            return $deleted;
        });
    }

    /**
     * Návrhy párování: storno s originálem (bez ohledu na datum) a dvojice stejné
     * částky na opačných stranách téhož účtu v okně `days` dní (převody přes 261,
     * zálohy na 395…). Každý řádek je nejvýš v jednom návrhu; bere se nejbližší datum.
     *
     * @return list<array{kind:string, account_id:int, amount:float, days_apart:int, lines:list<array<string,mixed>>}>
     */
    public function suggestions(int $supplierId, int $accountId, string $asOf, int $days): array
    {
        $this->account($supplierId, $accountId, $asOf);
        [$periodStart, $anchor] = $this->window($supplierId, $asOf);
        $lines = $this->pairings->unpairedLines($supplierId, $accountId, $asOf, $periodStart, $anchor);
        $days = max(0, $days);

        $used = [];
        $out = [];

        $byEntry = [];
        foreach ($lines as $l) {
            $byEntry[$l['entry_id']][] = $l;
        }
        foreach ($lines as $orig) {
            if ($orig['reversed_by'] === null || isset($used[$orig['line_id']])) continue;
            foreach ($byEntry[$orig['reversed_by']] ?? [] as $mirror) {
                if (isset($used[$mirror['line_id']])
                    || $mirror['account_id'] !== $orig['account_id']
                    || self::effectiveSide($mirror) === self::effectiveSide($orig)
                    || self::cents($mirror['amount']) !== self::cents($orig['amount'])) {
                    continue;
                }
                $used[$orig['line_id']] = $used[$mirror['line_id']] = true;
                $out[] = self::suggestion('reversal', $orig, $mirror);
                break;
            }
        }

        $groups = [];
        foreach ($lines as $l) {
            if (isset($used[$l['line_id']])) continue;
            $groups[$l['account_id'] . ':' . self::cents($l['amount'])][self::effectiveSide($l)][] = $l;
        }
        foreach ($groups as $group) {
            $debits = $group['debit'] ?? [];
            $credits = $group['credit'] ?? [];
            if ($debits === [] || $credits === []) continue;
            foreach ($debits as $debit) {
                $best = null;
                $bestGap = PHP_INT_MAX;
                foreach ($credits as $i => $credit) {
                    if (isset($used[$credit['line_id']])) continue;
                    $gap = self::daysBetween($debit['entry_date'], $credit['entry_date']);
                    if ($gap <= $days && $gap < $bestGap) {
                        $best = $i;
                        $bestGap = $gap;
                    }
                }
                if ($best === null) continue;
                $credit = $credits[$best];
                $used[$debit['line_id']] = $used[$credit['line_id']] = true;
                $out[] = self::suggestion('amount', $debit, $credit);
                if (count($out) >= self::MAX_SUGGESTIONS) break 2;
            }
        }

        usort($out, static fn (array $a, array $b): int => [$a['lines'][0]['entry_date'], $a['lines'][0]['line_id']] <=> [$b['lines'][0]['entry_date'], $b['lines'][0]['line_id']]);
        return array_slice($out, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * Založí okruhy z návrhů. Bez `pairs` vezme všechny aktuální návrhy; s nimi
     * jen vybrané dvojice (každá se validuje stejně jako ruční okruh).
     *
     * @param list<list<int>>|null $pairs
     * @param array{user_id:?int, ip:?string, user_agent:?string} $meta
     * @return int počet založených okruhů
     */
    public function applySuggestions(int $supplierId, int $accountId, string $asOf, int $days, ?array $pairs, array $meta): int
    {
        if ($pairs === null) {
            $pairs = array_map(
                static fn (array $s): array => array_map(static fn (array $l): int => $l['line_id'], $s['lines']),
                $this->suggestions($supplierId, $accountId, $asOf, $days),
            );
        }
        if ($pairs === []) {
            return 0;
        }
        return $this->transactional(function () use ($supplierId, $accountId, $pairs, $meta): int {
            $created = 0;
            foreach ($pairs as $lineIds) {
                $this->create($supplierId, $accountId, array_map('intval', (array) $lineIds), null, $meta, 'suggestion');
                $created++;
            }
            return $created;
        });
    }

    /**
     * Smazání zápisu odnese jeho položky okruhů kaskádou a v okruhu může zůstat jediný
     * řádek. Čtení ho bere jako nespárovaný; před zápisem se takový okruh zruší, aby
     * řádek nenarazil na primární klíč položky.
     *
     * @param array{user_id:?int, ip:?string, user_agent:?string} $meta
     */
    private function pruneDegenerate(int $supplierId, ?int $keep, array $meta): void
    {
        $byPairing = [];
        foreach ($this->pairings->dissolveDegenerate($supplierId, null, $keep) as $item) {
            $byPairing[$item['pairing_id']][] = ['entry_id' => $item['entry_id'], 'line_no' => $item['line_no']];
        }
        foreach ($byPairing as $pairingId => $lines) {
            $this->log('accounting.pairing_deleted', $supplierId, $pairingId, ['reason' => 'degenerate', 'lines' => $lines], $meta);
        }
    }

    /**
     * @param list<int> $lineIds
     * @return list<array{line_id:int, entry_id:int, line_no:int, account_id:int, side:string, amount:float, is_red_storno:bool}>
     */
    private function validatedLines(int $supplierId, int $accountId, array $lineIds, ?int $requiredLineAccount): array
    {
        $refs = $this->pairings->lineRefs($supplierId, $lineIds);
        $wanted = array_values(array_unique(array_map('intval', $lineIds)));
        if (count($refs) !== count($wanted)) {
            throw new ReportException('not_found', 'Některý z řádků deníku neexistuje.', 404);
        }
        $lineAccount = $requiredLineAccount;
        $out = [];
        foreach ($wanted as $id) {
            $r = $refs[$id];
            if (!$r['posted']) {
                throw new ReportException('line_not_posted', 'Párovat lze jen zaúčtované řádky.', 422);
            }
            if ($r['account_id'] !== $accountId && $r['parent_id'] !== $accountId) {
                throw new ReportException('account_mismatch', 'Řádek neleží na párovaném účtu.', 422);
            }
            $lineAccount ??= $r['account_id'];
            if ($r['account_id'] !== $lineAccount) {
                throw new ReportException('account_mismatch', 'Všechny řádky okruhu musí ležet na stejném účtu (analytice).', 422);
            }
            if ($r['pairing_id'] !== null) {
                throw new ReportException('line_already_paired', 'Řádek už je v okruhu #' . $r['pairing_id'] . '.', 409);
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * @return array{id:int, code:string, name:string, type:string, normal_side:mixed, is_synthetic:bool}
     */
    private function account(int $supplierId, int $accountId, ?string $asOf = null): array
    {
        $account = $this->accounts->findById($supplierId, $accountId, $asOf);
        if ($account === null) {
            throw new ReportException('account_not_found', 'Účet #' . $accountId . ' neexistuje.', 404);
        }
        return [
            'id'           => (int) $account['id'],
            'code'         => (string) $account['account_code'],
            'name'         => (string) $account['name'],
            'type'         => (string) $account['account_type'],
            'normal_side'  => $account['normal_side'],
            'is_synthetic' => (bool) $account['is_synthetic'],
        ];
    }

    /**
     * Okno otevřených položek k datu — stejná pravidla jako počáteční stav opisu účtu.
     *
     * @return array{0:string, 1:?string} [začátek období, kotva otevření knih]
     */
    private function window(int $supplierId, string $asOf): array
    {
        $period = $this->periods->findForDate($supplierId, $asOf);
        $periodStart = $period !== null ? (string) $period['starts_on'] : substr($asOf, 0, 4) . '-01-01';
        return [$periodStart, $this->ledger->openingAnchor($supplierId, $asOf)];
    }

    /** Zůstatek účtu k datu cestou opisu účtu (PS k začátku období + pohyby). */
    private function ledgerBalance(int $supplierId, int $accountId, string $periodStart, string $asOf): float
    {
        $opening = $this->ledger->accountOpening($supplierId, $accountId, $periodStart, $periodStart, true);
        $turnovers = $this->ledger->accountTurnovers($supplierId, $accountId, $periodStart, $asOf, true);
        return round($opening + $turnovers['md'] - $turnovers['d'], 2);
    }

    /**
     * @param list<int> $ids
     * @return array<int,array{code:string, name:string}>
     */
    private function accountCodes(int $supplierId, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, account_code, name FROM chart_of_accounts WHERE supplier_id = ? AND id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute([$supplierId, ...$ids]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = ['code' => (string) $r['account_code'], 'name' => (string) $r['name']];
        }
        return $out;
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);
        if ($note === null || $note === '') {
            return null;
        }
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new ReportException('validation_failed', 'Poznámka může mít nejvýš ' . self::MAX_NOTE_LENGTH . ' znaků.', 422);
        }
        return $note;
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function transactional(callable $fn): mixed
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $result = $fn();
            if ($ownTx) {
                $pdo->commit();
            }
            return $result;
        } catch (\PDOException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ReportException('line_already_paired', 'Řádek mezitím spároval někdo jiný.', 409);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{user_id:?int, ip:?string, user_agent:?string} $meta
     */
    private function log(string $action, int $supplierId, int $pairingId, array $payload, array $meta): void
    {
        $this->activity->log($action, $meta['user_id'] ?? null, 'journal_line_pairing', $pairingId, $payload,
            $meta['ip'] ?? null, $meta['user_agent'] ?? null, $supplierId);
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array{kind:string, account_id:int, amount:float, days_apart:int, lines:list<array<string,mixed>>}
     */
    private static function suggestion(string $kind, array $a, array $b): array
    {
        $pick = static fn (array $l): array => [
            'line_id'     => $l['line_id'],
            'entry_id'    => $l['entry_id'],
            'entry_date'  => $l['entry_date'],
            'document_no' => $l['document_no'],
            'description' => $l['description'],
            'side'        => $l['side'],
            'amount'      => JournalLineAmount::signed($l),
            'is_red_storno' => (bool) ($l['is_red_storno'] ?? false),
        ];
        $lines = [$pick($a), $pick($b)];
        usort($lines, static fn (array $x, array $y): int => [$x['entry_date'], $x['line_id']] <=> [$y['entry_date'], $y['line_id']]);
        return [
            'kind'       => $kind,
            'account_id' => $a['account_id'],
            'amount'     => $a['amount'],
            'days_apart' => self::daysBetween($a['entry_date'], $b['entry_date']),
            'lines'      => $lines,
        ];
    }

    private static function daysBetween(string $a, string $b): int
    {
        return (int) abs((new \DateTimeImmutable($a))->diff(new \DateTimeImmutable($b))->days);
    }

    /** @param array{side:string, is_red_storno?:bool} $line */
    private static function effectiveSide(array $line): string
    {
        if (empty($line['is_red_storno'])) {
            return $line['side'];
        }
        return $line['side'] === 'debit' ? 'credit' : 'debit';
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
