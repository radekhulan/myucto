<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalLinePairingRepository;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\DocumentPartnerName;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\Pair\JournalPairer;
use PDO;

/**
 * Kontext řádků výpisu účtu: protiúčet, partner, variabilní symbol, měna a okruh.
 *
 * Dotahuje se dávkově pro jednu stránku výpisu (pár dotazů bez ohledu na počet
 * řádků), ne JOINy v dotazu s běžícím zůstatkem: ten jede přes celou historii
 * účtu a další JOINy na doklady by ho zpomalily kvůli sloupcům, které se ukážou
 * jen na jedné stránce.
 *
 * Partner a VS se berou ze zdrojového dokladu zápisu (vydaná a přijatá faktura,
 * bankovní pohyb, pokladní doklad, zápočet přes hrazený doklad). Měna je měna
 * řádku, jinak měna dokladu, jinak CZK.
 *
 * Jednoduchý zápis: u firmy, která ho má aspoň od nějakého data
 * ({@see EntryFormService::hasAnySingle()}), nese položka navíc `pair_no`,
 * `counter_account_id` a `pair_status`:
 *  - `paired`: řádek je ve dvojici, protiúčet je druhý řádek dvojice;
 *  - `derived`: starší zápis bez dvojic, který jde jednoznačně rozložit (1 : N
 *    a dvojice se shodnou částkou, každá částka nejvýš jednou na straně); protiúčty
 *    dílů dává {@see JournalPairer} a shodují se s poměrným dělením v SQL;
 *  - `composite_mn`: zápis zůstává v původním tvaru (M : N, nejednoznačné dvojice
 *    se shodnou částkou), protiúčet dnešním pravidlem.
 * Firma bez jednoduchého zápisu jde beze změny dnešní cestou i dnešním SQL.
 */
final class JournalLineContext
{
    private const MAX_COUNTER_ACCOUNTS = 3;

    public const PAIR_STATUS_PAIRED = 'paired';
    public const PAIR_STATUS_DERIVED = 'derived';
    public const PAIR_STATUS_COMPOSITE = 'composite_mn';

    public function __construct(
        private readonly Connection $db,
        private readonly JournalLinePairingRepository $pairings,
        private readonly EntryFormService $entryForms,
    ) {}

