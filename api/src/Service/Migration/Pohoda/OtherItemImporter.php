<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\Shared\MigratedOtherItemPolicy;
use PDO;

/**
 * Ostatní pohledávky a závazky POHODY mimo přiznání DPH jako doklady agendy Ostatní
 * pohledávky a závazky. Doklady v přiznání převádí {@see InvoiceImporter} na faktury.
 *
 * Doklad s vlastním zápisem převedeného deníku ho převezme (bez nového účtování) i s jeho
 * kontací. Doklad, který POHODA vede bez zaúčtování (předkontace „bez", předpis nese jiný
 * doklad, třeba interní), se převede jako potvrzený bez vlastního zápisu - v agendě je
 * vidět a páruje úhrady, ale znovu se neúčtuje. Úhrady z `liquidations` přiřadí po převodu
 * banky a pokladny {@see DocumentLinker::matchPayments()}. Převádí se i uhrazené doklady,
 * aby agenda nesla celou historii roku.
 *
 * Předpisy mezd, odvodů a DPH (účty 331, 336, 342, 343) vlastní mzdový a daňový modul
 * a nepřevádějí se. Zálohy na daň z příjmů (341) bez zaúčtování převezme daňový modul jako
 * předpisy záloh ({@see self::taxAdvances()}).
 */
final class OtherItemImporter
{
    public const STEP = 'other_items';

