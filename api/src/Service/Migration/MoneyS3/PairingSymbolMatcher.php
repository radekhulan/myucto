<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use PDO;

/**
 * Vyrovnání dokladů podle párovacího symbolu deníku Money (`UcDenik.ParSym`).
 *
 * Saldokonto Money stojí na párovacím symbolu řádků deníku: předpis faktury, každá
 * úhrada (banka, pokladna) i vyrovnání interním dokladem (zápočet přes 395, odpis,
 * kurzový rozdíl) nesou na saldokontním účtu stejný symbol. Faktura sama drží v `UDoklad`
 * jen POSLEDNÍ doklad, který ji vyrovnal - u faktury placené po částech jen poslední
 * splátku, u faktury v cizí měně často jen interní doklad s kurzovým rozdílem. Párování
 * jen podle `UDoklad` ({@see DocumentLinker::matchPayments()}) proto nechalo dřívější
 * úhrady nespárované a saldokonto drželo uhrazené faktury jako otevřené.
 *
 * Skupina = symbol × účet (analytika) předpisu. Ve skupině se:
 *  - dopárují bankovní a pokladní úhrady do výše předpisu snížené o už evidované úhrady,
 *    u víc faktur podle data; úhrada v cizí měně v měně dokladu (kurz z `CastkaCM`),
 *    takže kurzový rozdíl úhradu nekrátí; vratka ani proplacení dobropisu se nepárují,
 *  - dobropis jediné faktury skupiny započte proti ní (vazba na opravovanou fakturu
 *    a `credit_note_offsets`) v rozsahu, který nepokryla jeho vratka,
 *  - vyrovnání interním dokladem (zápočet přes 395, odpis, kurzový rozdíl) zapíše
 *    ({@see recordInternalSettlements()}): zápis vyrovnávající jediný doklad svého druhu
 *    vazbou ručního zápisu na doklad, zápis vyrovnávající víc dokladů téhož druhu rozpadem
 *    podle řádků deníku jako zápočty proti protiúčtu (`invoice_settlements`, u vydané faktury
 *    i evidovaná platba `settlement`). Započte se jen čistý úbytek salda ze všech interních
 *    dokladů skupiny, kurzový zisk ho snižuje; u dokladu v cizí měně v jeho měně (korunová
 *    částka kurzem dokladu).
 *
 * Úhrady se symbolem, ke kterému převod doklad nemá (Money symbol jednou zapsalo s překlepem),
 * se přiřadí k jediné otevřené faktuře téhož partnera (IČO) na stejném účtu, jejíž symbol se
 * liší nejvýš jedním znakem.
 *
 * Řádek úhrady či interního dokladu, který podle symbolu přiřadit nejde (bez symbolu, se
 * symbolem cizího dokladu, nebo se symbolem `0000000000` sdíleným mnoha doklady), se přiřadí
 * podle čísla dokladu v popisu řádku („zápočet PF210573 x PH210045", „20220031 - Úhrada
 * faktury vydané") k dokladu na stejném syntetickém účtu ({@see groups()}).
 *
 * Nese-li předpis dokladu IČO partnera, musí ho nést i úhrada - symbol se v Money opakuje
 * u jiných partnerů a let. Pravidlo platí pro každý doklad skupiny zvlášť; úhradu přiřazenou
 * podle čísla dokladu v popisu neomezuje.
 *
 * Párování pohybu, které vytvořil převod, se srovná s řádky deníku Money: jedna platba za
 * víc faktur se rozdělí podle řádků bankovního dokladu ({@see rebalance()}).
 *
 * Opakovaný převod nic nepřepisuje ani nezdvojí: pohyb, zápis interního dokladu či dobropis
 * už vázaný k dokladu se přeskočí, pokladní doklad s vazbou se nepřepisuje a úhrady zadané
 * v MyÚčtu po převodu (ruční platby, zápočty) snižují, co zbývá spárovat.
 */
final class PairingSymbolMatcher
{
    private const FIELDS = ['Zdroj', 'Doklad', 'Datum', 'Castka', 'UcMD', 'UcD', 'ParSym', 'ParICO', 'CastkaCM', 'Mena', 'Popis'];

    /** Haléřové a korunové rozdíly zaokrouhlení, které skupina symbolu pojme. */
    private const BALANCE_TOLERANCE = 1.0;

    /** Zbytek po přepočtu měny úhrady (haléře), který interní doklad nevyrovnává. */
    private const REMNANT = 0.05;

    private ImportContext $ctx;

    /** @var array<int,array{amount:float,currency:string,date:string,czk:float}> */
    private array $movements = [];

    /** @var array<string,mixed> */
    private array $state = [];

    /** @var array<string,float> zbývá vyrovnat v Kč po dokladech */
    private array $capacity = [];

    /** @var array<int,array<string,array<string,array<string,mixed>>>> vyrovnání interním dokladem: zápis => druh => doklad => částka */
    private array $internal = [];

    /** @var array<int,array<string,array<string,true>>> doklady na řádcích zápisu interního dokladu: zápis => druh => doklady */
    private array $entryDocs = [];

    /** @var array<string,list<int>> */
    private array $bankByDoc = [];

    /** @var array<string,int> mapa úhrad převodu (klíč => cíl), i párování, která uživatel mezitím zrušil */
    private array $paymentKeys = [];

