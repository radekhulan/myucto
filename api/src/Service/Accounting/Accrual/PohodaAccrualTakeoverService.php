<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use PDO;

/**
 * Převzetí časového rozlišení z POHODY do agendy (průvodce s náhledem, opakovatelný).
 *
 * POHODA převod nese jen zápisy své agendy Časové rozlišení jako ruční zápisy deníku:
 * číslo zápisu je číslo položky POHODY (`24CR0001`), popis nese číslo zdrojového dokladu.
 * Převzetí z nich sestaví položky, nic neúčtuje:
 *
 *  1. Ruční zápisy seskupí podle `document_no` `^\d{2}CR`, zdrojový doklad spáruje podle čísla
 *     z popisu (vydaná faktura podle variabilního symbolu, přijatá podle čísla dodavatele,
 *     ostatní pohledávka či závazek podle čísla). Účet a protiúčet bere ze zápisů, metodu
 *     z rytmu (jen k 31. 12. roční, jinak měsíční podle dnů).
 *  2. Ukončení z interních a ručních zápisů na 38x s textem o ukončení: podle čísla smlouvy
 *     v textu (`20240005` → `24CR0005`), jinak podle data posledního rozpuštění. Doklad pro víc
 *     smluv se rozdělí podle odhadu jejich zůstatku k datu ukončení.
 *  3. Souhrnný počáteční stav účtu se rozepíše na položky: u ukončených smluv přesně
 *     (rozpuštění roku + ukončení), u běžících rekonstrukcí z faktury a rytmu (začátek =
 *     datum vystavení, denní sazba z ročního či měsíčního rozpuštění). Zbytek proti
 *     počátečnímu stavu jde na položku „Nerozepsaný zůstatek PS", odsouhlasení tak sedí
 *     na haléř a rozdíl je vidět.
 *  4. Převzaté zápisy roku (i předgenerované do budoucna) se navážou jako řádky plánu
 *     `linked`. Plán za nimi dopočte agenda.
 *
 * Opakované převzetí je idempotentní: položky jsou klíčované `import_key`, vazby a řádky
 * plánu jdou přes unikátní klíče, existující položky se nepřepisují, jen se doplní nové
 * zápisy a přepočte nerozepsaný zůstatek.
 */
final class PohodaAccrualTakeoverService
{
    public const IMPORT_PREFIX = 'pohoda:';

    public function __construct(
        private readonly Connection $db,
        private readonly AccrualItemRepository $items,
        private readonly AccrualItemService $service,
    ) {}

    /** @return array<string,mixed> náhled převzetí */
    public function preview(int $supplierId): array
    {
        return $this->analyze($supplierId);
    }