    /** Účet záloh na daň z příjmů: evidenční závazek na něm je předpis zálohy. */
    private const TAX_ADVANCE_ACCOUNT = '341';

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly PartnerImporter $partners,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $mapped = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_OTHER_ITEM);
        $mappedAdvances = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_TAX_ADVANCE);
        $journal = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY);
        $rules = $this->postingRuleAccounts($ctx);
        $advances = [];
        foreach (['receivable' => PohodaJournal::RECEIVABLE, 'commitment' => PohodaJournal::COMMITMENT] as $agenda => $source) {
            $invoiceKind = $agenda === 'receivable'
                ? PohodaImportRepository::KIND_INVOICE
                : PohodaImportRepository::KIND_PURCHASE_INVOICE;
            foreach ($ctx->export->records($agenda, 'invoice') as $record) {
                $header = PohodaXml::get($record, 'invoiceHeader');
                $number = PohodaXml::text($header, 'number/numberRequested');
                $issued = PohodaXml::date($header, 'date') ?? PohodaXml::date($header, 'dateAccounting');
                if ($number === '' || $issued === null || $ctx->skipsDate($issued)) {
                    $p->count(self::STEP, 'skipped');
                    continue;
                }
                $key = $agenda . '|' . $number . '|' . $issued;
                if (isset($mapped[$key])) {
                    // Úhrady se párují i při opakovaném převodu - mohly mezitím přibýt.
                    self::captureLiquidations($ctx, 'other_item', (int) $mapped[$key], $number, $record);
                    self::rememberSourcePaid($ctx, (int) $mapped[$key], self::candidate($record, $agenda, $ctx->vat), $record);
                    $p->count(self::STEP, 'existing');
                    continue;
                }
                if (isset($mappedAdvances[$key])) {
                    self::captureLiquidations($ctx, 'tax_advance', (int) $mappedAdvances[$key], $number, $record);
                    $p->count(self::STEP, 'existing');
                    continue;
                }
                if ($this->map->get($ctx->supplierId, $invoiceKind, $key) !== null) {
                    $p->count(self::STEP, 'existing');
                    continue;
                }
                if ($ctx->skipsNew(self::STEP)) {
                    continue;
                }
                $class = $agenda === 'receivable'
                    ? $ctx->vat->sale(PohodaXml::text($header, 'classificationVAT/ids'), 0.0)
                    : $ctx->vat->purchase(PohodaXml::text($header, 'classificationVAT/ids'));
                if ($class !== null && $class['in_return']) {
                    continue; // faktura z kroku dokladů
                }
                $candidate = self::candidate($record, $agenda, $ctx->vat);
                if ($candidate === null) {
                    $p->count(self::STEP, 'requires_review');
                    $p->warn(self::STEP, 'other_item_review', "Doklad {$number} (" . self::label($agenda) . ') má částky, měnu nebo zálohu, které převod do agendy nepřevezme; zůstává jen v deníku.', ['document_no' => $number]);
                    continue;
                }
                $entries = [];
                foreach ($journal as $journalKey => $entryId) {
                    $parts = explode('|', (string) $journalKey, 4);
                    if (count($parts) === 4 && $parts[1] === $source && $parts[2] === $number
                        && (int) $parts[0] >= $ctx->year() - 1 && (int) $parts[0] <= $ctx->year()) {
                        $entries[(int) $entryId] = true;
                    }
                }
                if ($entries === []) {
                    // Bez vlastního zápisu: předpis nese jiný doklad (nebo ho nese počáteční stav).
                    $rule = PohodaXml::text($header, 'accounting/ids');
                    $account = $rules[$rule] ?? null;
                    if ($account !== null && self::moduleOwned($account)) {
                        if ($agenda === 'commitment' && self::isTaxAdvance($account, $candidate['title'])) {
                            $advances[] = ['key' => $key, 'number' => $number, 'record' => $record, 'candidate' => $candidate];
                        } else {
                            $p->count(self::STEP, 'payroll_or_tax');
                        }
                        continue;
                    }
                    $id = $this->insertItem($ctx, $agenda, $header, $number, $issued, $candidate, [
                        'account' => $account, 'counter' => null, 'lines' => [], 'date' => $issued,
                        'posted_at' => null, 'entry_id' => null, 'status' => 'confirmed',
                        'note' => "Převzato z POHODY, doklad {$number}. V POHODĚ veden bez zaúčtování"
                            . ($rule !== '' ? " (předkontace {$rule})" : '')
                            . ', předpis je v deníku jiným dokladem nebo v počátečním stavu - položka se proto neúčtuje.',
                    ]);
                    if ($id !== null) {
                        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_OTHER_ITEM, $key, $id, $ctx->runId);
                        self::captureLiquidations($ctx, 'other_item', $id, $number, $record);
                        self::rememberSourcePaid($ctx, $id, $candidate, $record);
                        $p->count(self::STEP, 'created_unposted');
                        $p->count(self::STEP, $candidate['remaining'] >= 0.005 ? 'open' : 'settled');
                    }
                    continue;
                }
                if (count($entries) !== 1) {
                    $p->count(self::STEP, 'requires_review');
                    $p->warn(self::STEP, 'other_item_review', "Doklad {$number} (" . self::label($agenda) . ') má v deníku víc zápisů; zůstává jen v deníku.', ['document_no' => $number]);
                    continue;
                }
                $entryId = (int) array_key_first($entries);
                $posting = $this->posting($ctx->supplierId, $entryId, $agenda, $candidate['amount']);
                if ($posting === null) {
                    $p->count(self::STEP, 'requires_review');
                    $p->warn(self::STEP, 'other_item_review', "Doklad {$number} (" . self::label($agenda) . ') má zápis v deníku, který neodpovídá jednomu saldokontnímu účtu proti protiúčtům; zůstává jen v deníku.', ['document_no' => $number]);
                    continue;
                }
                if (self::moduleOwned($posting['account'])) {
                    $p->count(self::STEP, 'payroll_or_tax');
                    continue;
                }
                $id = $this->insertItem($ctx, $agenda, $header, $number, $issued, $candidate, [
                    'account' => $posting['account'],
                    'counter' => count($posting['lines']) === 1 ? $posting['lines'][0]['account_code'] : null,
                    'lines' => $posting['lines'], 'date' => $posting['date'], 'posted_at' => $posting['posted_at'],
                    'entry_id' => $entryId, 'status' => 'posted', 'note' => 'Převzato z POHODY, doklad ' . $number,
                ]);
                if ($id === null) {
                    continue;
                }
                $owner = $this->db->pdo()->prepare(
                    "UPDATE journal_entries SET source_type = 'other_item', source_id = ?
                       WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL"
                );
                $owner->execute([$id, $entryId, $ctx->supplierId]);
                if ($owner->rowCount() !== 1) {
                    throw new PohodaException('other_item_journal_claim', 'Převzatý zápis deníku už vlastní jiný doklad.');
                }
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_OTHER_ITEM, $key, $id, $ctx->runId);
                self::captureLiquidations($ctx, 'other_item', $id, $number, $record);
                self::rememberSourcePaid($ctx, $id, $candidate, $record);
                $p->count(self::STEP, 'created');
                $p->count(self::STEP, $candidate['remaining'] >= 0.005 ? 'open' : 'settled');
            }
        }
        $this->taxAdvances($ctx, $advances);
    }

    /**
     * Zálohy na daň z příjmů, které POHODA vede jako ostatní závazky bez zaúčtování, jako
     * předpisy záloh daňového modulu (`tax_advance_schedules`). Rok předpisu je kalendářní
     * rok splatnosti a pořadí je pořadí splatností v něm, stejně jako u předpisů z přiznání
     * ({@see \MyInvoice\Service\Tax\Return\TaxAdvanceScheduleService}). Rok, pro který firma
     * v MyÚčtu předpisy už má, se nepřepisuje. Úhradu převezme stav POHODY, vazbu na
     * bankovní pohyb doplní krok úhrad.
     *
     * @param list<array{key:string,number:string,record:array,candidate:array}> $advances
     */
    private function taxAdvances(PohodaContext $ctx, array $advances): void
    {
        if ($advances === []) {
            return;
        }
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $type = $pdo->prepare('SELECT taxpayer_type FROM supplier WHERE id = ?');
        $type->execute([$ctx->supplierId]);
        $taxpayer = (string) $type->fetchColumn() === 'fo' ? 'fo' : 'po';
        $byYear = [];
        foreach ($advances as $advance) {
            $byYear[(int) substr((string) $advance['candidate']['due'], 0, 4)][] = $advance;
        }
        ksort($byYear);
        $existing = $pdo->prepare("SELECT COUNT(*) FROM tax_advance_schedules
            WHERE supplier_id = ? AND taxpayer_type = ? AND advance_kind = 'tax' AND period_year = ?");
        $insert = $pdo->prepare("INSERT INTO tax_advance_schedules
            (supplier_id, taxpayer_type, advance_kind, period_year, seq_no, amount, due_date, variable_symbol,
             status, paid_amount, paid_on, paid_source)
            VALUES (?, ?, 'tax', ?, ?, ?, ?, ?, ?, ?, ?, 'manual')");
        foreach ($byYear as $year => $rows) {
            $existing->execute([$ctx->supplierId, $taxpayer, $year]);
            if ((int) $existing->fetchColumn() > 0) {
                $p->count(self::STEP, 'tax_advances_kept', count($rows));
                $p->info(self::STEP, 'tax_advances_kept', "Předpisy záloh na daň z příjmů za rok {$year} už v MyÚčtu jsou, zálohy z POHODY ("
                    . implode(', ', array_column($rows, 'number')) . ') se nepřevzaly.');
                continue;
            }
            usort($rows, static fn (array $a, array $b): int => [$a['candidate']['due'], $a['number']] <=> [$b['candidate']['due'], $b['number']]);
            foreach ($rows as $seq => $row) {
                $c = $row['candidate'];
                $paid = round($c['amount'] - $c['remaining'], 2);
                $paidOn = null;
                foreach (PohodaXml::all($row['record'], 'liquidations/liquidation') as $l) {
                    $date = PohodaXml::date($l, 'date');
                    if ($date !== null && ($paidOn === null || $date > $paidOn)) {
                        $paidOn = $date;
                    }
                }
                $settled = $c['remaining'] < 0.005;
                $insert->execute([
                    $ctx->supplierId, $taxpayer, $year, $seq + 1, number_format($c['amount'], 2, '.', ''), $c['due'],
                    $c['symbol'] !== null && strlen($c['symbol']) <= 20 ? $c['symbol'] : null,
                    $settled ? 'paid' : 'planned', number_format($paid, 2, '.', ''),
                    $paid > 0 ? ($paidOn ?? $c['due']) : null,
                ]);
                $id = (int) $pdo->lastInsertId();
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_TAX_ADVANCE, $row['key'], $id, $ctx->runId);
                self::captureLiquidations($ctx, 'tax_advance', $id, $row['number'], $row['record']);
                $p->count(self::STEP, 'tax_advances');
            }
        }
    }

    /**
     * Účet předkontace POHODY podle kódu: strana, která je vyplněná (předkontace „bez"
     * nese jen účet, na který míří úhrada). Kód účtu MyÚčta podle převedené osnovy.
     *
     * @return array<string,string>
     */
    private function postingRuleAccounts(PohodaContext $ctx): array
    {
        $codes = [];
        $stmt = $this->db->pdo()->prepare("SELECT account_code FROM chart_of_accounts WHERE supplier_id = ? AND REPLACE(account_code, '.', '') = ? ORDER BY account_code LIMIT 1");
        foreach ($ctx->export->records('posting_rules', 'itemAccounting') as $r) {
            $key = trim((string) ($r['@code'] ?? ''));
            $debit = trim((string) ($r['@debit'] ?? ''));
            $credit = trim((string) ($r['@credit'] ?? ''));
            if ($key === '' || isset($codes[$key]) || ($debit === '') === ($credit === '')) {
                continue;
            }
            $raw = $debit !== '' ? $debit : $credit;
            $stmt->execute([$ctx->supplierId, str_replace('.', '', $raw)]);
            $code = $stmt->fetchColumn();
            if ($code !== false) {
                $codes[$key] = (string) $code;
            }
        }
        return $codes;
    }

    /**
     * Založí ostatní položku. `status` posted = vlastní převzatý zápis, confirmed = bez
     * vlastního zápisu. Číslo dokladu obsazené jinou položkou se jen ohlásí.
     *
     * @param array{amount:float,remaining:float,due:string,title:string,symbol:?string} $candidate
     * @param array{account:?string,counter:?string,lines:list<array{account_code:string,amount:float}>,date:string,posted_at:?string,entry_id:?int,status:string,note:string} $posting
     */
    private function insertItem(PohodaContext $ctx, string $agenda, mixed $header, string $number, string $issued, array $candidate, array $posting): ?int
    {
        $snapshot = PartnerImporter::snapshot(PohodaXml::get($header, 'partnerIdentity'));
        $partnerId = $snapshot['name'] !== '' || $snapshot['ico'] !== ''
            ? $this->partners->resolvePartner($ctx, $snapshot)
            : null;
        $pdo = $this->db->pdo();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO other_items
                  (supplier_id, side, kind, title, partner_id, partner_name, issued_on, accounting_on, due_on,
                   currency, amount, amount_czk, variable_symbol, account_code, counter_account_code,
                   note, status, document_no, journal_entry_id, posted_at, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $ctx->supplierId, $agenda === 'receivable' ? 'receivable' : 'payable', 'other',
                mb_substr($candidate['title'], 0, 255), $partnerId,
                mb_substr($snapshot['name'], 0, 190) ?: null,
                $issued, $posting['date'], $candidate['due'], 'CZK', $candidate['amount'],
                $candidate['amount'], $candidate['symbol'], $posting['account'], $posting['counter'],
                $posting['note'], $posting['status'], mb_substr($number, 0, 50),
                $posting['entry_id'], $posting['posted_at'], $ctx->userOrNull(), $ctx->userOrNull(),
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') throw $e;
            $ctx->protocol->warn(self::STEP, 'number_taken', "Doklad {$number} nelze převzít, číslo už má jiná pohledávka nebo závazek.", ['document_no' => $number]);
            return null;
        }
        $id = (int) $pdo->lastInsertId();
        if ($posting['counter'] === null && $posting['lines'] !== []) {
            $line = $pdo->prepare('INSERT INTO other_item_posting_lines
                (supplier_id, other_item_id, position, account_code, amount) VALUES (?, ?, ?, ?, ?)');
            foreach ($posting['lines'] as $position => $row) {
                $line->execute([$ctx->supplierId, $id, $position + 1, $row['account_code'], number_format($row['amount'], 2, '.', '')]);
            }
        }
        return $id;
    }

    /**
     * Doklad mimo přiznání DPH v korunách bez odpočtu zálohy, se součtem dokladu shodným
     * s položkami. `remaining` je zbývající částka podle POHODY (úhrady páruje až krok úhrad).
     *
     * @return array{amount:float,remaining:float,due:string,title:string,symbol:?string}|null
     */
    public static function candidate(array $record, string $agenda, PohodaVat $vat): ?array
    {
        if (!in_array($agenda, ['receivable', 'commitment'], true)) return null;
        $header = PohodaXml::get($record, 'invoiceHeader');
        $summary = PohodaXml::get($record, 'invoiceSummary/homeCurrency');
        $code = PohodaXml::text($header, 'classificationVAT/ids');
        $class = $agenda === 'receivable' ? $vat->sale($code, 0.0) : $vat->purchase($code);
        if ($code === '' || $class === null || $class['in_return'] || PohodaXml::text($header, 'MOSS/ids') !== '') return null;
        if (PohodaXml::text($record, 'invoiceSummary/foreignCurrency/currency/ids') !== '') return null;
        if (PohodaXml::all($record, 'invoiceDetail/invoiceAdvancePaymentItem') !== []) return null;
        foreach (['priceLow', 'priceLowVAT', 'priceHigh', 'priceHighVAT', 'price3', 'price3VAT', 'round/priceRound'] as $field) {
            if (abs(PohodaXml::num($summary, $field)) >= 0.005) return null;
        }
        $amount = round(PohodaXml::num($summary, 'priceNone'), 2);
        $summaryTotal = PohodaXml::text($summary, 'priceSum');
        if ($summaryTotal !== '' && (!is_numeric($summaryTotal) || abs((float) $summaryTotal - $amount) >= 0.005)) return null;
        $detailAmount = 0.0;
        $details = PohodaXml::all($record, 'invoiceDetail/invoiceItem');
        foreach ($details as $detail) {
            if (abs(PohodaXml::num($detail, 'homeCurrency/priceVAT')) >= 0.005) return null;
            $detailAmount += PohodaXml::num($detail, 'homeCurrency/price');
        }
        if ($details !== [] && abs($detailAmount - $amount) >= 0.005) return null;
        $remaining = PohodaXml::text($header, 'liquidation/amountHome');
        $remaining = $remaining === '' ? 0.0 : (is_numeric($remaining) ? round((float) $remaining, 2) : null);
        if ($amount <= 0 || $remaining === null || $remaining < -0.005 || $remaining - $amount >= 0.005) return null;
        $due = PohodaXml::date($header, 'dateDue') ?? PohodaXml::date($header, 'date');
        if ($due === null) return null;
        $title = PohodaXml::text($header, 'text');
        $symbol = PohodaXml::text($header, 'symVar');
        return [
            'amount' => $amount,
            'remaining' => max(0.0, $remaining),
            'due' => $due,
            'title' => $title !== '' ? $title : PohodaXml::text($header, 'number/numberRequested'),
            'symbol' => preg_match('/^[0-9]{1,20}$/', $symbol) === 1 ? $symbol : null,
        ];
    }

    private function posting(int $supplierId, int $entryId, string $agenda, float $amount): ?array
    {
        $entry = $this->db->pdo()->prepare(
            "SELECT entry_date, posted_at FROM journal_entries
              WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL
                AND reversed_by IS NULL
                AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links l
                                 WHERE l.supplier_id = journal_entries.supplier_id AND l.entry_id = journal_entries.id)
                AND NOT EXISTS (SELECT 1 FROM other_items oi
                                 WHERE oi.supplier_id = journal_entries.supplier_id AND oi.journal_entry_id = journal_entries.id)"
        );
        $entry->execute([$entryId, $supplierId]);
        $head = $entry->fetch(PDO::FETCH_ASSOC);
        if ($head === false) return null;
        $lines = $this->db->pdo()->prepare(
            'SELECT l.side, l.amount, a.account_code, a.account_type
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.entry_id = ? AND l.supplier_id = ? ORDER BY l.line_no, l.id'
        );
        $lines->execute([$entryId, $supplierId]);
        $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
        $accounts = self::postingLines($rows, $agenda, $amount);
        if ($accounts === null) return null;
        return $accounts + ['date' => (string) $head['entry_date'], 'posted_at' => (string) $head['posted_at']];
    }

    /**
     * @param list<array{side:string,amount:string|float,account_code:string,account_type?:string}> $rows
     * @return array{account:string,lines:list<array{account_code:string,amount:float}>}|null
     */
    public static function postingLines(array $rows, string $agenda, float $amount): ?array
    {
        if (!in_array($agenda, ['receivable', 'commitment'], true)) return null;
        return MigratedOtherItemPolicy::postingLines($rows, $agenda === 'receivable' ? 'receivable' : 'payable', $amount);
    }

    /**
     * Záloha na daň z příjmů: závazek na 341 s textem zálohy. Na stejném účtu (a se stejnou
     * předkontací) vede POHODA i doplatek daně za uplynulý rok - ten patří k přiznání,
     * ne mezi předpisy záloh, a rozezná se jen podle textu.
     */
    public static function isTaxAdvance(string $account, string $title): bool
    {
        if (!str_starts_with($account, self::TAX_ADVANCE_ACCOUNT)) {
            return false;
        }
        $text = mb_strtolower($title);
        return str_contains($text, 'záloh') || str_contains($text, 'zaloh');
    }

    public static function moduleOwned(string $account): bool
    {
        return MigratedOtherItemPolicy::moduleOwned($account);
    }

    /**
     * Uhrazená část podle POHODY. Úhrada v roce, který se nepřevádí, se nepočítá: její pohyb
     * v převodu není a doplní ji až opakovaný převod s tímto rokem.
     *
     * @param array{amount:float,remaining:float}|null $candidate
     */
    private static function rememberSourcePaid(PohodaContext $ctx, int $id, ?array $candidate, array $record): void
    {
        if ($candidate === null) {
            return;
        }
        $skipped = 0.0;
        foreach (PohodaXml::all($record, 'liquidations/liquidation') as $l) {
            if ($ctx->skipsDate(PohodaXml::date($l, 'date'))) {
                $skipped += abs(PohodaXml::num($l, 'amount'));
            }
        }
        $ctx->otherItemPaid[$id] = max(0.0, round($candidate['amount'] - $candidate['remaining'] - $skipped, 2));
    }

    /** @param 'other_item'|'tax_advance' $doc */
    private static function captureLiquidations(PohodaContext $ctx, string $doc, int $id, string $number, array $record): void
    {
        foreach (PohodaXml::all($record, 'liquidations/liquidation') as $l) {
            $ctx->liquidations[] = [
                'doc' => $doc,
                'id' => $id,
                'number' => $number,
                'agenda' => PohodaXml::text($l, 'sourceAgenda'),
                'source' => PohodaXml::text($l, 'sourceDocument/number'),
                'source_id' => PohodaXml::text($l, 'sourceDocument/id'),
                'date' => PohodaXml::date($l, 'date'),
                'amount' => PohodaXml::num($l, 'amount'),
                'liq' => PohodaXml::text($l, 'id') ?: substr(md5((string) json_encode($l)), 0, 12),
            ];
        }
    }

    private static function label(string $agenda): string
    {
        return $agenda === 'receivable' ? 'ostatní pohledávka' : 'ostatní závazek';
    }
}