    /**
     * Každá položka musí nést entry_id, line_id, line_no, account_id (účet řádku), side,
     * source_type, source_id, currency_code a amount_foreign.
     *
     * `$withPieces` (opis účtu): u řádku staršího zápisu 1 : N, který se rozkládá na víc
     * dvojic, přidá `pair_pieces` = díly {amount_cents, amount_foreign_cents,
     * counter_account_id, counter_account_code, counter_parent_id} v pořadí dvojic.
     * Rozbalení na pohyby dělá volající; jen u firmy s jednoduchým zápisem.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function enrich(int $supplierId, array $items, bool $withPieces = false): array
    {
        if ($items === []) {
            return [];
        }
        $entryIds = array_values(array_unique(array_map(static fn (array $i): int => (int) $i['entry_id'], $items)));
        $pairs = $this->entryForms->hasAnySingle($supplierId);
        $entryLines = $pairs ? $this->pairedEntryLines($supplierId, $entryIds) : $this->entryLines($supplierId, $entryIds);

        $refs = [];
        foreach ($items as $it) {
            $sid = $it['source_id'] ?? null;
            if ($sid !== null && (int) $sid > 0 && (int) $sid < ClosingSourceId::STOCK_SLOT_BASE) {
                $refs[(string) $it['source_type']][] = (int) $sid;
            }
        }
        $docs = $this->documents($supplierId, $refs);
        $pairingMap = $this->pairings->pairingsForLines($supplierId, array_map(static fn (array $i): array => [
            'entry_id' => (int) $i['entry_id'],
            'line_no' => (int) $i['line_no'],
            'account_id' => (int) $i['account_id'],
        ], $items));

        foreach ($items as &$it) {
            $doc = $docs[(string) $it['source_type'] . ':' . (string) ($it['source_id'] ?? '')] ?? null;
            $it['counter_accounts'] = $this->counterAccounts($entryLines[(int) $it['entry_id']] ?? [], $it);
            if ($pairs) {
                $it += self::pairContext($entryLines[(int) $it['entry_id']] ?? [], $it, $withPieces);
            }
            $it['partner'] = $doc['partner'] ?? null;
            $it['variable_symbol'] = $doc['vs'] ?? null;
            $lineCurrency = isset($it['currency_code']) && $it['currency_code'] !== null && $it['currency_code'] !== ''
                ? strtoupper((string) $it['currency_code']) : null;
            $it['currency'] = $lineCurrency ?? ($doc['currency'] ?? 'CZK');
            $it['amount_foreign'] = isset($it['amount_foreign']) && $it['amount_foreign'] !== null
                ? round((float) $it['amount_foreign'], 2) : null;
            $it['pairing_id'] = $pairingMap[$it['entry_id'] . ':' . $it['line_no'] . ':' . $it['account_id']] ?? null;
            unset($it['currency_code']);
        }
        unset($it);
        return $items;
    }

    /**
     * Jen protiúčty, bez dokladů a párování z {@see enrich()}: rozpis hlavní knihy
     * je jinak nepoužije a u celého roku by stály tisíce dotazů navíc.
     * Položka nese entry_id, line_id, account_code, side a is_red_storno základního řádku (účinná strana protiúčtu).
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function withCounterAccounts(int $supplierId, array $items): array
    {
        if ($items === []) {
            return [];
        }
        $entryIds = array_values(array_unique(array_map(static fn (array $i): int => (int) $i['entry_id'], $items)));
        $pairs = $this->entryForms->hasAnySingle($supplierId);
        $entryLines = $pairs ? $this->pairedEntryLines($supplierId, $entryIds) : $this->entryLines($supplierId, $entryIds);
        foreach ($items as &$it) {
            $it['counter_accounts'] = $this->counterAccounts($entryLines[(int) $it['entry_id']] ?? [], $it);
        }
        unset($it);
        return $items;
    }

    /**
     * Protiúčty řádku: účty opačné ÚČINNÉ strany téhož zápisu (včetně vlastního účtu,
     * je-li na opačné straně); u zápisu bez opačné strany ostatní účty zápisu. Zápis
     * složený z dvojic (pokladní doklad 501 / 211 a 513 / 211) se páruje podle částky:
     * řádky se shodnou částkou tvoří dvojici, když jich je na obou účinných stranách
     * stejně. Spárovaný řádek má za protiúčet jen svou dvojici, ostatní řádky jen ostatní
     * nespárované řádky protistrany. Stejné pravidlo drží filtr i součty
     * v {@see \MyInvoice\Repository\LedgerReportRepository::accountCounterTotals()},
     * jinak by řádek ukazoval jiný protiúčet, než podle kterého se dá vyfiltrovat.
     *
     * @param array<string,mixed> $entry index zápisu z {@see entryIndex()} ([] = zápis nenalezen)
     * @param array<string,mixed> $item
     */
    private function counterAccounts(array $entry, array $item): ?string
    {
        if ($entry === []) {
            return null;
        }
        // Dvojice jednoduchého zápisu: protiúčet je jediný, druhý řádek dvojice.
        $partner = $entry['partner'][(int) ($item['line_id'] ?? 0)] ?? null;
        if ($partner !== null) {
            return $partner['account_code'];
        }
        // Starší zápis rozložený na díly: protiúčty dílů (u 1 : N totéž co poměrné dělení).
        $pieces = $entry['pieces'][(int) ($item['line_id'] ?? 0)] ?? null;
        if ($pieces !== null) {
            return self::label(array_fill_keys(array_column($pieces, 'counter_account_code'), true));
        }
        $own = (string) ($item['account_code'] ?? '');
        $ownSide = !empty($item['is_red_storno']) ? ($item['side'] === 'debit' ? 'credit' : 'debit') : $item['side'];
        $opposite = $ownSide === 'debit' ? 'credit' : 'debit';
        // Vlastní řádek z deníku podle id (částku bere odtud, položka může nést jen díl řádku;
        // line_no se v převzatých zápisech opakuje).
        $ownCents = $entry['by_id'][(int) ($item['line_id'] ?? 0)] ?? null;
        // Vlastní účet na opačné straně je protiúčtem jako každý jiný (převod v rámci
        // účtu); součty i filtr mu dávají jeho díl, zobrazení ho proto taky ukáže.
        $matched = $ownCents !== null && isset($entry['paired'][$ownCents])
            ? ($entry['pair_label'][$opposite][$ownCents] ?? null)
            : ($entry['free_label'][$opposite] ?? null);
        if ($matched !== null) {
            return $matched;
        }
        // Nespárovaný řádek, jehož protistrana je celá spárovaná, protiúčet nemá (součty
        // ho vedou bez protiúčtu a filtr ho nenajde). Ostatní účty zápisu jako nápověda
        // jen u jednostranného zápisu bez opačné strany.
        if (isset($entry['sides'][$opposite])) {
            return null;
        }
        $other = $entry['codes'];
        unset($other[$own]);
        return self::label($other);
    }

