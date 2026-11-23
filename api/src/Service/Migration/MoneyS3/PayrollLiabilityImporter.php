<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalSettlementRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Repository\PayrollPostingImportRepository;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Migration\Shared\MigratedOtherItemPolicy;
use PDO;

/**
 * Mzdové závazky Money otevřené ke konci posledního převáděného roku jako ostatní závazky.
 *
 * Mzdový modul Money vede mzdy, pojistné a daň ze mzdy jako závazky (`KnihZav` s příznakem
 * mzdového dokladu `MZTyp`, v deníku zdroj KZ). Převedená firma mzdy v MyÚčtu nepočítá, předpis
 * je jen v deníku a lednové úhrady mezd za prosinec by v MyÚčtu neměly protějšek k párování.
 * Závazek na účtu mezd (331, 336, 342, {@see MigratedOtherItemPolicy::payroll()}), který byl ke
 * konci posledního převáděného období otevřený, proto vznikne jako ostatní závazek, stejně jako
 * u převodu z POHODY:
 *  - s vlastním převzatým zápisem KZ ho převezme bez nového účtování (`posted`, zápis dostane
 *    zdroj `other_item`),
 *  - bez vlastního zápisu (čistá mzda: Money ji vede na témž účtu na obou stranách, předpis
 *    nese interní doklad mezd) vznikne potvrzený bez zápisu a jako předpis dostane část řádku
 *    Dal téhož účtu v převzatém interním dokladu téhož měsíce (`journal_settlement_allocations`
 *    druhu `origin`, jako položka ze zůstatku zápisu), takže ji saldokonto vidí.
 *
 * Měsíc, jehož zaúčtování mezd je načtené z PAMICY ({@see PayrollPostingImportRepository}),
 * závazky nedostane: import PAMICY je založil sám.
 *
 * Úhrady: Money vede zbytek k úhradě (`UhZbyva`) a datum poslední úhrady (`UhDatum`) k datu
 * zálohy. Úhrada do konce období závazku (jeho účetního roku) jde do `settled_outside_amount`,
 * která datum nenese. Úhrada v pozdějším převáděném období (lednová výplata převedená spolu
 * s dalším rokem) se naváže na převzatý pohyb banky či pokladny, kterým Money závazek uhradilo
 * (`UDoklad`), jako úhrada ostatní položky s datem pohybu: saldokonto k rozvahovému dni
 * minulého roku ji tak nezapočte. Úhradu, kterou na pohyb navázat nejde, převod neeviduje
 * a ohlásí. Úhrada po konci posledního převáděného období do převodu nepatří.
 * Opakovaný převod položku nezdvojí (mapa `KZ|rok|číslo`), uhrazenou část nastaví absolutně
 * a úhrady spárované v MyÚčtu nezmění.
 */
final class PayrollLiabilityImporter
{
    public const STEP = 'payroll_liabilities';

