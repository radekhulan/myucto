<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Service\Invoice\IssuedCzkAmounts;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use PDO;

/**
 * Doklady z let před prvním převáděným rokem (volba „Převést od roku"), které byly k začátku
 * prvního převáděného období ještě otevřené: v MyÚčtu vzniknou jako doklady počátečního stavu,
 * navázané na otevírací zápis období ({@see \MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments}).
 * Saldokonto je pak ukáže proti počátečnímu stavu 311/321 a jejich pozdější úhrady z převedené
 * banky jde spárovat ({@see DocumentLinker::matchPayments()}).
 *
 * Money vede u dokladu jen datum úhrady (`Uhrazeno`) a zbytek k úhradě dnes (`Proplatit`).
 * Otevřený k začátku období `S` je proto:
 *  - faktura (`Druh` N, ne dobropis, ne stornovaná ani smazaná) uhrazená v `S` nebo později
 *    (celá částka, částečné úhrady před `S` Money nerozliší),
 *  - faktura bez data úhrady s nenulovým `Proplatit` se ohlásí k ověření a nezakládá: Money
 *    staré roky často neaktualizovalo a počáteční stav ji nemusí nést.
 *
 * Doklady, které už jsou na otevírací zápis prvního období navázané (převzaté dřívějším
 * převodem nebo doplněné a označené ručně, {@see \MyInvoice\Service\Accounting\Activation\OpeningBalanceDocumentMarker}),
 * se nezakládají znovu. Ručně navázaný doklad se převezme do mapy převodu místo kandidáta
 * z Money, když se s ním jednoznačně shoduje ({@see matchLinked()}); sporná shoda se ohlásí
 * a kandidát se nezaloží.
 *
 * Pojistka: součet nově zakládaných dokladů strany nesmí převýšit zbývající krytí, tj.
 * počáteční stav 311 (vydané), resp. 321 (přijaté) v otevíracím zápisu prvního období
 * minus doklady na něj už navázané. Jinak se nezaloží žádný nový doklad té strany
 * a protokol to ohlásí.
 */
