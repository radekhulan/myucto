<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\JournalSettlementException;
use MyInvoice\Service\Accounting\JournalSettlementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Support\Sql\PayablePredicate;
use PDO;

/**
 * Zůstatky 315 a 325 bez dokladu: bankovní a pokladní pohyb, který POHODA zaúčtovala přímo
 * na účet (typicky přeplatek nebo chybná platba na analytice 315.999 a 325.999, někdy později
 * vrácená). V agendě Ostatní pohledávky a závazky doklad nemá, hlavní kniha ho nese
 * a saldokonto by ukazovalo rozdíl.
 *
 * Po účtu a partnerovi se spočítá, co z takových řádků zůstává nevyrovnané. Platba a vrácení
 * téhož partnera a částky se vyruší, jinak vrácení ubírá z nejstarších řádků opačné strany.
 * Zbytek dostane ostatní položku nad svým řádkem ({@see JournalSettlementService::createOtherItem}):
 * nevzniká nový zápis a zůstatek účtu se nemění.
 *
 * Partnera určí protiúčet, u pohybu bez něj název, nakonec variabilní symbol. Řádek bez všech
 * tří zůstává jen v protokolu. Položka nesmí rozdíl saldokonta zvětšit ani překročit, měřeno
 * na syntetice i na analytice: vrácení přeplatku z počátečního stavu (ten pohyb nemá, není
 * s čím párovat) by ho jinak z nuly otevřelo.
 *
 * Účty dokladů (311, 321, 314, 324) řídí saldo POHODY, tedy párovací symbol řádku deníku:
 *   - pohyb se symbolem, ke kterému dokladu není (úhrada faktury z nepřevedeného roku),
 *     dostane položku, POHODA ho ve svém saldu ukazuje stejně,
 *   - přeplatek skupiny s dokladem dostane položku jen na svou část pohybu,
 *   - doklad, jehož skupinu má POHODA vyrovnanou, se vyrovná i tady, bez zápisu do deníku:
 *     přijatý doklad přes `settled_outside_amount`, vydaný evidovanou platbou `settlement`.
 *     Záloha z dřívějších let bez řádku v deníku agendy ztratí vazbu na otevírací zápis.
 * Symbol faktury z minulého roku (doklad bez řádku v deníku, předpis je v souhrnném
 * počátečním stavu) položku nedostane. I tady platí, že rozdíl se nesmí zvětšit.
 *
 * Opakovaný převod: řádek s položkou z mapy se počítá jen zbytkem částky, takže se nic
 * nezdvojí a nové vrácení dostane vlastní položku opačné strany. Položku, kterou mezitím
 * účetní stornovala, převod znovu nezaloží.
 */