    /**
     * Index zápisu pro {@see counterAccounts()}: dvojice (částky se stejným počtem řádků
     * na obou účinných stranách) a hotové popisky protiúčtů podle strany a částky.
     * Spočítá se jednou na zápis, takže zápis o tisících řádků není kvadratický.
     *
     * @param list<array{line_id:int, account_code:string, side:string, amount_cents:int}> $lines
     * @return array<string,mixed>
     */
    private static function entryIndex(array $lines): array
    {
        $count = [];
        $byId = [];
        foreach ($lines as $l) {
            $count[$l['amount_cents']][$l['side']] = ($count[$l['amount_cents']][$l['side']] ?? 0) + 1;
            $byId[$l['line_id']] = $l['amount_cents'];
        }
        $paired = [];
        foreach ($count as $cents => $sides) {
            if (($sides['debit'] ?? 0) === ($sides['credit'] ?? 0)) {
                $paired[$cents] = true;
            }
        }
        $pair = [];
        $free = [];
        $sides = [];
        $codes = [];
        foreach ($lines as $l) {
            $sides[$l['side']] = true;
            $codes[$l['account_code']] = true;
            if (isset($paired[$l['amount_cents']])) {
                $pair[$l['side']][$l['amount_cents']][$l['account_code']] = true;
            } else {
                $free[$l['side']][$l['account_code']] = true;
            }
        }
        return [
            'by_id'      => $byId,
            'paired'     => $paired,
            'pair_label' => array_map(static fn (array $byCents): array => array_map(self::label(...), $byCents), $pair),
            'free_label' => array_map(self::label(...), $free),
            'sides'      => $sides,
            'codes'      => $codes,
        ];
    }

    /** @param array<string,true> $codes */
    private static function label(array $codes): ?string
    {
        if ($codes === []) {
            return null;
        }
        $codes = array_map('strval', array_keys($codes));
        sort($codes, SORT_STRING);
        $shown = array_slice($codes, 0, self::MAX_COUNTER_ACCOUNTS);
        return implode(', ', $shown) . (count($codes) > self::MAX_COUNTER_ACCOUNTS ? ', …' : '');
    }

    /**
     * Doplňky položky u firmy s jednoduchým zápisem (viz popis třídy).
     *
     * @param array<string,mixed> $entry index z {@see pairedEntryLines()}
     * @param array<string,mixed> $item
     * @return array{pair_no:?int, counter_account_id:?int, pair_status:?string, pair_pieces?:list<array<string,mixed>>}
     */
    private static function pairContext(array $entry, array $item, bool $withPieces): array
    {
        $lineId = (int) ($item['line_id'] ?? 0);
        if ($entry === []) {
            return ['pair_no' => null, 'counter_account_id' => null, 'pair_status' => null];
        }
        $partner = $entry['partner'][$lineId] ?? null;
        if ($partner !== null) {
            return [
                'pair_no'            => $entry['pair_no'][$lineId],
                'counter_account_id' => $partner['account_id'],
                'pair_status'        => self::PAIR_STATUS_PAIRED,
            ];
        }
        $pieces = $entry['pieces'][$lineId] ?? null;
        if ($pieces === null) {
            return ['pair_no' => null, 'counter_account_id' => null, 'pair_status' => self::PAIR_STATUS_COMPOSITE];
        }
        $out = [
            'pair_no'            => null,
            'counter_account_id' => count($pieces) === 1 ? $pieces[0]['counter_account_id'] : null,
            'pair_status'        => self::PAIR_STATUS_DERIVED,
        ];
        if ($withPieces && count($pieces) > 1) {
            $out['pair_pieces'] = $pieces;
        }
        return $out;
    }

