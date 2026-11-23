<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
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
 *    nese interní doklad mezd) vznikne potvrzený bez zápisu.
 *
 * Otevřený ke konci období: Money vede zbytek k úhradě (`UhZbyva`) a datum poslední úhrady
 * (`UhDatum`) k datu zálohy. Úhrada po konci období (lednová výplata) do převodu nepatří,
 * závazek je pak otevřený celý. Část uhrazená do konce období jde do `settled_outside_amount`.
 * Opakovaný převod položku nezdvojí (mapa `KZ|rok|číslo`), uhrazenou část nastaví absolutně
 * podle nového konce převáděných období a úhrady spárované v MyÚčtu nezmění.
 */
final class PayrollLiabilityImporter
{
    public const STEP = 'payroll_liabilities';

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
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
            $open = self::openAt($r, $lastEnd);
            if (isset($existing[$key])) {
                $this->settle($ctx, (int) $existing[$key], round($total - $open, 2));
                $p->count(self::STEP, 'existing');
                continue;
            }
            if ($open < 0.005 || $ctx->skipsNew(self::STEP)) {
                continue;
            }
            $candidates[] = ['key' => $key, 'year' => $year, 'dir' => (string) $r['__dir'], 'doc' => $docNo, 'total' => $total, 'open' => $open,
                'booked' => $booked, 'row' => $r];
        }
        if ($candidates === []) {
            $p->finish(self::STEP);
            return;
        }
        $accounts = $this->moneyAccounts($ctx, $candidates);
        $skipped = [];
        foreach ($candidates as $c) {
            $posting = $this->posting($ctx, $c);
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
            $this->settle($ctx, $id, round($c['total'] - $c['open'], 2));
            $p->count(self::STEP, $posting !== null ? 'created' : 'created_unposted');
            $p->count(self::STEP, 'open_amount', round($c['open'], 2));
        }
        if ($skipped !== []) {
            $p->info(self::STEP, 'payroll_liabilities_other_accounts', sprintf(
                '%d mzdových závazků otevřených k %s není na účtu mezd, pojistného ani daně ze mzdy (331, 336, 342), převod je jako ostatní závazky nezaložil: %s. Podle potřeby je založte ze zůstatku zápisu.',
                count($skipped), date('j. n. Y', (int) strtotime($lastEnd)), implode(', ', array_slice($skipped, 0, 20)) . (count($skipped) > 20 ? ', …' : ''),
            ), ['documents' => array_slice($skipped, 0, ImportProtocol::LIST_LIMIT)]);
        }
        $p->finish(self::STEP);
    }

    /**
     * Částka závazku otevřená ke konci převáděných období: úhrada až po něm (`UhDatum`) se
     * nepočítá a závazek je otevřený celý, jinak platí zbytek k úhradě z Money.
     *
     * @param array<string,mixed> $r řádek `KnihZav`
     */
    public static function openAt(array $r, string $lastEnd): float
    {
        $total = round((float) ($r['Celkem'] ?? 0), 2);
        $paidOn = InvoiceImporter::date($r, ['UhDatum']);
        if ($paidOn !== null && $paidOn > $lastEnd) {
            return $total;
        }
        return max(0.0, min($total, round((float) ($r['UhZbyva'] ?? 0), 2)));
    }

    /**
     * Převzatý zápis KZ závazku jako zaúčtování položky: jediný živý ruční zápis bez jiného
     * vlastníka, závazek na jednom účtu proti protiúčtům v celé částce ({@see MigratedOtherItemPolicy::postingLines()}).
     * Null = závazek vlastní zápis nemá (nebo nejde převzít).
     *
     * @param array<string,mixed> $c
     * @return array{account:string,lines:list<array{account_code:string,amount:float}>,entry_id:int,date:string,posted_at:string}|null
     */
    private function posting(ImportContext $ctx, array $c): ?array
    {
        $keys = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $c['year'] . '|KZ|' . $c['doc'] . '|');
        if (count($keys) !== 1) {
            return null;
        }
        $entryId = (int) reset($keys);
        $pdo = $this->db->pdo();
        $entry = $pdo->prepare(
            "SELECT entry_date, posted_at FROM journal_entries
              WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL AND reversed_by IS NULL
                AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links l WHERE l.supplier_id = journal_entries.supplier_id AND l.entry_id = journal_entries.id)
                AND NOT EXISTS (SELECT 1 FROM other_items oi WHERE oi.supplier_id = journal_entries.supplier_id AND oi.journal_entry_id = journal_entries.id)"
        );
        $entry->execute([$entryId, $ctx->supplierId]);
        $head = $entry->fetch(PDO::FETCH_ASSOC);
        if ($head === false) {
            return null;
        }
        $lines = $pdo->prepare(
            'SELECT l.side, l.amount, a.account_code, a.account_type FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.entry_id = ? AND l.supplier_id = ? ORDER BY l.line_no, l.id'
        );
        $lines->execute([$entryId, $ctx->supplierId]);
        $accounts = MigratedOtherItemPolicy::postingLines($lines->fetchAll(PDO::FETCH_ASSOC), 'payable', $c['total']);
        return $accounts === null ? null
            : $accounts + ['entry_id' => $entryId, 'date' => (string) $head['entry_date'], 'posted_at' => (string) $head['posted_at']];
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
     * Část závazku uhrazená do konce převáděných období mimo banku a pokladnu MyÚčta
     * (`settled_outside_amount`). Nastavuje se absolutně, úhrady spárované v MyÚčtu
     * (alokace) se odečtou a položku neotevřou.
     */
    private function settle(ImportContext $ctx, int $id, float $paid): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE other_items oi
                SET oi.settled_outside_amount = GREATEST(0, LEAST(oi.amount, ?) - COALESCE((SELECT SUM(a.amount)
                    FROM other_item_allocations a WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id AND a.reversed_on IS NULL), 0))
              WHERE oi.supplier_id = ? AND oi.id = ? AND oi.deleted_at IS NULL'
        );
        $stmt->execute([number_format(max(0.0, $paid), 2, '.', ''), $ctx->supplierId, $id]);
        if ($stmt->rowCount() > 0) {
            $ctx->protocol->count(self::STEP, 'settled_outside');
        }
    }
}