    /**
     * Provede převzetí podle náhledu v jedné transakci.
     *
     * @return array<string,mixed>
     */
    public function apply(int $supplierId, ?int $userId): array
    {
        return TransactionFailure::retrying($this->db->pdo(), function () use ($supplierId, $userId): array {
            $pdo = $this->db->pdo();
            $own = !$pdo->inTransaction();
            if ($own) $pdo->beginTransaction();
            try {
                $plan = $this->analyze($supplierId);
                $created = 0;
                $updated = 0;
                $activeIds = [];
                foreach ($plan['items'] as $p) {
                    $existing = $this->items->findByImportKey($supplierId, $p['import_key']);
                    if ($existing === null) {
                        $itemId = $this->items->insert($supplierId, [
                            'item_no' => $p['item_no'],
                            'title' => $p['title'],
                            'kind' => $p['kind'],
                            'accrual_account_code' => $p['account_code'],
                            'counter_account_code' => $p['counter_account_code'],
                            'termination_account_code' => $p['termination_account_code'],
                            'partner_id' => $p['partner_id'],
                            'partner_name' => $p['partner_name'],
                            'source_type' => $p['source_type'],
                            'source_id' => $p['source_id'],
                            'source_document_no' => $p['source_document_no'],
                            'managed_by' => 'agenda',
                            'basis_amount' => $p['basis_amount'],
                            'opening_balance' => $p['opening_share'],
                            'opening_as_of' => $p['opening_share'] === null ? null : $plan['opening_date'],
                            'period_from' => $p['period_from'],
                            'period_to' => $p['period_to'],
                            'method' => $p['method'],
                            'status' => $p['status'],
                            'terminated_on' => $p['terminated_on'],
                            'termination_entry_id' => $p['termination_entry_id'],
                            'note' => $p['note'],
                            'import_key' => $p['import_key'],
                            'created_by' => $userId,
                            'updated_by' => $userId,
                        ]);
                        $created++;
                    } else {
                        $itemId = (int) $existing['id'];
                        $updated++;
                        if ($existing['status'] === 'active' && $p['status'] === 'terminated') {
                            $this->items->update($supplierId, $itemId, ['status' => 'terminated', 'terminated_on' => $p['terminated_on'],
                                'termination_entry_id' => $p['termination_entry_id'], 'termination_account_code' => $p['termination_account_code']]);
                        }
                    }
                    $this->linkItem($supplierId, $itemId, $p, $plan, $userId);
                    if ($p['status'] === 'active') {
                        $activeIds[] = $itemId;
                    }
                }
                foreach ($plan['opening'] as $o) {
                    if ((int) round($o['remainder'] * 100) === 0 || $o['entry_id'] === null) {
                        continue;
                    }
                    $key = self::IMPORT_PREFIX . 'ps:' . $o['account_code'] . ':' . $plan['opening_date'];
                    $existing = $this->items->findByImportKey($supplierId, $key);
                    $kind = (string) AccrualKinds::kindForAccount($o['account_code']);
                    $itemId = $existing === null ? $this->items->insert($supplierId, [
                        'item_no' => 'PS-' . $o['account_code'] . '-' . substr((string) $plan['opening_date'], 0, 4),
                        'title' => 'Nerozepsaný zůstatek PS ' . $o['account_code'],
                        'kind' => $kind,
                        'accrual_account_code' => $o['account_code'],
                        'source_type' => 'migration',
                        'managed_by' => 'agenda',
                        'basis_amount' => $o['remainder'],
                        'opening_balance' => $o['remainder'],
                        'opening_as_of' => $plan['opening_date'],
                        'method' => 'manual',
                        'status' => 'active',
                        'note' => 'Rozdíl počátečního stavu proti položkám převzatým z POHODY. Rozepište ho na smlouvy nebo vyjasněte.',
                        'import_key' => $key,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]) : (int) $existing['id'];
                    if ($existing !== null) {
                        $this->items->update($supplierId, $itemId, ['basis_amount' => $o['remainder'], 'opening_balance' => $o['remainder']]);
                    }
                    $accountId = $this->items->accountId($supplierId, $o['account_code']);
                    $this->items->addLink($supplierId, $itemId, (int) $o['entry_id'], (int) $accountId,
                        round($o['remainder'] * AccrualKinds::normalSign($kind), 2), 'origin', 'reconstruction', null, $userId);
                }
                foreach ($activeIds as $id) {
                    $this->service->rebuildPlan($supplierId, $id);
                    $this->completeIfDone($supplierId, $id);
                }
                if ($own) $pdo->commit();
            } catch (\Throwable $e) {
                if ($own && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            return ['created' => $created, 'updated' => $updated] + $this->analyze($supplierId);
        });
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $plan
     */
    private function linkItem(int $supplierId, int $itemId, array $p, array $plan, ?int $userId): void
    {
        $sign = AccrualKinds::normalSign((string) $p['kind']);
        $accountId = (int) $this->items->accountId($supplierId, (string) $p['account_code']);
        if ($p['origin'] !== null) {
            $this->items->addLink($supplierId, $itemId, $p['origin']['entry_id'], $accountId, round($p['origin']['amount'] * $sign, 2), 'origin', 'auto_document_no', null, $userId);
        }
        if ($p['opening_share'] !== null && ($plan['opening_entries'][$p['account_code']] ?? null) !== null) {
            $this->items->addLink($supplierId, $itemId, (int) $plan['opening_entries'][$p['account_code']], $accountId,
                round($p['opening_share'] * $sign, 2), 'origin', 'reconstruction', null, $userId);
        }
        $usedDue = [];
        foreach ($this->items->schedule($supplierId, $itemId) as $row) {
            $usedDue[$row['kind'] . ':' . $row['due_on']] = $row;
        }
        foreach ($p['releases'] as $r) {
            $key = 'release:' . $r['date'];
            $rowId = null;
            if (!isset($usedDue[$key]) || in_array($usedDue[$key]['status'], ['planned', 'cancelled'], true)) {
                $rowId = $this->items->upsertScheduleRow($supplierId, $itemId, [
                    'kind' => 'release', 'due_on' => $r['date'], 'from_on' => $r['from'], 'to_on' => $r['date'],
                    'days' => AccrualScheduleCalculator::daysInclusive($r['from'], $r['date']), 'amount_planned' => $r['amount'],
                    'status' => 'linked', 'journal_entry_id' => $r['entry_id'],
                ]);
                $usedDue[$key] = ['status' => 'linked', 'id' => $rowId];
            } else {
                $rowId = $usedDue[$key]['id'];
            }
            $this->items->addLink($supplierId, $itemId, $r['entry_id'], $accountId, AccrualKinds::releaseLinkAmount((string) $p['kind'], $r['amount']),
                'release', 'auto_document_no', $rowId, $userId);
        }
        foreach ($p['terminations'] as $t) {
            $rowId = $this->items->upsertScheduleRow($supplierId, $itemId, [
                'kind' => 'termination', 'due_on' => $t['date'], 'from_on' => null, 'to_on' => $t['date'], 'days' => null,
                'amount_planned' => $t['amount'], 'status' => 'linked', 'journal_entry_id' => $t['entry_id'],
            ]);
            $this->items->addLink($supplierId, $itemId, $t['entry_id'], $accountId, AccrualKinds::releaseLinkAmount((string) $p['kind'], $t['amount']),
                'termination', 'auto_description', $rowId, $userId);
        }
    }

    private function completeIfDone(int $supplierId, int $itemId): void
    {
        foreach ($this->items->schedule($supplierId, $itemId) as $row) {
            if ($row['kind'] === 'release' && $row['status'] === 'planned') {
                return;
            }
        }
        $bal = $this->items->balances($supplierId, [$itemId], '9999-12-31')[$itemId]['balance'] ?? 0.0;
        if ((int) round($bal * 100) === 0) {
            $this->items->update($supplierId, $itemId, ['status' => 'completed']);
        }
    }

    /**
     * Rozbor převzatých zápisů: návrh položek, ukončení a rozpad počátečního stavu.
     *
     * @return array<string,mixed>
     */
    public function analyze(int $supplierId): array
    {
        $groups = $this->crGroups($supplierId);
        $openingEntries = $this->openingEntries($supplierId);
        $openingDate = $openingEntries['date'];
        $items = [];
        foreach ($groups as $docNo => $g) {
            $items[$docNo] = $this->buildItem($supplierId, $docNo, $g, $openingDate);
        }
        $terminations = $this->terminations($supplierId, $items);
        foreach ($terminations['matched'] as $t) {
            foreach ($t['allocations'] as $docNo => $amount) {
                $items[$docNo]['terminations'][] = ['entry_id' => $t['entry_id'], 'date' => $t['date'], 'amount' => $amount, 'document_no' => $t['document_no']];
                $items[$docNo]['status'] = 'terminated';
                $items[$docNo]['terminated_on'] = $t['date'];
                $items[$docNo]['termination_entry_id'] = $t['entry_id'];
                $items[$docNo]['termination_account_code'] = $t['counter_account_code'];
            }
        }
        // Rozpad počátečního stavu: ukončené přesně, běžící rekonstrukcí.
        $opening = [];
        foreach ($items as $docNo => &$p) {
            $releasedSince = $openingDate !== null && $p['releases'] !== [] && $p['releases'][0]['date'] >= $openingDate;
            $unpaired = $p['period_from'] === null && $p['origin'] === null && $releasedSince;
            if (!$unpaired && ($p['period_from'] === null || $openingDate === null || $p['period_from'] >= $openingDate || $p['origin'] !== null)) {
                $p['opening_share'] = null;
                continue;
            }
            if ($unpaired) {
                // Bez zdrojového dokladu nejde zůstatek rekonstruovat: položka nese aspoň to,
                // co z počátečního stavu rozpustila a ukončila, zbytek zůstane nerozepsaný.
                $share = round(array_sum(array_column($p['releases'], 'amount')) + array_sum(array_column($p['terminations'], 'amount')), 2);
                $p['reconstructed'] = false;
                $p['note'] .= ' Zdrojový doklad v datech chybí, počáteční stav položky je jen část rozpuštěná po převzetí.';
            } elseif ($p['status'] === 'terminated') {
                $share = round(array_sum(array_column($p['releases'], 'amount')) + array_sum(array_column($p['terminations'], 'amount')), 2);
                $p['reconstructed'] = false;
            } else {
                $share = round((float) $p['basis_amount'] - AccrualScheduleCalculator::cumRecognized((float) $p['basis_amount'],
                    (string) $p['period_from'], (string) $p['period_to'], AccrualPlanBuilder::dayBefore($openingDate)), 2);
                $p['reconstructed'] = true;
            }
            $p['opening_share'] = $share;
            $opening[$p['account_code']]['allocated'] = round(($opening[$p['account_code']]['allocated'] ?? 0.0) + $share, 2);
            $opening[$p['account_code']]['items'] = ($opening[$p['account_code']]['items'] ?? 0) + 1;
        }
        unset($p);
        $openingRows = [];
        foreach ($openingEntries['amounts'] as $code => $o) {
            $code = (string) $code;
            $allocated = $opening[$code]['allocated'] ?? 0.0;
            $openingRows[] = [
                'account_code' => $code,
                'entry_id' => $o['entry_id'],
                'opening_balance' => $o['amount'],
                'allocated' => $allocated,
                'items' => $opening[$code]['items'] ?? 0,
                'remainder' => round($o['amount'] - $allocated, 2),
            ];
        }
        $existing = [];
        foreach ($this->existingKeys($supplierId) as $key => $id) {
            $existing[$key] = $id;
        }
        foreach ($items as &$p) {
            $p['existing_id'] = $existing[$p['import_key']] ?? null;
        }
        unset($p);
        return [
            'opening_date' => $openingDate,
            'opening_entries' => array_map(static fn (array $o): ?int => $o['entry_id'], $openingEntries['amounts']),
            'items' => array_values($items),
            'opening' => $openingRows,
            'terminations' => $terminations['matched'],
            'unmatched_terminations' => $terminations['unmatched'],
            'summary' => [
                'items' => count($items),
                'new' => count(array_filter($items, static fn (array $p): bool => $p['existing_id'] === null)),
                'terminated' => count(array_filter($items, static fn (array $p): bool => $p['status'] === 'terminated')),
                'unpaired_sources' => count(array_filter($items, static fn (array $p): bool => $p['source_id'] === null)),
                'opening_remainder' => round(array_sum(array_column($openingRows, 'remainder')), 2),
            ],
        ];
    }

    /** @return array<string,int> import_key => id */
    private function existingKeys(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT import_key, id FROM accrual_items WHERE supplier_id = ? AND import_key LIKE ?');
        $stmt->execute([$supplierId, self::IMPORT_PREFIX . '%']);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Ruční zápisy s číslem položky POHODY a jejich řádky na 38x po zápisech.
     *
     * @return array<string, array{entries: list<array<string,mixed>>, account_code:string, counter:array<string,float>, description:string}>
     */
    private function crGroups(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.id, je.entry_date, je.document_no, je.description, ca.account_code,
                    SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS amount
               FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE je.supplier_id = ? AND je.source_type = 'manual' AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                AND je.document_no LIKE '__CR%'
              GROUP BY je.id, je.entry_date, je.document_no, je.description, ca.account_code
              ORDER BY je.document_no, je.entry_date, je.id"
        );
        $stmt->execute([$supplierId]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $docNo = (string) $r['document_no'];
            if (preg_match('/^\d{2}CR\d+$/', $docNo) !== 1) {
                continue;
            }
            $amount = round((float) $r['amount'], 2);
            $code = (string) $r['account_code'];
            $g = &$groups[$docNo];
            $g['description'] ??= (string) $r['description'];
            if (AccrualKinds::kindForAccount($code) !== null) {
                $g['accounts'][$code] = round(($g['accounts'][$code] ?? 0.0) + abs($amount), 2);
                $g['entries'][(int) $r['id']] = ['entry_id' => (int) $r['id'], 'date' => (string) $r['entry_date'], 'code' => $code,
                    'signed' => round(($g['entries'][(int) $r['id']]['signed'] ?? 0.0) + $amount, 2)];
            } elseif (!str_starts_with($code, '38')) {
                $g['counter'][$code] = round(($g['counter'][$code] ?? 0.0) + abs($amount), 2);
            }
            unset($g);
        }
        $out = [];
        foreach ($groups as $docNo => $g) {
            if (empty($g['accounts']) || empty($g['entries'])) {
                continue;
            }
            arsort($g['accounts']);
            $g['account_code'] = (string) array_key_first($g['accounts']);
            $g['entries'] = array_values(array_filter($g['entries'], static fn (array $e): bool => $e['code'] === $g['account_code']));
            $out[$docNo] = $g;
        }
        return $out;
    }

    /**
     * Návrh položky ze skupiny CR zápisů.
     *
     * @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    private function buildItem(int $supplierId, string $docNo, array $g, ?string $openingDate): array
    {
        $account = (string) $g['account_code'];
        $kind = (string) AccrualKinds::kindForAccount($account);
        $sign = AccrualKinds::normalSign($kind);
        $counter = $g['counter'] ?? [];
        arsort($counter);
        $sourceNo = preg_match('/č\.\s*([^\s]+)\s*$/u', (string) $g['description'], $m) === 1 ? $m[1] : null;
        $source = $sourceNo === null ? null : $this->findSource($supplierId, $kind, $sourceNo);
        $releases = [];
        foreach ($g['entries'] as $e) {
            $releases[] = ['entry_id' => $e['entry_id'], 'date' => $e['date'], 'amount' => round(-$e['signed'] * $sign, 2)];
        }
        usort($releases, static fn (array $a, array $b): int => [$a['date'], $a['entry_id']] <=> [$b['date'], $b['entry_id']]);
        $from = $source['date'] ?? null;
        $origin = $source === null ? null : $this->originLine($supplierId, $source, $account, $sign);
        $basis = $origin['amount'] ?? ($source['net'] ?? round(array_sum(array_column($releases, 'amount')), 2));
        $method = self::methodFromRhythm($releases);
        $to = $from === null ? null : self::reconstructEnd((float) $basis, $from, $releases, $method, $openingDate);
        // Řádky plánu: úsek od předchozího rozpuštění (nebo začátku období) do data zápisu.
        $prev = $from === null ? null : AccrualPlanBuilder::dayBefore(max($from, $openingDate ?? $from));
        foreach ($releases as &$r) {
            $r['from'] = $prev === null ? $r['date'] : min(AccrualPlanBuilder::dayAfter($prev), $r['date']);
            if ($method === 'monthly_days') {
                $r['from'] = max($r['from'], substr($r['date'], 0, 8) . '01');
            }
            $prev = $r['date'];
        }
        unset($r);
        $title = trim((string) preg_replace('/^Časové rozlišení\s+\S+\s+—\s*/u', '', (string) $g['description']));
        if ($source !== null && $source['partner_name'] !== null) {
            $title = mb_substr($title . ' — ' . $source['partner_name'], 0, 255);
        }
        return [
            'item_no' => $docNo,
            'import_key' => self::IMPORT_PREFIX . $docNo,
            'title' => $title !== '' ? $title : 'Časové rozlišení ' . $docNo,
            'kind' => $kind,
            'account_code' => $account,
            'counter_account_code' => $counter === [] ? null : (string) array_key_first($counter),
            'termination_account_code' => null,
            'partner_id' => $source['partner_id'] ?? null,
            'partner_name' => $source['partner_name'] ?? null,
            'source_type' => $source['type'] ?? 'migration',
            'source_id' => $source['id'] ?? null,
            'source_document_no' => $sourceNo === null ? null : mb_substr($sourceNo, 0, 50),
            'basis_amount' => round((float) $basis, 2),
            'period_from' => $from,
            'period_to' => $to,
            'method' => $from === null || $to === null ? 'manual' : $method,
            'status' => 'active',
            'terminated_on' => null,
            'termination_entry_id' => null,
            'origin' => $origin,
            'opening_share' => null,
            'reconstructed' => false,
            'releases' => $releases,
            'terminations' => [],
            'note' => 'Převzato z POHODY (' . $docNo . ').' . ($origin === null && $from !== null ? ' Konec období dopočten z rytmu rozpouštění.' : ''),
        ];
    }

    /**
     * Jen zápisy k 31. 12. → roční. Víc rozpuštění v jednom roce → měsíční podle dnů.
     * Jediný zápis mimo 31. 12. (rozpuštění do data ukončení) se bere jako roční.
     *
     * @param list<array<string,mixed>> $releases
     */
    private static function methodFromRhythm(array $releases): string
    {
        $perYear = [];
        foreach ($releases as $r) {
            $perYear[substr($r['date'], 0, 4)] = ($perYear[substr($r['date'], 0, 4)] ?? 0) + 1;
        }
        return max($perYear ?: [0]) >= 2 ? 'monthly_days' : 'yearly';
    }

    /**
     * Konec období z denní sazby: rozpuštění za celý úsek (rok, měsíc), jinak první úsek
     * od začátku pokrytí. Počet dnů = základ / sazba.
     *
     * @param list<array<string,mixed>> $releases
     */
    private static function reconstructEnd(float $basis, string $from, array $releases, string $method, ?string $openingDate): ?string
    {
        $rate = null;
        foreach ($releases as $r) {
            $date = (string) $r['date'];
            $segStart = $method === 'yearly' ? substr($date, 0, 4) . '-01-01' : substr($date, 0, 8) . '01';
            $isFull = $method === 'yearly' ? str_ends_with($date, '-12-31') : $date === (new \DateTimeImmutable($date))->format('Y-m-t');
            if ($isFull && $segStart > $from && (int) round($r['amount'] * 100) !== 0) {
                $rate = $r['amount'] / AccrualScheduleCalculator::daysInclusive($segStart, $date);
                break;
            }
        }
        if ($rate === null && $releases !== []) {
            $first = $releases[0];
            $start = max($from, $openingDate ?? $from);
            $days = AccrualScheduleCalculator::daysInclusive($start, (string) $first['date']);
            if ($days > 0 && (int) round($first['amount'] * 100) !== 0) {
                $rate = $first['amount'] / $days;
            }
        }
        if ($rate === null || $rate * $basis <= 0) {
            return null;
        }
        $total = (int) round($basis / $rate);
        return (new \DateTimeImmutable($from))->modify('+' . max(0, $total - 1) . ' days')->format('Y-m-d');
    }

    /**
     * Zdrojový doklad podle čísla z popisu.
     *
     * @return array{type:string, id:int, date:string, net:float, partner_id:?int, partner_name:?string}|null
     */
    private function findSource(int $supplierId, string $kind, string $number): ?array
    {
        $partner = "COALESCE(NULLIF(c.company_name, ''), NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)), ''))";
        $queries = [
            ['invoice', "SELECT d.id, d.issue_date AS d, d.total_without_vat AS net, d.client_id AS pid, {$partner} AS pname
                           FROM invoices d LEFT JOIN clients c ON c.id = d.client_id
                          WHERE d.supplier_id = ? AND d.varsymbol = ? AND d.status <> 'cancelled' ORDER BY d.id LIMIT 1"],
            ['purchase_invoice', "SELECT d.id, d.issue_date AS d, d.total_without_vat AS net, d.vendor_id AS pid, {$partner} AS pname
                           FROM purchase_invoices d LEFT JOIN clients c ON c.id = d.vendor_id
                          WHERE d.supplier_id = ? AND (d.vendor_invoice_number = ? OR d.varsymbol = ?) AND d.status <> 'cancelled' ORDER BY d.id LIMIT 1"],
            ['other_item', "SELECT d.id, d.issued_on AS d, COALESCE(d.amount_czk, d.amount) AS net, d.partner_id AS pid, COALESCE(d.partner_name, {$partner}) AS pname
                           FROM other_items d LEFT JOIN clients c ON c.id = d.partner_id
                          WHERE d.supplier_id = ? AND d.document_no = ? AND d.deleted_at IS NULL ORDER BY d.id LIMIT 1"],
        ];
        if ($kind === 'prepaid_expense') {
            $queries = [$queries[1], $queries[2], $queries[0]];
        }
        foreach ($queries as [$type, $sql]) {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($type === 'purchase_invoice' ? [$supplierId, $number, $number] : [$supplierId, $number]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r !== false) {
                return ['type' => $type, 'id' => (int) $r['id'], 'date' => (string) $r['d'], 'net' => round((float) $r['net'], 2),
                    'partner_id' => $r['pid'] === null ? null : (int) $r['pid'], 'partner_name' => $r['pname'] === null ? null : (string) $r['pname']];
            }
        }
        return null;
    }

    /**
     * Řádek vzniku na účtu položky v živých zápisech zdrojového dokladu (doklad v tomto roce).
     *
     * @param array<string,mixed> $source
     * @return array{entry_id:int, amount:float}|null
     */
    private function originLine(int $supplierId, array $source, string $account, int $sign): ?array
    {
        $entrySql = match ($source['type']) {
            'invoice', 'purchase_invoice' => DocumentPostingGroup::entriesSql($source['type'], $supplierId),
            default => null,
        };
        if ($entrySql !== null) {
            // entriesSql nese v sobě firmu; omezení na doklad doplní vnější WHERE.
            $stmt = $this->db->pdo()->prepare("SELECT x.entry_id FROM ({$entrySql}) x WHERE x.doc_id = ?");
            $stmt->execute([(int) $source['id']]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $stmt = $this->db->pdo()->prepare('SELECT journal_entry_id FROM other_items WHERE supplier_id = ? AND id = ? AND journal_entry_id IS NOT NULL');
            $stmt->execute([$supplierId, (int) $source['id']]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($ids === []) {
            return null;
        }
        $in = implode(',', $ids);
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.id, SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS amount
               FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE je.supplier_id = ? AND je.id IN ({$in}) AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL AND ca.account_code = ?
              GROUP BY je.id HAVING SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) <> 0
              ORDER BY je.id LIMIT 1"
        );
        $stmt->execute([$supplierId, $account]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : ['entry_id' => (int) $r['id'], 'amount' => round((float) $r['amount'] * $sign, 2)];
    }

    /**
     * Počáteční stav účtů 38x z převzatých zápisů počátečních stavů (poslední před prvním CR zápisem).
     *
     * @return array{date:?string, amounts: array<string, array{entry_id:?int, amount:float}>}
     */
    private function openingEntries(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.id, je.entry_date, ca.account_code,
                    SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS amount
               FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE je.supplier_id = ? AND je.source_type = 'opening' AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                AND ca.account_code >= '381' AND ca.account_code < '386'
                AND je.entry_date <= COALESCE((SELECT MIN(x.entry_date) FROM journal_entries x
                                                WHERE x.supplier_id = je.supplier_id AND x.source_type = 'manual' AND x.document_no LIKE '__CR%'), je.entry_date)
              GROUP BY je.id, je.entry_date, ca.account_code
              ORDER BY je.entry_date DESC, je.id DESC"
        );
        $stmt->execute([$supplierId]);
        $date = null;
        $amounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $date ??= (string) $r['entry_date'];
            if ((string) $r['entry_date'] !== $date) {
                continue;
            }
            $kind = AccrualKinds::kindForAccount((string) $r['account_code']);
            if ($kind === null || !in_array($kind, AccrualKinds::SUPPORTED, true)) {
                continue;
            }
            $amounts[(string) $r['account_code']] = ['entry_id' => (int) $r['id'],
                'amount' => round((float) $r['amount'] * AccrualKinds::normalSign($kind), 2)];
        }
        return ['date' => $date, 'amounts' => $amounts];
    }

    /**
     * Ukončení: ruční zápisy mimo řadu CR, které snižují 38x a mluví o ukončení či vyřazení.
     *
     * @param array<string, array<string,mixed>> $items
     * @return array{matched: list<array<string,mixed>>, unmatched: list<array<string,mixed>>}
     */
    private function terminations(int $supplierId, array $items): array
    {
        if ($items === []) {
            return ['matched' => [], 'unmatched' => []];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.id, je.entry_date, je.document_no, je.description, ca.account_code,
                    SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS amount
               FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE je.supplier_id = ? AND je.source_type = 'manual' AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                AND (je.document_no IS NULL OR je.document_no NOT LIKE '__CR%')
                AND EXISTS (SELECT 1 FROM journal_entry_lines x JOIN chart_of_accounts xa ON xa.id = x.account_id
                             WHERE x.supplier_id = je.supplier_id AND x.entry_id = je.id
                               AND xa.account_code >= '381' AND xa.account_code < '386')
              GROUP BY je.id, je.entry_date, je.document_no, je.description, ca.account_code
              ORDER BY je.entry_date, je.id"
        );
        $stmt->execute([$supplierId]);
        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            $entries[$id] ??= ['entry_id' => $id, 'date' => (string) $r['entry_date'], 'document_no' => $r['document_no'],
                'description' => (string) $r['description'], 'lines' => [], 'counter' => []];
            $code = (string) $r['account_code'];
            if (str_starts_with($code, '38')) {
                $entries[$id]['lines'][$code] = round((float) $r['amount'], 2);
            } else {
                $entries[$id]['counter'][$code] = abs(round((float) $r['amount'], 2));
            }
        }
        $lastDate = [];
        foreach ($items as $docNo => $p) {
            $lastDate[$docNo] = $p['releases'] === [] ? null : end($p['releases'])['date'];
        }
        $matched = [];
        $unmatched = [];
        $taken = [];
        foreach ($entries as $e) {
            if (preg_match('/ukon|vyřaz|předčas/iu', $e['description'] . ' ' . ($e['document_no'] ?? '')) !== 1) {
                continue;
            }
            foreach ($e['lines'] as $code => $signed) {
                $code = (string) $code;
                $kind = AccrualKinds::kindForAccount($code);
                if ($kind === null || !in_array($kind, AccrualKinds::SUPPORTED, true)) {
                    continue;
                }
                $amount = round(-$signed * AccrualKinds::normalSign($kind), 2);
                if ($amount <= 0) {
                    continue;
                }
                $candidates = [];
                $by = 'auto_description';
                if (preg_match_all('/(?:č\.|smlouv\w*)\s*(\d{7,8})/u', $e['description'], $m)) {
                    foreach ($m[1] as $contract) {
                        $year = substr($contract, 2, 2);
                        $seq = (int) substr($contract, 4);
                        foreach ($items as $docNo => $p) {
                            if ($p['account_code'] === $code && preg_match('/^' . $year . 'CR0*' . $seq . '$/', $docNo) === 1 && !isset($taken[$docNo])) {
                                $candidates[] = $docNo;
                            }
                        }
                    }
                }
                if ($candidates === []) {
                    $by = 'date';
                    foreach ($items as $docNo => $p) {
                        if ($p['account_code'] === $code && $lastDate[$docNo] === $e['date'] && !isset($taken[$docNo])) {
                            $candidates[] = $docNo;
                        }
                    }
                }
                if ($candidates === []) {
                    $unmatched[] = ['entry_id' => $e['entry_id'], 'date' => $e['date'], 'document_no' => $e['document_no'],
                        'description' => $e['description'], 'account_code' => $code, 'amount' => $amount];
                    continue;
                }
                $alloc = $this->allocate($items, $candidates, $e['date'], $amount);
                foreach ($candidates as $docNo) {
                    $taken[$docNo] = true;
                }
                arsort($e['counter']);
                $matched[] = ['entry_id' => $e['entry_id'], 'date' => $e['date'], 'document_no' => $e['document_no'],
                    'description' => $e['description'], 'account_code' => $code, 'amount' => $amount, 'matched_by' => $by,
                    'counter_account_code' => $e['counter'] === [] ? null : (string) array_key_first($e['counter']),
                    'allocations' => $alloc];
            }
        }
        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * Rozdělí částku ukončení mezi smlouvy podle odhadu jejich zůstatku k datu ukončení
     * (rekonstrukce z faktury a rytmu), poslední bere haléřový zbytek.
     *
     * @param array<string, array<string,mixed>> $items
     * @param list<string> $docNos
     * @return array<string,float>
     */
    private function allocate(array $items, array $docNos, string $date, float $amount): array
    {
        if (count($docNos) === 1) {
            return [$docNos[0] => $amount];
        }
        $weights = [];
        foreach ($docNos as $docNo) {
            $p = $items[$docNo];
            $w = ($p['period_from'] !== null && $p['period_to'] !== null)
                ? AccrualScheduleCalculator::deferredAmount((float) $p['basis_amount'], (string) $p['period_from'], (string) $p['period_to'], $date)
                : (float) $p['basis_amount'];
            $weights[$docNo] = max(0.0, $w);
        }
        $sum = array_sum($weights);
        $out = [];
        $rest = $amount;
        foreach ($docNos as $i => $docNo) {
            if ($i === count($docNos) - 1) {
                $out[$docNo] = round($rest, 2);
                break;
            }
            $part = $sum > 0 ? round($amount * $weights[$docNo] / $sum, 2) : round($amount / count($docNos), 2);
            $out[$docNo] = $part;
            $rest = round($rest - $part, 2);
        }
        return $out;
    }
}