    /**
     * Díly řádků staršího zápisu bez dvojic, rozloženého za běhu (nic se nezapisuje).
     * Jen jednoznačné rozložení: každá částka spárovaná podle shody je na obou účinných
     * stranách nejvýš jednou a její dvojice nemá stejný účet; zbytek je 1 : N. Pak se díly
     * {@see JournalPairer} kryjí s poměrným dělením v SQL ({@see \MyInvoice\Repository\LedgerReportRepository::accountCounterTotals()})
     * i s filtrem protiúčtu. Jinak null (zápis zůstává v původním tvaru).
     *
     * @param list<array{line_id:int, account_id:int, account_code:string, parent_id:?int, side:string, raw_side:string, is_red_storno:bool, amount:string, amount_foreign:?string, amount_cents:int}> $lines
     * @return array<int, list<array{amount_cents:int, amount_foreign_cents:?int, counter_account_id:int, counter_account_code:string, counter_parent_id:?int}>>|null
     */
    public static function derivedPieces(array $lines): ?array
    {
        if ($lines === []) {
            return null;
        }
        $count = [];
        foreach ($lines as $l) {
            $count[$l['amount_cents']][$l['side']][] = $l;
        }
        foreach ($count as $sides) {
            $d = $sides['debit'] ?? [];
            $c = $sides['credit'] ?? [];
            if ($d !== [] && count($d) === count($c)
                && (count($d) > 1
                    || $d[0]['account_id'] === $c[0]['account_id']
                    || $d[0]['is_red_storno'] !== $c[0]['is_red_storno'])
            ) {
                return null;
            }
        }
        $byId = [];
        $input = [];
        foreach ($lines as $l) {
            $byId[$l['line_id']] = $l;
            $input[] = [
                'id'             => $l['line_id'],
                'account_id'     => $l['account_id'],
                'side'           => $l['raw_side'],
                'is_red_storno'  => $l['is_red_storno'],
                'amount'         => $l['amount'],
                'amount_foreign' => $l['amount_foreign'],
            ];
        }
        $result = JournalPairer::pair($input);
        if ($result['status'] !== JournalPairer::STATUS_PAIRED) {
            return null;
        }
        $byPair = [];
        foreach ($result['lines'] as $piece) {
            $byPair[(int) $piece['pair_no']][] = $piece;
        }
        $pieces = [];
        foreach ($byPair as [$a, $b]) {
            foreach ([[$a, $b], [$b, $a]] as [$own, $counter]) {
                $counterLine = $byId[(int) $counter['id']];
                $pieces[(int) $own['id']][] = [
                    'amount_cents'         => JournalLineAmount::cents($own['amount']),
                    'amount_foreign_cents' => isset($own['amount_foreign']) && $own['amount_foreign'] !== null && $own['amount_foreign'] !== ''
                        ? JournalLineAmount::cents($own['amount_foreign']) : null,
                    'counter_account_id'   => $counterLine['account_id'],
                    'counter_account_code' => $counterLine['account_code'],
                    'counter_parent_id'    => $counterLine['parent_id'],
                ];
            }
        }
        return $pieces;
    }