    private const BATCH = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly OtherItemService $otherItems,
        private readonly JournalSettlementRepository $settlements,
        private readonly PayrollPostingImportRepository $payrollImports,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $lastEnd = $ctx->lastPeriodEnd();
        if ($lastEnd === null) {
            $p->finish(self::STEP);
            return;
        }
        $lastEnd = substr($lastEnd, 0, 10);
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_OTHER_ITEM);
        $candidates = [];
        $tracked = [];
        foreach ($ctx->backup->rowsAcrossYears('KnihZav') as $r) {
            $year = $ctx->yearOf($r);
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            $total = round((float) ($r['Celkem'] ?? 0), 2);
            $booked = InvoiceImporter::date($r, ['DatUcPr', 'DatVyst']);
            if ($year === null || $docNo === '' || (int) ($r['MZTyp'] ?? 0) === 0 || (int) ($r['Storno'] ?? 0) === 1
                || $total <= 0.0 || $booked === null || $booked > $lastEnd) {
                continue;
            }
            $key = 'KZ|' . $year . '|' . $docNo;
            $ownEnd = min($lastEnd, substr((string) ($ctx->periods[$year]['ends_on'] ?? sprintf('%04d-12-31', $year)), 0, 10));
            $openOwn = self::openAt($r, $ownEnd);
            $open = self::openAt($r, $lastEnd);
            $settlement = [
                'key' => $key, 'year' => $year, 'doc' => $docNo, 'booked' => $booked, 'own_end' => $ownEnd,
                'paid' => round($total - $openOwn, 2), 'later' => round($openOwn - $open, 2),
                'paid_on' => InvoiceImporter::date($r, ['UhDatum']), 'settled_by' => trim((string) ($r['UDoklad'] ?? '')),
            ];
            if (isset($existing[$key])) {
                $tracked[(int) $existing[$key]] = $settlement;
                $p->count(self::STEP, 'existing');
                continue;
            }
            if ($open < 0.005 || $ctx->skipsNew(self::STEP)) {
                continue;
            }
            $candidates[] = $settlement + ['dir' => (string) $r['__dir'], 'total' => $total, 'open' => $open, 'row' => $r];
        }
        $candidates = $this->withoutPayrollImports($ctx, $candidates);
        $skipped = [];
        if ($candidates !== []) {
            $postings = $this->postings($ctx, $candidates);
            $accounts = $this->moneyAccounts($ctx, $candidates);
            foreach ($candidates as $c) {
                $posting = $postings[$c['key']] ?? null;
                $account = $posting['account'] ?? $this->chartCode($ctx, $accounts[$c['dir'] . '|' . $c['doc']] ?? null);
                if ($account === null || !MigratedOtherItemPolicy::payroll($account)) {
                    $skipped[] = $c['doc'] . ($account !== null ? " ({$account})" : '');
                    $p->count(self::STEP, 'not_payroll_account');
                    continue;
                }
                $id = $this->insert($ctx, $c, $account, $posting);
                if ($id === null) {
                    continue;
                }
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_OTHER_ITEM, $c['key'], $id, $ctx->runId);
                $tracked[$id] = $c;
                $p->count(self::STEP, $posting !== null ? 'created' : 'created_unposted');
                $p->count(self::STEP, 'open_amount', round($c['open'], 2));
            }
        }
        $this->linkOrigins($ctx, $this->withoutOrigin($ctx, $tracked));
        foreach ($tracked as $id => $t) {
            $this->settle($ctx, $id, $t['paid'], $t['own_end']);
        }
        $this->allocateLaterPayments($ctx, $tracked);
        if ($skipped !== []) {
            $p->info(self::STEP, 'payroll_liabilities_other_accounts', sprintf(
                '%d mzdových závazků otevřených k %s není na účtu mezd, pojistného ani daně ze mzdy (331, 336, 342), převod je jako ostatní závazky nezaložil: %s. Podle potřeby je založte ze zůstatku zápisu.',
                count($skipped), date('j. n. Y', (int) strtotime($lastEnd)), implode(', ', array_slice($skipped, 0, 20)) . (count($skipped) > 20 ? ', …' : ''),
            ), ['documents' => array_slice($skipped, 0, ImportProtocol::LIST_LIMIT)]);
        }
        $p->finish(self::STEP);
    }

    /**
     * Částka závazku otevřená k `$asOf`: úhrada až po něm (`UhDatum`) se nepočítá a závazek
     * je otevřený celý, jinak platí zbytek k úhradě z Money.
     *
     * @param array<string,mixed> $r řádek `KnihZav`
     */
    public static function openAt(array $r, string $asOf): float
    {
        $total = round((float) ($r['Celkem'] ?? 0), 2);
        $paidOn = InvoiceImporter::date($r, ['UhDatum']);
        if ($paidOn !== null && $paidOn > $asOf) {
            return $total;
        }
        return max(0.0, min($total, round((float) ($r['UhZbyva'] ?? 0), 2)));
    }

    /**
     * Závazky měsíců, jejichž zaúčtování mezd je načtené z PAMICY, převod nezakládá: import
     * PAMICY je založil sám a druhé závazky by zdvojily úhrady.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function withoutPayrollImports(ImportContext $ctx, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $imported = $this->payrollImports->activePeriods($ctx->supplierId, array_map(static fn (array $c): string => substr($c['booked'], 0, 7), $candidates));
        if ($imported === []) {
            return $candidates;
        }
        $kept = [];
        $docs = [];
        foreach ($candidates as $c) {
            if (isset($imported[substr($c['booked'], 0, 7)])) {
                $docs[] = $c['doc'];
                $ctx->protocol->count(self::STEP, 'payroll_import_month');
                continue;
            }
            $kept[] = $c;
        }
        $months = array_map(static fn (string $m): string => substr($m, 5, 2) . '/' . substr($m, 0, 4), array_keys($imported));
        sort($months);
        $ctx->protocol->info(self::STEP, 'payroll_liabilities_payroll_import', sprintf(
            '%d mzdových závazků převod nezaložil, zaúčtování mezd za %s je načtené z mzdového programu (PAMICA) i s jeho závazky: %s.',
            count($docs), implode(', ', $months), implode(', ', array_slice($docs, 0, 20)) . (count($docs) > 20 ? ', …' : ''),
        ), ['documents' => array_slice($docs, 0, ImportProtocol::LIST_LIMIT)]);
        return $kept;
    }

    /**
     * Převzatý zápis KZ závazku jako zaúčtování položky: jediný živý ruční zápis bez jiného
     * vlastníka, závazek na jednom účtu proti protiúčtům v celé částce ({@see MigratedOtherItemPolicy::postingLines()}).
     * Mapa zápisů se čte jednou za rok, hlavičky a řádky zápisů po dávkách. Závazek bez
     * vlastního zápisu (nebo se zápisem, který převzít nejde) ve výsledku není.
     *
     * @param list<array<string,mixed>> $candidates
     * @return array<string,array{account:string,lines:list<array{account_code:string,amount:float}>,entry_id:int,date:string,posted_at:string}> klíč mapy závazku => zaúčtování
     */
    private function postings(ImportContext $ctx, array $candidates): array
    {
        $wanted = [];
        foreach ($candidates as $c) {
            $wanted[$c['year']][$c['doc']] = $c;
        }
        $entryOf = [];
        foreach ($wanted as $year => $docs) {
            $entries = [];
            foreach ($this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $year . '|KZ|') as $key => $entryId) {
                $docNo = explode('|', (string) $key)[2] ?? '';
                if (isset($docs[$docNo])) {
                    $entries[$docNo][] = (int) $entryId;
                }
            }
            foreach ($entries as $docNo => $ids) {
                if (count($ids) === 1) {
                    $entryOf[$ids[0]] = $docs[$docNo];
                }
            }
        }
        $pdo = $this->db->pdo();
        $out = [];
        foreach (array_chunk(array_keys($entryOf), self::BATCH) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $heads = $pdo->prepare(
                "SELECT id, entry_date, posted_at FROM journal_entries
                  WHERE supplier_id = ? AND id IN ({$in}) AND source_type = 'manual' AND source_id IS NULL AND reversed_by IS NULL
                    AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links l WHERE l.supplier_id = journal_entries.supplier_id AND l.entry_id = journal_entries.id)
                    AND NOT EXISTS (SELECT 1 FROM other_items oi WHERE oi.supplier_id = journal_entries.supplier_id AND oi.journal_entry_id = journal_entries.id)"
            );
            $heads->execute([$ctx->supplierId, ...$chunk]);
            $head = [];
            foreach ($heads->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $head[(int) $h['id']] = $h;
            }
            if ($head === []) {
                continue;
            }
            $in = implode(',', array_fill(0, count($head), '?'));
            $rows = $pdo->prepare(
                "SELECT l.entry_id, l.side, l.amount, a.account_code, a.account_type FROM journal_entry_lines l
                   JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                  WHERE l.supplier_id = ? AND l.entry_id IN ({$in}) ORDER BY l.entry_id, l.line_no, l.id"
            );
            $rows->execute([$ctx->supplierId, ...array_keys($head)]);
            $lines = [];
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $lines[(int) $l['entry_id']][] = $l;
            }
            foreach ($head as $entryId => $h) {
                $c = $entryOf[$entryId];
                $accounts = MigratedOtherItemPolicy::postingLines($lines[$entryId] ?? [], 'payable', $c['total']);
                if ($accounts !== null) {
                    $out[$c['key']] = $accounts + ['entry_id' => $entryId, 'date' => (string) $h['entry_date'], 'posted_at' => (string) $h['posted_at']];
                }
            }
        }
        return $out;
    }

    /**
     * Účet závazku z deníku Money (`UcD` řádků zdroje KZ dokladu) - pro závazek bez vlastního
     * zápisu (čistá mzda na témž účtu na obou stranách). "adresář|číslo" => účet Money.
     *
     * @param list<array<string,mixed>> $candidates
     * @return array<string,string>
     */
    private function moneyAccounts(ImportContext $ctx, array $candidates): array
    {
        $wanted = [];
        foreach ($candidates as $c) {
            $wanted[$c['dir']][$c['doc']] = true;
        }
        $out = [];
        foreach ($wanted as $dir => $docs) {
            $table = $ctx->backup->table('UcDenik', $ctx->backup->dir() . DIRECTORY_SEPARATOR . $dir);
            foreach ($table !== null && $table->hasData() ? $table->rows(['Zdroj', 'Doklad', 'UcD']) : [] as $r) {
                $docNo = trim((string) ($r['Doklad'] ?? ''));
                if (strtoupper(trim((string) ($r['Zdroj'] ?? ''))) === 'KZ' && isset($docs[$docNo]) && !isset($out[$dir . '|' . $docNo])) {
                    $out[$dir . '|' . $docNo] = trim((string) ($r['UcD'] ?? ''));
                }
            }
        }
        return $out;
    }

    /** Kód účtu MyÚčta k účtu Money, jen když ho převedená osnova má. */
    private function chartCode(ImportContext $ctx, ?string $moneyAccount): ?string
    {
        $code = $moneyAccount !== null ? AccountCode::fromMoney($moneyAccount) : null;
        return $code !== null && isset($ctx->accountIds[$code]) ? $code : null;
    }

    /**
     * @param array<string,mixed> $c
     * @param array{account:string,lines:list<array{account_code:string,amount:float}>,entry_id:int,date:string,posted_at:string}|null $posting
     */
    private function insert(ImportContext $ctx, array $c, string $account, ?array $posting): ?int
    {
        $r = $c['row'];
        $user = $ctx->userId > 0 ? $ctx->userId : null;
        $partnerName = mb_substr(trim((string) ($r['AdNazev'] ?? '')), 0, 190) ?: null;
        $partnerId = $ctx->clientsByMoneyNo[(int) ($r['AdCislo'] ?? 0)] ?? null;
        $symbol = mb_substr(preg_replace('/\D+/', '', (string) ($r['VarSym'] ?? '')) ?? '', 0, 20) ?: null;
        $note = $posting !== null
            ? "Převzato z Money S3, mzdový závazek {$c['doc']}."
            : "Převzato z Money S3, mzdový závazek {$c['doc']}. Předpis je v deníku mzdovým dokladem, položka se proto neúčtuje.";
        $pdo = $this->db->pdo();
        try {
            $pdo->prepare(
                'INSERT INTO other_items
                  (supplier_id, side, kind, title, partner_id, partner_name, issued_on, accounting_on, due_on,
                   currency, amount, amount_czk, variable_symbol, account_code, counter_account_code,
                   note, status, document_no, journal_entry_id, posted_at, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $ctx->supplierId, 'payable', 'other', mb_substr(trim((string) ($r['Popis'] ?? '')) ?: 'Mzdový závazek ' . $c['doc'], 0, 255),
                $partnerId, $partnerName, InvoiceImporter::date($r, ['DatVyst', 'DatUcPr']) ?? $c['booked'], $posting['date'] ?? $c['booked'],
                InvoiceImporter::date($r, ['DatSpl']) ?? $c['booked'], 'CZK', number_format($c['total'], 2, '.', ''), number_format($c['total'], 2, '.', ''),
                $symbol, $account, $posting !== null && count($posting['lines']) === 1 ? $posting['lines'][0]['account_code'] : null,
                $note, $posting !== null ? 'posted' : 'confirmed', mb_substr($c['doc'], 0, 50),
                $posting['entry_id'] ?? null, $posting['posted_at'] ?? null, $user, $user,
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $ctx->protocol->warn(self::STEP, 'number_taken', "Mzdový závazek {$c['doc']} nelze převzít, číslo už má jiná pohledávka nebo závazek.", ['document_no' => $c['doc']]);
            return null;
        }
        $id = (int) $pdo->lastInsertId();
        if ($posting === null) {
            return $id;
        }
        if (count($posting['lines']) > 1) {
            $line = $pdo->prepare('INSERT INTO other_item_posting_lines (supplier_id, other_item_id, position, account_code, amount) VALUES (?, ?, ?, ?, ?)');
            foreach ($posting['lines'] as $position => $l) {
                $line->execute([$ctx->supplierId, $id, $position + 1, $l['account_code'], number_format($l['amount'], 2, '.', '')]);
            }
        }
        $owner = $pdo->prepare(
            "UPDATE journal_entries SET source_type = 'other_item', source_id = ?
              WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL"
        );
        $owner->execute([$id, $posting['entry_id'], $ctx->supplierId]);
        if ($owner->rowCount() !== 1) {
            throw new MoneyS3Exception('other_item_journal_claim', 'Převzatý zápis mzdového závazku už vlastní jiný doklad.');
        }
        return $id;
    }

    /**
     * Potvrzené závazky bez zápisu (čistá mzda), které ještě nemají předpis z řádku zápisu:
     * nově založené i z dřívějšího běhu.
     *
     * @param array<int,array<string,mixed>> $tracked
     * @return array<int,array{doc:string,year:int,month:string,account:string,amount:float}>
     */
    private function withoutOrigin(ImportContext $ctx, array $tracked): array
    {
        $out = [];
        foreach (array_chunk(array_keys($tracked), self::BATCH) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT oi.id, oi.account_code, oi.amount FROM other_items oi
                  WHERE oi.supplier_id = ? AND oi.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                    AND oi.status = 'confirmed' AND oi.journal_entry_id IS NULL AND oi.deleted_at IS NULL AND oi.currency = 'CZK'
                    AND NOT EXISTS (SELECT 1 FROM journal_settlement_allocations ja
                                     WHERE ja.supplier_id = oi.supplier_id AND ja.doc_type = 'other_item' AND ja.doc_id = oi.id AND ja.kind = 'origin')"
            );
            $stmt->execute([$ctx->supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $t = $tracked[(int) $r['id']];
                $out[(int) $r['id']] = ['doc' => $t['doc'], 'year' => $t['year'], 'month' => substr($t['booked'], 0, 7),
                    'account' => (string) $r['account_code'], 'amount' => round((float) $r['amount'], 2)];
            }
        }
        return $out;
    }

    /**
     * Předpis závazku bez vlastního zápisu (čistá mzda): část řádku Dal téhož účtu v živém
     * převzatém interním dokladu (zdroj ID) téhož měsíce s dostatečnou volnou částkou, jako
     * u položky ze zůstatku zápisu ({@see \MyInvoice\Service\Accounting\JournalSettlementService::createOtherItem()}).
     * Nic se neúčtuje, saldokonto položku čte z rozpadu řádku. Víc čistých mezd téhož měsíce
     * si řádek hrubých mezd dělí, dokud stačí jeho volná částka.
     *
     * @param array<int,array{doc:string,year:int,month:string,account:string,amount:float}> $items
     */
    private function linkOrigins(ImportContext $ctx, array $items): void
    {
        $p = $ctx->protocol;
        $accountIds = [];
        $months = [];
        foreach ($items as $id => $item) {
            $accountId = $ctx->accountIds[$item['account']] ?? null;
            if ($accountId === null) {
                unset($items[$id]);
                continue;
            }
            $accountIds[$accountId] = true;
            $months[$item['year']][$item['month']] = true;
        }
        if ($items === []) {
            return;
        }
        $entryIds = [];
        foreach ($months as $year => $wanted) {
            foreach ($this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $year . '|ID|') as $key => $entryId) {
                if (isset($wanted[substr(explode('|', (string) $key)[3] ?? '', 0, 7)])) {
                    $entryIds[] = (int) $entryId;
                }
            }
        }
        $pdo = $this->db->pdo();
        $lines = [];
        $accounts = implode(',', array_map('intval', array_keys($accountIds)));
        foreach (array_chunk(array_values(array_unique($entryIds)), self::BATCH) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare(
                "SELECT l.entry_id, l.line_no, l.account_id, l.side, l.amount, e.entry_date
                   FROM journal_entry_lines l
                   JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
                  WHERE l.supplier_id = ? AND l.entry_id IN ({$in}) AND l.account_id IN ({$accounts})
                    AND l.side = 'credit' AND l.is_red_storno = 0 AND COALESCE(l.currency_code, 'CZK') = 'CZK'
                    AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                    AND NOT EXISTS (SELECT 1 FROM journal_entry_lines dup WHERE dup.supplier_id = l.supplier_id AND dup.entry_id = l.entry_id
                                     AND dup.line_no = l.line_no AND dup.id <> l.id)
                  ORDER BY e.entry_date, l.entry_id, l.line_no"
            );
            $stmt->execute([$ctx->supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $lines[$l['entry_id'] . '|' . $l['line_no'] . '|' . $l['account_id'] . '|credit'] = [
                    'entry_id' => (int) $l['entry_id'], 'line_no' => (int) $l['line_no'], 'account_id' => (int) $l['account_id'],
                    'side' => 'credit', 'month' => substr((string) $l['entry_date'], 0, 7), 'free' => round((float) $l['amount'], 2),
                ];
            }
            $used = $pdo->prepare(
                "SELECT entry_id, line_no, account_id, side, SUM(amount) FROM journal_settlement_allocations
                  WHERE supplier_id = ? AND entry_id IN ({$in}) AND (kind = 'settlement' OR released_on IS NULL)
                  GROUP BY entry_id, line_no, account_id, side"
            );
            $used->execute([$ctx->supplierId, ...$chunk]);
            foreach ($used->fetchAll(PDO::FETCH_NUM) as [$entryId, $lineNo, $accountId, $side, $amount]) {
                $key = $entryId . '|' . $lineNo . '|' . $accountId . '|' . $side;
                if (isset($lines[$key])) {
                    $lines[$key]['free'] = round($lines[$key]['free'] - (float) $amount, 2);
                }
            }
        }
        $missing = [];
        foreach ($items as $id => $item) {
            $accountId = $ctx->accountIds[$item['account']];
            $best = null;
            foreach ($lines as $key => $line) {
                if ($line['account_id'] === $accountId && $line['month'] === $item['month'] && $line['free'] + 0.005 >= $item['amount']
                    && ($best === null || $line['free'] > $lines[$best]['free'] + 0.005)) {
                    $best = $key;
                }
            }
            if ($best === null) {
                $missing[] = $item['doc'];
                $p->count(self::STEP, 'without_origin');
                continue;
            }
            $this->settlements->insert($ctx->supplierId, $lines[$best], 'origin', 'other_item', $id, $item['amount'], $ctx->userId > 0 ? $ctx->userId : null);
            $lines[$best]['free'] = round($lines[$best]['free'] - $item['amount'], 2);
            $p->count(self::STEP, 'origin_linked');
        }
        if ($missing !== []) {
            $p->info(self::STEP, 'payroll_liabilities_without_origin', sprintf(
                '%d mzdových závazků bez vlastního zápisu (čistá mzda) nemá v převzatém interním dokladu téhož měsíce řádek s předpisem na svém účtu, saldokonto je nevidí: %s. Předpis navažte ze zůstatku zápisu.',
                count($missing), implode(', ', array_slice($missing, 0, 20)) . (count($missing) > 20 ? ', …' : ''),
            ), ['documents' => array_slice($missing, 0, ImportProtocol::LIST_LIMIT)]);
        }
    }

    /**
     * Část závazku uhrazená do konce jeho období mimo banku a pokladnu MyÚčta
     * (`settled_outside_amount`, bez data). Nastavuje se absolutně; úhrady spárované
     * v MyÚčtu (alokace) z téhož období se odečtou a položku neotevřou. Úhrady z pozdějších
     * období sem nepatří, nesou je alokace s datem pohybu ({@see allocateLaterPayments()}).
     */
    private function settle(ImportContext $ctx, int $id, float $paid, string $ownEnd): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items oi
                SET oi.settled_outside_amount = GREATEST(0, LEAST(oi.amount, ?) - COALESCE((SELECT SUM(a.amount)
                    FROM other_item_allocations a WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id AND a.reversed_on IS NULL
                     AND a.payment_on <= ?), 0))
              WHERE oi.supplier_id = ? AND oi.id = ? AND oi.deleted_at IS NULL'
        );
        $stmt->execute([number_format(max(0.0, $paid), 2, '.', ''), $ownEnd, $ctx->supplierId, $id]);
        if ($stmt->rowCount() > 0) {
            $ctx->protocol->count(self::STEP, 'settled_outside');
        }
    }

    /**
     * Úhrada závazku v pozdějším převáděném období (lednová výplata mezd za prosinec, převedená
     * s dalším rokem): přiřazení převzatého pohybu banky či pokladny, kterým Money závazek
     * uhradilo (`UDoklad`), přes {@see OtherItemService::allocate()} - ověří volnou částku, směr
     * a zaúčtování pohybu na účet závazku. Pohyb vybírá {@see DocumentLinker::choosePayment()}
     * (číslo dokladu se v Money každý rok opakuje). Pohyb, který převod označil jako zaúčtovaný
     * bez dokladu, se nejdřív vrátí mezi nespárované. Úhrada spárovaná dřívějším během (mapa
     * `kz|rok|číslo`) ani v MyÚčtu se nezdvojí; nenavázanou úhradu převod ohlásí.
     *
     * @param array<int,array<string,mixed>> $tracked
     */
    private function allocateLaterPayments(ImportContext $ctx, array $tracked): void
    {
        $p = $ctx->protocol;
        $due = array_filter($tracked, static fn (array $t): bool => $t['later'] >= 0.005);
        if ($due === []) {
            return;
        }
        $mapped = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, 'kz|');
        [$live, $allocated] = $this->paymentState($ctx->supplierId, $due);
        $due = array_intersect_key($due, $live);
        $numbers = [];
        foreach ($due as $t) {
            if ($t['settled_by'] !== '') {
                $numbers[$t['settled_by']] = true;
            }
        }
        [$bankByDoc, $cashByDoc] = $this->paymentCandidates($ctx, $numbers);
        $pdo = $this->db->pdo();
        $unresolved = [];
        foreach ($due as $id => $t) {
            $mapKey = 'kz|' . $t['year'] . '|' . $t['doc'];
            if (isset($mapped[$mapKey])) {
                $p->count(self::STEP, 'payment_existing');
                continue;
            }
            $amount = round($t['later'] - ($allocated[$id] ?? 0.0), 2);
            if ($amount < 0.005) {
                $p->count(self::STEP, 'payment_matched_in_app');
                continue;
            }
            $paymentYear = $t['paid_on'] !== null ? (int) substr($t['paid_on'], 0, 4) : $t['year'] + 1;
            $txId = DocumentLinker::choosePayment($bankByDoc[$t['settled_by']] ?? [], $paymentYear, $t['later'], $t['paid_on']);
            $cashId = $txId === null ? DocumentLinker::choosePayment($cashByDoc[$t['settled_by']] ?? [], $paymentYear, $t['later'], $t['paid_on']) : null;
            if ($txId === null && $cashId === null) {
                $unresolved[] = $t['doc'];
                $p->count(self::STEP, 'payment_not_found');
                continue;
            }
            $candidate = $txId !== null ? $bankByDoc[$t['settled_by']][$txId] : $cashByDoc[$t['settled_by']][$cashId];
            if (!$ctx->writable($candidate['date'])) {
                $unresolved[] = $t['doc'];
                $p->count(self::STEP, 'closed_period_skipped');
                continue;
            }
            $input = $txId !== null ? ['bank_transaction_id' => $txId] : ['cash_document_id' => $cashId];
            $input['amount'] = round(min($amount, abs($candidate['amount'])), 2);
            $pdo->exec('SAVEPOINT money_s3_payroll_payment');
            try {
                if ($txId !== null) {
                    $pdo->prepare(
                        "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                            SET t.match_status = 'unmatched', t.match_reason = NULL, t.matched_at = NULL, t.matched_by = NULL, t.ignore_note = NULL
                          WHERE t.id = ? AND s.supplier_id = ? AND t.match_status = 'ignored' AND t.match_reason = 'money_s3_booked'"
                    )->execute([$txId, $ctx->supplierId]);
                } else {
                    // Pokladna převzala doklad jako obecný výdej; vazbu na položku dostává až tady.
                    $pdo->prepare("UPDATE cash_documents SET purpose = 'other'
                        WHERE id = ? AND supplier_id = ? AND purpose IN ('sale', 'purchase') AND invoice_id IS NULL AND purchase_invoice_id IS NULL
                          AND NOT EXISTS (SELECT 1 FROM cash_document_vat_lines v WHERE v.cash_document_id = cash_documents.id AND v.vat_amount <> 0)")
                        ->execute([$cashId, $ctx->supplierId]);
                }
                $this->otherItems->allocate($ctx->supplierId, $id, $input, $ctx->userId > 0 ? $ctx->userId : null);
                $pdo->exec('RELEASE SAVEPOINT money_s3_payroll_payment');
            } catch (OtherItemException $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT money_s3_payroll_payment');
                $pdo->exec('RELEASE SAVEPOINT money_s3_payroll_payment');
                $unresolved[] = $t['doc'];
                $p->count(self::STEP, 'payment_not_allocated');
                $p->warn(self::STEP, 'payroll_payment', "Úhradu {$t['settled_by']} mzdového závazku {$t['doc']} nešlo přiřadit: {$e->getMessage()}", ['document_no' => $t['doc']]);
                continue;
            }
            $column = $txId !== null ? 'bank_transaction_id' : 'cash_document_id';
            $allocation = $pdo->prepare("SELECT id FROM other_item_allocations WHERE supplier_id = ? AND other_item_id = ? AND {$column} = ? AND reversed_on IS NULL");
            $allocation->execute([$ctx->supplierId, $id, $txId ?? $cashId]);
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, $mapKey, (int) $allocation->fetchColumn(), $ctx->runId);
            $p->count(self::STEP, $txId !== null ? 'payment_bank' : 'payment_cash');
        }
        if ($unresolved !== []) {
            $p->warn(self::STEP, 'payroll_payments_unresolved', sprintf(
                '%d mzdových závazků má podle Money úhradu v pozdějším převáděném období, kterou převod nenavázal na pohyb v převedené bance ani pokladně: %s. Závazek zůstává otevřený, úhradu spárujte ručně.',
                count($unresolved), implode(', ', array_slice($unresolved, 0, 20)) . (count($unresolved) > 20 ? ', …' : ''),
            ), ['documents' => array_slice($unresolved, 0, ImportProtocol::LIST_LIMIT)]);
        }
    }

    /**
     * Závazky, kterým jde úhradu přiřadit (nesmazané, potvrzené či zaúčtované), a jejich úhrady
     * spárované po konci jejich období (v MyÚčtu nebo dřívějším během).
     *
     * @param array<int,array<string,mixed>> $due
     * @return array{0:array<int,true>,1:array<int,float>}
     */
    private function paymentState(int $supplierId, array $due): array
    {
        $live = [];
        $out = [];
        foreach (array_chunk(array_keys($due), self::BATCH) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $items = $this->db->pdo()->prepare(
                "SELECT id FROM other_items WHERE supplier_id = ? AND id IN ({$in}) AND deleted_at IS NULL AND status IN ('posted', 'confirmed')"
            );
            $items->execute([$supplierId, ...$chunk]);
            foreach ($items->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $live[(int) $id] = true;
            }
            $stmt = $this->db->pdo()->prepare(
                'SELECT other_item_id, payment_on, amount FROM other_item_allocations
                  WHERE supplier_id = ? AND other_item_id IN (' . $in . ') AND reversed_on IS NULL'
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $id = (int) $a['other_item_id'];
                if ((string) $a['payment_on'] > $due[$id]['own_end']) {
                    $out[$id] = ($out[$id] ?? 0.0) + (float) $a['amount'];
                }
            }
        }
        return [$live, $out];
    }

    /**
     * Převzaté pohyby banky a pokladny s čísly dokladů úhrad: číslo => id => kandidát
     * pro {@see DocumentLinker::choosePayment()}. Částky a data po dávkách.
     *
     * @param array<string,true> $numbers
     * @return array{0:array<string,array<int,array{id:int,year:int,amount:float,date:?string}>>,1:array<string,array<int,array{id:int,year:int,amount:float,date:?string}>>}
     */
    private function paymentCandidates(ImportContext $ctx, array $numbers): array
    {
        $out = [[], []];
        if ($numbers === []) {
            return $out;
        }
        $sql = [
            'SELECT t.id, t.amount, DATE(t.posted_at) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.id IN (%s)',
            'SELECT id, total_amount, issue_date FROM cash_documents WHERE supplier_id = ? AND id IN (%s)',
        ];
        foreach ([$ctx->bankTransactions, $ctx->cashDocuments] as $i => $docs) {
            $wanted = [];
            foreach ($docs as $key => $id) {
                [$year, $docNo] = explode('|', (string) $key, 3) + [1 => ''];
                if (isset($numbers[$docNo])) {
                    $wanted[(int) $id] = [$docNo, (int) $year];
                }
            }
            foreach (array_chunk(array_keys($wanted), self::BATCH) as $chunk) {
                $stmt = $this->db->pdo()->prepare(sprintf($sql[$i], implode(',', array_fill(0, count($chunk), '?'))));
                $stmt->execute([$ctx->supplierId, ...$chunk]);
                foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $amount, $date]) {
                    [$docNo, $year] = $wanted[(int) $id];
                    $out[$i][$docNo][(int) $id] = ['id' => (int) $id, 'year' => $year,
                        'amount' => $i === 0 && isset($ctx->bankTransactionCzk[(int) $id]) ? $ctx->bankTransactionCzk[(int) $id] : (float) $amount,
                        'date' => $date !== null ? substr((string) $date, 0, 10) : null];
                }
            }
        }
        return $out;
    }
}