final class BalanceItemImporter
{
    private const STEP = DocumentLinker::STEP_PAYMENTS;
    /** Do kolika dnů se opačný řádek stejné částky bere jako vrácená platba. */
    private const RETURN_DAYS = 60;
    /** Účty dokladů, na kterých rozhoduje párovací symbol. */
    private const DOCUMENT_ACCOUNTS = ['311', '321', '314', '324'];
    /** Pod touto částkou v haléřích je zbytek skupiny zaokrouhlení, ne přeplatek. */
    private const MIN_ITEM_CENTS = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly PartnerImporter $partners,
        private readonly JournalSettlementService $settlements,
        private readonly SaldoService $saldo,
        private readonly PohodaSaldoPairing $pairing,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $asOf = $ctx->lastPeriodEnd();
        if ($asOf === null || $ctx->period === null) {
            return;
        }
        $mapped = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_BALANCE_ITEM);
        // Nejdřív vyrovnání: mění rozdíl, ze kterého se položky teprve počítají.
        $this->closeByPohodaGroups($ctx, $asOf);
        $accepted = [...$this->overpayments($ctx, $asOf, $mapped), ...$this->documentAccountItems($ctx, $asOf, $mapped)];
        $this->createItems($ctx, $accepted, $mapped);
    }

    /**
     * Zůstatky 315 a 325: co vyrovná párovací symbol, potom vrácení a párování po partnerovi.
     *
     * @param array<string,int|string> $mapped
     * @return list<array<string,mixed>>
     */
    private function overpayments(PohodaContext $ctx, string $asOf, array $mapped): array
    {
        $accountIds = $this->accountIds($ctx->supplierId);
        $lines = $accountIds === [] ? [] : $this->lines($ctx->supplierId, $accountIds, $asOf);
        if ($lines === []) {
            return [];
        }
        $p = $ctx->protocol;
        [$covered, $foreign] = $this->allocations($ctx->supplierId, $accountIds, $mapped);
        $explained = $this->explainedMovements($ctx->supplierId);
        $documentSymbols = ['315' => $this->documentSymbols($ctx->supplierId, '315'), '325' => $this->documentSymbols($ctx->supplierId, '325')];

        $open = [];
        foreach ($lines as $line) {
            $key = $line['entry_id'] . '|' . $line['line_no'];
            $movement = $line['source_type'] . '|' . $line['source_id'] . '|';
            if (isset($foreign[$key])) {
                continue;
            }
            // Úhrada dokladu vysvětluje řádek, jen když symbol neříká něco jiného: přeplatek
            // vedle úhrady má na 325.999 vlastní symbol.
            $symbol = (string) ($line['pairing_symbol'] ?? '');
            $ownSymbol = ltrim($symbol, '0') !== '' && !isset($documentSymbols[$line['synthetic']][$symbol]);
            $isExplained = isset($explained[$movement . $line['synthetic']]) || isset($explained[$movement . '*']);
            if (!$ownSymbol && $isExplained) {
                continue;
            }
            // `quiet`: řádek pohybu s úhradou dokladu. Položku dostane, jen když rozdíl zmenší,
            // jinak je to běžná úhrada a do protokolu nepatří.
            $open[] = $line + ['quiet' => $isExplained] + ['key' => $key, 'cents' => self::cents($line['amount']) - ($covered[$key] ?? 0)];
        }

        $before = count($open);
        $open = self::withoutReturns(self::withoutClosedSymbols($open));
        if ($before > count($open)) {
            $p->count(self::STEP, 'balance_paired', (int) (($before - count($open)) / 2));
        }

        $candidates = [];
        foreach (self::byPartner($open) as $partnerKey => $group) {
            if ($partnerKey === '') {
                foreach (array_filter($group, static fn (array $l): bool => !$l['quiet']) as $line) {
                    $p->count(self::STEP, 'balance_unclear');
                    $p->warn(self::STEP, 'balance_item_unclear', sprintf(
                        'Pohyb %s na účtu %s (%s Kč) nemá partnera ani symbol. Ostatní položku jsme nezaložili, doplňte ji v saldokontu ručně.',
                        $line['document_no'] ?? '#' . $line['entry_id'], $line['account_code'], self::money($line['cents']),
                    ), ['document_no' => $line['document_no']]);
                }
                continue;
            }
            $rest = self::remainders($group);
            $paired = count(array_filter($group, static fn (array $l): bool => $l['cents'] > 0)) - count($rest);
            if ($paired > 0) {
                $p->count(self::STEP, 'balance_paired', $paired);
            }
            array_push($candidates, ...$rest);
        }
        $before = count($candidates);
        $candidates = self::withoutReturns($candidates);
        if ($before > count($candidates)) {
            $p->count(self::STEP, 'balance_paired', (int) (($before - count($candidates)) / 2));
        }
        $candidates = $this->withoutClosedPeriods($ctx, $candidates);
        return $this->reducingDifference($ctx, $candidates, (int) $ctx->period['id'], $asOf);
    }

    /**
     * Pohyby na účtech dokladů, kterým saldo POHODY žádný doklad nepřiřazuje.
     *
     * @param array<string,int|string> $mapped
     * @return list<array<string,mixed>>
     */
    private function documentAccountItems(PohodaContext $ctx, string $asOf, array $mapped): array
    {
        $accepted = [];
        foreach (self::DOCUMENT_ACCOUNTS as $synthetic) {
            $groups = $this->pairing->groups($ctx->supplierId, $synthetic, $asOf);
            // Bez souhrnného řádku bez symbolu není počáteční stav, který by předpis minulého
            // dokladu nesl: úhrada takového dokladu zůstává otevřená jako v saldu POHODY.
            $hasLump = ($groups[''] ?? 0) !== 0;
            unset($groups['']);
            $block = $groups === [] ? null : $this->saldoBlock($ctx, $synthetic, $asOf);
            if ($block === null || self::cents($block['difference']) === 0) {
                continue;
            }
            $accountIds = $this->accountIds($ctx->supplierId, $synthetic);
            $debitNormal = $block['account']['normal_side'] === 'debit';
            [$covered, $foreign] = $this->allocations($ctx->supplierId, $accountIds, $mapped);
            $bySymbol = [];
            foreach ($this->lines($ctx->supplierId, $accountIds, $asOf) as $line) {
                $key = $line['entry_id'] . '|' . $line['line_no'];
                if (!isset($foreign[$key]) && (string) $line['pairing_symbol'] !== '') {
                    $bySymbol[(string) $line['pairing_symbol']][] = $line + ['key' => $key, 'cents' => self::cents($line['amount']) - ($covered[$key] ?? 0), 'covered' => $covered[$key] ?? 0];
                }
            }
            $documents = $this->documentSymbols($ctx->supplierId, $synthetic);
            $known = $this->knownSymbols($ctx->supplierId);
            $candidates = [];
            foreach ($bySymbol as $symbol => $group) {
                $symbol = (string) $symbol;
                if (ltrim($symbol, '0') === '') {
                    array_push($candidates, ...array_values(array_filter($group, static fn (array $l): bool => $l['cents'] > 0)));
                } elseif (isset($documents[$symbol])) {
                    $line = self::overpaidLine($group, $groups[$symbol] ?? 0, $debitNormal, array_sum(array_column($group, 'covered')));
                    if ($line !== null) {
                        $candidates[] = $line;
                    }
                } elseif (!$hasLump || !isset($known[$symbol])) {
                    array_push($candidates, ...self::remainders($group));
                }
            }
            $candidates = array_values(array_filter($candidates, static fn (array $l): bool => $l['cents'] >= self::MIN_ITEM_CENTS));
            $candidates = $this->withoutClosedPeriods($ctx, $candidates);
            array_push($accepted, ...self::narrowing($candidates, self::cents($block['difference']), $debitNormal));
        }
        return $accepted;
    }

    /**
     * Skupina s dokladem, ve které platby převyšují předpis: položka nese přeplacenou část
     * největšího pohybu. `$balance` je zůstatek skupiny (MD kladně) bez ohledu na položky.
     *
     * @param list<array<string,mixed>> $group
     * @return array<string,mixed>|null
     */
    public static function overpaidLine(array $group, int $balance, bool $debitNormal, int $covered): ?array
    {
        $overpaid = ($debitNormal ? -$balance : $balance) - $covered;
        if ($overpaid <= 0) {
            return null;
        }
        $paymentSide = $debitNormal ? 'credit' : 'debit';
        $lines = array_values(array_filter($group, static fn (array $l): bool => $l['side'] === $paymentSide && $l['cents'] >= $overpaid));
        usort($lines, static fn (array $a, array $b): int => $b['cents'] <=> $a['cents']);
        return $lines === [] ? null : ['cents' => $overpaid] + $lines[0];
    }

    /**
     * Položky, které rozdíl nezvětší: všechny, když sedí dohromady, jinak od největší.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    public static function narrowing(array $candidates, int $difference, bool $debitNormal): array
    {
        $signed = static fn (array $l): int => ($l['side'] === 'debit') === $debitNormal ? $l['cents'] : -$l['cents'];
        if ($candidates === []) {
            return [];
        }
        if (self::narrows($difference, array_sum(array_map($signed, $candidates)))) {
            return $candidates;
        }
        usort($candidates, static fn (array $a, array $b): int => $b['cents'] <=> $a['cents']);
        $out = [];
        foreach ($candidates as $line) {
            if (self::narrows($difference, $signed($line))) {
                $difference -= $signed($line);
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * Doklad otevřený v MyÚčtu, jehož skupinu má POHODA vyrovnanou, nebo záloha bez řádku
     * v deníku agendy. Deník se nemění, zapíše se jen vyrovnání dokladu.
     */
    private function closeByPohodaGroups(PohodaContext $ctx, string $asOf): void
    {
        foreach (self::DOCUMENT_ACCOUNTS as $synthetic) {
            $groups = $this->pairing->groups($ctx->supplierId, $synthetic, $asOf);
            unset($groups['']);
            $block = $groups === [] ? null : $this->saldoBlock($ctx, $synthetic, $asOf);
            if ($block === null) {
                continue;
            }
            $difference = self::cents($block['difference']);
            $map = $this->documentMap($ctx->supplierId, $synthetic);
            $items = [];
            foreach ($block['partners'] as $partner) {
                array_push($items, ...$partner['items']);
            }
            usort($items, static fn (array $a, array $b): int => abs($b['remaining_czk']) <=> abs($a['remaining_czk']));
            foreach ($items as $item) {
                if ($item['kind'] !== 'document' || !in_array($item['doc_type'], ['invoice', 'purchase_invoice'], true)) {
                    continue;
                }
                $symbol = $map[$item['doc_type'] . '|' . $item['doc_id']] ?? null;
                $cents = self::cents($item['remaining_czk']);
                // Záloha z minulých let bez řádku v deníku agendy: pokud ji rozdíl potvrzuje,
                // zůstatek účtu ji nenese. Letošní záloha bez řádku je jen dosud neuhrazený předpis.
                $oldAdvance = $symbol === null && in_array($synthetic, ['314', '324'], true)
                    && (string) $item['issue_date'] < (string) $ctx->period['starts_on'];
                $closedGroup = $symbol !== null && ($groups[$symbol] ?? null) === 0;
                // Vyrovnání položku ze součtu ubere, rozdíl se o ni zvětší.
                if ($cents === 0 || !($oldAdvance || $closedGroup) || !self::narrows($difference, -$cents)) {
                    continue;
                }
                if (!($oldAdvance ? $this->unlinkFromOpening($ctx, $item) : $this->settleDocument($ctx, $item, $asOf))) {
                    continue;
                }
                $difference += $cents;
                $ctx->protocol->count(self::STEP, 'saldo_closed_by_symbol');
            }
        }
    }

    /**
     * Stará záloha je v saldokontu jen vazbou na otevírací zápis ({@see DocumentLinker}).
     *
     * @param array<string,mixed> $item položka saldokonta
     */
    private function unlinkFromOpening(PohodaContext $ctx, array $item): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "DELETE k FROM journal_entry_document_links k
               JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
              WHERE k.supplier_id = ? AND k.doc_type = ? AND k.doc_id = ? AND e.source_type = 'opening'"
        );
        $stmt->execute([$ctx->supplierId, $item['doc_type'], (int) $item['doc_id']]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $item položka saldokonta
     * @return bool false, když je zbytek v měně dokladu po zaokrouhlení nula
     */
    private function settleDocument(PohodaContext $ctx, array $item, string $asOf): bool
    {
        $remaining = abs((float) $item['remaining_czk']);
        $amount = $item['currency_code'] === 'CZK' || (float) $item['booked_czk'] === 0.0
            ? $remaining
            : round($remaining * abs((float) $item['amount_foreign'] / (float) $item['booked_czk']), 2);
        if ($amount < 0.01) {
            return false;
        }
        $pdo = $this->db->pdo();
        if ($item['doc_type'] === 'purchase_invoice') {
            $pdo->prepare('UPDATE purchase_invoices SET settled_outside_amount = settled_outside_amount + ? WHERE supplier_id = ? AND id = ?')
                ->execute([number_format($amount, 2, '.', ''), $ctx->supplierId, (int) $item['doc_id']]);
            // Stav jako u úhrady z POHODY, jinak by vyrovnaný doklad zůstal mezi neuhrazenými.
            $pdo->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = COALESCE(paid_at, ?)
                  WHERE supplier_id = ? AND id = ? AND status IN ('received', 'booked')" . PayablePredicate::excludeAdvanceVatDocument(''))
                ->execute([$asOf, $ctx->supplierId, (int) $item['doc_id']]);
            return true;
        }
        $pdo->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
             VALUES (?, ?, ?, ?, ?, 'Převzato z POHODY, skupina salda je vyrovnaná', 'settlement', ?)"
        )->execute([$ctx->supplierId, (int) $item['doc_id'], $asOf, number_format($amount, 2, '.', ''), $item['currency_code'], $ctx->userOrNull()]);
        // Bez stavu by na vyrovnanou fakturu dál chodily upomínky.
        $pdo->prepare("UPDATE invoices SET paid_total = paid_total + ?, status = 'paid', paid_at = COALESCE(paid_at, ?)
              WHERE supplier_id = ? AND id = ? AND status IN ('issued', 'sent', 'reminded')")
            ->execute([number_format($amount, 2, '.', ''), $asOf, $ctx->supplierId, (int) $item['doc_id']]);
        return true;
    }

    /** @return array<string,mixed>|null */
    private function saldoBlock(PohodaContext $ctx, string $synthetic, string $asOf): ?array
    {
        try {
            return $this->saldo->build($ctx->supplierId, (int) $ctx->period['id'], $asOf, $synthetic)['accounts'][0] ?? null;
        } catch (ReportException $e) {
            $ctx->protocol->warn(self::STEP, 'balance_item_saldo', "Saldokonto účtu {$synthetic} nejde sestavit, položky podle párovacích symbolů jsme nezaložili: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Symboly, které na účtu nese předpis dokladu.
     *
     * @return array<string,true>
     */
    private function documentSymbols(int $supplierId, string $synthetic): array
    {
        $out = [];
        foreach ($this->documentMap($supplierId, $synthetic) as $symbol) {
            $out[$symbol] = true;
        }
        return $out;
    }

    /**
     * Symbol dokladu na účtu podle jeho předpisu v deníku.
     *
     * @return array<string,string> `invoice|id` => symbol
     */
    private function documentMap(int $supplierId, string $synthetic): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.source_type, e.source_id, p.pairing_symbol
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
               JOIN journal_line_pairing p ON p.supplier_id = l.supplier_id AND p.entry_id = l.entry_id AND p.line_no = l.line_no
              WHERE l.supplier_id = ? AND a.account_code LIKE ?
                AND e.source_type IN ('invoice', 'purchase_invoice') AND e.source_id IS NOT NULL AND e.reversed_by IS NULL"
        );
        $stmt->execute([$supplierId, $synthetic . '%']);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['source_type'] . '|' . $row['source_id']] = (string) $row['pairing_symbol'];
        }
        return $out;
    }

    /**
     * Symboly dokladů v agendách, včetně dokladů minulého roku bez řádku v deníku.
     *
     * @return array<string,true>
     */
    private function knownSymbols(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $out = [];
        foreach ([
            'SELECT varsymbol FROM invoices WHERE supplier_id = ? AND varsymbol IS NOT NULL',
            'SELECT varsymbol FROM purchase_invoices WHERE supplier_id = ? AND varsymbol IS NOT NULL',
            'SELECT payment_variable_symbol FROM purchase_invoices WHERE supplier_id = ? AND payment_variable_symbol IS NOT NULL',
            'SELECT vendor_invoice_number FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number IS NOT NULL',
        ] as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $symbol) {
                $out[trim((string) $symbol)] = true;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $accepted
     * @param array<string,int|string> $mapped
     */
    private function createItems(PohodaContext $ctx, array $accepted, array $mapped): void
    {
        if ($accepted === []) {
            return;
        }
        $p = $ctx->protocol;
        $snapshots = $this->partnerSnapshots($ctx, $accepted);
        $pdo = $this->db->pdo();
        foreach ($accepted as $line) {
            $snapshot = $snapshots[$line['source_type'] . '|' . $line['source_id']] ?? null;
            $partnerId = $snapshot !== null && ($snapshot['name'] !== '' || $snapshot['ico'] !== '')
                ? $this->partners->resolvePartner($ctx, $snapshot)
                : null;
            $symbol = (string) ($line['variable_symbol'] ?? '');
            $pdo->exec('SAVEPOINT pohoda_balance_item');
            try {
                $created = $this->settlements->createOtherItem($ctx->supplierId, [
                    'line_id' => (int) $line['line_id'],
                    'amount' => $line['cents'] / 100,
                    'partner_id' => $partnerId,
                    'partner_name' => $partnerId === null ? mb_substr((string) ($line['partner_name'] ?? ''), 0, 190) : null,
                    'variable_symbol' => preg_match('/^[0-9]{1,20}$/', $symbol) === 1 ? $symbol : null,
                ], $ctx->userOrNull(), true);
                $pdo->exec('RELEASE SAVEPOINT pohoda_balance_item');
            } catch (JournalSettlementException $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_balance_item');
                $pdo->exec('RELEASE SAVEPOINT pohoda_balance_item');
                $p->count(self::STEP, 'balance_not_created');
                $p->warn(self::STEP, 'balance_item_refused', 'Pohyb ' . ($line['document_no'] ?? '#' . $line['entry_id']) . ' na účtu ' . $line['account_code'] . ': ' . $e->getMessage(), ['document_no' => $line['document_no']]);
                continue;
            }
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_BALANCE_ITEM, self::mapKey($line['key'], $mapped), (int) $created['item']['id'], $ctx->runId);
            $p->count(self::STEP, 'balance_items');
        }
    }

    /**
     * Skupiny jednoho účtu a párovacího symbolu se součtem nula: POHODA je má vyrovnané.
     * Symbol `0` a řádek bez symbolu nepárují.
     *
     * @param list<array<string,mixed>> $lines s klíči `account_code`, `side`, `cents`, `pairing_symbol`
     * @return list<array<string,mixed>>
     */
    public static function withoutClosedSymbols(array $lines): array
    {
        $sums = [];
        foreach ($lines as $line) {
            $symbol = (string) ($line['pairing_symbol'] ?? '');
            if (ltrim($symbol, '0') !== '') {
                $key = $line['account_code'] . '|' . $symbol;
                $sums[$key] = ($sums[$key] ?? 0) + ($line['side'] === 'debit' ? $line['cents'] : -$line['cents']);
            }
        }
        return array_values(array_filter($lines, static function (array $line) use ($sums): bool {
            $key = $line['account_code'] . '|' . (string) ($line['pairing_symbol'] ?? '');
            return ($sums[$key] ?? 1) !== 0;
        }));
    }

    /**
     * Zbytek nevyrovnaných řádků jednoho účtu a partnera (`cents` = dosud nepokrytá částka).
     * Platba a vrácení stejné částky se vyruší nejstarší s nejstarším, zbytek opačné strany
     * ubírá od nejstaršího řádku. Výsledek je jen na straně, která převažuje, a jeho součet
     * je právě rozdíl obou stran, takže opakovaný výpočet po založení položek vrátí nic.
     *
     * @param list<array<string,mixed>> $lines v pořadí data, s klíči `side` a `cents`
     * @return list<array<string,mixed>>
     */
    public static function remainders(array $lines): array
    {
        $debit = array_values(array_filter($lines, static fn (array $l): bool => $l['side'] === 'debit' && $l['cents'] > 0));
        $credit = array_values(array_filter($lines, static fn (array $l): bool => $l['side'] === 'credit' && $l['cents'] > 0));
        foreach ($debit as $i => $d) {
            foreach ($credit as $j => $c) {
                if ($c['cents'] === $d['cents']) {
                    unset($debit[$i], $credit[$j]);
                    break;
                }
            }
        }
        $debitTotal = array_sum(array_column($debit, 'cents'));
        $creditTotal = array_sum(array_column($credit, 'cents'));
        $rest = $debitTotal >= $creditTotal ? $debit : $credit;
        $offset = min($debitTotal, $creditTotal);
        $out = [];
        foreach ($rest as $line) {
            $used = min($offset, $line['cents']);
            $offset -= $used;
            if ($line['cents'] - $used > 0) {
                $out[] = ['cents' => $line['cents'] - $used] + $line;
            }
        }
        return $out;
    }

    /**
     * Vrácená platba: banka ji vrací s jiným názvem a často bez protiúčtu („Neexistující účet",
     * „Vracena platba"), po partnerovi se tedy s platbou nesejde. Řádky opačné strany téhož
     * účtu a stejné částky do {@see RETURN_DAYS} dnů se vyruší bez ohledu na partnera,
     * nejbližší s nejbližším.
     *
     * @param list<array<string,mixed>> $lines s klíči `account_code`, `side`, `cents`, `entry_date`
     * @return list<array<string,mixed>>
     */
    public static function withoutReturns(array $lines): array
    {
        $lines = array_values($lines);
        $used = [];
        foreach ($lines as $i => $a) {
            if (isset($used[$i]) || $a['side'] !== 'debit') {
                continue;
            }
            $best = null;
            $bestDays = self::RETURN_DAYS + 1;
            foreach ($lines as $j => $b) {
                if (isset($used[$j]) || $b['side'] !== 'credit' || $b['cents'] !== $a['cents'] || $b['account_code'] !== $a['account_code']) {
                    continue;
                }
                $days = abs((int) ((strtotime((string) $a['entry_date']) - strtotime((string) $b['entry_date'])) / 86400));
                if ($days < $bestDays) {
                    $best = $j;
                    $bestDays = $days;
                }
            }
            if ($best !== null) {
                $used[$i] = true;
                $used[$best] = true;
            }
        }
        return array_values(array_diff_key($lines, $used));
    }

    /**
     * Změna rozdílu saldokonta je přijatelná, když ho zmenší a nepřekročí: položka větší než
     * rozdíl by jen přesunula nesoulad na opačnou stranu.
     */
    public static function narrows(int $difference, int $change): bool
    {
        $after = $difference - $change;
        return abs($after) < abs($difference) && ($after === 0 || ($after > 0) === ($difference > 0));
    }

    /**
     * Řádky rozdělené po účtu a partnerovi; klíč '' = partnera nejde určit. Protiúčet je
     * nejsilnější: pohyb bez něj se k němu přidá, když jeho název nebo symbol ukazuje právě na
     * jeden protiúčet. Stejně se pohyb jen se symbolem přidá k jedinému názvu s tím symbolem.
     *
     * @param list<array<string,mixed>> $lines
     * @return array<string,list<array<string,mixed>>>
     */
    private static function byPartner(array $lines): array
    {
        $owners = [];
        foreach ($lines as $i => $line) {
            $identity = self::identity($line);
            $lines[$i]['identity'] = $identity;
            foreach ([$identity['account'] ?? null, $identity['name'] ?? null] as $owner) {
                if ($owner === null) {
                    continue;
                }
                foreach (array_filter([$identity['name'] ?? null, $identity['symbol'] ?? null]) as $alias) {
                    if ($alias !== $owner) {
                        $owners[$line['account_code']][$alias][$owner] = true;
                    }
                }
            }
        }
        $groups = [];
        foreach ($lines as $line) {
            $identity = $line['identity'];
            $key = $identity['account'] ?? null;
            foreach (array_filter([$identity['name'] ?? null, $identity['symbol'] ?? null]) as $alias) {
                if ($key !== null) {
                    break;
                }
                $found = array_keys($owners[$line['account_code']][$alias] ?? []);
                $accounts = array_values(array_filter($found, static fn (string $o): bool => str_starts_with($o, 'a:')));
                $key = count($accounts) === 1 ? $accounts[0] : null;
            }
            if ($key === null && isset($identity['symbol']) && !isset($identity['name'])) {
                $found = array_keys($owners[$line['account_code']][$identity['symbol']] ?? []);
                $key = count($found) === 1 ? $found[0] : null;
            }
            $key ??= $identity['name'] ?? $identity['symbol'] ?? null;
            $groups[$key === null ? '' : $line['account_code'] . '|' . $key][] = $line;
        }
        return $groups;
    }

    /**
     * @param array<string,mixed> $line
     * @return array{account?:string,name?:string,symbol?:string}
     */
    private static function identity(array $line): array
    {
        $out = [];
        $account = trim((string) ($line['counterparty_account'] ?? ''));
        if (ltrim($account, '0-') !== '') {
            $out['account'] = 'a:' . $account . '/' . trim((string) ($line['counterparty_bank'] ?? ''));
        }
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($line['partner_name'] ?? '')) ?? ''));
        if ($name !== '') {
            $out['name'] = 'n:' . $name;
        }
        $symbol = trim((string) ($line['variable_symbol'] ?? ''));
        if (ltrim($symbol, '0') !== '') {
            $out['symbol'] = 'v:' . $symbol;
        }
        return $out;
    }

    /**
     * Uzavřené období se nemění: položka by dodatečně změnila saldokonto k jeho konci.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function withoutClosedPeriods(PohodaContext $ctx, array $candidates): array
    {
        $out = [];
        foreach ($candidates as $line) {
            if ($line['period_status'] !== null && AccountingPeriodStatus::isClosed((string) $line['period_status'])) {
                $ctx->protocol->count(self::STEP, 'balance_not_created');
                $ctx->protocol->warn(self::STEP, 'balance_item_closed_period', 'Pohyb ' . ($line['document_no'] ?? '#' . $line['entry_id']) . ' na účtu ' . $line['account_code'] . ' je v uzavřeném období, ostatní položku jsme nezaložili.', ['document_no' => $line['document_no']]);
                continue;
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Jen položky, které rozdíl saldokonta proti hlavní knize nezvětší. Sedí-li všechny
     * dohromady, založí se všechny (dva opačné přeplatky se v rozdílu vyruší). Jinak se berou
     * od největší a každá ho musí zmenšit, ostatní zůstanou jen v protokolu.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function reducingDifference(PohodaContext $ctx, array $candidates, int $periodId, string $asOf): array
    {
        $bySynthetic = [];
        foreach ($candidates as $line) {
            $bySynthetic[$line['synthetic']][] = $line;
        }
        $accepted = [];
        foreach ($bySynthetic as $synthetic => $rows) {
            try {
                $block = $this->saldo->build($ctx->supplierId, $periodId, $asOf, (string) $synthetic)['accounts'][0] ?? null;
            } catch (ReportException $e) {
                $ctx->protocol->warn(self::STEP, 'balance_item_saldo', "Saldokonto účtu {$synthetic} nejde sestavit, ostatní položky ze zůstatků jsme nezaložili: " . $e->getMessage());
                continue;
            }
            if ($block === null) {
                continue;
            }
            $debitNormal = $block['account']['normal_side'] === 'debit';
            $signed = static fn (array $l): int => ($l['side'] === 'debit') === $debitNormal ? $l['cents'] : -$l['cents'];
            // Rozdíl se měří i na analytice: nevysvětlený zůstatek 315.900 nedává důvod
            // k položce na 315.999, jejíž zůstatek je nula.
            $syntheticDifference = self::cents($block['difference']);
            $byAnalytic = [];
            foreach ($rows as $line) {
                $byAnalytic[$line['account_code']][] = $line;
            }
            foreach ($byAnalytic as $account => $group) {
                $difference = $this->analyticDifference($ctx->supplierId, (string) $account, $debitNormal, $asOf);
                $total = array_sum(array_map($signed, $group));
                if (self::narrows($difference, $total) && self::narrows($syntheticDifference, $total)) {
                    array_push($accepted, ...$group);
                    $syntheticDifference -= $total;
                    continue;
                }
                usort($group, static fn (array $a, array $b): int => $b['cents'] <=> $a['cents']);
                foreach ($group as $line) {
                    if (self::narrows($difference, $signed($line)) && self::narrows($syntheticDifference, $signed($line))) {
                        $difference -= $signed($line);
                        $syntheticDifference -= $signed($line);
                        $accepted[] = $line;
                        continue;
                    }
                    if ($line['quiet']) {
                        continue;
                    }
                    $ctx->protocol->count(self::STEP, 'balance_not_created');
                    $ctx->protocol->warn(self::STEP, 'balance_item_no_effect', sprintf(
                        'Pohyb %s na účtu %s (%s Kč): položka by rozdíl saldokonta zvětšila nebo překročila, protiváha je nejspíš v počátečním stavu nebo jiném zápisu. Zkontrolujte ho ručně.',
                        $line['document_no'] ?? '#' . $line['entry_id'], $line['account_code'], self::money($line['cents']),
                    ), ['document_no' => $line['document_no']]);
                }
            }
        }
        return $accepted;
    }

    /** Zůstatek analytiky k datu na normální straně účtu mínus otevřené ostatní položky na ní, v haléřích. */
    private function analyticDifference(int $supplierId, string $account, bool $debitNormal, string $asOf): int
    {
        $pdo = $this->db->pdo();
        $balance = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND a.account_code = ? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                AND NOT EXISTS (SELECT 1 FROM journal_entries o WHERE o.supplier_id = e.supplier_id AND o.reversed_by = e.id)"
        );
        $balance->execute([$supplierId, $account, $asOf]);
        $items = $pdo->prepare(
            "SELECT COALESCE(SUM((CASE WHEN oi.side = 'receivable' THEN 1 ELSE -1 END)
                        * (oi.amount - oi.settled_outside_amount
                           - COALESCE((SELECT SUM(x.amount) FROM other_item_allocations x WHERE x.other_item_id = oi.id AND x.reversed_on IS NULL), 0))), 0)
               FROM other_items oi
              WHERE oi.supplier_id = ? AND oi.account_code = ? AND oi.deleted_at IS NULL AND oi.status <> 'draft'"
        );
        $items->execute([$supplierId, $account]);
        $difference = self::cents((float) $balance->fetchColumn() - (float) $items->fetchColumn());
        return $debitNormal ? $difference : -$difference;
    }

    /**
     * Partner pohybu z exportu tak, jak ho vede POHODA. Název v pohybu to být nemusí, u pohybu
     * bez partnera ho nahrazuje poznámka, a z té se v adresáři partner nezakládá.
     *
     * @param list<array<string,mixed>> $lines
     * @return array<string,array<string,string>> `bank|id` nebo `cash|id` => snímek partnera
     */
    private function partnerSnapshots(PohodaContext $ctx, array $lines): array
    {
        $wanted = [];
        foreach ($lines as $line) {
            if ($line['source_id'] !== null) {
                $wanted[$line['source_type'] . '|' . $line['source_id']] = true;
            }
        }
        $out = [];
        foreach ($ctx->export->records('bank', 'bank') as $record) {
            $header = PohodaXml::get($record, 'bankHeader');
            $key = 'bank|' . ($ctx->bankByPohodaId[PohodaXml::text($header, 'id')] ?? '');
            if (isset($wanted[$key])) {
                $out[$key] = PartnerImporter::snapshot(PohodaXml::get($header, 'partnerIdentity'));
            }
        }
        foreach ($ctx->export->records('cash', 'voucher') as $record) {
            $header = PohodaXml::get($record, 'voucherHeader');
            $key = 'cash|' . ($ctx->cashDocuments[PohodaXml::text($header, 'number/numberRequested')] ?? '');
            if (isset($wanted[$key])) {
                $out[$key] = PartnerImporter::snapshot(PohodaXml::get($header, 'partnerIdentity'));
            }
        }
        return $out;
    }

    /** @return list<int> účty 315 a 325 včetně analytik, nebo zadaná syntetika */
    private function accountIds(int $supplierId, ?string $synthetic = null): array
    {
        $patterns = $synthetic === null ? ['315%', '325%'] : [$synthetic . '%', $synthetic . '%'];
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts
            WHERE supplier_id = ? AND (account_code LIKE ? OR account_code LIKE ?)');
        $stmt->execute([$supplierId, ...$patterns]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Řádky živých zápisů banky a pokladny na saldokontních účtech bez vlastní položky, od
     * nejstaršího. Vazba zápisu na doklad řádek nevylučuje: pohyb hradí fakturu a řádek vedle
     * ní bývá přeplatek. Co úhrada dokladu vysvětluje, řeší `explainedMovements` a symbol.
     *
     * @param list<int> $accountIds
     * @return list<array<string,mixed>>
     */
    private function lines(int $supplierId, array $accountIds, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.id AS line_id, l.entry_id, l.line_no, l.side, l.amount, p.pairing_symbol, a.account_code, LEFT(a.account_code, 3) AS synthetic,
                    e.document_no, e.entry_date, e.source_type, e.source_id, ap.status AS period_status,
                    t.counterparty_account, t.counterparty_bank,
                    t.variable_symbol,
                    COALESCE(t.counterparty_name, c.partner_name) AS partner_name
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
               LEFT JOIN accounting_periods ap ON ap.id = e.period_id AND ap.supplier_id = e.supplier_id
               LEFT JOIN journal_line_pairing p ON p.supplier_id = l.supplier_id AND p.entry_id = l.entry_id AND p.line_no = l.line_no
               LEFT JOIN (bank_transactions t JOIN bank_statements s ON s.id = t.statement_id)
                      ON e.source_type = 'bank' AND t.id = e.source_id AND s.supplier_id = e.supplier_id
               LEFT JOIN cash_documents c ON e.source_type = 'cash' AND c.id = e.source_id AND c.supplier_id = e.supplier_id
              WHERE l.supplier_id = ? AND l.account_id IN (" . implode(',', $accountIds) . ")
                AND l.is_red_storno = 0
                AND e.source_type IN ('bank', 'cash')
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                AND NOT EXISTS (SELECT 1 FROM journal_entries o WHERE o.supplier_id = e.supplier_id AND o.reversed_by = e.id)
                AND NOT EXISTS (SELECT 1 FROM other_items oi WHERE oi.supplier_id = e.supplier_id AND oi.journal_entry_id = e.id)
              ORDER BY e.entry_date, l.id"
        );
        $stmt->execute([$supplierId, $asOf]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Co už na řádcích je: `$covered` = haléře položek z mapy převodu po řádcích (včetně
     * stornovaných, rozhodnutí účetní se nepřepisuje), `$foreign` = řádky s jiným rozpadem.
     *
     * @param list<int> $accountIds
     * @param array<string,int|string> $mapped
     * @return array{0:array<string,int>,1:array<string,true>}
     */
    private function allocations(int $supplierId, array $accountIds, array $mapped): array
    {
        $own = array_fill_keys(array_map('intval', array_values($mapped)), true);
        $stmt = $this->db->pdo()->prepare(
            'SELECT entry_id, line_no, kind, doc_type, doc_id, amount FROM journal_settlement_allocations
              WHERE supplier_id = ? AND account_id IN (' . implode(',', $accountIds) . ')'
        );
        $stmt->execute([$supplierId]);
        $covered = [];
        $foreign = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['entry_id'] . '|' . $row['line_no'];
            if ($row['kind'] === 'origin' && $row['doc_type'] === 'other_item' && isset($own[(int) $row['doc_id']])) {
                $covered[$key] = ($covered[$key] ?? 0) + self::cents($row['amount']);
            } else {
                $foreign[$key] = true;
            }
        }
        return [$covered, $foreign];
    }

    /**
     * Pohyby, které hradí doklad vedený na 315 nebo 325 (ostatní položku, fakturu ostatní
     * pohledávky či závazku), takže jejich řádek zůstatek bez dokladu není. Úhrada faktury na
     * 311 nebo 321 řádek na 315 nevysvětluje: tak vypadá přeplatek zaúčtovaný vedle úhrady.
     * Položka bez účtu vysvětluje pohyb na obou účtech (`*`).
     *
     * @return array<string,true> `bank|id|315`
     */
    private function explainedMovements(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $out = [];
        $items = $pdo->prepare(
            "SELECT a.bank_transaction_id, a.cash_document_id, COALESCE(LEFT(oi.account_code, 3), '*') AS synthetic
               FROM other_item_allocations a
               JOIN other_items oi ON oi.id = a.other_item_id AND oi.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.reversed_on IS NULL"
        );
        $items->execute([$supplierId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $movement = $row['bank_transaction_id'] !== null ? 'bank|' . $row['bank_transaction_id'] : 'cash|' . $row['cash_document_id'];
            $out[$movement . '|' . $row['synthetic']] = true;
        }
        $documents = $pdo->prepare(
            "WITH paid AS (
                 SELECT 'bank' AS kind, bank_transaction_id AS movement, 'invoice' AS doc_type, invoice_id AS doc_id
                   FROM payment_matches WHERE supplier_id = ? AND invoice_id IS NOT NULL
                 UNION ALL
                 SELECT 'bank', bank_transaction_id, 'purchase_invoice', purchase_invoice_id
                   FROM payment_matches WHERE supplier_id = ? AND purchase_invoice_id IS NOT NULL
                 UNION ALL
                 SELECT 'cash', id, 'invoice', invoice_id
                   FROM cash_documents WHERE supplier_id = ? AND invoice_id IS NOT NULL
                 UNION ALL
                 SELECT 'cash', id, 'purchase_invoice', purchase_invoice_id
                   FROM cash_documents WHERE supplier_id = ? AND purchase_invoice_id IS NOT NULL
             )
             SELECT DISTINCT p.kind, p.movement, LEFT(a.account_code, 3) AS synthetic
               FROM paid p
               JOIN journal_entry_document_links k ON k.supplier_id = ? AND k.doc_type = p.doc_type AND k.doc_id = p.doc_id
               JOIN journal_entry_lines l ON l.supplier_id = k.supplier_id AND l.entry_id = k.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE a.account_code LIKE '315%' OR a.account_code LIKE '325%'"
        );
        $documents->execute(array_fill(0, 5, $supplierId));
        foreach ($documents->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['kind'] . '|' . $row['movement'] . '|' . $row['synthetic']] = true;
        }
        return $out;
    }

    /**
     * Klíč mapy řádku: další položka téhož řádku (zbytek po mezitím změněném párování) dostane pořadí.
     *
     * @param array<string,int|string> $mapped
     */
    private static function mapKey(string $key, array &$mapped): string
    {
        $candidate = $key;
        for ($i = 2; isset($mapped[$candidate]); $i++) {
            $candidate = $key . '#' . $i;
        }
        $mapped[$candidate] = 0;
        return $candidate;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ');
    }
}