    /**
     * Index zápisů firmy s jednoduchým zápisem: dnešní index nad řádky BEZ dvojice
     * (shodně s SQL, které páruje podle částky jen mezi nimi), partner každého řádku
     * dvojice a díly starších zápisů rozložených za běhu ({@see derivedPieces()}).
     *
     * @param list<int> $entryIds
     * @return array<int,array<string,mixed>>
     */
    private function pairedEntryLines(int $supplierId, array $entryIds): array
    {
        $raw = [];
        foreach (array_chunk($entryIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT l.id, l.entry_id, l.account_id, ca.account_code, ca.parent_id, l.amount, l.amount_foreign,
                        l.side AS raw_side, l.is_red_storno, l.pair_no,
                        CASE WHEN l.is_red_storno = 1
                             THEN (CASE WHEN l.side = 'debit' THEN 'credit' ELSE 'debit' END)
                             ELSE l.side END AS side
                   FROM journal_entry_lines l
                   JOIN chart_of_accounts ca ON ca.id = l.account_id
                  WHERE l.supplier_id = ? AND l.entry_id IN ({$in})
                  ORDER BY l.entry_id, l.line_no, l.id"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $raw[(int) $r['entry_id']][] = [
                    'line_id'        => (int) $r['id'],
                    'account_id'     => (int) $r['account_id'],
                    'account_code'   => (string) $r['account_code'],
                    'parent_id'      => $r['parent_id'] === null ? null : (int) $r['parent_id'],
                    'side'           => (string) $r['side'],
                    'raw_side'       => (string) $r['raw_side'],
                    'is_red_storno'  => (bool) $r['is_red_storno'],
                    'amount'         => (string) $r['amount'],
                    'amount_foreign' => $r['amount_foreign'] === null ? null : (string) $r['amount_foreign'],
                    'amount_cents'   => JournalLineAmount::cents((string) $r['amount']),
                    'pair_no'        => $r['pair_no'] === null ? null : (int) $r['pair_no'],
                ];
            }
        }
        $out = [];
        foreach ($raw as $entryId => $lines) {
            $paired = [];
            $unpaired = [];
            foreach ($lines as $l) {
                if ($l['pair_no'] !== null) {
                    $paired[$l['pair_no']][] = $l;
                } else {
                    $unpaired[] = $l;
                }
            }
            $index = self::entryIndex($unpaired);
            // Strany a účty celého zápisu (nápověda u jednostranného zápisu) jako dnes.
            foreach ($lines as $l) {
                $index['sides'][$l['side']] = true;
                $index['codes'][$l['account_code']] = true;
            }
            $index['partner'] = [];
            $index['pair_no'] = [];
            foreach ($paired as $pairNo => $pair) {
                if (count($pair) !== 2) {
                    continue;
                }
                foreach ([[$pair[0], $pair[1]], [$pair[1], $pair[0]]] as [$own, $counter]) {
                    $index['partner'][$own['line_id']] = $counter;
                    $index['pair_no'][$own['line_id']] = $pairNo;
                }
            }
            $index['pieces'] = $paired === [] ? (self::derivedPieces($unpaired) ?? []) : [];
            $out[$entryId] = $index;
        }
        return $out;
    }

    /**
     * @param list<int> $entryIds
     * @return array<int,array<string,mixed>> entry_id => index z {@see entryIndex()} (strana = účinná)
     */
    private function entryLines(int $supplierId, array $entryIds): array
    {
        $out = [];
        foreach (array_chunk($entryIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT l.id, l.entry_id, ca.account_code, l.amount,
                        CASE WHEN l.is_red_storno = 1
                             THEN (CASE WHEN l.side = 'debit' THEN 'credit' ELSE 'debit' END)
                             ELSE l.side END AS side
                   FROM journal_entry_lines l
                   JOIN chart_of_accounts ca ON ca.id = l.account_id
                  WHERE l.supplier_id = ? AND l.entry_id IN ({$in})"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['entry_id']][] = [
                    'line_id'      => (int) $r['id'],
                    'account_code' => (string) $r['account_code'],
                    'side'         => (string) $r['side'],
                    'amount_cents' => (int) round(((float) $r['amount']) * 100),
                ];
            }
        }
        return array_map(self::entryIndex(...), $out);
    }

    /**
     * @param array<string,list<int>> $refs source_type => id[]
     * @return array<string,array{partner:?string, vs:?string, currency:?string}> "typ:id" => kontext
     */
    private function documents(int $supplierId, array $refs): array
    {
        $out = [];
        $settlements = $refs['settlement'] ?? [];
        if ($settlements !== []) {
            foreach ($this->rows(
                "SELECT id, doc_type, doc_id FROM invoice_settlements WHERE supplier_id = ? AND id IN (%s)",
                $supplierId,
                $settlements,
            ) as $r) {
                $type = (string) $r['doc_type'];
                if ($type === 'invoice' || $type === 'purchase_invoice') {
                    $refs[$type][] = (int) $r['doc_id'];
                    $out['settlement:' . $r['id']] = ['ref' => $type . ':' . $r['doc_id']];
                }
            }
        }

        if (!empty($refs['invoice'])) {
            foreach ($this->rows(
                "SELECT i.id, i.varsymbol, i.client_snapshot, c.company_name, c.first_name, c.last_name,
                        UPPER(cur.code) AS currency
                   FROM invoices i
              LEFT JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
              LEFT JOIN currencies cur ON cur.id = i.currency_id
                  WHERE i.supplier_id = ? AND i.id IN (%s)",
                $supplierId,
                $refs['invoice'],
            ) as $r) {
                $out['invoice:' . $r['id']] = [
                    'partner'  => DocumentPartnerName::from($r),
                    'vs'       => self::nullable($r['varsymbol']),
                    'currency' => self::nullable($r['currency']),
                ];
            }
        }
        if (!empty($refs['purchase_invoice'])) {
            foreach ($this->rows(
                "SELECT p.id, p.varsymbol, p.vendor_snapshot, c.company_name, c.first_name, c.last_name,
                        UPPER(cur.code) AS currency
                   FROM purchase_invoices p
              LEFT JOIN clients c ON c.id = p.vendor_id AND c.supplier_id = p.supplier_id
              LEFT JOIN currencies cur ON cur.id = p.currency_id
                  WHERE p.supplier_id = ? AND p.id IN (%s)",
                $supplierId,
                $refs['purchase_invoice'],
            ) as $r) {
                $out['purchase_invoice:' . $r['id']] = [
                    'partner'  => DocumentPartnerName::from($r, 'vendor_snapshot'),
                    'vs'       => self::nullable($r['varsymbol']),
                    'currency' => self::nullable($r['currency']),
                ];
            }
        }
        if (!empty($refs['bank'])) {
            // bank_transactions nemá supplier_id — tenant přes výpis.
            foreach ($this->rows(
                "SELECT t.id, t.counterparty_name, t.variable_symbol, UPPER(COALESCE(t.currency, s.currency)) AS currency
                   FROM bank_transactions t
                   JOIN bank_statements s ON s.id = t.statement_id
                  WHERE s.supplier_id = ? AND t.id IN (%s)",
                $supplierId,
                $refs['bank'],
            ) as $r) {
                $out['bank:' . $r['id']] = [
                    'partner'  => self::nullable($r['counterparty_name']),
                    'vs'       => self::nullable($r['variable_symbol']),
                    'currency' => self::nullable($r['currency']),
                ];
            }
        }
        if (!empty($refs['cash'])) {
            foreach ($this->rows(
                "SELECT id, partner_name, UPPER(currency_code) AS currency
                   FROM cash_documents
                  WHERE supplier_id = ? AND id IN (%s)",
                $supplierId,
                $refs['cash'],
            ) as $r) {
                $out['cash:' . $r['id']] = [
                    'partner'  => self::nullable($r['partner_name']),
                    'vs'       => null,
                    'currency' => self::nullable($r['currency']),
                ];
            }
        }

        foreach ($out as $key => $ctx) {
            if (isset($ctx['ref'])) {
                $out[$key] = $out[$ctx['ref']] ?? ['partner' => null, 'vs' => null, 'currency' => null];
            }
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function rows(string $sqlWithIn, int $supplierId, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(sprintf($sqlWithIn, implode(',', array_fill(0, count($chunk), '?'))));
            $stmt->execute([$supplierId, ...$chunk]);
            $rows = [...$rows, ...$stmt->fetchAll(PDO::FETCH_ASSOC)];
        }
        return $rows;
    }

    private static function nullable(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