    /** @var array<string,list<int>> */
    private array $cashByDoc = [];

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly IssuedPaymentRecorder $issuedPayments,
    ) {}

    /** @param array<int,array{amount:float,currency:string,date:string,czk:float}> $movements */
    public function match(ImportContext $ctx, array $movements): void
    {
        $this->ctx = $ctx;
        $groups = $this->groups($ctx);
        if ($groups === []) {
            return;
        }
        $best = array_map(static fn (string $gk): array => [$gk], self::homes($groups));
        $this->movements = $movements;
        $this->state = $this->state($ctx, $movements);
        $this->paymentKeys = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT);
        $this->bankByDoc = self::byDocument($ctx->bankTransactions);
        $this->cashByDoc = self::byDocument($ctx->cashDocuments);
        $p = $ctx->protocol;
        // Měna dokladu je ta, ve které ho převod založil: doklad v měně, kterou firma v číselníku
        // nemá, je v MyÚčtu v Kč, i když ho deník Money vede ve valutách.
        foreach ($groups as $gk => $g) {
            foreach ($g['docs'] as $ref => $d) {
                if (!isset($this->state['info'][$d['type']][$d['id']])) {
                    // Doklad z mapy převodu v MyÚčtu mezitím smazali.
                    unset($groups[$gk]['docs'][$ref], $best[$ref]);
                    $p->count(DocumentLinker::STEP_PAYMENTS, 'missing_target');
                    continue;
                }
                if ($this->state['info'][$d['type']][$d['id']]['currency'] === 'CZK') {
                    $groups[$gk]['docs'][$ref]['mena'] = 'CZK';
                }
            }
        }
        if ($this->rebalance($groups, $best) > 0) {
            // Srovnaná párování mění, co zbývá vyrovnat - stav se načte znovu.
            $this->state = $this->state($ctx, $this->movements);
        }

        $open = [];
        foreach ($groups as $gk => $g) {
            $docs = array_filter($g['docs'], static fn (array $d, string $ref): bool => $best[$ref][0] === $gk && abs($d['net']) >= 0.01, ARRAY_FILTER_USE_BOTH);
            if ($docs === []) {
                continue;
            }
            // Směr předpisu určí největší doklad skupiny; doklady opačného směru (dobropisy) se neplatí.
            $sign = self::mainSign($docs, (string) $gk);
            $notes = array_filter($docs, static fn (array $d): bool => ($d['net'] > 0 ? 1 : -1) !== $sign);
            $docs = array_filter($docs, static fn (array $d): bool => ($d['net'] > 0 ? 1 : -1) === $sign);
            uasort($docs, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
            foreach ($docs as $ref => $d) {
                $this->capacity[$ref] = abs($d['net']) - ($this->state['allocated'][$ref] ?? 0.0);
            }
            $payments = $g['pay'];
            uasort($payments, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
            foreach ($payments as $pay) {
                if (($pay['net'] > 0 ? 1 : -1) === $sign || abs($pay['net']) < 0.01) {
                    continue; // vratka, proplacení dobropisu
                }
                $targets = self::payable($pay, $docs);
                if ($targets !== []) {
                    $this->pay($pay, $targets);
                }
            }
            $this->offsetCreditNotes($g, $docs, $notes, $sign);
            $this->settleByInternalDocuments($g, $docs, $sign);
            [$sym, $account] = explode('|', $gk, 2);
            foreach ($docs as $ref => $d) {
                if ($this->capacity[$ref] >= 0.01 && $d['ico'] !== '') {
                    $open[$account . '|' . $d['ico']][$ref] = ['doc' => $d, 'sym' => $sym];
                }
            }
        }

        // Úhrady se symbolem, ke kterému převod doklad nemá: překlep v symbolu u téhož partnera.
        // Opravný převod tohle odhadovat nesmí - pohyb mohl uživatel mezitím spárovat sám.
        foreach ($ctx->options->relinkOnly ? [] : $groups as $gk => $g) {
            if ($g['docs'] !== []) {
                continue;
            }
            [$sym, $account] = explode('|', $gk, 2);
            $issued = str_starts_with($account, '31');
            foreach ($g['pay'] as $pay) {
                if ($pay['ico'] === '' || strlen($sym) < 6 || ($pay['net'] < 0) !== $issued || abs($pay['net']) < 0.01) {
                    continue;
                }
                $partnerOpen = $open[$account . '|' . $pay['ico']] ?? [];
                $candidates = array_filter($partnerOpen, fn (array $o, string $ref): bool =>
                    $this->capacity[$ref] >= abs($pay['net']) - 0.005 && self::typo($o['sym'], $sym), ARRAY_FILTER_USE_BOTH);
                // Měsíční faktury téhož partnera mívají stejnou částku i podobný symbol: víc
                // otevřených dokladů se stejným zůstatkem znamená, že úhradu odhadnout nejde.
                $sameAmount = array_filter($partnerOpen, fn (array $o, string $ref): bool =>
                    abs($this->capacity[$ref] - abs($pay['net'])) < 0.01, ARRAY_FILTER_USE_BOTH);
                if (count($candidates) !== 1 || count($sameAmount) > 1) {
                    continue;
                }
                $ref = (string) array_key_first($candidates);
                if ($this->pay($pay, [$ref => $candidates[$ref]['doc']]) > 0) {
                    $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_similar_symbol');
                }
            }
        }
        $this->payAdvancesBySymbol($groups, $best);
        $this->recordInternalSettlements();
    }

    /**
     * Úhrady zálohy na zálohovém účtu (314, 324) se symbolem zálohového dokladu, který v deníku
     * Money řádek nemá (proforma ani zálohová faktura se nezaúčtovávají): skupina symbolu
     * vlastní doklad nemá a úhrada by zůstala nespárovaná. Symbol určí zálohu podle čísla
     * (shodné číslo, nebo jeho číslice bez úvodních nul; jen jediná záloha té strany).
     *
     * Dřívější převod takovou úhradu přiřazoval konečné faktuře, která zálohu v téže skupině
     * čerpá (faktura, kterou záloha pokryla celou, se do skupiny zálohového účtu zařadila,
     * {@see homes()}). Párování úhrady s takovou fakturou, které vytvořil převod, se proto
     * uvolní a úhrada přejde na zálohu; párování zadané uživatelem zůstává.
     *
     * @param array<string,array<string,mixed>> $groups
     * @param array<string,array{0:string}> $best
     */
    private function payAdvancesBySymbol(array $groups, array $best): void
    {
        $index = $this->advanceIndex();
        $plan = [];
        foreach ($groups as $gk => $g) {
            [$sym, $account] = explode('|', (string) $gk, 2);
            if (preg_match('/^3[12]4/', $account) !== 1 || $g['pay'] === []) {
                continue;
            }
            $type = str_starts_with($account, '32') ? 'invoice' : 'purchase_invoice';
            // Konečná faktura, kterou záloha pokryla celou, úhradu zálohy nepotřebuje: na účtu
            // dokladu (311, 321) má v deníku nulu, takže patří do skupiny zálohového účtu,
            // nebo má nulovou částku dokladu.
            $covered = fn (array $d): bool => $d['type'] === $type && ($this->state['info'][$type][$d['id']]['kind'] ?? '') === 'invoice'
                && (($best[$d['type'] . ':' . $d['id']][0] ?? null) === $gk || ($this->state['info'][$type][$d['id']]['total'] ?? 1.0) < 0.01);
            $home = array_filter($g['docs'], static fn (array $d, string $ref): bool => ($best[$ref][0] ?? null) === $gk && abs($d['net']) >= 0.01 && !$covered($d), ARRAY_FILTER_USE_BOTH);
            if ($home !== []) {
                continue;
            }
            $advanceId = $index[$type][self::symbolKey($sym)] ?? null;
            if ($advanceId === null) {
                continue;
            }
            // Přijatá záloha přichází na dal 324, poskytnutá odchází na má dáti 314.
            $pays = array_filter($g['pay'], static fn (array $pay): bool => $type === 'invoice' ? $pay['net'] <= -0.01 : $pay['net'] >= 0.01);
            if ($pays === []) {
                continue;
            }
            $finals = [];
            foreach ($g['docs'] as $ref => $d) {
                if ($d['id'] !== $advanceId && $covered($d)) {
                    $finals[$d['id']] = true;
                }
            }
            $plan[] = ['type' => $type, 'advance' => $advanceId, 'pays' => $pays, 'finals' => $finals];
        }
        if ($plan === []) {
            return;
        }
        // Párování z tohoto běhu (krok výše) musí být ve stavu jako párování převodu.
        $this->state = $this->state($this->ctx, $this->movements);
        $released = 0;
        foreach ($plan as $item) {
            foreach ($item['pays'] as $pay) {
                $released += $this->releaseFinalMatch($pay, $item['type'], $item['finals']);
            }
        }
        if ($released > 0) {
            $this->state = $this->state($this->ctx, $this->movements);
            $this->ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'advance_payment_released', $released);
        }
        foreach ($plan as $item) {
            $type = $item['type'];
            $info = $this->state['info'][$type][$item['advance']] ?? null;
            if ($info === null) {
                continue;
            }
            $ref = $type . ':' . $item['advance'];
            $foreign = $info['currency'] !== 'CZK';
            $totalCzk = $foreign ? $info['total'] * ($info['rate'] ?? 0.0) : $info['total'];
            $this->capacity[$ref] = max(0.0, $totalCzk - ($this->state['allocated'][$ref] ?? 0.0));
            $doc = ['type' => $type, 'id' => $item['advance'], 'net' => $totalCzk, 'fnet' => $foreign ? $info['total'] : 0.0,
                'mena' => $info['currency'], 'date' => '', 'ico' => ''];
            $pays = $item['pays'];
            uasort($pays, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
            foreach ($pays as $pay) {
                if ($this->pay($pay, [$ref => $doc]) > 0) {
                    $this->ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'paired_advance_symbol');
                }
            }
        }
    }

    /**
     * Uvolní párování bankovní úhrady zálohy s konečnou fakturou skupiny, které vytvořil převod
     * (i evidovanou úhradu vydané faktury). Vrací počet uvolněných párování.
     *
     * @param array<string,mixed> $pay
     * @param array<int,true> $finals
     */
    private function releaseFinalMatch(array $pay, string $type, array $finals): int
    {
        if ($pay['zdroj'] !== 'BK' || $finals === []) {
            return 0;
        }
        $movementId = $this->resolve($pay);
        $m = is_int($movementId) ? ($this->movements[$movementId] ?? null) : null;
        if ($m === null || !$m['taken'] || ($m['user_matches'] ?? 1) !== 0) {
            return 0;
        }
        $ctx = $this->ctx;
        $pdo = $this->db->pdo();
        $column = $type === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        $rows = $pdo->prepare("SELECT id, {$column} AS doc_id, amount FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ? AND {$column} IS NOT NULL");
        $rows->execute([$ctx->supplierId, $movementId]);
        $released = 0;
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $docId = (int) $r['doc_id'];
            if (!isset($finals[$docId]) || !isset($this->state['own'][(int) $r['id']])) {
                continue;
            }
            // Řádek mapy úhrad zůstává: další běh pak párování s fakturou znovu nezaloží.
            $pdo->prepare('DELETE FROM payment_matches WHERE id = ? AND supplier_id = ?')->execute([(int) $r['id'], $ctx->supplierId]);
            if ($type === 'invoice') {
                $this->issuedPayments->releaseBank($ctx, $docId, $movementId);
            }
            $pdo->prepare(
                "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                    SET t.matched_invoice_id = NULL
                  WHERE t.id = ? AND s.supplier_id = ? AND t.matched_invoice_id = ?"
            )->execute([$movementId, $ctx->supplierId, $type === 'invoice' ? $docId : -1]);
            $this->movements[$movementId]['matched'] = max(0.0, $this->movements[$movementId]['matched'] - abs((float) $r['amount']));
            $released++;
        }
        return $released;
    }

    /**
     * Zálohové doklady (proformy, zálohové faktury přijaté) podle klíče symbolu; klíč, který
     * sdílí víc záloh téže strany, se vynechá.
     *
     * @return array<string,array<string,int>> strana => klíč => id zálohy
     */
    private function advanceIndex(): array
    {
        $out = [];
        $dup = [];
        foreach (['invoice' => 'proforma', 'purchase_invoice' => 'advance'] as $type => $kind) {
            foreach ($this->state['info'][$type] ?? [] as $id => $info) {
                if ($info['kind'] !== $kind) {
                    continue;
                }
                foreach (array_unique([self::symbolKey($info['number']), mb_strtoupper(trim($info['number']))]) as $key) {
                    if ($key === '' || strlen($key) < 4) {
                        continue;
                    }
                    if (isset($out[$type][$key]) && $out[$type][$key] !== $id) {
                        $dup[$type][$key] = true;
                    }
                    $out[$type][$key] = (int) $id;
                }
            }
            foreach (array_keys($dup[$type] ?? []) as $key) {
                unset($out[$type][$key]);
            }
        }
        return $out;
    }

    /** Číslice symbolu bez úvodních nul („ZV025212" i „25212" => „25212"). */
    private static function symbolKey(string $symbol): string
    {
        return ltrim((string) preg_replace('/\D+/', '', $symbol), '0');
    }

    /**
     * Bankovní či pokladní úhrada ze skupiny k dokladům skupiny (podle data, do zbývající
     * částky). Vrací počet vazeb, které vznikly.
     *
     * @param array<string,mixed> $pay
     * @param array<string,array<string,mixed>> $docs
     */
    private function pay(array $pay, array $docs): int
    {
        $ctx = $this->ctx;
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $userId = $ctx->userId > 0 ? $ctx->userId : null;
        $isBank = $pay['zdroj'] === 'BK';
        $movementId = $this->resolve($pay);
        if (is_string($movementId)) {
            $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_' . $movementId);
            return 0;
        }
        $available = abs($pay['net']);
        $m = null;
        // Kurz úhrady v cizí měně (Kč za jednotku) z řádku deníku Money (`CastkaCM`).
        $rate = null;
        if ($isBank) {
            $m = $this->movements[$movementId] ?? null;
            if ($m === null || !$m['taken']) {
                // Platba zaúčtovaná v MyÚčtu - ani evidence úhrady se k ní nedoplňuje.
                $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_movement_kept');
                return 0;
            }
            // Pohyb už spárovaný s dokladem skupiny dostane jen chybějící evidovanou úhradu.
            $this->recordExistingMatches($pay, $docs, $movementId, $m);
            if (!DocumentLinker::movementEditable($m, $ctx->options->relinkOnly)) {
                // Pohyb ignorovaný uživatelem, nebo v opravném převodu spárovaný uživatelem -
                // jeho párování je rozhodnutí v MyÚčtu.
                $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_movement_kept');
                return 0;
            }
            $foreign = $m['currency'] !== 'CZK';
            if ($foreign) {
                if ($pay['mena'] !== $m['currency'] || abs($pay['fnet']) < 0.01) {
                    $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_currency_unknown');
                    return 0;
                }
                $rate = abs($pay['net']) / abs($pay['fnet']);
            }
            $txCzk = $foreign ? abs($ctx->bankTransactionCzk[$movementId] ?? $m['amount'] * $rate) : abs($m['amount']);
            // Řádek úhrady už spárovaný s doklady skupiny (dřívější běh) se nepočítá znovu -
            // skupina se stejným symbolem pro mnoho měsíčních faktur by jinak dostala zbytek
            // pohybu, který Money přiřadilo jiným symbolům.
            $already = 0.0;
            foreach ($docs as $ref => $_) {
                $already += ($this->state['pmAmount'][$ref][$movementId] ?? 0.0) * ($foreign && abs($m['amount']) >= 0.01 ? $txCzk / abs($m['amount']) : 1.0);
            }
            // Nespárovaná část pohybu: v Kč podle evidence párování i podle jeho součtu v měně pohybu.
            $available = min($available - $already, $txCzk - ($this->state['txUsed'][$movementId] ?? 0.0),
                (abs($m['amount']) - $m['matched']) * ($rate ?? 1.0));
            if ($already > 0.0 && $available < self::BALANCE_TOLERANCE) {
                return 0; // zbytek zaokrouhlení přepočtu měny
            }
        } elseif (!isset($this->state['cash'][$movementId])) {
            return 0;
        }
        $made = 0;
        foreach ($docs as $ref => $d) {
            if ($available < 0.01) {
                break;
            }
            $linkKey = ($isBank ? 'b' : 'c') . $movementId;
            if (isset($this->state['linked'][$ref][$linkKey])) {
                continue;
            }
            if ($m !== null && isset($this->paymentKeys[DocumentLinker::paymentMapKey($movementId, $d['type'], $d['id'])])) {
                // Párování, které převod vytvořil dřív a uživatel ho v MyÚčtu zrušil.
                $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_removed_kept');
                continue;
            }
            $isIssued = $d['type'] === 'invoice';
            if ($rate !== null && $d['mena'] === $m['currency'] && abs($d['fnet']) >= 0.01) {
                // Doklad i úhrada v téže cizí měně: páruje se v ní.
                $txAmount = round(min(abs($d['fnet']) - ($this->state['allocatedF'][$ref][$m['currency']] ?? 0.0), $available / $rate), 2);
                if ($txAmount < 0.01) {
                    continue;
                }
                $part = round($txAmount * $rate, 2);
                $this->state['allocatedF'][$ref][$m['currency']] = ($this->state['allocatedF'][$ref][$m['currency']] ?? 0.0) + $txAmount;
            } else {
                // Zbytek do koruny u dokladu s úhradou je rozdíl přepočtu měny, ne nedoplatek.
                if ($this->capacity[$ref] < 0.01 || ($this->capacity[$ref] < self::BALANCE_TOLERANCE && ($this->state['allocated'][$ref] ?? 0.0) > 0.0)) {
                    continue;
                }
                $part = round(min($this->capacity[$ref], $available), 2);
                $txAmount = $rate === null ? $part : round($part / $rate, 2);
            }
            if ($m !== null) {
                $pdo->prepare(
                    'INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
                     VALUES (?, ?, ?, ?, ?, "manual", ?)'
                )->execute([$ctx->supplierId, $movementId, $isIssued ? $d['id'] : null, $isIssued ? null : $d['id'],
                    number_format($txAmount, 2, '.', ''), $userId]);
                // Párování převodu se pozná podle mapy úhrad (opravný převod tak odliší párování uživatele).
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, DocumentLinker::paymentMapKey($movementId, $d['type'], $d['id']),
                    (int) $pdo->lastInsertId(), $ctx->runId);
                // Pohyb, který krok dřív označil jako zaúčtovaný bez dokladu, je teď úhradou faktury.
                $pdo->prepare(
                    "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                        SET t.ignore_note = IF(t.match_status = 'ignored', NULL, t.ignore_note),
                            t.match_reason = IF(t.match_status = 'ignored', NULL, t.match_reason),
                            t.match_status = 'manual', t.matched_at = COALESCE(t.matched_at, NOW()), t.matched_by = COALESCE(t.matched_by, ?),
                            t.matched_invoice_id = COALESCE(t.matched_invoice_id, ?)
                      WHERE t.id = ? AND s.supplier_id = ? AND (t.match_status <> 'ignored' OR t.match_reason = 'money_s3_booked')"
                )->execute([$userId, $isIssued ? $d['id'] : null, $movementId, $ctx->supplierId]);
                $this->state['txUsed'][$movementId] = ($this->state['txUsed'][$movementId] ?? 0.0) + $part;
                $this->movements[$movementId]['matched'] += $txAmount;
                $this->movements[$movementId]['status'] = 'manual';
                $m['matched'] += $txAmount;
                if ($isIssued) {
                    $amount = $this->issuedPayments->toInvoiceCurrency($ctx->supplierId, $d['id'], $part, $m['currency'], $txAmount);
                    if ($amount !== null) {
                        $this->issuedPayments->record($ctx, $d['id'], 'bank', $movementId, null, $amount, $m['date'], $pay['doc']);
                    }
                }
                $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_bank');
            } else {
                $cash = $this->state['cash'][$movementId];
                if ($cash['invoice_id'] === null && $cash['purchase_invoice_id'] === null) {
                    $pdo->prepare('UPDATE cash_documents SET ' . ($isIssued ? 'invoice_id' : 'purchase_invoice_id')
                        . ' = ?, purpose = ? WHERE id = ? AND supplier_id = ? AND invoice_id IS NULL AND purchase_invoice_id IS NULL')
                        ->execute([$d['id'], $isIssued ? 'invoice_payment' : 'purchase_payment', $movementId, $ctx->supplierId]);
                    $this->state['cash'][$movementId][$isIssued ? 'invoice_id' : 'purchase_invoice_id'] = $d['id'];
                } elseif (!$isIssued) {
                    // Pokladní doklad nese vazbu jen na jeden doklad; přijatou fakturu
                    // bez vazby saldokonto nespáruje, uhrazená zůstává podle Money.
                    continue;
                }
                if ($isIssued) {
                    $amount = $this->issuedPayments->toInvoiceCurrency($ctx->supplierId, $d['id'], $part);
                    if ($amount !== null) {
                        $this->issuedPayments->record($ctx, $d['id'], 'cash', null, $movementId, $amount, $cash['date'], $pay['doc']);
                    }
                }
                $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_cash');
            }
            $this->state['linked'][$ref][$linkKey] = true;
            $this->capacity[$ref] = max(0.0, $this->capacity[$ref] - $part);
            $available -= $part;
            $made++;
        }
        return $made;
    }

    /**
     * Párování pohybu srovná s řádky bankovního dokladu Money. Bankovní doklad hradící víc
     * faktur nese v deníku řádek pro každou z nich (symbol, částka); převod podle `UDoklad`
     * ale mohl spárovat celý pohyb s jedinou fakturou a částku, která jí v Money nepatří,
     * vzít jiné úhradě téže faktury. Párování se proto nastaví na částku řádku deníku pro
     * daný doklad (v měně pohybu) a evidovaná úhrada vydané faktury s ním; doklady, které
     * párování zatím nemají, dostanou zbytek pohybu v dalším kroku ({@see pay()}).
     *
     * Jen pohyb s živým převzatým bankovním zápisem, jehož všechna párování vytvořil převod,
     * a jen doklad, který řádek deníku určuje jednoznačně (jediný doklad skupiny, nebo doklad
     * z popisu řádku). Pohyb s párováním k dokladu, kterému ho Money nepřiřazuje, se nechá.
     * Párování se mění, jen když na tom závisí saldo (pohyb Money dělí i mezi doklady bez
     * párování, nebo doklad s nižším párováním zůstává otevřený, typicky přeplatek vrácený
     * vratkou); kurzový rozdíl, o který úhrada převyšuje uzavřený doklad, párování nemění.
     * Nejdřív se párování snižují, pak zvyšují - evidované úhrady faktury tak nepřesáhnou
     * její částku ani na chvíli.
     *
     * @param array<string,array<string,mixed>> $groups
     * @param array<string,array{0:string}> $best
     * @return int počet srovnaných párování
     */
    private function rebalance(array $groups, array $best): int
    {
        $ctx = $this->ctx;
        $targets = [];
        $skip = [];
        foreach ($groups as $gk => $g) {
            $docs = array_filter($g['docs'], static fn (array $d, string $ref): bool => ($best[$ref][0] ?? null) === $gk && abs($d['net']) >= 0.01, ARRAY_FILTER_USE_BOTH);
            if ($docs === []) {
                continue;
            }
            foreach ($g['pay'] as $pay) {
                if ($pay['zdroj'] !== 'BK' || abs($pay['net']) < 0.01) {
                    continue;
                }
                $movementId = $this->resolve($pay);
                $m = is_int($movementId) ? ($this->movements[$movementId] ?? null) : null;
                if ($m === null) {
                    continue;
                }
                $candidates = self::payable($pay, $docs);
                if (count($candidates) > 1) {
                    // Dobropisy skupiny úhrada faktury neurčuje - jen doklady směru předpisu.
                    $main = self::mainSign($docs, (string) $gk);
                    $candidates = array_filter($candidates, static fn (array $d): bool => ($d['net'] > 0 ? 1 : -1) === $main);
                }
                if (count($candidates) !== 1) {
                    $skip[$movementId] = ($skip[$movementId] ?? false) || $candidates !== [];
                    continue;
                }
                $ref = (string) array_key_first($candidates);
                $d = $candidates[$ref];
                $amount = $m['currency'] === 'CZK' ? abs($pay['net']) : ($pay['mena'] === $m['currency'] ? abs($pay['fnet']) : 0.0);
                $refund = ($pay['net'] > 0) === ($d['net'] > 0);
                if ($amount < 0.01 || (isset($targets[$movementId][$ref]) && $targets[$movementId][$ref]['refund'] !== $refund)) {
                    $skip[$movementId] = true;
                    continue;
                }
                $targets[$movementId][$ref] ??= ['amount' => 0.0, 'czk' => 0.0, 'refund' => $refund, 'doc' => $d, 'pay' => $pay];
                $targets[$movementId][$ref]['amount'] += $amount;
                $targets[$movementId][$ref]['czk'] += abs($pay['net']);
            }
        }
        $targets = array_diff_key($targets, array_filter($skip));
        if ($targets === []) {
            return 0;
        }
        $pdo = $this->db->pdo();
        $rows = [];
        foreach (array_chunk(array_keys($targets), 500) as $chunk) {
            $stmt = $pdo->prepare('SELECT id, bank_transaction_id, invoice_id, purchase_invoice_id, amount FROM payment_matches
                                    WHERE supplier_id = ? AND bank_transaction_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $stmt->execute(array_merge([$ctx->supplierId], $chunk));
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[(int) $r['bank_transaction_id']][] = $r;
            }
        }
        // Dva průchody: snížení párování u platby za víc faktur uvolní fakturu, kterou pak
        // dorovná její další úhrada (navýšení jen u dokladu, který zůstává otevřený).
        $update = $pdo->prepare('UPDATE payment_matches SET amount = ? WHERE id = ? AND supplier_id = ? AND amount = ?');
        $changed = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            $ops = [];
            foreach ($targets as $tx => $byRef) {
                $m = $this->movements[$tx];
                if (!$m['taken'] || ($m['user_matches'] ?? 1) !== 0 || !isset($rows[$tx])) {
                    continue;
                }
                $plan = [];
                $sum = 0.0;
                foreach ($rows[$tx] as $r) {
                    $ref = $r['invoice_id'] !== null ? 'invoice:' . $r['invoice_id'] : 'purchase_invoice:' . $r['purchase_invoice_id'];
                    if (!isset($byRef[$ref]) || !isset($this->state['own'][(int) $r['id']]) || isset($plan[$ref])) {
                        $plan = null;
                        break;
                    }
                    $plan[$ref] = ['tx' => $tx, 'id' => (int) $r['id'], 'old' => (string) $r['amount'], 'new' => round($byRef[$ref]['amount'], 2), 'target' => $byRef[$ref]];
                    $sum += $plan[$ref]['new'];
                }
                if ($plan === null) {
                    continue;
                }
                // Řádky deníku pohybu nesou i kurzový rozdíl mimo saldokonto (663, 563), takže
                // součet za doklady může pohyb převýšit; párování ho převýšit nesmí. Malý přesah
                // (nebo přesah menší než dosavadní) jde z největšího párování.
                $excess = round($sum - abs($m['amount']), 2);
                if ($excess > 0.005) {
                    $oldSum = array_sum(array_map(static fn (array $op): float => (float) $op['old'], $plan));
                    uasort($plan, static fn (array $a, array $b): int => $b['new'] <=> $a['new']);
                    $largest = (string) array_key_first($plan);
                    if (($excess > max(1.0, 0.05 * abs($m['amount'])) && $oldSum <= abs($m['amount']) + 0.005) || $plan[$largest]['new'] - $excess < 0.01) {
                        continue;
                    }
                    $plan[$largest]['new'] = round($plan[$largest]['new'] - $excess, 2);
                }
                // Párování se srovnává, jen když na tom závisí saldo: Money dělí pohyb i mezi
                // doklady bez párování, nebo doklad, který zůstává otevřený, srovnáním získá
                // (vyšší úhrada, nižší vratka).
                $needed = array_diff_key($byRef, $plan) !== [];
                foreach ($plan as $ref => $op) {
                    $open = abs($op['target']['doc']['net']) - ($this->state['allocated'][$ref] ?? 0.0);
                    $gains = $op['target']['refund'] ? $op['new'] < (float) $op['old'] - 0.005 : $op['new'] > (float) $op['old'] + 0.005;
                    $needed = $needed || ($gains && $open >= self::BALANCE_TOLERANCE);
                }
                if (!$needed) {
                    continue;
                }
                foreach ($plan as $op) {
                    if (abs($op['new'] - (float) $op['old']) >= 0.005) {
                        $ops[] = $op;
                    }
                }
            }
            usort($ops, static fn (array $a, array $b): int => ($a['new'] - (float) $a['old']) <=> ($b['new'] - (float) $b['old']));
            $done = 0;
            foreach ($ops as $op) {
                $update->execute([number_format($op['new'], 2, '.', ''), $op['id'], $ctx->supplierId, $op['old']]);
                if ($update->rowCount() === 0) {
                    continue;
                }
                $done++;
                $tx = $op['tx'];
                $m = $this->movements[$tx];
                $this->movements[$tx]['matched'] += $op['new'] - (float) $op['old'];
                foreach ($rows[$tx] as $i => $r) {
                    if ((int) $r['id'] === $op['id']) {
                        $rows[$tx][$i]['amount'] = number_format($op['new'], 2, '.', '');
                    }
                }
                $d = $op['target']['doc'];
                if ($d['type'] !== 'invoice' || $op['target']['refund']) {
                    continue;
                }
                $czk = $m['currency'] === 'CZK' ? $op['new'] : $op['target']['czk'] * $op['new'] / $op['target']['amount'];
                $amount = $this->issuedPayments->toInvoiceCurrency($ctx->supplierId, $d['id'], $czk, $m['currency'], $op['new']);
                if ($amount !== null) {
                    $this->issuedPayments->setBank($ctx, $d['id'], $tx, $amount, $m['date'], $op['target']['pay']['doc']);
                }
            }
            if ($done === 0) {
                break;
            }
            $changed += $done;
            $this->state = $this->state($ctx, $this->movements);
        }
        if ($changed > 0) {
            $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'rebalanced_matches', $changed);
        }
        return $changed;
    }

    /**
     * Pohyb spárovaný s vydanou fakturou skupiny, které chybí evidovaná úhrada: doplní se
     * v částce párování, nejvýš do částky faktury ({@see IssuedPaymentRecorder::record()}).
     * Párování ani pohyb se nemění. Opravný převod doplní úhradu jen k párování, které
     * vytvořil převod - párování zadané uživatelem je jeho rozhodnutí.
     *
     * @param array<string,mixed> $pay
     * @param array<string,array<string,mixed>> $docs
     * @param array<string,mixed> $m
     */
    private function recordExistingMatches(array $pay, array $docs, int $movementId, array $m): void
    {
        foreach ($docs as $ref => $d) {
            $matched = $this->state['pmAmount'][$ref][$movementId] ?? null;
            if ($d['type'] !== 'invoice' || $matched === null) {
                continue;
            }
            if ($this->ctx->options->relinkOnly && !isset($this->state['ownPm'][$ref][$movementId])) {
                continue;
            }
            // Kurz pohybu v cizí měně: z Money (částka pohybu v Kč), jinak z řádku úhrady v deníku.
            $rate = 1.0;
            if ($m['currency'] !== 'CZK') {
                $rate = isset($this->ctx->bankTransactionCzk[$movementId]) && abs($m['amount']) >= 0.01
                    ? abs($this->ctx->bankTransactionCzk[$movementId]) / abs($m['amount'])
                    : ($pay['mena'] === $m['currency'] && abs($pay['fnet']) >= 0.01 ? abs($pay['net']) / abs($pay['fnet']) : null);
                if ($rate === null) {
                    continue;
                }
            }
            $czk = $matched * $rate;
            $amount = $this->issuedPayments->toInvoiceCurrency($this->ctx->supplierId, $d['id'], $czk, $m['currency'], $matched);
            if ($amount !== null) {
                $this->issuedPayments->record($this->ctx, $d['id'], 'bank', $movementId, null, $amount, $m['date'], $pay['doc']);
            }
        }
    }

    /**
     * Dobropis vyrovnaný proti faktuře se stejným symbolem: co z dobropisu nepokryla jeho
     * vlastní vratka, započetl Money proti faktuře.
     *
     * @param array<string,mixed> $g
     * @param array<string,array<string,mixed>> $docs
     * @param array<string,array<string,mixed>> $notes
     */
    private function offsetCreditNotes(array $g, array $docs, array $notes, int $sign): void
    {
        $info = $this->state['info'];
        $invoices = array_filter($docs, static fn (array $d): bool => ($info[$d['type']][$d['id']]['kind'] ?? '') === 'invoice');
        if ($notes === [] || count($invoices) !== 1) {
            return;
        }
        $invoiceRef = (string) array_key_first($invoices);
        $refunds = 0.0;
        foreach ($g['pay'] as $pay) {
            if (($pay['net'] > 0 ? 1 : -1) === $sign && abs($pay['net']) >= 0.01 && self::payable($pay, $invoices + $notes) !== []) {
                $movementId = $this->resolve($pay);
                $linkKey = ($pay['zdroj'] === 'BK' ? 'b' : 'c') . (is_int($movementId) ? $movementId : 0);
                $linkedToNote = false;
                foreach ($notes as $noteRef => $_) {
                    $linkedToNote = $linkedToNote || isset($this->state['linked'][$noteRef][$linkKey]);
                }
                $refunds += $linkedToNote ? 0.0 : abs($pay['net']);
            }
        }
        uasort($notes, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        foreach ($notes as $noteRef => $note) {
            // Dobropis, nebo storno faktury, které Money vede jako fakturu se zápornou částkou.
            if (!in_array($info[$note['type']][$note['id']]['kind'] ?? '', ['credit_note', 'invoice'], true) || $note['type'] !== $invoices[$invoiceRef]['type']) {
                continue;
            }
            $left = abs($note['net']) - ($this->state['allocated'][$noteRef] ?? 0.0);
            $fromRefund = min($left, $refunds);
            $refunds -= $fromRefund;
            $offset = round(min($left - $fromRefund, $this->capacity[$invoiceRef]), 2);
            if ($offset >= 0.01 && $this->offsetCreditNote($invoices[$invoiceRef], $note, $offset)) {
                $this->capacity[$invoiceRef] -= $offset;
                $this->ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'credit_note_offset');
            }
        }
    }

    /**
     * Zápočet dobropisu proti faktuře se stejným párovacím symbolem: dobropis dostane
     * vazbu na opravovanou fakturu a zápočet (`credit_note_offsets`) jako po zápočtu
     * v MyÚčtu, vydaná faktura i evidovanou platbu se zdrojem `credit_note`. Stav dokladů
     * vede převod podle Money.
     *
     * Dobropis v jiné měně než faktura (převod založil fakturu v Kč, dobropis ve valutách)
     * MyÚčto k faktuře nezapočte ({@see \MyInvoice\Service\Invoice\CreditNoteOffsetService},
     * `currency_mismatch`), zápočet z Money se ale do saldokonta faktury promítnout musí:
     * faktura dostane korunovou částku zápočtu v měně faktury (u faktury v cizí měně
     * přepočtenou jejím kurzem; vydaná jako evidovanou platbu `credit_note`, přijatá řádkem
     * zápočtu), dobropis vazbu na fakturu ne. Dva doklady ve dvou různých cizích měnách se
     * nezapočítávají.
     *
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $note
     */
    private function offsetCreditNote(array $invoice, array $note, float $czk): bool
    {
        $ctx = $this->ctx;
        $type = $invoice['type'];
        $invoiceInfo = $this->state['info'][$type][$invoice['id']];
        $noteInfo = $this->state['info'][$type][$note['id']];
        $sameCurrency = $invoiceInfo['currency'] === $noteInfo['currency'];
        if (!$sameCurrency && $invoiceInfo['currency'] !== 'CZK' && $noteInfo['currency'] !== 'CZK') {
            return false;
        }
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT 1 FROM credit_note_offsets WHERE supplier_id = ? AND doc_type = ? AND credit_note_id = ?');
        $exists->execute([$ctx->supplierId, $type, $note['id']]);
        if ($exists->fetchColumn() !== false) {
            return false;
        }
        $rate = $sameCurrency ? $noteInfo['rate'] : $invoiceInfo['rate'];
        $amount = $invoiceInfo['currency'] === 'CZK' ? $czk : ($rate !== null ? round($czk / $rate, 2) : 0.0);
        if ($amount < 0.01) {
            return false;
        }
        // Vydaná faktura: zápočet nese evidovaná platba, ze které čte saldokonto. Kde platba
        // nevznikne (faktura už má platby do uhrazené částky), nevznikne ani zápočet - jinak by
        // zápočet a saldo nesouhlasily. Zápočet má částku platby.
        $paymentId = null;
        if ($type === 'invoice') {
            $paymentId = $this->issuedPayments->record($ctx, $invoice['id'], 'credit_note', null, null, $amount, $note['date'], $noteInfo['number']);
            if ($paymentId === null) {
                return false;
            }
            if (!$sameCurrency) {
                return true;
            }
            $paid = $pdo->prepare('SELECT amount FROM invoice_payments WHERE id = ?');
            $paid->execute([$paymentId]);
            $amount = (float) $paid->fetchColumn();
        }
        // Vazbu na opravovanou fakturu dostane jen dobropis; záporná faktura (storno) s rodičem
        // by přestala být dokladem k vyplacení, který vyrovnává jeho stav.
        if ($noteInfo['kind'] === 'credit_note' && $sameCurrency) {
            $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
            $parent = $type === 'invoice' ? 'parent_invoice_id' : 'parent_purchase_invoice_id';
            $pdo->prepare("UPDATE {$table} SET {$parent} = ? WHERE id = ? AND supplier_id = ? AND {$parent} IS NULL")
                ->execute([$invoice['id'], $note['id'], $ctx->supplierId]);
        }
        $pdo->prepare(
            'INSERT INTO credit_note_offsets (supplier_id, doc_type, invoice_id, credit_note_id, amount, offset_on, invoice_payment_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$ctx->supplierId, $type, $invoice['id'], $note['id'], number_format($amount, 2, '.', ''), $note['date'], $paymentId,
            $ctx->userId > 0 ? $ctx->userId : null]);
        return true;
    }

    /**
     * Vyrovnání interními doklady skupiny jako zápočty proti protiúčtu řádku
     * (`invoice_settlements` s vazbou na zápis interního dokladu), stejně jako zápočet
     * proti účtu v MyÚčtu. Vydaná faktura dostane i evidovanou platbu `settlement`
     * (saldokonto vydaných faktur čte jen ji). Zápis interního dokladu, který vyrovnává
     * víc faktur (vzájemný zápočet přes 395), se rozpadne podle svých řádků.
     *
     * @param array<string,mixed> $g
     * @param array<string,array<string,mixed>> $docs
     */
    private function settleByInternalDocuments(array $g, array $docs, int $sign): void
    {
        if ($g['manual'] === []) {
            return;
        }
        foreach ($g['manual'] as $key => $e) {
            foreach ($this->state['entries'][$key] ?? [] as $entryId) {
                foreach (self::scoped($e, $g['docs']) as $ref => $d) {
                    $this->entryDocs[$entryId][$d['type']][$ref] = true;
                }
            }
        }
        // Čistý úbytek salda ze všech interních dokladů skupiny (v Kč a v měnách dokladů);
        // zápis přiřazený k dokladu podle čísla v popisu i jen v rozsahu svých dokladů.
        $left = [];
        foreach ($g['manual'] as $e) {
            foreach (array_unique(['*', self::scopeKey($e)]) as $scope) {
                $left[$scope]['czk'] = ($left[$scope]['czk'] ?? 0.0) - $sign * $e['net'];
                if ($e['mena'] !== 'CZK') {
                    $left[$scope]['f'][$e['mena']] = ($left[$scope]['f'][$e['mena']] ?? 0.0) - $sign * $e['fnet'];
                }
            }
        }
        $entries = $g['manual'];
        uasort($entries, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
        foreach ($entries as $key => $e) {
            if (($e['net'] > 0 ? 1 : -1) === $sign || abs($e['net']) < 0.01) {
                continue; // kurzový zisk, který saldo zvyšuje
            }
            $entryIds = $this->state['entries'][$key] ?? [];
            if (count($entryIds) !== 1) {
                continue;
            }
            $entryId = $entryIds[0];
            $availableCzk = abs($e['net']);
            $availableF = abs($e['fnet']);
            $scope = self::scopeKey($e);
            $leftCzk = min($left['*']['czk'], $left[$scope]['czk']);
            $leftF = $left[$scope]['f'] ?? [];
            foreach (self::scoped($e, $docs) as $ref => $d) {
                // Zápis už navázaný na doklad ruční vazbou (dřívější běh, uživatel) se znovu
                // nezapisuje, ale vyrovnání, které nese, se odečte jako při prvním běhu - vazbu
                // stav firmy mezi úhradami nepočítá a další zápis by jinak dostal její částku.
                $replay = isset($this->state['manualLinked'][$ref][$entryId]);
                if (!$replay && isset($this->state['linked'][$ref]['e' . $entryId])) {
                    continue;
                }
                $leftDocF = abs($d['fnet']) - ($this->state['allocatedF'][$ref][$d['mena']] ?? 0.0);
                $docRate = $this->state['info'][$d['type']][$d['id']]['rate'] ?? null;
                if ($d['mena'] === 'CZK') {
                    $amount = round(min($availableCzk, $leftCzk, $this->capacity[$ref]), 2);
                    $czk = $amount;
                } elseif ($e['mena'] === $d['mena'] && $availableF >= 0.01) {
                    $amount = round(min($availableF, $leftF[$d['mena']] ?? 0.0, $leftDocF), 2);
                    $czk = round($amount * abs($e['net']) / abs($e['fnet']), 2);
                } elseif ($e['mena'] === 'CZK' && $docRate !== null) {
                    // Korunový zápočet či kurzový rozdíl dokladu v cizí měně (úhrada z korunového
                    // účtu přepočtená kurzem dokladu na nominál nedosáhne) - kurzem dokladu.
                    $czk = round(min($availableCzk, $leftCzk, $this->capacity[$ref], $leftDocF * $docRate), 2);
                    $amount = round($czk / $docRate, 2);
                } else {
                    continue;
                }
                // Haléřový zbytek po přepočtu měny úhrady doklad nevyrovnává.
                if ($amount < 0.01 || ($czk < self::REMNANT && ($this->state['allocated'][$ref] ?? 0.0) > 0.0)) {
                    continue;
                }
                if (!$replay) {
                    $this->internal[$entryId][$d['type']][$ref] = ['doc' => $d, 'amount' => $amount, 'entry' => $e];
                    $this->state['linked'][$ref]['e' . $entryId] = true;
                }
                $this->capacity[$ref] = max(0.0, $this->capacity[$ref] - $czk);
                $availableCzk -= $czk;
                $leftCzk -= $czk;
                foreach (array_unique(['*', $scope]) as $s) {
                    $left[$s]['czk'] -= $czk;
                }
                if ($d['mena'] !== 'CZK') {
                    $this->state['allocatedF'][$ref][$d['mena']] = ($this->state['allocatedF'][$ref][$d['mena']] ?? 0.0) + $amount;
                    if ($e['mena'] === $d['mena']) {
                        $availableF -= $amount;
                        $leftF[$d['mena']] -= $amount;
                        foreach (array_unique(['*', $scope]) as $s) {
                            $left[$s]['f'][$d['mena']] = ($left[$s]['f'][$d['mena']] ?? 0.0) - $amount;
                        }
                    }
                }
                if ($availableCzk < 0.01) {
                    break;
                }
            }
        }
    }

    /**
     * Doklady, kterých se řádek úhrady či interního dokladu týká: přiřazený podle čísla
     * dokladu v popisu jen svých dokladů, jinak všech dokladů skupiny.
     *
     * @param array<string,mixed> $line
     * @param array<string,array<string,mixed>> $docs
     * @return array<string,array<string,mixed>>
     */
    private static function scoped(array $line, array $docs): array
    {
        return $line['pin'] === null ? $docs : array_intersect_key($docs, array_flip($line['pin']));
    }

    /**
     * Směr předpisu skupiny (+1 MD, -1 D) podle největšího dokladu. Storno stejné výše jako
     * faktura směr neurčí - rozhodne normální strana účtu (31x MD, 32x D).
     *
     * @param array<string,array<string,mixed>> $docs
     */
    private static function mainSign(array $docs, string $gk): int
    {
        $natural = str_starts_with(explode('|', $gk, 2)[1] ?? '', '31') ? 1 : -1;
        $best = null;
        foreach ($docs as $d) {
            $key = [round(abs($d['net']), 2), ($d['net'] > 0 ? 1 : -1) === $natural];
            if ($best === null || $key > $best[0]) {
                $best = [$key, $d['net'] > 0 ? 1 : -1];
            }
        }
        return $best[1] ?? $natural;
    }

    /** @param array<string,mixed> $line */
    private static function scopeKey(array $line): string
    {
        if ($line['pin'] === null) {
            return '*';
        }
        $pin = $line['pin'];
        sort($pin);
        return implode(',', $pin);
    }

    /**
     * Doklady skupiny, které smí úhrada vyrovnat. Úhrada přiřazená podle čísla dokladu
     * v popisu jen svůj doklad; jinak doklad, jehož předpis IČO nenese nebo nese stejné
     * IČO jako úhrada (symbol se v Money opakuje u jiných partnerů a let).
     *
     * @param array<string,mixed> $pay
     * @param array<string,array<string,mixed>> $docs
     * @return array<string,array<string,mixed>>
     */
    private static function payable(array $pay, array $docs): array
    {
        if ($pay['pin'] !== null) {
            return self::scoped($pay, $docs);
        }
        return array_filter($docs, static fn (array $d): bool => $d['ico'] === '' || $d['ico'] === $pay['ico']);
    }

    /**
     * Skupina (symbol × účet), ve které se doklad páruje: největší předpis mimo zálohové
     * účty (314, 324), jinak největší předpis vůbec. Konečná faktura má se stejným symbolem
     * i odpočet zálohy na 324/314, který bývá větší než zbytek předpisu na 311/321.
     *
     * @param array<string,array<string,mixed>> $groups
     * @return array<string,string> doklad => klíč skupiny
     */
    private static function homes(array $groups): array
    {
        $best = [];
        foreach ($groups as $gk => $g) {
            $advance = preg_match('/^3[12]4/', explode('|', (string) $gk, 2)[1]) === 1;
            foreach ($g['docs'] as $ref => $d) {
                $score = [!$advance && abs($d['net']) >= 0.01 ? 1 : 0, abs($d['net'])];
                if (!isset($best[$ref]) || $score > $best[$ref][1]) {
                    $best[$ref] = [(string) $gk, $score];
                }
            }
        }
        return array_map(static fn (array $b): string => $b[0], $best);
    }

    /**
     * Vyrovnání interními doklady do evidence. Zápis, který vyrovnává jediný doklad svého
     * druhu, se na doklad naváže jako ruční zápis ({@see \MyInvoice\Support\Sql\LinkedManualSettlementSql}) -
     * tak ho čte saldokonto i kontroly uzávěrky. Zápis vyrovnávající víc dokladů téhož druhu
     * (vzájemný zápočet přes 395) vazba neunese, nenese rozpad částky; ten se zapíše jako
     * zápočty proti protiúčtu po dokladech ({@see recordSettlement()}). Vazba jen k dokladu
     * s vlastním zaúčtovaným zápisem a jen zápis, který jiný doklad téhož druhu nevyrovnává.
     */
    private function recordInternalSettlements(): void
    {
        $ctx = $this->ctx;
        $link = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($this->internal as $entryId => $byType) {
            foreach ($byType as $type => $docs) {
                $first = reset($docs);
                $linked = $this->state['manualLinks'][$entryId][$type] ?? [];
                if (count($this->entryDocs[$entryId][$type] ?? []) === 1 && $linked === [] && isset($this->state['booked'][$type][$first['doc']['id']])
                    && !isset($this->state['settledEntries'][$entryId][$type])) {
                    $link->execute([$ctx->supplierId, $entryId, $type, $first['doc']['id'], 'Vyrovnání interním dokladem ' . $first['entry']['doc'] . ' (převzato z Money S3)',
                        $ctx->userId > 0 ? $ctx->userId : null]);
                    if ($link->rowCount() > 0) {
                        $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'internal_settlement_linked');
                    }
                    continue;
                }
                if ($linked !== []) {
                    continue; // zápis už je vazbou na doklad (dřívější běh nebo uživatel)
                }
                foreach ($docs as $item) {
                    if ($this->recordSettlement($item['doc'], $entryId, $item['amount'], $item['entry'])) {
                        $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'internal_settlement');
                    }
                }
            }
        }
    }

    /**
     * Zápočet proti protiúčtu řádku interního dokladu. Částka je v měně dokladu.
     *
     * @param array<string,mixed> $d
     * @param array<string,mixed> $e
     */
    private function recordSettlement(array $d, int $entryId, float $amount, array $e): bool
    {
        $ctx = $this->ctx;
        $accountId = $this->state['accounts'][AccountCode::fromMoney($e['counter']) ?? ''] ?? null;
        if ($accountId === null) {
            return false;
        }
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT 1 FROM invoice_settlements WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? AND journal_entry_id = ?');
        $exists->execute([$ctx->supplierId, $d['type'], $d['id'], $entryId]);
        if ($exists->fetchColumn() !== false) {
            return false;
        }
        $paymentId = null;
        if ($d['type'] === 'invoice') {
            $paymentId = $this->issuedPayments->record($ctx, $d['id'], 'settlement', null, null, $amount, $e['date'], $e['doc']);
            if ($paymentId === null) {
                return false;
            }
        }
        $pdo->prepare(
            "INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id, note, status, journal_entry_id, invoice_payment_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?)"
        )->execute([$ctx->supplierId, $d['type'], $d['id'], $e['date'], number_format($amount, 2, '.', ''), $accountId,
            'Převzato z Money S3, interní doklad ' . $e['doc'], $entryId, $paymentId, $ctx->userId > 0 ? $ctx->userId : null]);
        return true;
    }

    /**
     * Překlep v symbolu: prohozené dva sousední znaky (stejná délka), nebo jedna vypuštěná či
     * přidaná číslice. Záměna jedné číslice překlepem není - `…0123` a `…0124` jsou sousední
     * faktury téže řady.
     */
    public static function typo(string $a, string $b): bool
    {
        $la = strlen($a);
        $lb = strlen($b);
        if ($la === $lb) {
            $diff = [];
            for ($i = 0; $i < $la; $i++) {
                if ($a[$i] !== $b[$i]) {
                    $diff[] = $i;
                }
            }
            return count($diff) === 2 && $diff[1] === $diff[0] + 1 && $a[$diff[0]] === $b[$diff[1]] && $a[$diff[1]] === $b[$diff[0]];
        }
        if (abs($la - $lb) !== 1) {
            return false;
        }
        [$long, $short] = $la > $lb ? [$a, $b] : [$b, $a];
        for ($i = 0, $n = strlen($long); $i < $n; $i++) {
            if (ctype_digit($long[$i]) && substr($long, 0, $i) . substr($long, $i + 1) === $short) {
                return true;
            }
        }
        return false;
    }

    /** Pohyb úhrady podle čísla dokladu a roku; stejné číslo na dvou účtech rozliší datum. */
    private function resolve(array $pay): int|string
    {
        $isBank = $pay['zdroj'] === 'BK';
        $ids = ($isBank ? $this->bankByDoc : $this->cashByDoc)[$pay['year'] . '|' . $pay['doc']] ?? [];
        if (count($ids) > 1) {
            $ids = array_values(array_filter($ids, fn (int $id): bool =>
                ($isBank ? ($this->movements[$id]['date'] ?? null) : ($this->state['cash'][$id]['date'] ?? null)) === $pay['date']));
        }
        return count($ids) === 1 ? $ids[0] : ($ids === [] ? 'movement_missing' : 'ambiguous');
    }

    /**
     * Řádky deníku převáděných roků s párovacím symbolem na saldokontních účtech (31x, 32x),
     * seskupené podle symbolu a účtu: předpisy převedených faktur, úhrady banky a pokladny
     * a interní doklady (s protiúčtem řádku).
     *
     * Řádek úhrady či interního dokladu, který v popisu nese číslo převedeného dokladu na
     * stejném syntetickém účtu (a téhož partnera, nese-li řádek IČO), se zařadí podle něj:
     *  - je-li doklad ve skupině symbolu řádku, zůstane tam a vyrovnává jen tento doklad
     *    (symbol `0000000000` sdílí mnoho dokladů),
     *  - nemá-li skupina symbolu řádku doklad na tomto účtu (řádek bez symbolu, se symbolem
     *    cizího dokladu), přejde do skupiny dokladu z popisu. Víc dokladů v jednom řádku
     *    („zápočet FV20260018,20260019 X PFZ260022") se rozdělí postupně do zůstatku každého
     *    z nich podle deníku Money, zbytek dostane poslední,
     *  - nese-li skupina symbolu jiný doklad téhož účtu, platí symbol.
     *
     * @return array<string,array<string,mixed>>
     */
    private function groups(ImportContext $ctx): array
    {
        $dirs = [];
        foreach ($ctx->dirYears as $dir => $year) {
            if (isset($ctx->periods[$year])) {
                $dirs[] = $dir;
            }
        }
        if ($dirs === []) {
            return [];
        }
        $numbers = self::documentNumbers($ctx);
        $groups = [];
        $deferred = [];
        foreach ($ctx->backup->rowsAcrossYears('UcDenik', self::FIELDS, $dirs) as $r) {
            if (Ms3Journal::isOpening($r) || Ms3Journal::isYearEndClosing($r)) {
                continue;
            }
            $sym = trim((string) ($r['ParSym'] ?? ''));
            $zdroj = strtoupper(trim((string) ($r['Zdroj'] ?? '')));
            $kind = match ($zdroj) {
                'FV', 'KP', 'FP' => 'doc',
                'BK', 'PK' => 'payment',
                default => Ms3Journal::sourceType($zdroj) === 'manual' ? 'manual' : null,
            };
            if ($kind === null || ($sym === '' && $kind === 'doc')) {
                continue;
            }
            $year = $ctx->yearOf($r);
            $refs = $kind !== 'doc' && $year !== null ? self::popisRefs((string) ($r['Popis'] ?? ''), $year, $numbers) : [];
            if ($sym === '' && $refs === []) {
                continue;
            }
            $effect = Ms3Journal::effect($r);
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            $date = substr((string) ($r['Datum'] ?? ''), 0, 10);
            if ($effect === null || $year === null || $docNo === '' || $date === '') {
                continue;
            }
            $doc = match ($zdroj) {
                'FV' => ['invoice', $ctx->issuedInvoices[$year . '|' . $docNo] ?? null],
                'KP' => ['invoice', $ctx->otherReceivables[$year . '|' . $docNo] ?? null],
                'FP' => ['purchase_invoice', $ctx->purchaseInvoices[$year . '|' . $docNo] ?? null],
                default => [$kind, null],
            };
            if ($kind === 'doc' && $doc[1] === null) {
                continue;
            }
            $mena = MoneyS3Currency::code($r['Mena'] ?? null);
            $foreign = $mena === 'CZK' ? 0.0 : abs((float) ($r['CastkaCM'] ?? 0));
            foreach ([[$effect['debit'], $effect['amount'], $effect['credit']], [$effect['credit'], -$effect['amount'], $effect['debit']]] as [$account, $delta, $counter]) {
                if (preg_match('/^3[12]/', $account) !== 1) {
                    continue;
                }
                $line = ['doc' => $doc, 'zdroj' => $zdroj, 'source' => trim((string) $r['Zdroj']), 'year' => $year, 'docNo' => $docNo, 'date' => $date,
                    'sym' => $sym, 'account' => $account, 'counter' => $counter, 'ico' => trim((string) ($r['ParICO'] ?? '')), 'mena' => $mena,
                    'delta' => $delta, 'fdelta' => $delta > 0 ? $foreign : -$foreign];
                if ($refs !== []) {
                    $deferred[] = $line + ['refs' => $refs];
                    continue;
                }
                self::addLine($groups, $sym . '|' . $account, $line, null);
            }
        }
        if ($deferred !== []) {
            $this->placeByDescription($groups, $deferred);
        }
        // Přiřazení k dokladu, které pokrývá všechny doklady skupiny, nic neomezuje.
        foreach ($groups as $gk => $g) {
            foreach (['pay', 'manual'] as $part) {
                foreach ($g[$part] as $key => $line) {
                    if ($line['pin'] !== null && array_diff(array_keys($g['docs']), $line['pin']) === []) {
                        $groups[$gk][$part][$key]['pin'] = null;
                    }
                }
            }
        }
        // Skupina jen s předpisem zůstává: může k ní patřit úhrada se symbolem s překlepem.
        return array_filter($groups, static fn (array $g): bool => $g['docs'] !== [] || $g['pay'] !== []);
    }

    /**
     * Zařazení řádků s číslem dokladu v popisu ({@see groups()}). Řádek se symbolem jiného
     * dokladu téhož účtu zůstane u symbolu, jen pokud ho zůstatek skupiny symbolu pojme;
     * vyrovnaný doklad by jinak přeplatil („zápočet FV20260018,20260019 X PFZ260022" se
     * symbolem dřívější faktury PFZ260013, kterou už vyrovnal jiný zápočet).
     *
     * @param array<string,array<string,mixed>> $groups
     * @param list<array<string,mixed>> $deferred
     */
    private function placeByDescription(array &$groups, array $deferred): void
    {
        $homes = self::homes($groups);
        $contested = [];
        $moved = [];
        foreach ($deferred as $line) {
            $gk = $line['sym'] . '|' . $line['account'];
            $candidates = [];
            foreach ($line['refs'] as $ref) {
                $home = $homes[$ref] ?? null;
                if ($home === null || substr(explode('|', $home, 2)[1], 0, 3) !== substr($line['account'], 0, 3)) {
                    continue;
                }
                $docIco = $groups[$home]['docs'][$ref]['ico'];
                if ($line['ico'] !== '' && $docIco !== '' && $docIco !== $line['ico']) {
                    continue;
                }
                $candidates[$ref] = $home;
            }
            $symbolDocs = $line['sym'] !== '' ? ($groups[$gk]['docs'] ?? []) : [];
            if ($candidates === []) {
                if ($line['sym'] !== '') {
                    self::addLine($groups, $gk, $line, null);
                }
                continue;
            }
            $inGroup = array_intersect_key($candidates, $symbolDocs);
            if ($inGroup !== []) {
                self::addLine($groups, $gk, $line, array_keys($inGroup));
                continue;
            }
            $line['candidates'] = $candidates;
            if ($symbolDocs !== []) {
                $contested[] = $line;
                continue;
            }
            $moved[] = $line;
        }
        // Zůstatek skupin podle deníku Money (předpisy, úhrady i interní doklady, které zůstaly
        // ve skupině svého symbolu).
        $balance = [];
        foreach ($groups as $gk => $g) {
            $balance[$gk] = array_sum(array_column($g['docs'], 'net')) + array_sum(array_column($g['pay'], 'net')) + array_sum(array_column($g['manual'], 'net'));
        }
        foreach ($contested as $line) {
            $gk = $line['sym'] . '|' . $line['account'];
            $nets = array_column($groups[$gk]['docs'], 'net');
            usort($nets, static fn (float $a, float $b): int => abs($b) <=> abs($a));
            $natural = ($nets[0] ?? 0.0) > 0 ? 1 : -1;
            $reduces = ($line['delta'] > 0 ? 1 : -1) !== $natural;
            if (!$reduces || abs($line['delta']) <= max(0.0, $natural * $balance[$gk]) + self::BALANCE_TOLERANCE) {
                self::addLine($groups, $gk, $line, null);
                $balance[$gk] += $line['delta'];
                continue;
            }
            $moved[] = $line;
        }
        $p = $this->ctx->protocol;
        foreach ($moved as $line) {
            $candidates = $line['candidates'];
            $p->count(DocumentLinker::STEP_PAYMENTS, 'paired_by_description');
            $left = abs($line['delta']);
            $sign = $line['delta'] > 0 ? 1 : -1;
            $i = 0;
            foreach ($candidates as $ref => $home) {
                $i++;
                $docNet = $groups[$home]['docs'][$ref]['net'];
                // Do zůstatku dokladu jen řádek, který ho snižuje; zbytek dostane poslední doklad.
                $open = ($docNet > 0) !== ($line['delta'] > 0) ? max(0.0, ($docNet > 0 ? 1 : -1) * ($balance[$home] ?? 0.0)) : 0.0;
                $part = $i === count($candidates) ? $left : round(min($left, $open), 2);
                if ($part < 0.01) {
                    continue;
                }
                $share = $part / abs($line['delta']);
                self::addLine($groups, $home, ['delta' => $sign * $part, 'fdelta' => $line['fdelta'] * $share] + $line, [$ref]);
                $balance[$home] = ($balance[$home] ?? 0.0) + $sign * $part;
                $left = round($left - $part, 2);
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $groups
     * @param array<string,mixed> $line
     * @param list<string>|null $pin doklady, kterých se řádek týká (null = všech dokladů skupiny)
     */
    private static function addLine(array &$groups, string $gk, array $line, ?array $pin): void
    {
        $groups[$gk] ??= ['ico' => '', 'docs' => [], 'pay' => [], 'manual' => []];
        $doc = $line['doc'];
        if ($doc[0] === 'manual' || $doc[0] === 'payment') {
            [$part, $key, $entry] = $doc[0] === 'manual'
                ? ['manual', $line['year'] . '|' . $line['source'] . '|' . $line['docNo'] . '|' . $line['date'],
                    ['doc' => $line['docNo'], 'date' => $line['date'], 'mena' => $line['mena'], 'counter' => $line['counter']]]
                : ['pay', $line['year'] . '|' . $line['zdroj'] . '|' . $line['docNo'] . '|' . $line['date'],
                    ['zdroj' => $line['zdroj'], 'year' => $line['year'], 'doc' => $line['docNo'], 'date' => $line['date'], 'mena' => $line['mena'], 'ico' => $line['ico']]];
            if (!isset($groups[$gk][$part][$key])) {
                $groups[$gk][$part][$key] = $entry + ['net' => 0.0, 'fnet' => 0.0, 'pin' => $pin];
            } else {
                $current = $groups[$gk][$part][$key]['pin'];
                $groups[$gk][$part][$key]['pin'] = $current === null || $pin === null ? null : array_values(array_unique(array_merge($current, $pin)));
            }
            $groups[$gk][$part][$key]['net'] += $line['delta'];
            $groups[$gk][$part][$key]['fnet'] += $line['fdelta'];
            return;
        }
        $ref = $doc[0] . ':' . $doc[1];
        $groups[$gk]['docs'][$ref] ??= ['type' => $doc[0], 'id' => (int) $doc[1], 'net' => 0.0, 'fnet' => 0.0, 'mena' => $line['mena'], 'date' => $line['date'], 'ico' => ''];
        $groups[$gk]['docs'][$ref]['net'] += $line['delta'];
        $groups[$gk]['docs'][$ref]['fnet'] += $line['fdelta'];
        if ($line['ico'] !== '') {
            if ($groups[$gk]['docs'][$ref]['ico'] === '') {
                $groups[$gk]['docs'][$ref]['ico'] = $line['ico'];
            }
            if ($groups[$gk]['ico'] === '') {
                $groups[$gk]['ico'] = $line['ico'];
            }
        }
    }

    /**
     * Čísla převedených dokladů (vydané a přijaté faktury, ostatní pohledávky a závazky)
     * => doklady s rokem.
     *
     * @return array<string,list<array{0:string,1:int,2:int}>>
     */
    private static function documentNumbers(ImportContext $ctx): array
    {
        $out = [];
        foreach ([['invoice', $ctx->issuedInvoices], ['invoice', $ctx->otherReceivables], ['purchase_invoice', $ctx->purchaseInvoices]] as [$type, $map]) {
            foreach ($map as $key => $id) {
                $parts = explode('|', (string) $key, 3);
                if (count($parts) >= 2 && $parts[1] !== '') {
                    $out[mb_strtoupper($parts[1])][] = [$type, (int) $id, (int) $parts[0]];
                }
            }
        }
        return $out;
    }

    /**
     * Převedené doklady, jejichž číslo nese popis řádku deníku („zápočet FV20260018,20260019
     * X PFZ260022", „20220031 - Úhrada faktury vydané", „Kurz. ztráta … 20210215A").
     * Předpona FV/FP/DBP před číslem se ignoruje, ze stejného čísla v několika letech platí
     * doklad nejbližšího roku, který řádek nepředchází. Čísla kratší než 5 znaků ani
     * zkrácené odkazy („-030") se nehledají.
     *
     * @param array<string,list<array{0:string,1:int,2:int}>> $numbers
     * @return list<string> doklady `typ:id` v pořadí popisu
     */
    public static function popisRefs(string $popis, int $year, array $numbers): array
    {
        if ($popis === '' || preg_match('/\d{4}/', $popis) !== 1) {
            return [];
        }
        $refs = [];
        foreach (preg_split('/[^A-Z0-9]+/', mb_strtoupper($popis)) ?: [] as $token) {
            if (strlen($token) < 5 || preg_match('/\d{4}/', $token) !== 1) {
                continue;
            }
            $found = $numbers[$token] ?? null;
            if ($found === null && preg_match('/^(?:FV|FP|FA|DBP|DOBROPIS)(\d.*)$/', $token, $m) === 1) {
                $found = $numbers[$m[1]] ?? null;
            }
            $bestYear = null;
            $pick = null;
            foreach ($found ?? [] as [$type, $id, $docYear]) {
                if ($docYear > $year || ($bestYear !== null && $docYear < $bestYear)) {
                    continue;
                }
                // Stejné číslo téhož roku u dvou druhů dokladů nejde rozlišit.
                $pick = $bestYear === $docYear && $pick !== null ? false : $type . ':' . $id;
                $bestYear = $docYear;
            }
            if (is_string($pick) && !in_array($pick, $refs, true)) {
                $refs[] = $pick;
            }
        }
        return $refs;
    }

    /**
     * Stav firmy, ze kterého párování vychází - jedním dotazem na tabulku: evidované úhrady
     * po dokladech (spárované pohyby, pokladna, zápočty i platby zadané v MyÚčtu) a pohybech,
     * pokladní doklady, druh a měna dokladů, zápisy interních dokladů z mapy převodu a účty.
     *
     * Částky jsou v Kč u korunových dokladů; u dokladů v cizí měně jdou úhrady v jejich
     * měně do `allocatedF`.
     *
     * @param array<int,array{amount:float,currency:string,date:string,czk:float}> $movements
     * @return array<string,mixed>
     */
    private function state(ImportContext $ctx, array $movements): array
    {
        $pdo = $this->db->pdo();
        $sid = $ctx->supplierId;
        $state = ['allocated' => [], 'allocatedF' => [], 'linked' => [], 'pmAmount' => [], 'txUsed' => [], 'cash' => [], 'info' => [], 'entries' => [], 'accounts' => [],
            'booked' => [], 'manualLinks' => [], 'manualLinked' => [], 'settledEntries' => [], 'own' => [], 'ownPm' => []];

        foreach ([
            'invoice' => 'SELECT d.id, d.invoice_type AS kind, d.exchange_rate, d.varsymbol, d.total_with_vat, cur.code FROM invoices d JOIN currencies cur ON cur.id = d.currency_id WHERE d.supplier_id = ?',
            'purchase_invoice' => 'SELECT d.id, d.document_kind AS kind, d.exchange_rate, d.varsymbol, d.total_with_vat, cur.code FROM purchase_invoices d JOIN currencies cur ON cur.id = d.currency_id WHERE d.supplier_id = ?',
        ] as $type => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$sid]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $state['info'][$type][(int) $r['id']] = [
                    'kind' => (string) $r['kind'], 'currency' => (string) $r['code'], 'number' => (string) $r['varsymbol'],
                    'negative' => (float) $r['total_with_vat'] < 0, 'total' => abs((float) $r['total_with_vat']),
                    'rate' => $r['exchange_rate'] !== null && (float) $r['exchange_rate'] > 0 ? (float) $r['exchange_rate'] : null,
                ];
            }
        }
        // Částka v měně dokladu → do Kč, resp. do úhrad v cizí měně.
        $add = static function (array &$state, string $ref, string $type, int $id, float $amount): void {
            $currency = $state['info'][$type][$id]['currency'] ?? 'CZK';
            if ($currency === 'CZK') {
                $state['allocated'][$ref] = ($state['allocated'][$ref] ?? 0.0) + $amount;
            } else {
                $state['allocatedF'][$ref][$currency] = ($state['allocatedF'][$ref][$currency] ?? 0.0) + $amount;
                $state['allocated'][$ref] = ($state['allocated'][$ref] ?? 0.0) + $amount * ($state['info'][$type][$id]['rate'] ?? 1.0);
            }
        };

        // Párování, která vytvořil převod (mapa úhrad); ostatní zadal uživatel.
        $pm = $pdo->prepare('SELECT p.id, p.bank_transaction_id, p.invoice_id, p.purchase_invoice_id, p.amount, ' . DocumentLinker::ownMatchSql('p') . ' AS own
                               FROM payment_matches p WHERE p.supplier_id = ?');
        $pm->execute([$sid]);
        while ($r = $pm->fetch(PDO::FETCH_ASSOC)) {
            $tx = (int) $r['bank_transaction_id'];
            $m = $movements[$tx] ?? null;
            $czk = abs((float) $r['amount']);
            // Párování nese částku v měně pohybu, saldokonto Money je v Kč.
            if ($m !== null && $m['currency'] !== 'CZK' && abs($m['amount']) >= 0.01 && isset($ctx->bankTransactionCzk[$tx])) {
                $czk = $czk * abs($ctx->bankTransactionCzk[$tx]) / abs($m['amount']);
            }
            $state['txUsed'][$tx] = ($state['txUsed'][$tx] ?? 0.0) + $czk;
            $ref = $r['invoice_id'] !== null ? 'invoice:' . $r['invoice_id'] : ($r['purchase_invoice_id'] !== null ? 'purchase_invoice:' . $r['purchase_invoice_id'] : null);
            if ((int) $r['own'] === 1) {
                $state['own'][(int) $r['id']] = true;
            }
            if ($ref !== null) {
                // Vratka (příchozí pohyb k přijaté faktuře, u dobropisu odchozí) úhradu snižuje -
                // stejně ji čte saldokonto ({@see \MyInvoice\Support\Sql\PurchaseSettledExpr::bankAmountSql()}).
                // Vydanou fakturu saldokonto čte z evidovaných plateb, párování ji nesnižuje.
                $refund = $m !== null && $r['purchase_invoice_id'] !== null
                    && ($m['amount'] > 0) !== ($state['info']['purchase_invoice'][(int) $r['purchase_invoice_id']]['negative'] ?? false);
                $sign = $refund ? -1.0 : 1.0;
                $state['allocated'][$ref] = ($state['allocated'][$ref] ?? 0.0) + $sign * $czk;
                $state['linked'][$ref]['b' . $tx] = true;
                if (!$refund) {
                    $state['pmAmount'][$ref][$tx] = ($state['pmAmount'][$ref][$tx] ?? 0.0) + abs((float) $r['amount']);
                    if ((int) $r['own'] === 1) {
                        $state['ownPm'][$ref][$tx] = true;
                    }
                }
                if ($m !== null && $m['currency'] !== 'CZK') {
                    $state['allocatedF'][$ref][$m['currency']] = ($state['allocatedF'][$ref][$m['currency']] ?? 0.0) + $sign * abs((float) $r['amount']);
                }
            }
        }

        $cash = $pdo->prepare("SELECT id, issue_date, total_amount, invoice_id, purchase_invoice_id FROM cash_documents WHERE supplier_id = ? AND status <> 'draft'");
        $cash->execute([$sid]);
        while ($r = $cash->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $r['id'];
            $state['cash'][$id] = [
                'date' => substr((string) $r['issue_date'], 0, 10),
                'invoice_id' => $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null,
                'purchase_invoice_id' => $r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null,
            ];
            $ref = $r['invoice_id'] !== null ? 'invoice:' . $r['invoice_id'] : ($r['purchase_invoice_id'] !== null ? 'purchase_invoice:' . $r['purchase_invoice_id'] : null);
            if ($ref !== null) {
                $state['allocated'][$ref] = ($state['allocated'][$ref] ?? 0.0) + abs((float) $r['total_amount']);
                $state['linked'][$ref]['c' . $id] = true;
            }
        }

        // Zápočty (převzaté i zadané v MyÚčtu) a ruční platby vydaných faktur.
        $settlements = $pdo->prepare("SELECT doc_type, doc_id, amount, journal_entry_id FROM invoice_settlements WHERE supplier_id = ? AND status = 'confirmed'");
        $settlements->execute([$sid]);
        while ($r = $settlements->fetch(PDO::FETCH_ASSOC)) {
            $ref = $r['doc_type'] . ':' . $r['doc_id'];
            $add($state, $ref, (string) $r['doc_type'], (int) $r['doc_id'], (float) $r['amount']);
            if ($r['journal_entry_id'] !== null) {
                $state['linked'][$ref]['e' . (int) $r['journal_entry_id']] = true;
                $state['settledEntries'][(int) $r['journal_entry_id']][(string) $r['doc_type']] = true;
            }
        }
        // Zaúčtované doklady a vazby ručních zápisů (převzaté i zadané v MyÚčtu).
        $links = $pdo->prepare(
            "SELECT dl.entry_id, dl.doc_type, dl.doc_id, e.source_type
               FROM journal_entry_document_links dl
               JOIN journal_entries e ON e.id = dl.entry_id AND e.supplier_id = dl.supplier_id
              WHERE dl.supplier_id = ? AND dl.doc_type IN ('invoice', 'purchase_invoice')"
        );
        $links->execute([$sid]);
        while ($r = $links->fetch(PDO::FETCH_ASSOC)) {
            if ((string) $r['source_type'] === (string) $r['doc_type']) {
                $state['booked'][(string) $r['doc_type']][(int) $r['doc_id']] = true;
            } elseif ((string) $r['source_type'] === 'manual') {
                $state['manualLinks'][(int) $r['entry_id']][(string) $r['doc_type']][] = (int) $r['doc_id'];
                $state['linked'][$r['doc_type'] . ':' . $r['doc_id']]['e' . (int) $r['entry_id']] = true;
                $state['manualLinked'][$r['doc_type'] . ':' . $r['doc_id']][(int) $r['entry_id']] = true;
            }
        }
        $offsets = $pdo->prepare(
            "SELECT oi.doc_type, oi.doc_id, oi.amount FROM offset_agreement_items oi
               JOIN offset_agreements oa ON oa.id = oi.agreement_id AND oa.status = 'confirmed'
              WHERE oi.supplier_id = ?
             UNION ALL
             SELECT doc_type, invoice_id, amount FROM credit_note_offsets WHERE supplier_id = ?
             UNION ALL
             SELECT 'invoice', invoice_id, amount FROM invoice_payments
              WHERE supplier_id = ? AND source IN ('manual', 'mark_paid', 'legacy') AND bank_transaction_id IS NULL
             UNION ALL
             SELECT 'invoice', ip.invoice_id, ip.amount FROM invoice_payments ip
              WHERE ip.supplier_id = ? AND ip.source IN ('credit_note', 'settlement')
                AND NOT EXISTS (SELECT 1 FROM credit_note_offsets o WHERE o.invoice_payment_id = ip.id)
                AND NOT EXISTS (SELECT 1 FROM invoice_settlements s WHERE s.invoice_payment_id = ip.id)"
        );
        $offsets->execute([$sid, $sid, $sid, $sid]);
        while ($r = $offsets->fetch(PDO::FETCH_ASSOC)) {
            $add($state, $r['doc_type'] . ':' . $r['doc_id'], (string) $r['doc_type'], (int) $r['doc_id'], (float) $r['amount']);
        }

        // Zápisy interních dokladů z mapy převodu (klíč `rok|zdroj|číslo|datum`), jen živé
        // ruční zápisy firmy - zápis mohl uživatel smazat nebo stornovat.
        $liveManual = $pdo->prepare("SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual' AND reversed_by IS NULL");
        $liveManual->execute([$sid]);
        $live = array_fill_keys(array_map('intval', $liveManual->fetchAll(PDO::FETCH_COLUMN)), true);
        foreach ($this->map->all($sid, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY) as $key => $entryId) {
            $parts = explode('|', (string) $key);
            if (count($parts) >= 4 && Ms3Journal::sourceType($parts[1]) === 'manual' && isset($live[(int) $entryId])) {
                $state['entries'][$parts[0] . '|' . $parts[1] . '|' . $parts[2] . '|' . $parts[3]][] = (int) $entryId;
            }
        }
        $accounts = $pdo->prepare('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $accounts->execute([$sid]);
        while ($r = $accounts->fetch(PDO::FETCH_ASSOC)) {
            $state['accounts'][(string) $r['account_code']] = (int) $r['id'];
        }
        return $state;
    }

    /**
     * Klíče převodu `rok|číslo[|účet]` → id po `rok|číslo` (stejné číslo dokladu na dvou
     * účtech či pokladnách rozliší datum).
     *
     * @param array<string,int> $map
     * @return array<string,list<int>>
     */
    private static function byDocument(array $map): array
    {
        $out = [];
        foreach ($map as $key => $id) {
            $parts = explode('|', (string) $key, 3);
            $out[$parts[0] . '|' . ($parts[1] ?? '')][] = (int) $id;
        }
        return $out;
    }
}