final class OpeningDocuments
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const UNVERIFIED = 'unverified';
    public const SKIP = 'skip';

    /** Saldokontní účet strany a znaménko jeho počátečního stavu (MD − D). */
    private const BALANCE = ['issued' => ['311', 1], 'purchase' => ['321', -1]];

    /**
     * Stav dokladu Money k začátku prvního převáděného období.
     *
     * @param array<string,mixed> $r řádek `VFaktury` / `PFaktury`
     */
    public static function status(array $r, string $periodStart): string
    {
        if ((int) ($r['FlagDel'] ?? 0) === 1 || (int) ($r['Storno'] ?? 0) === 1 || (int) ($r['Dobropis'] ?? 0) === 1) {
            return self::SKIP;
        }
        $kind = strtoupper(trim((string) ($r['Druh'] ?? '')));
        if ($kind !== '' && $kind !== 'N') {
            return self::SKIP;
        }
        if (round((float) ($r['CelkemSDPH'] ?? 0), 2) <= 0.0) {
            return self::SKIP;
        }
        $paid = InvoiceImporter::date($r, ['Uhrazeno']);
        if ($paid !== null) {
            return $paid >= $periodStart ? self::OPEN : self::CLOSED;
        }
        return abs(round((float) ($r['Proplatit'] ?? 0), 2)) >= 0.005 ? self::UNVERIFIED : self::CLOSED;
    }

    /**
     * Rok adresáře, pokud je doklad ověřený doklad počátečního stavu ({@see plan()}), jinak null.
     *
     * @param array<string,mixed> $r
     */
    public static function yearOf(ImportContext $ctx, array $r, bool $issued): ?int
    {
        $dir = (string) ($r['__dir'] ?? '');
        if (!isset($ctx->openingDocumentDirs[$dir])) {
            return null;
        }
        return $ctx->openingCandidates[$issued ? 'issued' : 'purchase'][$dir . '|' . trim((string) ($r['Doklad'] ?? ''))] ?? null;
    }

    /**
     * Vybere doklady počátečního stavu jedné strany a ověří je proti počátečnímu stavu
     * (viz třída). Výsledek zapíše do kontextu, zprávy do protokolu kroku `$step`.
     *
     * @param array<string,int> $existing mapa převodu strany: "rok|číslo dokladu" => id dokladu
     * @return array<string,int> ručně navázané doklady převzaté místo kandidátů: "rok|číslo dokladu" => id
     */
    public static function plan(ImportContext $ctx, PDO $pdo, bool $issued, string $step, array $existing): array
    {
        $side = $issued ? 'issued' : 'purchase';
        $ctx->openingCandidates ??= ['issued' => [], 'purchase' => []];
        $ctx->openingCandidates[$side] = [];
        if ($ctx->openingDocumentDirs === [] || $ctx->periods === []) {
            return [];
        }
        $first = $ctx->periods[min(array_keys($ctx->periods))];
        $start = (string) $first['starts_on'];
        $open = [];
        $unverified = [];
        foreach ($ctx->backup->rowsAcrossYears($issued ? 'VFaktury' : 'PFaktury') as $r) {
            $dir = (string) ($r['__dir'] ?? '');
            $year = $ctx->openingDocumentDirs[$dir] ?? null;
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            if ($year === null || $docNo === '') {
                continue;
            }
            $total = round((float) ($r['CelkemSDPH'] ?? 0), 2);
            switch (self::status($r, $start)) {
                case self::OPEN:
                    $open[$dir . '|' . $docNo] = ['year' => $year, 'document_no' => $docNo, 'total' => $total] + self::identity($r, $issued, $docNo, $year);
                    break;
                case self::UNVERIFIED:
                    $unverified[] = ['year' => $year, 'document_no' => $docNo, 'total' => $total, 'remaining' => round((float) ($r['Proplatit'] ?? 0), 2)];
                    break;
            }
        }
        $label = $issued ? 'vydaných' : 'přijatých';
        $fromYear = (int) substr($start, 0, 4);
        if ($unverified !== []) {
            $ctx->protocol->warn($step, 'opening_documents_unverified', sprintf(
                '%d %s faktur z let před rokem %d nemá v Money datum úhrady, ale vede zbytek k úhradě (celkem %s Kč): %s. '
                . 'Money je ve starých letech nejspíš neaktualizovalo, převod je jako otevřené nezaložil. Ověřte je proti počátečnímu stavu a případně založte položku ze zůstatku.',
                count($unverified), $label, $fromYear, number_format(array_sum(array_column($unverified, 'remaining')), 2, ',', ' '),
                self::listText($unverified),
            ), ['documents' => array_slice($unverified, 0, ImportProtocol::LIST_LIMIT)]);
        }
        if ($open === []) {
            return [];
        }
        [$account, $sign] = self::BALANCE[$side];
        $periodId = (int) $first['id'];
        $balance = self::openingBalance($pdo, $ctx->supplierId, $periodId, $account, $sign);
        if ($balance === null) {
            $ctx->protocol->warn($step, 'opening_documents_no_opening', sprintf(
                '%d %s faktur z let před rokem %d bylo k jeho začátku otevřených, první převáděné období ale nemá otevírací zápis. Doklady počátečního stavu převod nezaložil.',
                count($open), $label, $fromYear,
            ));
            return [];
        }

        $match = self::matchLinked($open, self::linkedDocuments($pdo, $ctx->supplierId, $periodId, $issued), $existing);
        foreach ($match['existing'] as $dirKey) {
            $ctx->openingCandidates[$side][$dirKey] = $open[$dirKey]['year'];
        }
        $adopted = [];
        foreach ($match['adopted'] as $dirKey => $docId) {
            $ctx->openingCandidates[$side][$dirKey] = $open[$dirKey]['year'];
            $adopted[$open[$dirKey]['year'] . '|' . $open[$dirKey]['document_no']] = $docId;
        }
        if ($adopted !== []) {
            $docs = array_map(static fn (string $k): array => $open[$k], array_keys($match['adopted']));
            $ctx->protocol->info($step, 'opening_documents_adopted', sprintf(
                '%d %s faktur z let před rokem %d už v MyÚčtu je jako doklad počátečního stavu navázaný na otevírací zápis (%s). Převod je převzal a nový doklad nezaložil.',
                count($adopted), $label, $fromYear, self::listText($docs),
            ), ['documents' => array_slice(array_map(static fn (array $d): array => ['year' => $d['year'], 'document_no' => $d['document_no']], $docs), 0, ImportProtocol::LIST_LIMIT)]);
        }
        if ($match['ambiguous'] !== []) {
            $docs = array_map(static fn (string $k): array => $open[$k], $match['ambiguous']);
            $ctx->protocol->warn($step, 'opening_documents_ambiguous', sprintf(
                '%d %s faktur z let před rokem %d má v MyÚčtu doklad navázaný na otevírací zápis se stejným číslem nebo variabilním symbolem, '
                . 'který se ale neshoduje částkou, odpovídá více dokladům Money nebo patří k jinému otevíracímu zápisu (%s). Převod je nezaložil, ověřte je ručně.',
                count($docs), $label, $fromYear, self::listText($docs),
            ), ['documents' => array_slice(array_map(static fn (array $d): array => ['year' => $d['year'], 'document_no' => $d['document_no']], $docs), 0, ImportProtocol::LIST_LIMIT)]);
        }
        if ($match['new'] === []) {
            return $adopted;
        }

        $sum = round(array_sum(array_map(static fn (string $k): float => $open[$k]['total'], $match['new'])), 2);
        $remaining = round($balance - $match['covered'], 2);
        $coverage = $match['covered'] >= 0.005
            ? sprintf('zbývající krytí počátečního stavu účtu %s (%s Kč, z počátečního stavu %s Kč už %s Kč nesou navázané doklady)',
                $account, number_format($remaining, 2, ',', ' '), number_format($balance, 2, ',', ' '), number_format($match['covered'], 2, ',', ' '))
            : sprintf('počáteční stav účtu %s (%s Kč)', $account, number_format($balance, 2, ',', ' '));
        if (round($sum - $remaining, 2) > 1.0) {
            $ctx->protocol->warn($step, 'opening_documents_exceed_balance', sprintf(
                'Otevřené %s faktury z let před rokem %d (%d dokladů, %s Kč) převyšují %s. Doklady počátečního stavu převod nezaložil, ověřte úhrady v Money.',
                $issued ? 'vydané' : 'přijaté', $fromYear, count($match['new']), number_format($sum, 2, ',', ' '), $coverage,
            ), ['documents' => count($match['new']), 'amount' => $sum, 'balance' => $balance, 'covered' => $match['covered']]);
            return $adopted;
        }
        foreach ($match['new'] as $dirKey) {
            $ctx->openingCandidates[$side][$dirKey] = $open[$dirKey]['year'];
        }
        $ctx->protocol->info($step, 'opening_documents', sprintf(
            '%d %s faktur z let před rokem %d bylo k jeho začátku otevřených (%s Kč, %s). Převádějí se jako doklady počátečního stavu navázané na otevírací zápis.',
            count($match['new']), $label, $fromYear, number_format($sum, 2, ',', ' '), $coverage,
        ), ['documents' => count($match['new']), 'amount' => $sum, 'balance' => $balance, 'covered' => $match['covered']]);
        return $adopted;
    }

    /**
     * Rozdělí otevřené doklady Money podle dokladů, které už jsou na otevírací zápis navázané.
     *
     * Kandidát s klíčem v mapě převodu už v MyÚčtu je (`existing`). Ostatní se porovnají
     * s navázanými doklady mimo mapu (ručně doplněné). Shoda identifikátoru = číslo dokladu
     * (i s příponou roku, kterou by mu dal převod), variabilní symbol, u přijaté faktury
     * i číslo dokladu dodavatele. Identifikátor sám nestačí: Money čísluje řady každý rok
     * od začátku a VS se u různých partnerů opakuje. Proto se navíc nesmí lišit IČO partnera
     * (chybějící IČO shodu nevylučuje) a pro převzetí se musí shodovat celková částka v Kč.
     *  - `adopted`: přesně jedna taková shoda na otevírací zápis prvního období, kterou si
     *    nenárokuje jiný kandidát - doklad se převezme do mapy místo založení nového,
     *  - `ambiguous`: shoda identifikátoru a partnera s jinou částkou, s více doklady, s dokladem
     *    sdíleným více kandidáty nebo s dokladem jiného otevíracího zápisu - nezakládá se,
     *  - `new`: bez shody, založí se po ověření proti zbývajícímu krytí.
     * `covered` je částka, kterou už nesou doklady navázané na otevírací zápis prvního období,
     * plus kandidáti z mapy, jejichž doklad na něm navázaný není (převod je naváže).
     *
     * @param array<string,array{year:int,document_no:string,total:float,ids:array<string,true>,ico:string}> $open
     * @param list<array{id:int,total:float,ids:array<string,true>,ico:string,first:bool}> $linked
     * @param array<string,int> $existing
     * @return array{existing:list<string>,adopted:array<string,int>,ambiguous:list<string>,new:list<string>,covered:float}
     */
    public static function matchLinked(array $open, array $linked, array $existing): array
    {
        $mapped = array_flip(array_map('intval', $existing));
        $covered = 0.0;
        $firstIds = [];
        $byId = [];
        foreach ($linked as $i => $d) {
            if ($d['first']) {
                $covered += $d['total'];
                $firstIds[$d['id']] = true;
            }
            if (isset($mapped[$d['id']])) {
                continue;
            }
            foreach (array_keys($d['ids']) as $id) {
                $byId[$id][$i] = true;
            }
        }
        $out = ['existing' => [], 'adopted' => [], 'ambiguous' => [], 'new' => [], 'covered' => 0.0];
        $found = [];
        $claims = [];
        foreach ($open as $dirKey => $c) {
            $docId = $existing[$c['year'] . '|' . $c['document_no']] ?? null;
            if ($docId !== null) {
                $out['existing'][] = $dirKey;
                if (!isset($firstIds[(int) $docId])) {
                    $covered += $c['total'];
                }
                continue;
            }
            $hits = [];
            foreach (array_keys($c['ids']) as $id) {
                $hits += $byId[$id] ?? [];
            }
            $matches = [];
            $conflict = false;
            foreach (array_keys($hits) as $i) {
                $d = $linked[$i];
                if ($c['ico'] !== '' && $d['ico'] !== '' && $c['ico'] !== $d['ico']) {
                    continue;
                }
                $claims[$d['id']][$dirKey] = true;
                if ($d['first'] && abs($d['total'] - $c['total']) < 0.005) {
                    $matches[] = $d['id'];
                } else {
                    $conflict = true;
                }
            }
            $found[$dirKey] = ['matches' => $matches, 'conflict' => $conflict];
        }
        foreach ($found as $dirKey => $f) {
            if ($f['matches'] === [] && !$f['conflict']) {
                $out['new'][] = $dirKey;
            } elseif (count($f['matches']) === 1 && !$f['conflict'] && count($claims[$f['matches'][0]]) === 1) {
                $out['adopted'][$dirKey] = $f['matches'][0];
            } else {
                $out['ambiguous'][] = $dirKey;
            }
        }
        $out['covered'] = round($covered, 2);
        return $out;
    }

    /**
     * Identifikátory a IČO partnera dokladu Money pro porovnání s navázanými doklady ({@see matchLinked()}).
     *
     * @param array<string,mixed> $r
     * @return array{ids:array<string,true>,ico:string}
     */
    private static function identity(array $r, bool $issued, string $docNo, int $year): array
    {
        $suffix = '/' . $year;
        $values = [$docNo, mb_substr($docNo, 0, 20), mb_substr($docNo, 0, 20 - mb_strlen($suffix)) . $suffix, (string) ($r['VarSymbol'] ?? '')];
        if (!$issued) {
            $values[] = (string) ($r['PrijatDokl'] ?? '');
        }
        $ico = $issued ? (string) ($r['O_ICO'] ?? $r['AdICO'] ?? '') : (string) ($r['D_ICO'] ?? '');
        return ['ids' => self::identifiers($values), 'ico' => CodebookImporter::ico($ico)];
    }

    /**
     * @param list<string|null> $values
     * @return array<string,true>
     */
    private static function identifiers(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $v = mb_strtoupper(preg_replace('/\s+/u', '', (string) $v) ?? '');
            if ($v !== '') {
                $out[$v] = true;
            }
        }
        return $out;
    }

    /**
     * Doklady strany navázané na živé otevírací zápisy firmy, jak je saldokonto ukáže proti
     * počátečnímu stavu ({@see \MyInvoice\Repository\SaldoRepository}: ne koncept ani storno,
     * u přijatých ne záloha). `first` = vazba na otevírací zápis období `$periodId`.
     *
     * @return list<array{id:int,total:float,ids:array<string,true>,ico:string,first:bool}>
     */
    private static function linkedDocuments(PDO $pdo, int $supplierId, int $periodId, bool $issued): array
    {
        [$docType, $table, $partner, $vendorNumber] = $issued
            ? ['invoice', 'invoices', 'client_id', 'NULL']
            : ['purchase_invoice', 'purchase_invoices', 'vendor_id', 'd.vendor_invoice_number'];
        $total = ForeignCurrencyTakeover::homeAmountSql('d.total_with_vat', 'd.exchange_rate');
        if ($issued) {
            $total = IssuedCzkAmounts::grossSql('d', $total);
        }
        $stmt = $pdo->prepare(
            "SELECT d.id, MAX(e.period_id = ?), {$total},
                    d.varsymbol, d.payment_variable_symbol, {$vendorNumber}, cl.ic
               FROM journal_entries e
               JOIN journal_entry_document_links l ON l.supplier_id = e.supplier_id AND l.entry_id = e.id AND l.doc_type = ?
               JOIN {$table} d ON d.id = l.doc_id AND d.supplier_id = l.supplier_id
               JOIN clients cl ON cl.id = d.{$partner}
              WHERE e.supplier_id = ? AND e.source_type = 'opening' AND e.reversed_by IS NULL
                AND d.status NOT IN ('draft', 'cancelled')" . ($issued ? '' : " AND d.document_kind <> 'advance'") . '
              GROUP BY d.id'
        );
        $stmt->execute([$periodId, $docType, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $isFirst, $total, $a, $b, $c, $ico]) {
            $out[] = ['id' => (int) $id, 'total' => round((float) $total, 2), 'ids' => self::identifiers([$a, $b, $c]),
                'ico' => CodebookImporter::ico((string) $ico), 'first' => (bool) $isFirst];
        }
        return $out;
    }

    /** @param list<array{year:int,document_no:string}> $docs */
    private static function listText(array $docs): string
    {
        return implode(', ', array_map(static fn (array $d): string => "{$d['document_no']} ({$d['year']})", array_slice($docs, 0, 20))) . (count($docs) > 20 ? ', …' : '');
    }

    /**
     * Počáteční stav syntetického účtu (se znaménkem strany) v živém otevíracím zápisu
     * období, nebo null, když období otevírací zápis nemá.
     */
    private static function openingBalance(PDO $pdo, int $supplierId, int $periodId, string $account, int $sign): ?float
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT e.id), COALESCE(SUM(CASE WHEN a.account_code LIKE ? THEN IF(l.side = 'debit', l.signed_amount, -l.signed_amount) ELSE 0 END), 0)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.period_id = ? AND e.source_type = 'opening' AND e.reversed_by IS NULL"
        );
        $stmt->execute([$account . '%', $supplierId, $periodId]);
        [$entries, $balance] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
        return (int) $entries === 0 ? null : round($sign * (float) $balance, 2);
    }
}
